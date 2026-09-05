<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use App\Services\DocumentEngine\PdfOverlayRenderer;
use App\Services\Modules\ModuleRegistry;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\OrgScopeResolver;
use App\Services\PermissionEvaluator;
use App\Services\SecureFileAccessService;
use App\Services\Workflow\Assignee\AssigneeResolverRegistry;
use App\Services\Workflow\DelegationResolver;
use App\Services\Workflow\WorkflowEngine;
use App\Support\StoredFile;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * INT-01 — สัญญาระหว่างโมดูลสารบรรณกับระบบกลาง (Core Contract)
 *
 * `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` §4 ห้ามโมดูลสารบรรณสร้าง
 * Auth / User / Personnel / Organization / Permission / Audit / Notification / API
 * ขึ้นมาซ้ำกับของกลาง ข้อห้ามนี้จะอยู่ได้จริงก็ต่อเมื่อมีอะไรบังคับมันทุกครั้งที่ CI รัน
 * ไม่ใช่เป็นเพียงข้อตกลงในเอกสารที่คนอ่านข้ามได้
 *
 * เทสต์นี้ทำสองหน้าที่:
 *
 *   1. **ตรึงของกลางที่สารบรรณจะเกาะ** — ถ้ามีใครลบหรือเปลี่ยนชื่อตาราง/เมธอด
 *      ที่สารบรรณพึ่งพา จะรู้ทันทีว่าสัญญาขาด ไม่ต้องรอไปพังตอนเขียนโมดูล
 *   2. **กันการสร้างของซ้ำ** — สแกนโค้ดและ migration ของสารบรรณ แล้วล้ม
 *      เมื่อพบรูปแบบที่แปลว่ากำลังสร้าง Core ชุดที่สอง
 *
 * ตอน INT-01 ยังไม่มีโค้ดสารบรรณอยู่จริง ข้อ 2 จึงยังไม่มีอะไรให้จับ —
 * `test_the_contract_scanner_actually_detects_violations()` จึงมีไว้พิสูจน์ว่า
 * ตัวสแกนทำงานจริงตั้งแต่วันนี้ ไม่ใช่ด่านที่ผ่านเพราะไม่ได้ตรวจอะไรเลย
 *
 * โมดูลสารบรรณรุ่นก่อนที่ถูกถอดออกไปแล้ว (migration `2026_08_27_000000_drop_saraban_module.php`)
 * เคยมีตาราง `saraban_audit_logs` เป็นของตัวเองคู่ขนานกับ `audit_logs` กลาง —
 * รายการต้องห้ามด้านล่างจึงไม่ใช่ข้อกังวลเชิงทฤษฎี แต่คือความผิดพลาดที่เคยเกิดขึ้นจริง
 */
class SarabanCoreContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ตารางกลางที่โมดูลสารบรรณจะอ้างถึง — ห้ามหาย ห้ามเปลี่ยนชื่อ
     *
     * ที่มาของแต่ละตัวอยู่ใน docs/saraban-integration/CORE-COMPATIBILITY-MATRIX.md
     *
     * @var array<string,string>
     */
    private const CORE_TABLES = [
        'users' => 'เจ้าของ identity — สารบรรณอ้าง user_id เท่านั้น',
        'personnel' => 'ทะเบียนบุคลากรกลาง',
        'personnel_types' => 'ประเภทบุคลากร',
        'user_profiles' => 'โปรไฟล์ + ลายเซ็น (ใช้ตอนลงนาม)',
        'org_units' => 'โครงสร้างองค์กรแบบต้นไม้ + effective dating',
        'personnel_assignments' => 'ใครดำรงตำแหน่งใด หน่วยงานใด ณ วันใด',
        'org_unit_managers' => 'หัวหน้าหน่วยงาน / ผู้รักษาการ',
        'workflow_delegations' => 'การมอบอำนาจของ workflow engine',
        'workflow_definitions' => 'นิยามสายงาน',
        'workflow_versions' => 'เวอร์ชันของสายงาน (draft/active/retired)',
        'workflow_instances' => 'เรื่องที่กำลังเดินสาย (ผูก version ไว้)',
        'workflow_tasks' => 'งานที่รอผู้มีสิทธิ์ทำ',
        'workflow_actions' => 'ประวัติการกระทำในสายงาน',
        'permissions' => 'สิทธิ์ (Spatie)',
        'roles' => 'บทบาท (Spatie)',
        'permission_denies' => 'explicit deny — ชนะทุก allow',
        'permission_scopes' => 'ขอบเขตของสิทธิ์ (ABAC)',
        'audit_logs' => 'audit กลางเพียงชุดเดียวของระบบ',
        'menus' => 'เมนูที่กรองตามสิทธิ์',
        'menu_permissions' => 'สิทธิ์ที่ผูกกับเมนู',
        'notification_types' => 'ชนิดการแจ้งเตือนของศูนย์แจ้งเตือนกลาง',
        'notification_templates' => 'แม่แบบข้อความ',
        'notification_events' => 'เหตุการณ์แจ้งเตือน',
        'notification_deliveries' => 'การนำส่งรายช่องทาง + retry',
        'document_templates' => 'แม่แบบเอกสารของ Document Engine',
        'document_template_versions' => 'เวอร์ชันแม่แบบ + ไฟล์ PDF ต้นฉบับ',
    ];

    /**
     * จุดเชื่อมของ service กลางที่สารบรรณต้องเรียก แทนการเขียนเอง
     *
     * @var array<class-string,array<int,string>>
     */
    private const CORE_ENTRY_POINTS = [
        PermissionEvaluator::class => ['allows', 'isDenied', 'scopeFor', 'equivalentNames'],
        OrgScopeResolver::class => ['resolve', 'visibleOrgUnitIds'],
        AuditLogger::class => ['log'],
        ModuleRegistry::class => ['all', 'get', 'has', 'isEnabled', 'enabled', 'moduleForPath'],
        NotificationCenter::class => ['emit'],
        WorkflowEngine::class => ['instanceFor', 'currentStep', 'availableTransitions', 'authorize', 'startOnVersion', 'perform'],
        DelegationResolver::class => ['delegatorFor', 'activeDelegationsFor'],
        AssigneeResolverRegistry::class => ['candidatesFor'],
        SecureFileAccessService::class => ['canView'],
        StoredFile::class => ['relative', 'path', 'exists'],
        UploadRules::class => ['attachment', 'documentTemplate'],
        PdfOverlayRenderer::class => ['render'],
    ];

    /**
     * ชื่อตารางที่ถือว่าเป็น "Core ชุดที่สอง" ทันทีที่ปรากฏ
     *
     * ไม่ได้ห้ามด้วยการเดาจากคำที่อยู่ในชื่อ เพราะตารางที่ถูกต้องหลายตัวก็มีคำเหล่านี้
     * (เช่น `saraban_document_user_states` ที่อ้าง user เฉย ๆ ไม่ได้เก็บ user)
     * จึงห้ามเฉพาะชื่อที่แปลว่า "ทะเบียนหลัก" ของ Core ตรง ๆ เท่านั้น
     *
     * @var array<int,string>
     */
    private const FORBIDDEN_MASTER_TABLES = [
        'saraban_users', 'saraban_accounts', 'saraban_sessions', 'saraban_tokens',
        'saraban_password_resets', 'saraban_personnel', 'saraban_employees',
        'saraban_organizations', 'saraban_org_units', 'saraban_departments',
        'saraban_divisions', 'saraban_positions', 'saraban_roles', 'saraban_permissions',
        'saraban_role_permissions', 'saraban_user_roles', 'saraban_menus',
        'saraban_audit_logs', 'saraban_notifications', 'saraban_notification_types',
        'saraban_notification_templates',
    ];

    /**
     * รูปแบบในโค้ดที่แปลว่ากำลังสร้างของกลางซ้ำ หรือ hard-code สิ่งที่ห้าม hard-code
     *
     * @var array<string,string>
     */
    private const FORBIDDEN_CODE_PATTERNS = [
        '/Auth::(attempt|login|loginUsingId)\s*\(/' => 'สารบรรณห้ามทำ authentication เอง — ใช้ session ของระบบกลาง (SoT §4)',
        '/Hash::make\s*\(/' => 'สารบรรณห้ามจัดการรหัสผ่าน — เป็นงานของ Core Identity (SoT §4)',
        '/DB::table\(\s*[\'"]audit_logs[\'"]/' => 'ห้ามเขียน audit ตรง ๆ — ต้องผ่าน App\Services\AuditLogger (SoT §25)',
        '/DB::table\(\s*[\'"](users|personnel|org_units|permissions|roles)[\'"]\s*\)\s*->\s*(insert|update|delete|truncate)/' => 'ห้ามแก้ทะเบียนหลักของ Core จากโมดูลสารบรรณ (SoT §4)',
        '/->hasRole\s*\(/' => 'ห้ามตัดสินสิทธิ์จากชื่อบทบาท — ใช้ Gate/permission ผ่าน PermissionEvaluator (SoT §6)',
        '/\bdepartment_id\b/' => 'ให้อ้าง org_unit_id แทน department_id เพื่อไม่สืบทอดข้อจำกัดหน่วยงานระดับเดียว',
        '/\bMail::(send|to|raw|queue)\s*\(/' => 'ห้ามส่งอีเมลเอง — ต้องผ่าน NotificationCenter (SoT §16)',
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => 'พบอีเมลฝังในโค้ด — ห้าม hard-code ตัวบุคคล (SoT §11)',
        '/(?<![\w>$])(?:now\(\)|Carbon::now\(\)|Carbon::today\(\))/' => 'ห้ามอ่านเวลาจากนาฬิกาเครื่องตรง ๆ — ต้องผ่าน App\Services\TrustedClock (SoT §10)',
    ];

    public function test_core_tables_that_saraban_binds_to_exist(): void
    {
        $missing = [];

        foreach (self::CORE_TABLES as $table => $purpose) {
            if (! Schema::hasTable($table)) {
                $missing[] = "{$table} ({$purpose})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            'ตารางกลางที่โมดูลสารบรรณพึ่งพาหายไป — สัญญา INT-01 ขาด: '.implode(', ', $missing)
        );
    }

    public function test_core_services_expose_the_entry_points_saraban_binds_to(): void
    {
        $broken = [];

        foreach (self::CORE_ENTRY_POINTS as $class => $methods) {
            if (! class_exists($class) && ! interface_exists($class)) {
                $broken[] = "{$class} (ไม่มีคลาสนี้แล้ว)";

                continue;
            }

            foreach ($methods as $method) {
                if (! method_exists($class, $method)) {
                    $broken[] = "{$class}::{$method}()";
                }
            }
        }

        $this->assertSame(
            [],
            $broken,
            'จุดเชื่อมของระบบกลางที่สารบรรณจะเรียกหายไปหรือเปลี่ยนชื่อ: '.implode(', ', $broken)
        );
    }

    /**
     * ระบบต้องมี audit กลางเพียงชุดเดียว
     *
     * โมดูลสารบรรณรุ่นก่อนเคยมี `saraban_audit_logs` ของตัวเอง ทำให้ประวัติของ
     * เอกสารกระจายอยู่สองที่และตอบไม่ได้ว่าที่ไหนคือความจริง
     */
    public function test_saraban_does_not_create_a_second_core_master_table(): void
    {
        $violations = [];

        foreach ($this->sarabanMigrationFiles() as $file) {
            foreach ($this->tablesCreatedIn($file->getContents()) as $table) {
                if (in_array($table, self::FORBIDDEN_MASTER_TABLES, true)) {
                    $violations[] = "{$file->getFilename()} สร้าง `{$table}`";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'พบการสร้างทะเบียนหลักซ้ำกับระบบกลาง — สารบรรณต้องอ้าง id ของ Core เท่านั้น (SoT §4, §5): '
            .implode(' · ', $violations)
        );
    }

    public function test_saraban_code_does_not_reimplement_core_capabilities(): void
    {
        $violations = [];

        foreach ($this->sarabanSourceFiles() as $file) {
            foreach ($this->violationsIn($file->getContents()) as $reason) {
                $violations[] = "{$file->getRelativePathname()}: {$reason}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "พบโค้ดสารบรรณที่สร้างความสามารถของระบบกลางขึ้นมาซ้ำ:\n".implode("\n", $violations)
        );
    }

    /**
     * ด่านที่ตรวจไม่เจออะไรเลยไม่ใช่ด่าน
     *
     * ตอน INT-01 ยังไม่มีไฟล์ของสารบรรณให้สแกน เทสต์นี้จึงป้อนโค้ดตัวอย่างที่ผิดกติกา
     * เข้าไปตรง ๆ เพื่อพิสูจน์ว่าตัวสแกนจับได้จริง ไม่ใช่ผ่านเพราะไม่ได้ทำงาน
     */
    public function test_the_contract_scanner_actually_detects_violations(): void
    {
        $this->assertNotEmpty(
            $this->violationsIn('if (Auth::attempt($credentials)) { return true; }'),
            'ตัวสแกนต้องจับการทำ authentication เองได้'
        );

        $this->assertNotEmpty(
            $this->violationsIn("DB::table('audit_logs')->insert(\$row);"),
            'ตัวสแกนต้องจับการเขียน audit ตรง ๆ ได้'
        );

        $this->assertNotEmpty(
            $this->violationsIn('if ($user->hasRole("admin_saraban")) { return true; }'),
            'ตัวสแกนต้องจับการตัดสินสิทธิ์จากชื่อบทบาทได้'
        );

        $this->assertNotEmpty(
            $this->violationsIn('$query->where("department_id", $user->department_id);'),
            'ตัวสแกนต้องจับการอ้าง department_id ได้'
        );

        $this->assertNotEmpty(
            $this->tablesCreatedIn("Schema::create('saraban_audit_logs', function (Blueprint \$table) {"),
            'ตัวสแกนต้องอ่านชื่อตารางที่ migration สร้างได้'
        );

        $this->assertSame(
            [],
            $this->violationsIn('$documents = SarabanDocument::query()->where("org_unit_id", $unitId)->get();'),
            'โค้ดที่ทำถูกกติกาต้องไม่ถูกจับผิด'
        );

        /*
         * RISK-09 (INT-01) — คำอธิบายว่า "ทำไมถึงห้าม" ต้องไม่ถูกจับว่าเป็นการฝ่าฝืน
         * เกิดขึ้นจริงตอน INT-03 กับคอมเมนต์ใน `Document.php`
         */
        $this->assertSame(
            [],
            $this->violationsIn('// ห้ามใช้ department_id — ให้อ้าง org_unit_id แทน'."\n".'$q->where("org_unit_id", 1);'),
            'คอมเมนต์ที่อธิบายข้อห้ามต้องไม่ถูกนับเป็นการฝ่าฝืน'
        );

        $this->assertSame(
            [],
            $this->violationsIn('/** ติดต่อ somchai@example.go.th หากพบปัญหา */'."\n".'return true;'),
            'อีเมลในคอมเมนต์ไม่ใช่การ hard-code ตัวบุคคลลงในตรรกะ'
        );

        // แต่ของจริงในโค้ดต้องยังถูกจับได้เหมือนเดิม
        $this->assertNotEmpty(
            $this->violationsIn('$q->where("department_id", $id); // ตรงนี้คือโค้ดจริง'),
            'การอ้าง department_id ในโค้ดจริงต้องยังถูกจับได้'
        );

        /*
         * SoT §10 — เวลาที่ผูกผลทางกฎหมายต้องมาจากตะเข็บเดียว
         * มิฉะนั้นจะเปลี่ยนไปใช้แหล่งเวลาที่รับรอง (TSA) ทีหลังไม่ได้
         */
        $this->assertNotEmpty(
            $this->violationsIn('$document->registered_at = now();'),
            'การอ่านเวลาจากนาฬิกาเครื่องตรง ๆ ต้องถูกจับได้'
        );

        $this->assertSame(
            [],
            $this->violationsIn('$document->registered_at = $this->clock->now();'),
            'การอ่านเวลาผ่าน TrustedClock ต้องผ่าน'
        );
    }

    /**
     * ชื่อตารางทั้งหมดที่ migration นี้สร้าง
     *
     * @return array<int,string>
     */
    private function tablesCreatedIn(string $contents): array
    {
        preg_match_all('/Schema::create\(\s*[\'"]([a-z0-9_]+)[\'"]/', $contents, $matches);

        return $matches[1] ?? [];
    }

    /**
     * เหตุผลของทุกข้อที่โค้ดชิ้นนี้ผิดกติกา
     *
     * @return array<int,string>
     */
    private function violationsIn(string $contents): array
    {
        $code = $this->withoutComments($contents);
        $reasons = [];

        foreach (self::FORBIDDEN_CODE_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $code) === 1) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * ตัดคอมเมนต์ออกก่อนตรวจ — ด่านนี้ตรวจ "โค้ด" ไม่ใช่ "คำอธิบาย"
     *
     * INT-03 เจอกรณีนี้จริง: คอมเมนต์ใน `Document.php` ที่เขียนอธิบายว่า
     * *ห้าม* ใช้ `department_id` ถูกด่านจับว่าใช้ `department_id` เสียเอง
     * ซึ่งเป็นความเสี่ยง RISK-09 ที่บันทึกไว้ตั้งแต่ INT-01 ว่าอาจ "จับผิดคนบริสุทธิ์"
     *
     * ทางแก้ที่ถูกคือทำให้ด่านฉลาดขึ้น ไม่ใช่ลบคำอธิบายที่มีประโยชน์ออกจากโค้ด
     * เพราะคำอธิบายว่าทำไมถึงห้าม คือสิ่งที่กันไม่ให้คนรุ่นถัดไปทำผิดซ้ำ
     */
    private function withoutComments(string $contents): string
    {
        $source = str_starts_with(ltrim($contents), '<?php') ? $contents : "<?php\n".$contents;
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                // แทนคอมเมนต์ด้วยช่องว่าง เพื่อไม่ให้โค้ดสองฝั่งเชื่อมติดกันจนเกิดคำใหม่
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * migration ของโมดูลสารบรรณ — ไม่รวม migration ที่ถอดโมดูลรุ่นเก่าออก
     * ซึ่งอ้างชื่อตารางเก่าเพื่อ "ลบ" ไม่ใช่เพื่อ "สร้าง"
     *
     * @return iterable<SplFileInfo>
     */
    private function sarabanMigrationFiles(): iterable
    {
        $path = database_path('migrations');

        if (! is_dir($path)) {
            return [];
        }

        return Finder::create()
            ->files()
            ->in($path)
            ->name('*saraban*.php')
            ->notName('*drop_saraban_module*');
    }

    /**
     * ซอร์สทั้งหมดของโมดูลสารบรรณ ไม่ว่าจะวางไว้ที่ใดใต้ app/
     *
     * @return iterable<SplFileInfo>
     */
    private function sarabanSourceFiles(): iterable
    {
        $paths = array_filter([
            app_path('Services/Saraban'),
            app_path('Models/Saraban'),
            app_path('Http/Controllers/Saraban'),
            app_path('Http/Requests/Saraban'),
            app_path('Http/Resources/Saraban'),
            app_path('Policies/Saraban'),
            app_path('Jobs/Saraban'),
        ], 'is_dir');

        if ($paths === []) {
            return [];
        }

        return Finder::create()->files()->in($paths)->name('*.php');
    }
}
