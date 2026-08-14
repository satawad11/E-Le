<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SarabanPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-9 — เทสต์ที่ป้องกันความผิดพลาดที่เกิดขึ้นจริงในเฟสนี้
 *
 * บทเรียนจาก LEGACY-02B: ผมใส่ `can:attendance.view_all` ลงใน route
 * โดยไม่รู้ว่า permission ชื่อนั้น **ไม่มีอยู่ในฐานข้อมูล** (มีแต่ชื่อรุ่นเก่า
 * `view_all_attendances`) ผลคือ endpoint กลายเป็น Super Admin เท่านั้น
 * และ ผอ./รอง ผอ./HR ใช้ไม่ได้
 *
 * เทสต์เดิมจับไม่ได้เพราะสร้าง permission เองด้วย findOrCreate จึงไม่สะท้อน
 * ฐานข้อมูลจริง เทสต์ชุดนี้เทียบกับ **ชุดที่ seeder สร้างจริง** แทน
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ใช้ชุดสิทธิ์ชุดเดียวกับที่ระบบจริงติดตั้ง
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SarabanPermissionSeeder::class);
    }

    /**
     * ดึงชื่อ permission ทุกตัวที่ถูกบังคับใช้จริงบน route
     *
     * @return array<int,string>
     */
    private function enforcedPermissionNames(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                // รูปแบบ can:x และ permission:x|y
                if (preg_match('/^(can|permission):(.+)$/', $middleware, $matches) !== 1) {
                    continue;
                }

                foreach (explode('|', $matches[2]) as $name) {
                    $name = trim($name);

                    // ข้ามชื่อ ability ของ Policy (เช่น can:view,leave) ที่ไม่ใช่ permission
                    if ($name !== '' && ! str_contains($name, ',')) {
                        $names[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * ช่องว่างที่ค้นพบแล้วและยังรอการตัดสินใจของเจ้าของระบบ
     *
     * **รายการนี้ว่างแล้ว** — permission ทั้ง 7 ตัวที่เคยอยู่ในนี้ถูกสร้างและแจก
     * ตามหน้าที่จริงเมื่อ 2026-08-14 หลังได้รับอนุมัติ (Decision 1 / Decision 2)
     * ดู `OperationalPermissionSeeder` และ `OperationalPermissionMatrixTest`
     *
     * ถ้าจะเพิ่มชื่อกลับเข้ามาอีก ต้องระบุเหตุผลกำกับเสมอ เพราะทุกชื่อในรายการนี้
     * หมายถึง endpoint ที่ใช้ได้เฉพาะ Super Admin
     *
     * @var array<int,string>
     */
    private const KNOWN_MISSING_PENDING_DECISION = [];

    public function test_every_permission_enforced_on_a_route_exists_in_the_catalogue(): void
    {
        $catalogue = Permission::pluck('name')->all();
        $enforced = $this->enforcedPermissionNames();

        $this->assertNotEmpty($enforced, 'ต้องมี route ที่บังคับ permission อย่างน้อยหนึ่งเส้น');

        $missing = array_values(array_diff($enforced, $catalogue, self::KNOWN_MISSING_PENDING_DECISION));

        $this->assertSame(
            [],
            $missing,
            "พบ permission ที่ route บังคับใช้แต่ไม่มีในชุดที่ seeder สร้าง:\n  ".implode("\n  ", $missing).
            "\n\nendpoint เหล่านี้จะใช้ได้แค่ Super Admin เท่านั้น\n".
            'ถ้าตั้งใจให้เป็นแบบนั้นจริง ให้เพิ่มชื่อลง KNOWN_MISSING_PENDING_DECISION พร้อมเหตุผล',
        );
    }

    /**
     * ช่องว่างที่รู้แล้วต้องไม่หายไปเงียบ ๆ — ถ้ามีใครเติม permission ให้ครบแล้ว
     * ต้องถอดชื่อออกจากรายการ เพื่อให้รายการสะท้อนความจริงเสมอ
     */
    public function test_the_known_gap_list_does_not_contain_stale_entries(): void
    {
        $catalogue = Permission::pluck('name')->all();

        $alreadyFixed = array_values(array_intersect(self::KNOWN_MISSING_PENDING_DECISION, $catalogue));

        $this->assertSame(
            [],
            $alreadyFixed,
            "permission เหล่านี้ถูกเพิ่มเข้าระบบแล้ว ให้ถอดออกจาก KNOWN_MISSING_PENDING_DECISION:\n  ".
            implode("\n  ", $alreadyFixed),
        );
    }

    /**
     * ชื่อ role ที่โค้ดอ้างถึง ต้องมีอยู่จริง
     *
     * บทเรียนจาก 04-4: โค้ดเช็ค `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย`, `admin_saraban`,
     * `super_admin` ฯลฯ ซึ่งไม่มีอยู่จริง ทำให้ตรรกะทั้งก้อนไม่เคยทำงาน
     */
    public function test_role_names_still_referenced_in_code_actually_exist(): void
    {
        $referenced = ['Super Admin'];   // จุดเดียวที่ยังคงไว้โดยตั้งใจ (bootstrap identity)
        $existing = Role::pluck('name')->all();

        foreach ($referenced as $role) {
            $this->assertContains(
                $role,
                $existing,
                "โค้ดยังอ้างถึง role `{$role}` แต่ไม่มีอยู่จริง — ตรรกะที่ผูกกับชื่อนี้จะไม่ทำงาน",
            );
        }
    }

    /**
     * `hasRole()` / `hasAnyRole()` ต้องเหลือเฉพาะ Super Admin bootstrap ที่ประกาศไว้
     *
     * LEGACY-04 (ปิดงาน) — เกณฑ์ข้อสุดท้ายของเฟสนี้ เดิมตรวจด้วยมือด้วย grep
     * ซึ่งเชื่อถือไม่ได้เมื่อมีคนเพิ่มโค้ดใหม่ เทสต์ตัวนี้ตรวจให้อัตโนมัติ
     *
     * สามจุดที่คงไว้เป็น bootstrap identity ของผู้ดูแลระบบสูงสุด — ถ้าผูกกับ
     * permission จะเกิดปัญหาไก่กับไข่ตอนตั้งค่าระบบครั้งแรก
     */
    public function test_role_name_checks_survive_only_at_the_declared_bootstrap_points(): void
    {
        $declared = [
            'app/Services/PermissionEvaluator.php',
            'app/Providers/AppServiceProvider.php',
        ];

        $found = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($directory as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match('/->has(Any)?Role\s*\(/', $contents) !== 1) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if (! in_array($relative, $declared, true)) {
                $found[] = $relative;
            }
        }

        sort($found);

        $this->assertSame(
            [],
            $found,
            "พบการตัดสินสิทธิ์จากชื่อ role นอกจุดที่ประกาศไว้:\n  ".implode("\n  ", $found).
            "\n\nชื่อ role แก้ได้จากหน้าจอ จึงใช้เป็นตัวตัดสินสิทธิ์ไม่ได้ — ให้ใช้ permission แทน",
        );
    }

    /**
     * Decision 3 — ห้ามมี role ที่ตั้งขึ้นเพื่อใช้เป็น business logic ของตำแหน่งหัวหน้า
     *
     * ตำแหน่งหัวหน้าแผนก/หัวหน้างาน/หัวหน้าฝ่าย/ผู้รักษาการ เป็น **การมอบหมาย**
     * ใน `org_unit_managers` ไม่ใช่ชื่อบทบาท
     */
    public function test_the_install_does_not_create_a_department_head_role(): void
    {
        $forbidden = [
            'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
            'Head of Department',
            'admin_saraban',
            'Saraban Officer',
            'เจ้าหน้าที่สารบัญ',
        ];

        $existing = Role::pluck('name')->all();

        foreach ($forbidden as $name) {
            $this->assertNotContains(
                $name,
                $existing,
                "seeder ยังสร้าง role `{$name}` ซึ่งไม่ควรมี — ตำแหน่งหัวหน้าใช้การมอบหมายใน org_unit_managers",
            );
        }
    }

    /**
     * alias ที่ 04-1 สร้างไว้ ต้องชี้ไปยัง permission ที่มีอยู่จริงทั้งสองฝั่ง
     */
    public function test_permission_aliases_point_at_real_permissions(): void
    {
        $catalogue = Permission::pluck('name')->all();

        foreach (DB::table('permission_aliases')->get() as $alias) {
            $this->assertContains(
                $alias->canonical_name,
                $catalogue,
                "alias ชี้ไปยัง `{$alias->canonical_name}` ซึ่งไม่มีอยู่ในชุด permission",
            );
        }
    }
}
