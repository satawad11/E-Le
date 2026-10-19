<?php

namespace App\Services;

use App\Models\OrgUnitManager;
use App\Models\OrgUnitMember;
use App\Models\User;
use App\Models\UserDelegation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * การทบทวนสิทธิ์ผู้ใช้ปีละครั้งตามปีการศึกษา (ACCESS-GOV-01 · คำตอบ Q9)
 *
 * สร้างรายงานผู้ใช้ที่ยังใช้งานพร้อมทุกช่องทางที่ให้อำนาจ (บทบาท สิทธิ์รายบุคคล ขอบเขตพิเศษ
 * หัวหน้าหน่วยงาน การทำการแทน) ให้หัวหน้างานตรวจ แล้วผู้บริหารลงนามรับรองในระบบ
 *
 * รายงานมีข้อมูลบุคคลจึงเก็บใน disk `local` (private) ไม่เปิดผ่าน URL สาธารณะ
 * รอบที่ลงนามแล้วสร้างใหม่ไม่ได้ เพื่อให้หลักฐานที่รับรองไปแล้วไม่เปลี่ยน
 */
class AccessReviewService
{
    /** สิทธิ์ที่ถือว่า "สิทธิ์สูง" ในรายงาน — กำหนดว่าใครอนุมัติ ใครให้สิทธิ์ หรือแก้ข้อมูลของคนอื่นได้ */
    public const PRIVILEGED_PERMISSIONS = [
        'admin.users.manage_permissions', 'system.menu.manage', 'system.org_structure.manage', 'manage_workflow_steps',
        'leave.management.manage', 'leave.delete', 'system.user.manage', 'system.setting.manage',
        'saraban.document.view_classified', 'saraban.setting.manage',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{review: object, path: string, user_count: int, privileged_count: int}
     */
    public function generate(User $actor, string $academicYear): array
    {
        $this->assertYear($academicYear);
        if (! $actor->is_active || ! $actor->can('admin.users.manage_permissions')) {
            throw new InvalidArgumentException('ผู้สร้างรายงานต้องเป็นผู้ดูแลที่มีสิทธิ์จัดการสิทธิ์ผู้ใช้');
        }

        $existing = DB::table('access_reviews')->where('academic_year', $academicYear)->first();
        if ($existing?->signed_off_at !== null) {
            throw new InvalidArgumentException("รอบทบทวนปีการศึกษา {$academicYear} ลงนามรับรองแล้ว สร้างใหม่ไม่ได้");
        }

        $today = now()->toDateString();
        $users = User::query()->where('is_active', true)
            ->with(['roles:id,name', 'permissions:id,name', 'orgUnit:id,name', 'profile'])
            ->orderBy('name')->get();

        $scopes = DB::table('permission_scopes')->where('model_type', (new User)->getMorphClass())->get()->groupBy('model_id');
        $managers = OrgUnitManager::with('orgUnit:id,name')->inEffect($today)->get()->groupBy('user_id');
        $delegations = UserDelegation::query()->inEffect($today)->get();
        $memberships = OrgUnitMember::with('orgUnit:id,name')->inEffect($today)->get()->groupBy('user_id');
        $grants = DB::table('user_role_grants')->whereNull('ended_at')->get()->keyBy('user_id');

        $rows = [];
        $privilegedCount = 0;
        foreach ($users as $user) {
            $all = $user->getAllPermissions()->pluck('name');
            $isSuperAdmin = $user->roles->pluck('name')->intersect(['Super Admin', 'SuperAdmin'])->isNotEmpty();
            $userScopes = $scopes->get($user->id, collect());
            $userManagers = $managers->get($user->id, collect());
            $privileged = $isSuperAdmin
                || $all->intersect(self::PRIVILEGED_PERMISSIONS)->isNotEmpty()
                || $user->permissions->isNotEmpty()
                || $userScopes->isNotEmpty();
            $privilegedCount += $privileged ? 1 : 0;

            $rows[] = [
                $user->email,
                $user->full_name_with_title ?? $user->name,
                $user->orgUnit?->name ?? '',
                $user->roles->pluck('name')->implode(', '),
                $user->permissions->pluck('name')->implode(', '),
                $userScopes->map(fn ($s) => $s->permission_name.'='.$s->scope)->implode(', '),
                $userManagers->map(fn (OrgUnitManager $m) => $m->assignment_type.' '.($m->orgUnit?->name ?? '').($m->order_number ? ' (คำสั่ง '.$m->order_number.')' : ''))->implode(', '),
                ($memberships->get($user->id) ?? collect())->map(fn (OrgUnitMember $m) => ($m->orgUnit?->name ?? '').' (คำสั่ง '.$m->order_number.')')->implode(', '),
                trim(($grants->get($user->id)?->order_number ? 'คำสั่ง '.$grants->get($user->id)->order_number.' ' : '').($grants->get($user->id)?->expires_at ? 'หมดอายุ '.substr($grants->get($user->id)->expires_at, 0, 10) : '')),
                $delegations->filter(fn ($d) => (int) $d->delegate_id === $user->id)->map(fn ($d) => $d->delegation_type.' แทน user#'.$d->user_id.' ถึง '.$d->end_date)->implode(', '),
                $user->last_login_at?->toDateTimeString() ?? 'ไม่เคยเข้าระบบ',
                $privileged ? 'ใช่' : '',
                '',
            ];
        }

        $header = ['อีเมล', 'ชื่อ', 'หน่วยงาน', 'บทบาท', 'สิทธิ์รายบุคคล', 'ขอบเขตพิเศษ', 'หัวหน้าหน่วยงาน (มีผล)', 'ประจำงาน (มีผล)', 'การมอบบทบาท', 'ทำการแทน (มีผล)', 'เข้าระบบล่าสุด', 'สิทธิ์สูง', 'ผลการทบทวน (คงไว้/ถอน)'];
        $path = "access-reviews/{$academicYear}.csv";
        Storage::disk('local')->put($path, "\u{FEFF}".$this->csv(array_merge([$header], $rows)));

        $values = [
            'generated_at' => now(),
            'generated_by' => $actor->id,
            'user_count' => count($rows),
            'privileged_count' => $privilegedCount,
            'file_path' => $path,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('access_reviews')->where('id', $existing->id)->update($values);
        } else {
            DB::table('access_reviews')->insert($values + ['uuid' => (string) Str::uuid(), 'academic_year' => $academicYear, 'created_at' => now()]);
        }
        $review = DB::table('access_reviews')->where('academic_year', $academicYear)->first();

        $this->audit->log('access.review.generated', 'access_review', $review->id, null, [
            'academic_year' => $academicYear, 'user_count' => count($rows), 'privileged_count' => $privilegedCount,
        ], null, $actor->id);

        return ['review' => $review, 'path' => $path, 'user_count' => count($rows), 'privileged_count' => $privilegedCount];
    }

    /** ผู้บริหารรับรองผลการทบทวน — ผู้รับรองต้องไม่ใช่ผู้สร้างรายงาน */
    public function signOff(User $actor, string $academicYear, ?string $note = null): object
    {
        $this->assertYear($academicYear);
        $review = DB::table('access_reviews')->where('academic_year', $academicYear)->first();

        if ($review === null) {
            throw new InvalidArgumentException("ยังไม่ได้สร้างรายงานทบทวนปีการศึกษา {$academicYear}");
        }
        if ($review->signed_off_at !== null) {
            throw new InvalidArgumentException("รอบทบทวนปีการศึกษา {$academicYear} ลงนามรับรองแล้ว");
        }
        if ((int) $review->generated_by === $actor->id) {
            throw new InvalidArgumentException('ผู้สร้างรายงานลงนามรับรองรายงานของตนเองไม่ได้');
        }
        if (! $actor->is_active) {
            throw new InvalidArgumentException('บัญชีผู้รับรองถูกระงับการใช้งาน');
        }
        // ผู้อำนวยการเป็นผู้บังคับบัญชาสูงสุดของสถานศึกษา (ระเบียบฯ 2569 ข้อ 41) — ผู้รับรองคือหัวหน้า/รักษาการของหน่วยระดับสถานศึกษา
        $rootId = DB::table('org_units')->whereNull('parent_id')->value('id');
        $isDirector = $rootId !== null && OrgUnitManager::query()
            ->where('org_unit_id', $rootId)->where('user_id', $actor->id)
            ->whereIn('assignment_type', [OrgUnitManager::TYPE_MANAGER, OrgUnitManager::TYPE_ACTING])
            ->inEffect()->exists();
        if (! $isDirector) {
            throw new InvalidArgumentException('ผู้รับรองต้องเป็นผู้อำนวยการหรือผู้รักษาการ ตามหน้าโครงสร้างหน่วยงาน');
        }

        DB::table('access_reviews')->where('id', $review->id)->update([
            'signed_off_by' => $actor->id,
            'signed_off_at' => now(),
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'updated_at' => now(),
        ]);
        $this->audit->log('access.review.signed_off', 'access_review', $review->id, null, ['academic_year' => $academicYear], $note, $actor->id);

        return DB::table('access_reviews')->where('id', $review->id)->first();
    }

    private function assertYear(string $academicYear): void
    {
        if (! preg_match('/^25\d{2}$/', $academicYear)) {
            throw new InvalidArgumentException('ปีการศึกษาต้องเป็น พ.ศ. 4 หลัก เช่น 2569');
        }
    }

    /** @param  array<int,array<int,string>>  $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            // กัน CSV injection เมื่อเปิดด้วยโปรแกรมตารางคำนวณ
            fputcsv($handle, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'".$v : (string) $v, $row), escape: '');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }
}
