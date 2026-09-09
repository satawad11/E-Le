<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldDefinition;
use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplateVersion;
use Illuminate\Support\Collection;

/**
 * ตรวจแบบฟอร์มก่อนเผยแพร่
 *
 * เดิมกดเผยแพร่ได้เสมอ ความผิดพลาดที่พบบ่อยจึงไปโผล่ตอนพิมพ์เอกสารจริงของผู้ใช้:
 * ฟิลด์ล้นขอบกระดาษ อ้างหน้าที่ไม่มี อ้างตัวแปรที่ถูกปิดหรือไม่มีอยู่ กล่องเล็กจนพิมพ์ไม่อ่านออก
 * หรือกล่องซ้อนทับกันจนข้อความพิมพ์ทับกันบนกระดาษ
 *
 * แยกเป็นสองระดับ:
 *   - `errors`   ความผิดพลาดที่ทำให้เอกสารออกมาผิดแน่ ๆ → ห้ามเผยแพร่
 *   - `warnings` สิ่งที่อาจตั้งใจ (เช่น วางฟิลด์เดียวกันสองที่) → เตือนแต่ไม่ห้าม
 */
class TemplatePreflight
{
    /** ความสูงต่ำสุดที่ยังพิมพ์ข้อความอ่านออกได้ (pt) */
    private const MIN_TEXT_HEIGHT = 8.0;

    public function __construct(private readonly TemplateGeometry $geometry) {}

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function check(DocumentTemplateVersion $version): array
    {
        $mappings = $version->fieldMappings()->where('is_active', true)->get();

        return [
            'errors' => array_values(array_merge(
                $this->sourceFileProblems($version),
                $this->geometry->problems($version, $mappings),
                $this->unknownFieldProblems($mappings),
            )),
            'warnings' => array_values(array_merge(
                $this->emptyFormWarning($mappings),
                $this->sizeWarnings($mappings),
                $this->overlapWarnings($mappings),
            )),
        ];
    }

    /** @return array<int, string> */
    private function sourceFileProblems(DocumentTemplateVersion $version): array
    {
        if (! $version->isPdfOverlay()) {
            return [];
        }

        return $this->geometry->pageSizes($version) === []
            ? ['ยังไม่มีไฟล์แบบฟอร์มต้นฉบับที่อ่านได้ — อัปโหลดไฟล์ PDF ก่อนเผยแพร่']
            : [];
    }

    /**
     * @param  Collection<int, DocumentFieldMapping>  $mappings
     * @return array<int, string>
     */
    private function unknownFieldProblems($mappings): array
    {
        $known = DocumentFieldDefinition::where('is_active', true)->pluck('code')->all();
        $problems = [];

        foreach ($mappings as $mapping) {
            if (filled($mapping->static_text)) {
                continue;
            }

            if (! in_array($mapping->field_code, $known, true)) {
                $problems[] = sprintf('ฟิลด์ %s ไม่มีอยู่ในรายการตัวแปรที่ใช้ได้ (หรือถูกปิดการใช้งาน) จึงจะพิมพ์ออกมาว่างเปล่า', $mapping->field_code);
            }
        }

        return array_unique($problems);
    }

    /** @return array<int, string> */
    private function emptyFormWarning($mappings): array
    {
        return $mappings->isEmpty()
            ? ['ยังไม่ได้วางฟิลด์ใดบนแบบฟอร์มนี้ — เอกสารที่ออกจะเป็นแบบฟอร์มเปล่า']
            : [];
    }

    /** @return array<int, string> */
    private function sizeWarnings($mappings): array
    {
        $warnings = [];

        foreach ($mappings as $mapping) {
            if (in_array($mapping->field_type, ['SIGNATURE', 'IMAGE', 'QR_CODE', 'CHECKBOX'], true)) {
                continue;
            }

            if ((float) $mapping->height < self::MIN_TEXT_HEIGHT) {
                $warnings[] = sprintf('ช่องของฟิลด์ %s สูงเพียง %.0f pt อาจพิมพ์ข้อความไม่ครบ', $mapping->field_code, $mapping->height);
            }
        }

        return $warnings;
    }

    /**
     * กล่องที่ซ้อนทับกันบนหน้าเดียวกัน
     *
     * เป็นคำเตือน ไม่ใช่ข้อห้าม เพราะบางแบบฟอร์มวางช่องติ๊กทับกรอบข้อความโดยตั้งใจ
     *
     * @return array<int, string>
     */
    private function overlapWarnings($mappings): array
    {
        $warnings = [];
        $rows = $mappings->values();

        for ($i = 0; $i < $rows->count(); $i++) {
            for ($j = $i + 1; $j < $rows->count(); $j++) {
                $a = $rows[$i];
                $b = $rows[$j];

                if ((int) $a->page !== (int) $b->page || ! $this->overlaps($a, $b)) {
                    continue;
                }

                $warnings[] = sprintf('ฟิลด์ %s กับ %s วางทับกันในหน้า %d', $a->field_code, $b->field_code, $a->page);
            }
        }

        return array_unique($warnings);
    }

    private function overlaps(DocumentFieldMapping $a, DocumentFieldMapping $b): bool
    {
        return (float) $a->x < (float) $b->x + (float) $b->width
            && (float) $b->x < (float) $a->x + (float) $a->width
            && (float) $a->y < (float) $b->y + (float) $b->height
            && (float) $b->y < (float) $a->y + (float) $a->height;
    }
}
