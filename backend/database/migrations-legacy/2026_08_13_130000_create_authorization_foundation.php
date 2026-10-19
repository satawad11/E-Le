<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-04 — รากฐาน RBAC + ABAC (additive ทั้งหมด)
 *
 * ปัญหาที่แก้:
 *
 * 1. CORE-AUTHZ-002 — ชื่อ permission มี 3 รุ่นปนกัน และที่ร้ายกว่านั้นคือ
 *    ฐานข้อมูลจริงมีแต่ชื่อรุ่นเก่า (`view_all_attendances`) ขณะที่โค้ดบังคับใช้
 *    ชื่อ canonical (`attendance.view_all`) ซึ่งไม่มีอยู่จริง ทำให้ทุกคนยกเว้น
 *    Super Admin ถูกปฏิเสธโดยไม่ตั้งใจ
 *
 * 2. CORE-AUTHZ-004 — Spatie ให้ได้แต่ allow ไม่มี explicit deny และไม่มี scope
 *
 * แนวทาง: **เพิ่มอย่างเดียว ไม่มีใครเสียสิทธิ์**
 * - สร้าง permission canonical เป็นแถวจริง
 * - role ที่ถือชื่อเก่าอยู่แล้ว ได้ชื่อ canonical ที่เทียบเท่าเพิ่มให้
 * - ชื่อเก่าคงไว้ทั้งหมดเพื่อ backward compatibility
 * - บันทึกการ map ไว้ใน `permission_aliases` เพื่อให้เลิกใช้ชื่อเก่าได้ในอนาคต
 */
return new class extends Migration
{
    /**
     * ชื่อเก่า => ชื่อ canonical
     *
     * map เฉพาะกรณีที่ชื่อเก่า "สื่อความหมายเดียวกัน" อย่างชัดเจนเท่านั้น
     * ได้รับการยืนยันจากเจ้าของระบบเมื่อ 2026-08-13
     *
     * หมายเหตุ: จงใจ **ไม่** map อะไรไปเป็น `attendance.ot_summary`
     * เพราะเดิมไม่มี role ใดถือสิทธิ์นั้นอยู่แล้ว (เป็น Super Admin เท่านั้น)
     * การ map เพิ่มจะเป็นการ "ให้สิทธิ์ใหม่" ซึ่งขัดกับหลักการของเฟสนี้
     */
    private const ALIASES = [
        'view_all_attendances' => 'attendance.view_all',
        'approve_leave' => 'leave.approval.dept',
        'export_reports' => 'leave.report.view',
        'edit_users' => 'system.user.manage',
        'view_users' => 'system.user.view',
        'manage_settings' => 'system.setting.manage',
    ];

    public function up(): void
    {
        Schema::create('permission_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_name')->unique();
            $table->string('canonical_name')->index();
            $table->timestamps();
        });

        /*
         * Explicit deny — ชนะทุก allow รวมถึง Super Admin
         * ใช้สำหรับกรณีที่ต้องตัดสิทธิ์เฉพาะรายโดยไม่แตะ role
         */
        Schema::create('permission_denies', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('permission_name');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['model_type', 'model_id', 'permission_name'], 'permission_denies_unique');
            $table->index(['model_type', 'model_id']);
        });

        /*
         * ขอบเขตของสิทธิ์ที่ได้รับ — ผูกกับ org_units ที่สร้างใน LEGACY-03
         *
         * ไม่มีแถว = ไม่จำกัดขอบเขต (พฤติกรรมเดิม) จึงปลอดภัยที่จะเพิ่มตารางนี้
         * โดยยังไม่ใส่ข้อมูล
         */
        Schema::create('permission_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('permission_name');
            $table->enum('scope', ['SELF', 'UNIT', 'DESCENDANTS', 'ORGANIZATION', 'ASSIGNED', 'CUSTOM'])
                ->default('ORGANIZATION');
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->timestamps();
            $table->unique(['model_type', 'model_id', 'permission_name'], 'permission_scopes_unique');
            $table->index(['model_type', 'model_id']);
        });

        $now = now();
        $guard = 'web';

        foreach (self::ALIASES as $legacy => $canonical) {
            DB::table('permission_aliases')->insert([
                'legacy_name' => $legacy,
                'canonical_name' => $canonical,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $canonicalId = DB::table('permissions')->where('name', $canonical)->where('guard_name', $guard)->value('id');

            if ($canonicalId === null) {
                $canonicalId = DB::table('permissions')->insertGetId([
                    'name' => $canonical,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $legacyId = DB::table('permissions')->where('name', $legacy)->where('guard_name', $guard)->value('id');

            if ($legacyId === null) {
                continue;
            }

            // ทุก role ที่ถือชื่อเก่า ให้ได้ชื่อ canonical เพิ่ม (ไม่ถอนของเดิม)
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $legacyId)->pluck('role_id');

            foreach ($roleIds as $roleId) {
                $exists = DB::table('role_has_permissions')
                    ->where('permission_id', $canonicalId)
                    ->where('role_id', $roleId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $canonicalId,
                        'role_id' => $roleId,
                    ]);
                }
            }

            // สิทธิ์ที่ให้ผู้ใช้โดยตรงก็ต้องตามไปด้วย
            $direct = DB::table('model_has_permissions')->where('permission_id', $legacyId)->get();

            foreach ($direct as $row) {
                $exists = DB::table('model_has_permissions')
                    ->where('permission_id', $canonicalId)
                    ->where('model_type', $row->model_type)
                    ->where('model_id', $row->model_id)
                    ->exists();

                if (! $exists) {
                    DB::table('model_has_permissions')->insert([
                        'permission_id' => $canonicalId,
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                    ]);
                }
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_scopes');
        Schema::dropIfExists('permission_denies');
        Schema::dropIfExists('permission_aliases');

        // จงใจไม่ถอน permission canonical ที่ให้ role ไปแล้ว
        // การถอนจะทำให้ผู้ใช้เสียสิทธิ์ที่ควรมีตั้งแต่แรก
        // ถ้าต้องการย้อนจริงต้องทำด้วยมือพร้อมตรวจสอบผลกระทบ
    }
};
