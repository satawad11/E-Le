<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SarabanPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
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
     * ทุก permission ที่ route บังคับใช้ ต้องมีอยู่จริงในชุดที่ seeder สร้าง
     *
     * ถ้าข้อนี้ล้ม แปลว่ามี endpoint ที่ไม่มีใครเข้าถึงได้เลยนอกจาก Super Admin
     * ซึ่งคือ regression แบบเดียวกับที่เกิดใน LEGACY-02B
     */
    /**
     * ช่องว่างที่ค้นพบแล้วและยังรอการตัดสินใจของเจ้าของระบบ
     *
     * ทุกตัวในรายการนี้ทำให้ endpoint ที่เกี่ยวข้อง **ใช้ได้เฉพาะ Super Admin**
     * เพราะ permission ไม่มีอยู่จริงในฐานข้อมูล — เป็นปัญหาที่มีมาก่อน LEGACY-04
     * ไม่ใช่ผลจากการ refactor
     *
     * ที่ร้ายแรงที่สุดคือสองตัวแรก: ทำให้ **บุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้เลย**
     *
     * การเติม permission เหล่านี้เป็นการ "ให้สิทธิ์ใหม่" จึงต้องได้รับอนุมัติก่อน
     * — ห้ามลบชื่อออกจากรายการนี้จนกว่าจะแก้จริง
     *
     * @var array<int,string>
     */
    private const KNOWN_MISSING_PENDING_DECISION = [
        'attendance.checkin',
        'attendance.view_self',
        'attendance.ot',
        'attendance.ot_summary',
        'system.menu.manage',
        'admin.users.manage_permissions',
        'saraban.setting.manage',
    ];

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
        $existing = \Spatie\Permission\Models\Role::pluck('name')->all();

        foreach ($referenced as $role) {
            $this->assertContains(
                $role,
                $existing,
                "โค้ดยังอ้างถึง role `{$role}` แต่ไม่มีอยู่จริง — ตรรกะที่ผูกกับชื่อนี้จะไม่ทำงาน",
            );
        }
    }

    /**
     * alias ที่ 04-1 สร้างไว้ ต้องชี้ไปยัง permission ที่มีอยู่จริงทั้งสองฝั่ง
     */
    public function test_permission_aliases_point_at_real_permissions(): void
    {
        $catalogue = Permission::pluck('name')->all();

        foreach (\Illuminate\Support\Facades\DB::table('permission_aliases')->get() as $alias) {
            $this->assertContains(
                $alias->canonical_name,
                $catalogue,
                "alias ชี้ไปยัง `{$alias->canonical_name}` ซึ่งไม่มีอยู่ในชุด permission",
            );
        }
    }
}
