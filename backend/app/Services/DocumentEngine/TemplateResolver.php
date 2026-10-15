<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentBindingRule;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use Illuminate\Support\Carbon;

/**
 * หาว่า "entity นี้ใช้แม่แบบไหน และเวอร์ชันใด"
 *
 * ลำดับการตัดสิน:
 *   1. กติกาที่เจาะจง entity_id ตรง ๆ (priority น้อยมาก่อน)
 *   2. กติกาแบบครอบทั้ง entity_type (entity_id = null)
 * โดยนับเฉพาะกติกาที่มีผล ณ วันที่ระบุ (effective dating)
 *
 * เวอร์ชันที่เลือกให้เอกสารใหม่ต้องเป็น ACTIVE และมีผล ณ วันนั้นเท่านั้น
 * ส่วนเอกสารเก่าที่ pin เวอร์ชันไว้แล้วให้ใช้เวอร์ชันที่ pin เสมอ แม้จะ RETIRED ไปแล้ว
 */
class TemplateResolver
{
    public function resolveTemplate(string $module, string $entityType, ?int $entityId, ?string $on = null): ?DocumentTemplate
    {
        $date = $on ? Carbon::parse($on)->toDateString() : now()->toDateString();

        $rule = DocumentBindingRule::query()
            ->where('is_active', true)
            ->where('module', $module)
            ->where('entity_type', $entityType)
            ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            // กติกาที่ระบุ entity ตรง ๆ สำคัญกว่ากติกาครอบทุก entity เสมอ
            ->orderByRaw('CASE WHEN entity_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority')
            ->orderByDesc('id')
            ->with('template')
            ->first();

        return $rule?->template;
    }

    /** เวอร์ชันที่ใช้กับเอกสารที่ออก "ใหม่" ณ วันที่ระบุ */
    public function activeVersion(DocumentTemplate $template, ?string $on = null): ?DocumentTemplateVersion
    {
        $date = $on ? Carbon::parse($on)->toDateString() : now()->toDateString();

        return DocumentTemplateVersion::query()
            ->where('document_template_id', $template->id)
            ->where('status', DocumentTemplateVersion::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('version')
            ->first();
    }

    /**
     * เวอร์ชันที่ต้องใช้พิมพ์เอกสารฉบับหนึ่ง
     *
     * เอกสารที่ pin ไว้แล้วต้องได้เวอร์ชันเดิมเสมอ — ห้ามเลื่อนไปเวอร์ชันปัจจุบัน
     * แม้ผู้ดูแลจะออกเวอร์ชันใหม่หรือปลดเวอร์ชันเดิมไปแล้วก็ตาม
     */
    public function versionForDocument(?int $pinnedVersionId, ?DocumentTemplate $template, ?string $on = null): ?DocumentTemplateVersion
    {
        if ($pinnedVersionId !== null) {
            $pinned = DocumentTemplateVersion::find($pinnedVersionId);

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $template === null ? null : $this->activeVersion($template, $on);
    }
}
