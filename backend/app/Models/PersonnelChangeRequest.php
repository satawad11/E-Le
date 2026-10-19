<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * คำขอแก้ไขข้อมูลบุคลากร (CORE-02) — Maker–Checker: ผู้ตรวจ ≠ ผู้ยื่น และ ≠ เจ้าของข้อมูล (ตรวจใน service R-03/R-06)
 * ค่าเดิมยังแสดงจนกว่าคำขอได้รับอนุมัติ
 */
class PersonnelChangeRequest extends Model
{
    use HasPublicUuid;

    public const STATUSES = ['pending', 'approved', 'returned', 'rejected'];

    protected $fillable = [
        'personnel_id', 'requested_by', 'entity', 'entity_id', 'payload', 'evidence_document_ids',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = ['payload' => 'array', 'evidence_document_ids' => 'array', 'reviewed_at' => 'datetime'];

    protected $hidden = ['id', 'personnel_id', 'requested_by', 'entity_id', 'reviewed_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
