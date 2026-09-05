<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * LEGACY-04 (ปิดงาน) — ชุดสิทธิ์ที่ระบบบังคับใช้จริง พร้อมการแจกตามหน้าที่
 *
 * `LEGACY-04-REPORT.md` พบว่า route บังคับ permission 7 ตัวที่ **ไม่มีอยู่ใน
 * ฐานข้อมูลเลย** ผลคือ endpoint เหล่านั้นใช้ได้เฉพาะ Super Admin
 * และที่ร้ายที่สุดคือบุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้
 *
 * เจ้าของระบบอนุมัติให้เติมเมื่อ 2026-08-14 (Decision 1 / Decision 2)
 *
 * ไฟล์นี้เป็น **แหล่งความจริงเดียว** ของการแจกสิทธิ์ชุดนี้
 * ถูกเรียกจากสองทาง:
 *   1. migration `2026_08_14_100000_seed_operational_permissions` (ระบบที่ติดตั้งแล้ว)
 *   2. `RolesAndPermissionsSeeder` (ติดตั้งใหม่ และชุดทดสอบ)
 *
 * idempotent — รันซ้ำได้ และ **ไม่ถอนสิทธิ์ของใคร** (ใช้ givePermissionTo ไม่ใช่ sync)
 */
class OperationalPermissionSeeder extends Seeder
{
    /**
     * ชื่อ permission => คำอธิบายภาษาไทย
     *
     * ทุกชื่อเป็น canonical ที่โค้ดบังคับใช้อยู่แล้ว
     *
     * @var array<string,string>
     */
    public const PERMISSIONS = [
        // Decision 1 — 7 ตัวที่ route บังคับใช้แต่ไม่เคยถูกสร้าง
        'attendance.checkin' => 'บันทึกเวลาเข้า-ออกงานของตนเอง',
        'attendance.view_self' => 'ดูประวัติการลงเวลาของตนเอง',
        'attendance.ot' => 'บันทึกเวลาทำงานล่วงเวลา (OT)',
        'attendance.ot_summary' => 'ดูสรุปการทำงานล่วงเวลาของทั้งองค์กร',
        'system.menu.manage' => 'จัดการเมนูและบทบาทของระบบ',
        'admin.users.manage_permissions' => 'กำหนดสิทธิ์รายบุคคล',

        // สายคำสั่ง OT (เจ้าของระบบกำหนด 2026-08-19)
        'overtime.operation.execute' => 'ออกคำสั่งปฏิบัติงานล่วงเวลาและลงเวลา OT',
        'overtime.management.review' => 'อนุญาต/อนุมัติคำสั่ง OT มอบหมายผู้ปฏิบัติงาน และรับรองเวลา',
    ];

