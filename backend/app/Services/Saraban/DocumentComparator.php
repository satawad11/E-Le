<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentVersion;
use Illuminate\Support\Facades\Storage;

/**
 * เปรียบเทียบเอกสารสองเวอร์ชัน (SoT §22)
 *
 * SoT §22 กำหนดให้เทียบสี่ชั้น: **metadata / file hash / page / attachment**
 *
 * **ไม่เทียบเนื้อความในไฟล์** โดยตั้งใจ — การอ่านข้อความออกจาก PDF ต้องพึ่ง
 * ตัวแยกข้อความที่ผลลัพธ์ต่างกันตามฟอนต์และวิธีสร้างไฟล์ · ความต่างที่รายงาน
 * ผิดในเอกสารราชการอันตรายกว่าการไม่รายงาน เพราะคนจะเชื่อมัน
 *
 * สิ่งที่รายงานจึงเป็นข้อเท็จจริงที่วัดได้แน่นอน: ค่าในทะเบียนเปลี่ยนไหม
 * ไฟล์เป็นไฟล์เดียวกันไหม จำนวนหน้าเท่ากันไหม ขนาดต่างกันเท่าไร
 */
class DocumentComparator
{
    /**
     * @return array<string,mixed>
     *
     * @throws DocumentComparisonException
     */
    public function compare(DocumentVersion $left, DocumentVersion $right): array
    {
        if ($left->id === $right->id) {
            throw new DocumentComparisonException('เลือกเวอร์ชันเดียวกันสองครั้ง ไม่มีอะไรให้เทียบ');
        }

        return [
            'left' => $this->describe($left),
            'right' => $this->describe($right),
            'identical_file' => $left->file_hash !== null && $left->file_hash === $right->file_hash,
            'metadata' => $this->metadataDiff($left->document, $right->document),
            'file' => $this->fileDiff($left, $right),
        ];
    }

    /**
     * เทียบทะเบียนของหนังสือสองฉบับ — ใช้เมื่อเทียบข้ามฉบับ ไม่ใช่ข้ามเวอร์ชัน
     *
     * @return array<int,array{field:string,left:mixed,right:mixed}>
     */
    public function metadataDiff(?Document $left, ?Document $right): array
    {
        if ($left === null || $right === null || $left->id === $right->id) {
            return [];
        }

        $fields = ['subject', 'summary', 'direction', 'confidentiality', 'urgency', 'status', 'owner_org_unit_id'];
        $diff = [];

        foreach ($fields as $field) {
            if ($left->{$field} !== $right->{$field}) {
                $diff[] = ['field' => $field, 'left' => $left->{$field}, 'right' => $right->{$field}];
            }
        }

        return $diff;
    }

    /**
     * @return array<string,mixed>
     */
    private function fileDiff(DocumentVersion $left, DocumentVersion $right): array
    {
        $leftSize = $this->sizeOf($left);
        $rightSize = $this->sizeOf($right);

        return [
            'left_hash' => $left->file_hash,
            'right_hash' => $right->file_hash,
            'left_bytes' => $leftSize,
            'right_bytes' => $rightSize,
            'byte_delta' => $leftSize === null || $rightSize === null ? null : $rightSize - $leftSize,
            'left_pages' => $this->pageCountOf($left),
            'right_pages' => $this->pageCountOf($right),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describe(DocumentVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'stage' => $version->stage,
            'document_uuid' => $version->document?->uuid,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    private function sizeOf(DocumentVersion $version): ?int
    {
        $disk = Storage::disk('private_uploads');

        return $version->pdf_file_path !== null && $disk->exists($version->pdf_file_path)
            ? $disk->size($version->pdf_file_path)
            : null;
    }

    /**
     * นับหน้าโดยไม่ต้องพึ่งตัวแยกข้อความ
     *
     * นับ `/Type /Page` ที่ไม่ใช่ `/Pages` — วิธีนี้ตรงกับ PDF ที่สร้างจาก FPDI
     * ซึ่งเป็นทุกไฟล์ที่โมดูลนี้ผลิตเอง · คืน `null` เมื่ออ่านไม่ได้ ดีกว่าคืน
     * ตัวเลขที่อาจผิด เพราะหน้าจอต้องแยกได้ระหว่าง "เท่ากัน" กับ "ไม่รู้"
     */
    private function pageCountOf(DocumentVersion $version): ?int
    {
        $disk = Storage::disk('private_uploads');

        if ($version->pdf_file_path === null || ! $disk->exists($version->pdf_file_path)) {
            return null;
        }

        $contents = (string) $disk->get($version->pdf_file_path);
        $matches = preg_match_all('#/Type\s*/Page(?![sA-Za-z])#', $contents);

        return $matches > 0 ? $matches : null;
    }
}
