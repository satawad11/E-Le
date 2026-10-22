<?php

namespace App\Console\Commands;

use App\Models\Authz\PermissionTemplate;
use App\Models\Authz\RoleGrant;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Access\AccessRecipients;
use App\Services\Access\PermissionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * ตั้งผู้ถือชุดสิทธิ์คนแรก (Q11 · ADR-R-023) — ทางเดียวที่ข้าม grant_ceiling ได้ และใช้ได้เฉพาะตอนที่ยังไม่มีผู้ถือเลย
 *
 * - เฉพาะ `system.admin` และ `personnel.hr_officer` · ปฏิเสธเมื่อมีผู้ถือชุดสิทธิ์นั้นที่ยังมีผล (ไม่ถอน ไม่หมดอายุ บัญชี active)
 * - ต้องมี --order-number และ --expires ไม่เกิน 90 วัน · บัญชีเป้าหมายต้อง active และไม่ใช่บัญชีระบบ
 * - ล้าง MFA และ token ของบัญชีเป้าหมาย → เข้าสู่ระบบครั้งแรกต้องตั้ง MFA ใหม่ (ชุดสิทธิ์ทั้งสองบังคับ MFA อยู่แล้ว)
 * - บันทึก permission_audit_logs และแจ้งผู้ดำรงตำแหน่ง L1 ทุกครั้ง · ไม่มี API หรือหน้าจอสำหรับงานนี้
 */
class AccessBootstrapCommand extends Command
{
    public const TEMPLATES = ['system.admin', 'personnel.hr_officer'];

    public const MAX_DAYS = 90;

    protected $signature = 'access:bootstrap
        {template : ชุดสิทธิ์ (system.admin หรือ personnel.hr_officer)}
        {user : ชื่อผู้ใช้หรืออีเมลของบัญชีที่จะรับสิทธิ์}
        {--order-number= : เลขคำสั่งแต่งตั้ง (บังคับ)}
        {--expires= : วันหมดอายุ YYYY-MM-DD ไม่เกิน 90 วัน (บังคับ)}';

    protected $description = 'ตั้งผู้ถือชุดสิทธิ์ผู้ดูแลระบบหรืองานบุคคลคนแรก เมื่อยังไม่มีผู้ถือที่มีผลเลย';

    public function handle(PermissionAudit $audit, AccessRecipients $recipients): int
    {
        $code = (string) $this->argument('template');
        if (! in_array($code, self::TEMPLATES, true)) {
            return $this->refuse('ใช้ได้เฉพาะชุดสิทธิ์ '.implode(', ', self::TEMPLATES));
        }

        $order = trim((string) $this->option('order-number'));
        if ($order === '') {
            return $this->refuse('ต้องระบุ --order-number (เลขคำสั่งแต่งตั้ง)');
        }

        $expiresInput = (string) $this->option('expires');
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresInput) ? Carbon::createFromFormat('Y-m-d', $expiresInput)->startOfDay() : null;
        if ($expires === null || $expires->format('Y-m-d') !== $expiresInput) {
            return $this->refuse('ต้องระบุ --expires เป็นวันที่ YYYY-MM-DD');
        }
        if ($expires->lte(today()) || $expires->gt(today()->addDays(self::MAX_DAYS))) {
            return $this->refuse('--expires ต้องอยู่หลังวันนี้และไม่เกิน '.self::MAX_DAYS.' วัน ('.today()->addDays(self::MAX_DAYS)->toDateString().')');
        }

        $template = PermissionTemplate::where('code', $code)->whereNull('retired_at')->first();
        if ($template === null) {
            return $this->refuse("ไม่พบชุดสิทธิ์ {$code} — รัน php artisan modules:sync ก่อน");
        }

        $identifier = (string) $this->argument('user');
        $user = User::where('username', $identifier)->orWhere('email', $identifier)->first();
        if ($user === null || $user->status !== User::STATUS_ACTIVE || $user->isService()) {
            return $this->refuse('บัญชีเป้าหมายต้องมีอยู่ อยู่ในสถานะใช้งาน และไม่ใช่บัญชีระบบ');
        }

        $today = today()->toDateString();
        $holders = RoleGrant::where('template_id', $template->id)
            ->whereNull('ended_at')
            ->whereDate('expires_at', '>=', $today)
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->count();
        if ($holders > 0) {
            return $this->refuse("มีผู้ถือ {$code} ที่ยังมีผลอยู่แล้ว — มอบเพิ่มผ่านหน้าจอ/API ตามปกติ (grant_ceiling + ผู้ยืนยันคนที่สอง)");
        }

        $grant = DB::transaction(function () use ($user, $template, $order, $expires, $today, $audit, $code) {
            $grant = RoleGrant::create([
                'user_id' => $user->id,
                'template_id' => $template->id,
                'order_number' => $order,
                'granted_by' => null,
                'approved_by' => null,
                'effective_from' => $today,
                'expires_at' => $expires->toDateString(),
            ]);

            $credentials = $user->mfaCredentials()->delete();
            $tokens = $user->tokens()->delete();

            $audit->log('role_grant.bootstrapped', $grant, null, [
                'template_code' => $code,
                'user_id' => $user->id,
                'username' => $user->username,
                'order_number' => $order,
                'effective_from' => $today,
                'expires_at' => $expires->toDateString(),
                'mfa_credentials_cleared' => $credentials,
                'tokens_revoked' => $tokens,
                'via' => 'artisan access:bootstrap',
                'host' => gethostname() ?: null,
                'os_user' => get_current_user() ?: null,
            ], null);

            return $grant;
        });

        $directors = $recipients->directors();
        Notification::send($directors, new AccountNotice(
            AccountNotice::BOOTSTRAP_GRANT,
            'ตั้งผู้ถือชุดสิทธิ์ '.$template->name.' คนแรกด้วยคำสั่งบนเซิร์ฟเวอร์',
            [
                'บัญชี '.$user->username.' ได้รับชุดสิทธิ์ '.$template->name.' ตามคำสั่ง '.$order.' ถึงวันที่ '.$expires->toDateString(),
                'ตั้งด้วยคำสั่ง access:bootstrap เมื่อ '.now()->format('d/m/Y H:i').' ซึ่งใช้ได้เฉพาะเมื่อยังไม่มีผู้ถือชุดสิทธิ์นี้ที่มีผล',
                'ถ้าไม่ได้สั่งการไว้ ให้ถอนสิทธิ์และตรวจสอบบันทึกการใช้งานทันที',
            ],
        ));

        $this->info("มอบ {$code} ให้ {$user->username} แล้ว (หมดอายุ {$expires->toDateString()}) · ล้าง MFA/token — เข้าสู่ระบบครั้งแรกต้องตั้ง MFA");
        if ($directors->isEmpty()) {
            $this->warn('ไม่พบผู้ดำรงตำแหน่ง L1 ที่ใช้งานอยู่ — ยังไม่มีผู้รับแจ้งเตือน ให้แจ้ง ผอ. ด้วยตนเอง (บันทึกใน permission_audit_logs แล้ว)');
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