    /**
     * role => permission ที่ได้รับเพิ่ม (Principle of Least Privilege)
     *
     * role ที่ไม่ปรากฏในตารางนี้ไม่ได้อะไรเพิ่มเลย
     * สิทธิ์ที่ไม่ปรากฏกับ role ใดเลย = Super Admin เท่านั้น และผู้ดูแลมอบหมาย
     * รายบุคคลเพิ่มได้ผ่านหน้าจัดการสิทธิ์
     *
     * @var array<string,array<int,string>>
     */
    public const GRANTS = [
        /*
         * บุคลากรทั่วไป — ได้เฉพาะสิทธิ์ลงเวลาของ "ตนเอง"
         * ไม่มีสิทธิ์ใดที่ทำให้เห็นการลงเวลาของคนอื่น
         */
        'Employee' => [
            'attendance.checkin',
            'attendance.view_self',
        ],

        /*
         * ผู้บริหารและ HR ก็ต้องลงเวลาเช่นกัน
         * `attendance.ot_summary` ให้เพราะทั้งสามคนถือ `attendance.view_all`
         * อยู่แล้ว จึงเห็นข้อมูลการลงเวลาทั้งองค์กรได้ตั้งแต่ต้น — สรุป OT
         * ไม่ใช่ข้อมูลชั้นใหม่สำหรับกลุ่มนี้
         */
        /*
         * สายคำสั่ง OT ที่เจ้าของระบบกำหนดเมื่อ 2026-08-19:
         *   หัวหน้าบุคลากร ตั้งคำสั่ง → รอง ผอ. ฝ่ายทรัพยากร อนุญาต → ผอ. อนุมัติ
         *
         * ทั้งสองตำแหน่งเป็นผู้พิจารณาในสายเดียวกันจึงได้
         * `overtime.management.review` เท่ากัน ส่วนการแยกว่าใครทำขั้นไหน
         * บังคับด้วย workflow step ที่ผูกกับ role ไม่ใช่ด้วยสิทธิ์
         * (ดู OvertimeOrderWorkflowProvisioner::CHAIN)
         */
        'Director' => [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot_summary',
            'overtime.management.review',
        ],

        'Deputy Director' => [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot_summary',
            'overtime.management.review',
        ],

        /*
         * HR เป็นผู้ดูแล OT ตามหน้าที่จริง จึงได้ทั้งบันทึกและดูสรุป
         *
         * `overtime.operation.execute` = ผู้ตั้งคำสั่ง OT ตามที่เจ้าของระบบ
         * กำหนดเมื่อ 2026-08-19 — ตั้งได้อย่างเดียว ไม่ได้สิทธิ์อนุมัติของตนเอง
         */
        'HR Head' => [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot',
            'attendance.ot_summary',
            'overtime.operation.execute',
            /*
             * งานบุคลากร — ตรวจสอบสถิติการลาก่อนเสนออนุมัติต่อ
             *
             * ปุ่ม "ตรวจสอบสถิติการลา" ต้องการ `leave.approval.hr` แต่ไม่เคยมีบทบาทใด
             * ได้รับสิทธิ์นี้เลยนอกจาก Super Admin หัวหน้าบุคลากรซึ่งเป็นผู้ทำหน้าที่นี้จริง
             * และเป็น role ที่ผูกกับขั้นตอน "ตรวจสอบโดยหัวหน้าบุคลากร" ในสายอนุมัติ
             * จึงกดปุ่มของตัวเองไม่ได้ · ที่ผ่านมาไม่มีใครสังเกตเพราะบัญชีที่ใช้ทดสอบ
             * ถูกมอบบทบาท Super Admin ไว้ ซึ่งผ่าน Gate::before ทุกกรณีอยู่แล้ว
             */
            'leave.approval.hr',
        ],

        'Document Officer' => [
            'attendance.checkin',
            'attendance.view_self',
        ],
    ];

    /**
     * role ที่ถือทุก permission ในระบบตามการออกแบบเดิม
     *
     * @var array<int,string>
     */
    public const SUPER_ADMIN_ROLES = ['Super Admin', 'SuperAdmin'];

    /**
     * INT-22 (RISK-34) — สิทธิ์เปิดกล่องหนังสือเวียนถึงตนเอง
     *
     * **หนังสือราชการเวียนถึงข้าราชการทุกคน** ไม่ใช่เฉพาะงานสารบรรณ ·
     * ถ้าต้องรอผู้ดูแลมามอบหมายให้ทีละบทบาทหลังติดตั้ง หนังสือที่เวียนไป
     * ในสัปดาห์แรกจะไม่มีใครเปิดเจอ และไม่มีอะไรบอกว่าทำไม
     *
     * `saraban.inbox.view` เป็นสิทธิ์ **อ่านของตัวเองเท่านั้น** — ขอบเขตจริง
     * คือการเป็นผู้รับ ซึ่ง `InboxController` บังคับกับทุก query · การให้สิทธิ์นี้
     * กับทุกบทบาทจึงไม่เปิดเผยหนังสือของใครเพิ่มแม้แต่ฉบับเดียว
     *
     * **ต่างจาก `saraban.document.view`** ซึ่งเป็นสิทธิ์ค้นทะเบียนหนังสือของ
     * หน่วยงาน และยังคงไม่ถูกแจกให้ใครโดยอัตโนมัติ
     *
     * @var array<int,string>
     */
    public const SARABAN_INBOX_ROLES = [
        'Employee',
        'HR Head',
        'Deputy Director',
        'Director',
        'Document Officer',
    ];

