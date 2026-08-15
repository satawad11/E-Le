<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'leave_type_id', 'leave_policy_id', 'fiscal_year_id', 'document_number', 'verification_hash', 'content_hash', 'start_date', 'end_date', 'leave_period', 'total_days', 'paid_days', 'unpaid_days', 'reserved_days', 'reason', 'contact_address', 'attachment', 'status', 'approval_level', 'substitute_id', 'substitute_status', 'substitute_reason', 'hr_verified_by', 'hr_verified_at', 'cancellation_status', 'cancellation_reason', 'cancellation_date', 'meta_data', 'signature_path', 'notified_supervisor_at', 'submitted_at', 'voided_by', 'voided_at', 'void_reason'])]
class Leave extends Model
{
    use HasPublicUuid, SoftDeletes;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'total_days' => 'float', 'paid_days' => 'float', 'unpaid_days' => 'float', 'reserved_days' => 'float', 'meta_data' => 'array', 'notified_supervisor_at' => 'datetime', 'submitted_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Leave $leave): void {
            if ($leave->document_template_version_id !== null) {
                return;
            }

            /*
             * LEGACY-07 · 07-1 — pin แบบฟอร์มที่ใบลานี้ **จะถูกพิมพ์ออกมาจริง**
             *
             * เดิมเลือก "เทมเพลต module=leave ที่ id น้อยที่สุด" ซึ่งไม่เกี่ยวกับ
             * ประเภทการลาเลย ใบลาพักผ่อนจึง pin แบบฟอร์มลาป่วยไว้
             * การ pin จึงไม่ได้ป้องกันอะไร เพราะเวอร์ชันที่ pin ไม่ใช่ตัวที่ใช้พิมพ์
             */
            $resolver = app(LeaveDocumentTemplateResolver::class);
            $typeName = $leave->type?->name ?? LeaveType::whereKey($leave->leave_type_id)->value('name');
            $code = $resolver->templateCodeForLeaveType($typeName);

            $version = $code === null ? null : $resolver->activeVersionFor($code);

            /*
             * ประเภทลาที่ยังไม่มีแบบฟอร์มเฉพาะ (ฮัจย์ / ช่วยภริยา / ศึกษาต่อ)
             * ยังต้อง pin เวอร์ชันไว้เพื่อรักษาหลักประกันของ LEGACY-03 ที่ว่า
             * ใบลาทุกใบต้องรู้ว่าอ้างอิงเทมเพลตชุดใด
             *
             * เวลาพิมพ์จริง `LeaveDocumentTemplateResolver` จะเทียบ code ของ
             * เวอร์ชันที่ pin กับแบบฟอร์มที่กำลังใช้อยู่ ถ้าไม่ตรงก็ถอยไปใช้ blade
             * การ pin ที่ไม่ตรงจึงไม่ทำให้พิมพ์ผิดแบบฟอร์ม
             */
            $leave->document_template_version_id = $version?->id
                ?? DocumentTemplateVersion::query()
                    ->where('is_active', true)
                    ->whereHas('template', fn ($query) => $query->where('module', 'leave'))
                    ->whereDate('effective_from', '<=', today())
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
                    ->orderBy('document_template_id')
                    ->value('id');
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy()
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function documentTemplateVersion()
    {
        return $this->belongsTo(DocumentTemplateVersion::class);
    }

    public function approvals()
    {
        return $this->hasMany(LeaveApproval::class);
    }

    public function evidences()
    {
        return $this->hasMany(LeaveEvidence::class);
    }

    public function adjustments()
    {
        return $this->hasMany(LeaveAdjustment::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(LeaveAuditLog::class);
    }

    public function substitute()
    {
        return $this->belongsTo(User::class, 'substitute_id');
    }

    public function hrVerifier()
    {
        return $this->belongsTo(User::class, 'hr_verified_by');
    }

    public function actionLogs()
    {
        return $this->hasMany(LeaveActionLog::class)->orderBy('created_at', 'desc');
    }
}
