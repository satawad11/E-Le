<?php

namespace App\Models;

use App\Models\Document\DocumentFieldMapping;
use App\Models\Document\DocumentGeneratedFile;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplateVersion extends Model
{
    /** แก้ไขได้ ลบได้ ตราบใดที่ยังไม่มีเอกสารอ้างอิง */
    public const STATUS_DRAFT = 'DRAFT';

    /** ใช้กับเอกสารที่ออกใหม่ */
    public const STATUS_ACTIVE = 'ACTIVE';

    /** ไม่ใช้กับเอกสารใหม่แล้ว แต่ห้ามลบ เพราะเอกสารเก่ายังอ้างอยู่ */
    public const STATUS_RETIRED = 'RETIRED';

    /** เก็บเป็นประวัติ */
    public const STATUS_ARCHIVED = 'ARCHIVED';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'page_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (DocumentTemplateVersion $version): void {
            /*
             * ห้ามลบเวอร์ชันที่เคยมีเอกสารอ้างอิง — เอกสารย้อนหลังต้องพิมพ์ออกมาได้เหมือนเดิมเสมอ
             * เวอร์ชันที่เลิกใช้ให้เปลี่ยนสถานะเป็น RETIRED แทนการลบ
             */
            if ($version->status !== self::STATUS_DRAFT && $version->isReferenced()) {
                throw new \RuntimeException('ลบเวอร์ชันที่มีเอกสารอ้างอิงอยู่ไม่ได้ ให้เปลี่ยนสถานะเป็น RETIRED แทน');
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function fieldMappings()
    {
        return $this->hasMany(DocumentFieldMapping::class, 'document_template_version_id');
    }

    /** มีเอกสาร (ใบลา หรือไฟล์ที่ออกไปแล้ว) อ้างเวอร์ชันนี้อยู่หรือไม่ */
    public function isReferenced(): bool
    {
        return Leave::where('document_template_version_id', $this->id)->exists()
            || DocumentGeneratedFile::where('document_template_version_id', $this->id)->exists();
    }

    public function isPdfOverlay(): bool
    {
        return $this->render_mode === DocumentTemplate::RENDER_PDF_OVERLAY;
    }

    public function usableForNewDocuments(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
