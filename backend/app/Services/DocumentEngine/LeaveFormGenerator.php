<?php

namespace App\Services\DocumentEngine;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Services\AuditLogger;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * สร้างแบบฟอร์มใบลาสำหรับหน้าออกแบบ (Visual Designer) จากแบบฟอร์มในระบบ
 *
 * ผลลัพธ์คือ "ฉบับร่าง" ของแม่แบบชนิด pdf_overlay (`designer_<code>`) ที่มีแบบฟอร์มเปล่าเป็นพื้นหลัง
 * และวางฟิลด์ไว้ครบทุกช่องตรงตำแหน่งแล้ว ผู้ดูแลเปิดหน้าออกแบบ ปรับ/เพิ่ม/ลบฟิลด์ แล้วเผยแพร่ได้ทันที
 *
 * ไม่แตะเวอร์ชันที่เผยแพร่อยู่ — สร้างเป็นเวอร์ชันใหม่เสมอ เอกสารเก่าจึงพิมพ์ซ้ำได้เหมือนเดิม
 */
class LeaveFormGenerator
{
    public const TEMPLATE_PREFIX = 'designer_';

    public function __construct(
        private readonly LeaveFormLayoutProbe $probe,
        private readonly LeaveDocumentTemplateResolver $resolver,
        private readonly TemplateVersionPublisher $publisher,
        private readonly AuditLogger $audit,
    ) {}

    /** รหัสแบบฟอร์มในระบบที่สร้างแบบฟอร์มสำหรับหน้าออกแบบได้ @return array<int, string> */
    public function codes(): array
    {
        return array_values(LeaveDocumentTemplateResolver::VIEW_TO_TEMPLATE);
    }

    public function templateFor(string $code): ?DocumentTemplate
    {
        return DocumentTemplate::where('code', self::TEMPLATE_PREFIX.$code)->first();
    }

    public function generate(string $code, ?int $actorId = null, bool $publish = false): DocumentTemplateVersion
    {
        $view = $this->resolver->viewForCode($code);

        if ($view === null) {
            throw new \InvalidArgumentException("ไม่มีแบบฟอร์มในระบบรหัส {$code}");
        }

        $result = $this->probe->probe($view);

        $version = DB::transaction(function () use ($code, $result, $actorId) {
            // สร้างแม่แบบแบบไม่ยิง model event — event ของ DocumentTemplate จะออกเวอร์ชัน HTML เปล่าให้อัตโนมัติ
            $template = DocumentTemplate::withoutEvents(fn () => DocumentTemplate::firstOrCreate(
                ['code' => self::TEMPLATE_PREFIX.$code],
                [
                    'name' => $this->name($code),
                    'module' => 'leave',
                    'document_type' => 'FORM',
                    'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
                    'description' => 'สร้างจากแบบฟอร์มในระบบ — ปรับตำแหน่งฟิลด์ได้ที่หน้าออกแบบ',
                    'paper_size' => 'A4',
                    'orientation' => 'portrait',
                    'content_html' => '',
                    'is_active' => true,
                    'created_by' => $actorId,
                ],
            ));

            $next = (int) DocumentTemplateVersion::where('document_template_id', $template->id)->max('version') + 1;
            $path = 'document-templates/'.$template->code.'-v'.$next.'.pdf';
            Storage::disk('private_uploads')->put($path, $result['background']);

            $version = $template->versions()->create([
                'version' => $next,
                'status' => DocumentTemplateVersion::STATUS_DRAFT,
                'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
                'pdf_file_path' => $path,
                'page_count' => $result['page_count'],
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
                'content_html' => '',
                'effective_from' => now()->toDateString(),
                'is_active' => false,
                'created_by' => $actorId,
            ]);

            foreach ($result['mappings'] as $mapping) {
                $version->fieldMappings()->create($mapping + ['is_active' => true]);
            }

            return $version;
        });

        $this->audit->log('document_template_generated', 'DocumentTemplateVersion', $version->id, after: [
            'code' => $code, 'version' => $version->version, 'fields' => count($result['mappings']),
        ], actorId: $actorId);

        return $publish ? $this->publisher->publish($version, $actorId) : $version;
    }

    private function name(string $code): string
    {
        $name = DocumentTemplate::where('code', $code)->value('name');

        return ($name ?: $code).' (ออกแบบได้)';
    }
}