    /**
     * ผู้ได้รับอนุญาตเข้าถึงชั้นความลับตั้งต้น ตามลำดับบังคับบัญชาของสถานศึกษา
     * (ระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544)
     *
     * **ระเบียบไม่ได้กำหนดว่าตำแหน่งใดได้รับอนุญาตชั้นใด** — เป็นอำนาจของ
     * หัวหน้าหน่วยงานตามหมวด 2 · ค่าตั้งต้นนี้จึงยึดตามลำดับบังคับบัญชาซึ่ง
     * เป็นแนวปฏิบัติทั่วไป และ **ผู้อำนวยการแก้ได้ทุกเมื่อ** จากหน้าจอจัดการสิทธิ์
     *
     * | บทบาท | ชั้นที่ได้รับอนุญาต | เหตุผล |
     * |---|---|---|
     * | ผู้อำนวยการ | ลับที่สุด | หัวหน้าสถานศึกษา · ผู้รับผิดชอบสูงสุดของหน่วยงาน |
     * | รองผู้อำนวยการ | ลับมาก | ผู้ปฏิบัติราชการแทนในสายงานที่รับผิดชอบ |
     * | หัวหน้าบุคลากร | ลับ | เรื่องบุคคล วินัย และการประเมิน เป็นชั้นลับตามปกติ |
     * | เจ้าหน้าที่ธุรการ | ลับ | ต้องลงทะเบียนและเดินเรื่องหนังสือลับที่เข้ามาในระบบ |
     * | ข้าราชการทั่วไป | — | เข้าถึงได้เฉพาะเรื่องที่ตนเกี่ยวข้อง ตามหลักความจำเป็นต้องรู้ |
     *
     * **ข้าราชการทั่วไปไม่ได้ถูกตัดออกจากหนังสือลับ** — ผู้ที่อยู่ในสายงานเดินเรื่อง
     * เป็นผู้รับหนังสือ หรือเป็นเจ้าของเรื่อง เข้าถึงเรื่องนั้นได้เสมอโดยไม่ต้องมี
     * สิทธิ์ชั้นความลับ (`workflow_participant_bypass` · `owner_and_recipient_bypass`)
     * สิทธิ์ในตารางนี้ตอบคำถามคนละข้อ คือ "ค้นเจอหนังสือลับของหน่วยงานได้ไหม"
     *
     * @var array<string,string>
     */
    /**
     * วงจรการเพิกถอนลายเซ็น — **ผู้ขอกับผู้ตัดสินต้องเป็นคนละบทบาท** (SoT §26)
     *
     * ค่าตั้งต้นตามการปฏิบัติจริง: เจ้าหน้าที่ธุรการเป็นผู้พบความผิดพลาดของ
     * หนังสือที่ออกไปแล้วและยื่นเรื่อง · ผู้อำนวยการซึ่งเป็นผู้ลงนามเป็นผู้ตัดสิน
     *
     * การจับคู่แบบนี้ทำให้แยกหน้าที่ได้จริงตั้งแต่วันแรก โดยไม่ต้องรอผู้ดูแล
     * มาตั้งค่า · **ปรับได้จากหน้าจอจัดการสิทธิ์** ตามโครงสร้างจริงของหน่วยงาน
     *
     * @var array<string,string>
     */
    public const SARABAN_REVOCATION = [
        'Document Officer' => 'saraban.signature.revoke',
        'Director' => 'saraban.signature.revoke_approve',
    ];

