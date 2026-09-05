<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * กำหนดการเก็บรักษาและการทำลายของหนังสือหนึ่งฉบับ (SoT §25)
 *
 * **`disposed_at` มีค่าแปลว่าไฟล์ถูกทำลายแล้ว แต่แถวนี้ยังอยู่** — ทะเบียนที่
 * ลบตัวเองพร้อมเอกสารคือทะเบียนที่ใช้ตรวจสอบไม่ได้
 */
#[Fillable([
    'uuid', 'document_id', 'retain_years', 'retain_until', 'status',
    'approved_by', 'approved_at', 'disposed_at', 'disposed_by',
    'disposal_note', 'disposed_file_hash',
])]
class RetentionSchedule extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_retention_schedules';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISPOSED = 'disposed';

    protected function casts(): array
    {
        return [
            'retain_years' => 'integer',
            'retain_until' => 'date',
            'approved_at' => 'datetime',
            'disposed_at' => 'datetime',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
