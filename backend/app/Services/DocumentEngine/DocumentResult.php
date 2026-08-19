<?php

namespace App\Services\DocumentEngine;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;

/** ผลลัพธ์การเรนเดอร์เอกสารหนึ่งฉบับ พร้อมข้อมูลที่ใช้เรนเดอร์ (เก็บเป็น snapshot) */
final readonly class DocumentResult
{
    /** @param array<string,mixed> $values */
    public function __construct(
        public ?DocumentTemplate $template,
        public DocumentTemplateVersion $version,
        public string $content,
        public array $values,
    ) {}
}