    public const SARABAN_CLEARANCE = [
        'Director' => 'saraban.top_secret.view',
        'Deputy Director' => 'saraban.secret.view',
        'HR Head' => 'saraban.confidential.view',
        'Document Officer' => 'saraban.confidential.view',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $now = now();
        $guard = 'web';
        $permissionIds = [];

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

            $permissionIds[$name] = $id ?? DB::table('permissions')->insertGetId([
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // สิทธิ์ที่ seeder นี้เป็นเจ้าของ — Super Admin ได้ครบชุดนี้ ไม่ใช่ทุกชื่อที่บังเอิญถูกหาระหว่างทาง
        $ownedIds = $permissionIds;

        foreach (self::GRANTS as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

            // ไม่สร้าง role ใหม่จากที่นี่ — ติดตั้งที่ไม่มี role นี้ให้ข้ามไป
            if ($roleId === null) {
                continue;
            }

            foreach ($names as $name) {
                $this->attach($this->permissionId($name, $guard, $permissionIds), $roleId);
            }
        }

        /*
         * RISK-34 — แจกสิทธิ์เปิดกล่องหนังสือเวียนให้ทุกบทบาทที่คนถือได้
         *
         * แยกออกจาก `GRANTS` เพราะเป็นสิทธิ์ที่ **ทุกบทบาทได้เหมือนกันหมด**
         * การเขียนซ้ำในทุกบล็อกของ GRANTS จะทำให้คนอ่านคิดว่ามีเหตุผล
         * ต่างกันในแต่ละบทบาท ทั้งที่เหตุผลเดียวกันคือ "ทุกคนได้รับหนังสือเวียน"
         */
        foreach (self::SARABAN_INBOX_ROLES as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

            if ($roleId === null) {
                continue;
            }

            $this->attach($this->permissionId('saraban.inbox.view', $guard, $permissionIds), $roleId);
        }

        /*
         * ผู้ได้รับอนุญาตเข้าถึงชั้นความลับ
         * (ระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544)
         *
         * แจกทีละหนึ่งข้อ **ไม่ใช่แจกทุกชั้นที่ต่ำกว่าซ้ำ** เพราะผู้ได้รับอนุญาต
         * ชั้นสูงกว่าเข้าถึงชั้นต่ำกว่าได้อยู่แล้วโดยตัวนโยบายเอง (ระเบียบฯ ข้อ 26)
         * การแจกซ้ำจะทำให้การถอนสิทธิ์ในอนาคตทำได้ไม่หมดและตรวจสอบยาก
         */
        /*
         * รวมสองตารางเป็น **คู่ (บทบาท, สิทธิ์)** ไม่ใช่การรวม array ตามคีย์
         *
         * ทั้งสองตารางใช้ชื่อบทบาทเป็นคีย์ และมีบทบาทซ้ำกัน (Director กับ
         * Document Officer อยู่ในทั้งคู่) · การรวมด้วย spread จะทำให้สิทธิ์ของ
         * ตารางแรกหายไปเงียบ ๆ ซึ่งเป็นข้อผิดพลาดที่ไม่มีอะไรฟ้อง
         */
        $roleGrants = [];

        foreach ([self::SARABAN_CLEARANCE, self::SARABAN_REVOCATION] as $table) {
            foreach ($table as $roleName => $permission) {
                $roleGrants[] = [$roleName, $permission];
            }
        }

        foreach ($roleGrants as [$roleName, $permission]) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

            if ($roleId === null) {
                continue;
            }

            $this->attach($this->permissionId($permission, $guard, $permissionIds), $roleId);
        }

        foreach (self::SUPER_ADMIN_ROLES as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($ownedIds as $permissionId) {
                $this->attach($permissionId, $roleId);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * หา id ของ permission ตามชื่อ — รองรับชื่อที่ seeder อื่นเป็นเจ้าของ
     *
     * `GRANTS` อ้างชื่อจากสารบัญกลาง (`PermissionCatalogSeeder`) ได้ด้วย ไม่ใช่เฉพาะ
     * ชื่อที่ seeder นี้สร้างเอง จึงต้องหาแบบ lazy แทนการอ่านจากตารางที่เตรียมไว้ล่วงหน้า
     * มิฉะนั้นการมอบสิทธิ์ตามหน้าที่จะทำได้เฉพาะกับสิทธิ์ชุดของตัวเอง
     *
     * @param  array<string,int>  $known  ตารางที่เตรียมไว้แล้ว — เติมผลลัพธ์กลับเข้าไปเพื่อไม่ต้องหาซ้ำ
     */
    private function permissionId(string $name, string $guard, array &$known): int
    {
        if (isset($known[$name])) {
            return $known[$name];
        }

        $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

        return $known[$name] = $id ?? DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => $guard,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attach(int $permissionId, int $roleId): void
    {
        $exists = DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->exists();

        if (! $exists) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }
}
