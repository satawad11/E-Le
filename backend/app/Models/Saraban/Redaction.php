<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * สำเนาที่ปกปิดข้อมูลหนึ่งฉบับ (SoT §22)
 *
 * **แยกจากต้นฉบับเด็ดขาด** — ต้นฉบับไม่ถูกแตะ และยังเปิดได้โดยผู้มีสิทธิ์เสมอ
 * สำเนานี้เป็นไฟล์คนละไฟล์ที่มี hash ของตัวเอง
 *
 * **แก้ไม่ได้หลังสร้าง** — สำเนาที่ปกปิดแล้วถูกส่งออกไปข้างนอก การแก้ย้อนหลัง
 * ทำให้สิ่งที่ส่งไปกับสิ่งที่บันทึกไว้ไม่ตรงกัน
 */
#[Fillable([
    'uuid', 'document_id', 'source_version_id', 'reason', 'areas',
    'pdf_file_path', 'file_hash', 'created_by',
])]
class Redaction extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_redactions';

    protected function casts(): array
    {
        return ['areas' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('สำเนาที่ปกปิดข้อมูลแล้วแก้ไขไม่ได้ ต้องสร้างฉบับใหม่');
        });
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function sourceVersion()
    {
        return $this->belongsTo(DocumentVersion::class, 'source_version_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
