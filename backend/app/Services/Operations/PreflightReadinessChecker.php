<?php

namespace App\Services\Operations;

use App\Models\DocumentTemplate;
use App\Models\FiscalYear;
use App\Models\Holiday;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelType;
use App\Models\Setting;
use App\Models\Time\WorkSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreflightReadinessChecker
{
    /**
     * @return array{
     *     summary: array{
     *         pass_count: int,
     *         warn_count: int,
     *         fail_count: int,
     *         is_ready: bool,
     *         status: string,
     *         checked_at: string
     *     },
     *     checks: array<int, array{
     *         id: string,
     *         category: string,
     *         status: string,
     *         message: string,
     *         advice: string|null,
     *         action_url: string,
     *         action_text: string
     *     }>
     * }
     */
    public function check(): array
    {
        $checks = [];
        $failCount = 0;
        $warnCount = 0;
        $passCount = 0;

        $collect = function (string $id, string $category, string $status, string $message, ?string $advice, string $actionUrl, string $actionText) use (&$checks, &$failCount, &$warnCount, &$passCount) {
            if ($status === 'FAIL') {
                $failCount++;
            } elseif ($status === 'WARN') {
                $warnCount++;
            } else {
                $passCount++;
            }

            $checks[] = [
                'id' => $id,
                'category' => $category,
                'status' => $status,
                'message' => $message,
                'advice' => $advice,
                'action_url' => $actionUrl,
                'action_text' => $actionText,
            ];
        };

        // 1. ผังองค์กร (OrgUnits)
        $this->checkOrganizationUnits($collect);

        // 2. ผู้บังคับบัญชา (OrgUnitManagers)
        $this->checkOrgUnitManagers($collect);

        // 3. บุคลากรและผู้ใช้ (Users & Personnel)
        $this->checkPersonnelAndUsers($collect);

        // 4. ปีงบประมาณและนโยบายวันลา
        $this->checkFiscalYearAndPolicies($collect);

        // 5. ปฏิทินและเวลาทำงาน
        $this->checkWorkScheduleAndHolidays($collect);

        // 6. แบบฟอร์มเอกสาร (Document Engine)
        $this->checkDocumentTemplates($collect);

        // 7. ศูนย์การแจ้งเตือน (Notifications)
        $this->checkNotificationChannels($collect);

        // 8. งานสารบรรณ (Saraban Readiness)
        $this->checkSarabanReadiness($collect);

        $status = 'READY';
        if ($failCount > 0) {
            $status = 'FAILED';
        } elseif ($warnCount > 0) {
            $status = 'WARNING';
        }

        return [
            'summary' => [
                'pass_count' => $passCount,
                'warn_count' => $warnCount,
                'fail_count' => $failCount,
                'is_ready' => $failCount === 0,
                'status' => $status,
                'checked_at' => now()->toISOString(),
            ],
            'checks' => $checks,
        ];
    }

    private function checkOrganizationUnits(callable $collect): void
    {
        $totalUnits = OrgUnit::query()->where('is_active', true)->count();
        $rootUnits = OrgUnit::query()->whereNull('parent_id')->where('is_active', true)->count();

        if ($totalUnits === 0) {
            $collect('org_units', 'ผังองค์กร (OrgUnits)', 'FAIL', 'ยังไม่มีโครงสร้างหน่วยงานในระบบ', 'เพิ่มสถานศึกษายอดผังและหน่วยงานย่อยผ่านเมนู จัดการผังองค์กร', '/admin/org-units', 'จัดการผังองค์กร');

            return;
        }

        if ($rootUnits === 0) {
            $collect('org_units', 'ผังองค์กร (OrgUnits)', 'FAIL', 'ไม่พบหน่วยงานระดับบนสุด (Root OrgUnit)', 'ตั้งค่าหน่วยงานระดับบนสุดให้มี parent_id เป็น null', '/admin/org-units', 'จัดการผังองค์กร');

            return;
        }

        if ($rootUnits > 1) {
            $collect('org_units', 'ผังองค์กร (OrgUnits)', 'WARN', "พบยอดผังระดับบนสุด {$rootUnits} แห่ง", 'ควรมียอดผังสถานศึกษาเพียงแห่งเดียว เว้นแต่มีหลายวิทยาเขต', '/admin/org-units', 'จัดการผังองค์กร');

            return;
        }

        $collect('org_units', 'ผังองค์กร (OrgUnits)', 'PASS', "โครงสร้างหน่วยงานสมบูรณ์ ({$totalUnits} หน่วยงาน, 1 ยอดผัง)", null, '/admin/org-units', 'จัดการผังองค์กร');
    }

    private function checkOrgUnitManagers(callable $collect): void
    {
        if (! Schema::hasTable('org_unit_managers')) {
            $collect('org_unit_managers', 'ผู้บังคับบัญชา (OrgUnitManagers)', 'WARN', 'ไม่พบคอนฟิก org_unit_managers', null, '/admin/org-unit-managers', 'จัดการผู้บังคับบัญชา');

            return;
        }

        $today = now()->toDateString();
        $activeUnits = OrgUnit::query()->where('is_active', true)->get();
        $unitsWithManager = 0;
        $unitsWithoutManager = [];

        foreach ($activeUnits as $unit) {
            $hasManager = DB::table('org_unit_managers')
                ->where('org_unit_id', $unit->id)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->exists();

            if ($hasManager) {
                $unitsWithManager++;
            } else {
                $unitsWithoutManager[] = $unit->name;
            }
        }

        if ($activeUnits->count() > 0 && $unitsWithManager === 0) {
            $collect('org_unit_managers', 'ผู้บังคับบัญชา (OrgUnitManagers)', 'FAIL', 'ยังไม่มีการแต่งตั้งหัวหน้าหน่วยงานใด ๆ เลย', 'กำหนดหัวหน้าฝ่าย/งานผ่านเมนู ผู้จัดการหน่วยงาน เพื่อให้สายอนุมัติทำงานได้', '/admin/org-unit-managers', 'แต่งตั้งหัวหน้าหน่วยงาน');

            return;
        }

        if (count($unitsWithoutManager) > 0) {
            $msg = "มีผู้บังคับบัญชา {$unitsWithManager}/{$activeUnits->count()} หน่วยงาน (ขาด ".count($unitsWithoutManager).' แห่ง)';
            $advice = 'หน่วยงานที่ยังไม่มีหัวหน้า: '.implode(', ', array_slice($unitsWithoutManager, 0, 3)).(count($unitsWithoutManager) > 3 ? ' และอื่น ๆ' : '');
            $collect('org_unit_managers', 'ผู้บังคับบัญชา (OrgUnitManagers)', 'WARN', $msg, $advice, '/admin/org-unit-managers', 'แต่งตั้งหัวหน้าหน่วยงาน');

            return;
        }

        $collect('org_unit_managers', 'ผู้บังคับบัญชา (OrgUnitManagers)', 'PASS', "ทุกหน่วยงานมีผู้บังคับบัญชาพร้อมปฏิบัติหน้าที่ ({$unitsWithManager} แห่ง)", null, '/admin/org-unit-managers', 'ตรวจสอบผู้บังคับบัญชา');
    }

    private function checkPersonnelAndUsers(callable $collect): void
    {
        $userCount = User::query()->count();
        $adminCount = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Admin', 'super_admin', 'Administrator', 'admin']))->count();

        if ($userCount === 0) {
            $collect('personnel_and_users', 'บุคลากรและผู้ใช้ (Users & Personnel)', 'FAIL', 'ไม่มีบัญชีผู้ใช้งานในระบบ', 'นำเข้าบุคลากรผ่านไฟล์ CSV หรือคำสั่ง seeder', '/admin/users', 'นำเข้าบุคลากร');

            return;
        }

        if ($adminCount === 0) {
            $collect('personnel_and_users', 'บุคลากรและผู้ใช้ (Users & Personnel)', 'FAIL', 'ไม่พบบัญชีผู้ดูแลระบบ (Super Admin)', 'กำหนดบทบาท Super Admin ให้ผู้ดูแลระบบอย่างน้อย 1 บัญชี', '/admin/users', 'กำหนดสิทธิ์ผู้ดูแลระบบ');

            return;
        }

        $personnelCount = Schema::hasTable('personnel') ? Personnel::query()->count() : 0;

        if ($personnelCount < $userCount) {
            $diff = $userCount - $personnelCount;
            $collect('personnel_and_users', 'บุคลากรและผู้ใช้ (Users & Personnel)', 'WARN', "พบบัญชีผู้ใช้ {$diff} ราย ที่ยังไม่ได้เชื่อมโยงข้อมูล Personnel", 'รันระบบซิงค์ข้อมูลบุคลากรเข้าสู่โดเมน Personnel', '/admin/users', 'จัดการบุคลากร');

            return;
        }

        $collect('personnel_and_users', 'บุคลากรและผู้ใช้ (Users & Personnel)', 'PASS', "ผู้ใช้งาน {$userCount} ราย (ผูก Personnel ครบถ้วน, ผู้ดูแล {$adminCount} ราย)", null, '/admin/users', 'ดูรายชื่อผู้ใช้งาน');
    }

    private function checkFiscalYearAndPolicies(callable $collect): void
    {
        $currentFiscalYear = FiscalYear::current();

        if ($currentFiscalYear === null) {
            $collect('fiscal_year_and_policies', 'ปีงบประมาณและนโยบายวันลา', 'FAIL', 'ยังไม่ได้กำหนดปีงบประมาณปัจจุบัน (is_current = true)', 'ตั้งค่าปีงบประมาณปัจจุบันผ่านเมนู จัดการปีงบประมาณ', '/admin/fiscal-years', 'จัดการปีงบประมาณ');

            return;
        }

        $today = now()->toDateString();
        if ($currentFiscalYear->start_date && $currentFiscalYear->end_date) {
            $startDate = $currentFiscalYear->start_date->toDateString();
            $endDate = $currentFiscalYear->end_date->toDateString();

            if ($today < $startDate || $today > $endDate) {
                $collect('fiscal_year_and_policies', 'ปีงบประมาณและนโยบายวันลา', 'WARN', "วันที่ปัจจุบัน ({$today}) อยู่นอกช่วงปีงบประมาณปัจจุบัน ({$startDate} ถึง {$endDate})", 'ตรวจสอบการเปิดรอบปีงบประมาณใหม่', '/admin/fiscal-years', 'จัดการปีงบประมาณ');
            }
        }

        $activePersonnelTypes = PersonnelType::query()->where('is_active', true)->get();
        $activeLeaveTypes = LeaveType::all();

        if ($activePersonnelTypes->isEmpty() || $activeLeaveTypes->isEmpty()) {
            $collect('fiscal_year_and_policies', 'ปีงบประมาณและนโยบายวันลา', 'FAIL', 'ยังไม่มีการตั้งค่าประเภทบุคลากรหรือประเภทวันลา', 'ตั้งค่าประเภทบุคลากรและประเภทวันลาให้พร้อมใช้งาน', '/admin/leave-policies', 'กำหนดนโยบายวันลา');

            return;
        }

        $missingPolicies = [];
        foreach ($activePersonnelTypes as $pt) {
            foreach ($activeLeaveTypes as $lt) {
                $hasPolicy = LeavePolicy::query()
                    ->where('personnel_type_id', $pt->id)
                    ->where('leave_type_id', $lt->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasPolicy) {
                    $missingPolicies[] = "{$pt->name} -> {$lt->name}";
                }
            }
        }

        if (count($missingPolicies) > 0) {
            $missingCount = count($missingPolicies);
            $remaining = $missingCount - 3;
            $sample = implode(', ', array_slice($missingPolicies, 0, 3)).($remaining > 0 ? " และอีก {$remaining} รายการ" : '');
            $collect('fiscal_year_and_policies', 'ปีงบประมาณและนโยบายวันลา', 'WARN', "พบนโยบายวันลาที่ยังไม่ได้ตั้งค่า {$missingCount} คู่", "ตัวอย่างที่ขาด: {$sample} (ไปที่เมนู กำหนดนโยบายวันลา)", '/admin/leave-policies', 'กำหนดนโยบายวันลา');

            return;
        }

        $collect('fiscal_year_and_policies', 'ปีงบประมาณและนโยบายวันลา', 'PASS', "ปีงบประมาณ {$currentFiscalYear->name} ใช้งานอยู่ และมีนโยบายวันลาครบทุกคู่", null, '/admin/leave-policies', 'ดูนโยบายวันลา');
    }

    private function checkWorkScheduleAndHolidays(callable $collect): void
    {
        $hasSchedule = Schema::hasTable('work_schedules') && WorkSchedule::query()->where('is_active', true)->exists();

        if (! $hasSchedule) {
            $collect('work_schedule_and_holidays', 'ปฏิทินและเวลาทำงาน', 'FAIL', 'ไม่พบตารางเวลาทำงานหลักของสถานศึกษา (WorkSchedule)', 'ตั้งค่าตารางเวลาทำงานหลัก (เช่น จันทร์-ศุกร์ 08:30-16:30)', '/admin/work-calendar', 'ตั้งค่าเวลาทำงาน');

            return;
        }

        $holidayCount = Holiday::query()->where('is_active', true)->count();

        if ($holidayCount === 0) {
            $collect('work_schedule_and_holidays', 'ปฏิทินและเวลาทำงาน', 'WARN', 'ยังไม่ได้บันทึกวันหยุดราชการประจำปี (Holidays)', 'เพิ่มวันหยุดราชการเพื่อความถูกต้องในการคำนวณวันลา', '/admin/work-calendar', 'เพิ่มวันหยุดราชการ');

            return;
        }

        $collect('work_schedule_and_holidays', 'ปฏิทินและเวลาทำงาน', 'PASS', "มีตารางเวลาทำงานหลัก และวันหยุดราชการ {$holidayCount} วัน", null, '/admin/work-calendar', 'ดูปฏิทินวันทำการ');
    }

    private function checkDocumentTemplates(callable $collect): void
    {
        if (! Schema::hasTable('document_templates')) {
            $collect('document_templates', 'แบบฟอร์มเอกสาร (Document Engine)', 'WARN', 'ไม่พบตาราง document_templates', null, '/saraban/document-templates', 'จัดการแบบฟอร์ม');

            return;
        }

        $publishedCount = 0;
        if (Schema::hasTable('document_template_versions')) {
            $versionQuery = DB::table('document_template_versions');
            if (Schema::hasColumn('document_template_versions', 'status')) {
                $versionQuery->whereIn('status', ['published', 'ACTIVE', 'active']);
            } elseif (Schema::hasColumn('document_template_versions', 'is_active')) {
                $versionQuery->where('is_active', true);
            }
            $publishedCount = $versionQuery->count();
        }

        if ($publishedCount === 0) {
            $publishedCount = DocumentTemplate::query()->where('is_active', true)->count();
        }

        $totalCount = DocumentTemplate::query()->count();

        if ($publishedCount === 0) {
            $collect('document_templates', 'แบบฟอร์มเอกสาร (Document Engine)', 'WARN', 'ยังไม่มีแบบฟอร์มเอกสารที่เผยแพร่ (Published)', 'เผยแพร่แบบฟอร์มมาตรฐานที่ศูนย์จัดการเอกสารเพื่อให้ระบบสร้าง PDF/Word ได้', '/saraban/document-templates', 'เผยแพร่แบบฟอร์ม');

            return;
        }

        $collect('document_templates', 'แบบฟอร์มเอกสาร (Document Engine)', 'PASS', "มีแบบฟอร์มเอกสารพร้อมใช้งาน ({$publishedCount}/{$totalCount} เผยแพร่แล้ว)", null, '/saraban/document-templates', 'ดูแบบฟอร์มเอกสาร');
    }

    private function checkNotificationChannels(callable $collect): void
    {
        $mailHost = config('mail.mailers.smtp.host');
        $isMailConfigured = filled($mailHost) && ! str_contains($mailHost, 'example') && ! str_contains($mailHost, 'unconfigured');

        /*
         * โทเคนของ LINE/Telegram เก็บในตาราง `settings` ไม่ใช่ env (ดู `config/services.php`)
         * การอ่านผ่าน `env()` ยังพังซ้ำเมื่อรัน `config:cache` บน production เพราะ
         * `env()` จะคืน null — ต้องอ่านจากแหล่งเดียวกับ adapter ที่ส่งจริง
         */
        $lineConfigured = Setting::query()->where('key', 'line_channel_access_token')->whereNotNull('value')->exists();
        $telegramConfigured = Setting::query()->where('key', 'telegram_bot_token')->whereNotNull('value')->exists();

        $channels = [];
        if ($isMailConfigured) {
            $channels[] = 'Email (SMTP)';
        }
        if ($lineConfigured) {
            $channels[] = 'LINE OA';
        }
        if ($telegramConfigured) {
            $channels[] = 'Telegram';
        }

        if (count($channels) === 0) {
            $collect('notification_channels', 'ศูนย์การแจ้งเตือน (Notifications)', 'WARN', 'ยังไม่ได้ตั้งค่าช่องทางส่งข้อความภายนอก (SMTP / LINE / Telegram)', 'ผู้ใช้จะได้รับการแจ้งเตือนเฉพาะในแอป (In-App) เท่านั้น แนะนำตั้งค่า SMTP หรือ LINE OA', '/admin/settings', 'ตั้งค่าการแจ้งเตือน');

            return;
        }

        $channelList = implode(', ', $channels);
        $collect('notification_channels', 'ศูนย์การแจ้งเตือน (Notifications)', 'PASS', "ช่องทางการแจ้งเตือนพร้อมใช้งาน: {$channelList}", null, '/admin/settings', 'ดูการตั้งค่าการแจ้งเตือน');
    }

    private function checkSarabanReadiness(callable $collect): void
    {
        if (Schema::hasTable('saraban_number_series')) {
            $activeSeriesCount = DB::table('saraban_number_series')->where('is_active', true)->count();
            if ($activeSeriesCount === 0) {
                $collect('saraban_number_series', 'งานสารบรรณ (Saraban Readiness)', 'WARN', 'ยังไม่ได้สร้างชุดเลขหนังสือราชการที่เปิดใช้งาน', 'สร้างชุดเลขหนังสืออย่างน้อยหนึ่งชุด หรือใช้ปุ่มตั้งค่าเริ่มต้นสำหรับสถานศึกษา', '/saraban/number-series', 'จัดการชุดเลขหนังสือ');
            } else {
                $collect('saraban_number_series', 'งานสารบรรณ (Saraban Readiness)', 'PASS', "มีชุดเลขหนังสือพร้อมใช้งาน ({$activeSeriesCount} ชุด)", null, '/saraban/number-series', 'ดูชุดเลขหนังสือ');
            }
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('model_has_permissions')) {
            $hasSigners = DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->where('permissions.name', 'saraban.signature.sign')
                ->exists();

            if (! $hasSigners && Schema::hasTable('role_has_permissions')) {
                $hasSigners = DB::table('role_has_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                    ->where('permissions.name', 'saraban.signature.sign')
                    ->exists();
            }

            if (! $hasSigners) {
                $collect('saraban_signers', 'งานสารบรรณ (Saraban Readiness)', 'WARN', 'ยังไม่มีผู้ได้รับมอบหมายสิทธิ์ลงนามในหนังสือราชการ (saraban.signature.sign)', 'มอบหมายสิทธิ์ลงนามให้แก่ผู้อำนวยการสถานศึกษาหรือผู้บริหาร', '/saraban/authority', 'กำหนดผู้มีอำนาจลงนาม');
            } else {
                $collect('saraban_signers', 'งานสารบรรณ (Saraban Readiness)', 'PASS', 'มีผู้ได้รับมอบหมายสิทธิ์ลงนามในหนังสือราชการเรียบร้อยแล้ว', null, '/saraban/authority', 'ดูผู้มีอำนาจลงนาม');
            }
        }
    }
}
