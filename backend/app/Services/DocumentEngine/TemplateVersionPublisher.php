<?php

namespace App\Services\DocumentEngine;

use App\Models\DocumentTemplateVersion;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * เผยแพร่เวอร์ชันแบบฟอร์ม — ใช้ร่วมกันระหว่างหน้าออกแบบ คำสั่งสร้างแบบฟอร์ม และหน้าแบบฟอร์มใบลา
 *
 * เวอร์ชันที่ใช้อยู่เดิมถูกปลดเป็น RETIRED (ไม่ลบ) เอกสารเก่าที่อ้างอยู่จึงยังพิมพ์ได้
 */
class TemplateVersionPublisher
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function publish(DocumentTemplateVersion $version, ?int $actorId = null): DocumentTemplateVersion
    {
        DB::transaction(function () use ($version, $actorId) {
            DocumentTemplateVersion::where('document_template_id', $version->document_template_id)
                ->where('id', '!=', $version->id)
                ->where('status', DocumentTemplateVersion::STATUS_ACTIVE)
                ->update([
                    'status' => DocumentTemplateVersion::STATUS_RETIRED,
                    'is_active' => false,
                    'effective_to' => now()->subDay()->toDateString(),
                ]);

            $version->update([
                'status' => DocumentTemplateVersion::STATUS_ACTIVE,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'published_by' => $actorId,
                'published_at' => now(),
            ]);

            // แม่แบบสะท้อนวิธีเรนเดอร์ของเวอร์ชันที่ใช้งานอยู่ (saveQuietly กัน hook ออกเวอร์ชันเปล่า)
            $version->template->forceFill(['render_mode' => $version->render_mode])->saveQuietly();
        }, 5);

        $this->audit->log('document_version_published', 'DocumentTemplateVersion', $version->id, after: ['version' => $version->version], actorId: $actorId);

        return $version->fresh();
    }
}
