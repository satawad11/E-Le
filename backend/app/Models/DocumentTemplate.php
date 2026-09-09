<?php

namespace App\Models;

use App\Models\Document\DocumentBindingRule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    use HasFactory;

    /** แม่แบบเรนเดอร์จาก HTML เดิม (ค่าเริ่มต้น) */
    public const RENDER_HTML = 'html';

    /** แม่แบบวางฟิลด์ลงบนไฟล์ PDF ต้นฉบับตามพิกัด */
    public const RENDER_PDF_OVERLAY = 'pdf_overlay';

    public const TYPES = ['FORM', 'REPORT', 'CERTIFICATE', 'LETTER', 'LABEL', 'OTHER'];

    protected $fillable = [
        'code',
        'name',
        'module',
        'document_type',
        'render_mode',
        'description',
        'created_by',
        'paper_size',
        'orientation',
        'margin_top',
        'margin_bottom',
        'margin_left',
        'margin_right',
        'content_html',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'margin_top' => 'float',
        'margin_bottom' => 'float',
        'margin_left' => 'float',
        'margin_right' => 'float',
    ];

    protected static function booted(): void
    {
        static::created(fn (DocumentTemplate $template) => $template->createVersion());

        static::updated(function (DocumentTemplate $template): void {
            if ($template->wasChanged(['paper_size', 'orientation', 'margin_top', 'margin_bottom', 'margin_left', 'margin_right', 'content_html', 'is_active', 'render_mode'])) {
                $template->currentVersion?->update(['effective_to' => now()->subDay()->toDateString(), 'is_active' => false]);
                $template->createVersion();
            }
        });
    }

    public function versions()
    {
        return $this->hasMany(DocumentTemplateVersion::class);
    }

    public function currentVersion()
    {
        return $this->hasOne(DocumentTemplateVersion::class)->whereNull('effective_to')->latestOfMany('version');
    }

    public function bindingRules()
    {
        return $this->hasMany(DocumentBindingRule::class, 'document_template_id');
    }

    public function isPdfOverlay(): bool
    {
        return $this->render_mode === self::RENDER_PDF_OVERLAY;
    }

    /**
     * ออกเวอร์ชันใหม่จากค่าปัจจุบันของแม่แบบ มีผลตั้งแต่วันนี้เสมอ
     *
     * เปิดให้เรียกจากภายนอกได้เพราะคำสั่ง `document:backfill-versions` ใช้เติมเวอร์ชันแรก
     * ให้แม่แบบที่สร้างไว้ก่อนมีระบบเวอร์ชัน (LS-08) — ตรรกะการสร้างต้องเป็นชุดเดียวกับ hook
     * มิฉะนั้นเวอร์ชันที่เติมย้อนหลังจะต่างจากเวอร์ชันที่ระบบออกให้ตามปกติ
     */
    public function createVersion(): void
    {
        $this->versions()->create([
            'version' => ($this->versions()->max('version') ?? 0) + 1,
            // เวอร์ชันสืบทอดวิธีเรนเดอร์จากแม่แบบ ณ เวลาที่ออกเวอร์ชัน
            'render_mode' => $this->render_mode ?? self::RENDER_HTML,
            'status' => ($this->is_active ?? true) ? DocumentTemplateVersion::STATUS_ACTIVE : DocumentTemplateVersion::STATUS_RETIRED,
            'paper_size' => $this->paper_size ?? 'A4',
            'orientation' => $this->orientation ?? 'portrait',
            'margin_top' => $this->margin_top ?? 8.0,
            'margin_bottom' => $this->margin_bottom ?? 8.0,
            'margin_left' => $this->margin_left ?? 12.0,
            'margin_right' => $this->margin_right ?? 12.0,
            'content_html' => $this->content_html,
            'effective_from' => now()->toDateString(),
            'is_active' => $this->is_active ?? true,
        ]);
    }
}
