<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * การปฏิบัติหน้าที่แทน (DELEGATION-01) — เจ้าของระบบตัดสิน 2026-10-16
 *
 * user_delegations กลายเป็น "ทะเบียนการทำหน้าที่แทน" ที่งานบุคลากร/ผู้ดูแลบันทึกตามคำสั่ง
 *   - delegation_type   acting = รักษาราชการแทน · on_behalf = ปฏิบัติราชการแทน
 *   - acting_position   ตำแหน่งที่ทำหน้าที่แทน (พิมพ์ต่อท้ายบล็อกลงนาม)
 *   - order_number / order_date  คำสั่งที่แต่งตั้ง
 *   - status            pending (ผู้ใช้ขอเอง รอรับรอง) · approved · revoked (ไม่ลบแถว — เก็บประวัติ)
 *
 * leave_approvals เก็บ snapshot ของฐานะการลงนาม ณ เวลาที่ลงนาม เอกสารย้อนหลังจึงไม่เปลี่ยนตามทะเบียน
 *   - acting_type / acting_position / acting_order_ref
 *   - recorded_by_user_id  ผู้ดูแลที่ "บันทึกตามเอกสารที่ลงนามแล้ว" แทนผู้มีอำนาจตัวจริง
 *
 * additive ทั้งหมด · การมอบหมายเดิมทุกแถวเป็น approved + ปฏิบัติราชการแทน (ใช้งานต่อได้เหมือนเดิม)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_delegations', function (Blueprint $table) {
            if (! Schema::hasColumn('user_delegations', 'delegation_type')) {
                $table->string('delegation_type', 20)->default('on_behalf')->after('delegate_id');
            }
            if (! Schema::hasColumn('user_delegations', 'acting_position')) {
                $table->string('acting_position')->nullable()->after('delegation_type');
            }
            if (! Schema::hasColumn('user_delegations', 'order_number')) {
                $table->string('order_number', 100)->nullable()->after('acting_position');
            }
            if (! Schema::hasColumn('user_delegations', 'order_date')) {
                $table->date('order_date')->nullable()->after('order_number');
            }
            if (! Schema::hasColumn('user_delegations', 'note')) {
                $table->string('note', 500)->nullable()->after('order_date');
            }
            if (! Schema::hasColumn('user_delegations', 'status')) {
                $table->string('status', 20)->default('approved')->after('is_active');
            }
            if (! Schema::hasColumn('user_delegations', 'requested_by')) {
                $table->foreignId('requested_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_delegations', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('user_delegations', 'revoked_by')) {
                $table->foreignId('revoked_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable()->after('revoked_by');
            }
        });

        Schema::table('leave_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_approvals', 'acting_type')) {
                $table->string('acting_type', 20)->nullable()->after('delegated_from_user_id');
                $table->string('acting_position')->nullable()->after('acting_type');
                $table->string('acting_order_ref')->nullable()->after('acting_position');
            }
            if (! Schema::hasColumn('leave_approvals', 'recorded_by_user_id')) {
                $table->foreignId('recorded_by_user_id')->nullable()->after('acting_order_ref')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('leave_approvals', 'recorded_by_user_id')) {
                $table->dropConstrainedForeignId('recorded_by_user_id');
            }
            foreach (['acting_order_ref', 'acting_position', 'acting_type'] as $column) {
                if (Schema::hasColumn('leave_approvals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('user_delegations', function (Blueprint $table) {
            foreach (['revoked_by', 'approved_by', 'requested_by'] as $column) {
                if (Schema::hasColumn('user_delegations', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach (['revoked_at', 'approved_at', 'status', 'note', 'order_date', 'order_number', 'acting_position', 'delegation_type'] as $column) {
                if (Schema::hasColumn('user_delegations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
