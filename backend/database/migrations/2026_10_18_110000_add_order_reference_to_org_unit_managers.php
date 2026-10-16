<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ACCESS-GOV-01 — ทะเบียนหัวหน้าหน่วยงานอ้างคำสั่งแต่งตั้ง
 *
 * ระเบียบ สอศ. ว่าด้วยการบริหารสถานศึกษา พ.ศ. 2569 ข้อ 13 และข้อ 47: ผู้อำนวยการแต่งตั้ง/มอบหมาย
 * รองฯ หัวหน้างาน หัวหน้าแผนกวิชา แล้วรายงาน สอศ. — การเป็นหัวหน้าทุกรายการจึงต้องอ้างเลขที่และวันที่คำสั่ง
 * และต้องตรวจย้อนหลังได้ว่าใครปิดวาระ
 *
 * additive เท่านั้น · สิทธิ์ใหม่ `system.org_structure.manage` มอบให้ Super Admin (เจ้าของระบบตอบ Q5: ผู้ดูแลระบบคงเดิม)
 * เพราะการตั้งหัวหน้าหน่วยงานคือการกำหนดว่าใครอนุมัติใบลาของใคร
 */
return new class extends Migration
{
    private const PERMISSION = 'system.org_structure.manage';

    private const ROLES = ['Super Admin', 'SuperAdmin'];

    public function up(): void
    {
        Schema::table('org_unit_managers', function (Blueprint $table) {
            if (! Schema::hasColumn('org_unit_managers', 'order_number')) {
                $table->string('order_number', 100)->nullable()->after('scope');
            }
            if (! Schema::hasColumn('org_unit_managers', 'order_date')) {
                $table->date('order_date')->nullable()->after('order_number');
            }
            if (! Schema::hasColumn('org_unit_managers', 'ended_by')) {
                $table->foreignId('ended_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => $guard]);
        Role::whereIn('name', self::ROLES)->where('guard_name', $guard)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::where('name', self::PERMISSION)->where('guard_name', $guard)->first();
        if ($permission) {
            Role::whereIn('name', self::ROLES)->where('guard_name', $guard)->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('org_unit_managers', function (Blueprint $table) {
            if (Schema::hasColumn('org_unit_managers', 'ended_by')) {
                $table->dropConstrainedForeignId('ended_by');
            }
            foreach (['order_date', 'order_number'] as $column) {
                if (Schema::hasColumn('org_unit_managers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
