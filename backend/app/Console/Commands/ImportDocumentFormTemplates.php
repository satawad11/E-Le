<?php

namespace App\Console\Commands;

use App\Models\Document\DocumentBindingRule;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * นำแบบฟอร์มราชการต้นฉบับ (ไฟล์ PDF) เข้าสู่ระบบเป็นแม่แบบชนิด pdf_overlay
 *
 * ไฟล์ต้นฉบับอยู่ใน `resources/document-templates` — เป็นแบบฟอร์มทางการที่หน่วยงานใช้จริง
 * คำสั่งนี้สร้าง "ฉบับร่าง" ให้เท่านั้น ผู้ดูแลต้องวางตำแหน่งฟิลด์แล้วกดเผยแพร่เอง
 * จึงไม่มีทางที่การรันคำสั่งนี้จะเปลี่ยนเอกสารที่ใช้งานอยู่
 *
 * รันซ้ำได้ (idempotent) — ถ้ามีฉบับร่างที่ยังไม่เผยแพร่อยู่แล้วจะไม่สร้างซ้ำ
 */
class ImportDocumentFormTemplates extends Command
{
    protected $signature = 'document:import-forms {--bind : ผูกกติกาให้ประเภทการลาที่ชื่อตรงกันด้วย}';

    protected $description = 'นำแบบฟอร์มราชการ (PDF) เข้าเป็นแม่แบบของ Document Engine';

    /**
     * code => [ชื่อไทย, ไฟล์ต้นฉบับ, คำที่ใช้จับคู่ประเภทการลา, ลำดับความสำคัญ]
     *
     * แบบฟอร์มเฉพาะทาง (อุปสมบท/ช่วยเหลือภริยา) ต้องมีลำดับเหนือแบบฟอร์มรวม
     * เพราะชื่อประเภทการลาบางชื่อเข้าเงื่อนไขของทั้งสองแบบ เช่น "ลาไปช่วยเหลือภริยาที่คลอดบุตร"
     */
    private const FORMS = [
        'form_leave_vacation' => ['แบบใบขอลาพักผ่อน', 'leave_vacation.pdf', ['พักผ่อน'], 100],
        'form_leave_sick_personal' => ['แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว', 'leave_sick_personal.pdf', ['ป่วย', 'กิจ', 'คลอด'], 150],
        'form_leave_cancel' => ['แบบใบขอยกเลิกวันลา', 'leave_cancel.pdf', [], 100],
        'form_leave_ordination' => ['แบบใบลาอุปสมบท', 'leave_ordination.pdf', ['อุปสมบท'], 50],
        'form_leave_paternity' => ['แบบใบลาไปช่วยเหลือภริยาที่คลอดบุตร', 'leave_paternity.pdf', ['ช่วยเหลือภริยา'], 50],
    ];

    public function handle(): int
    {
        $disk = Storage::disk('private_uploads');

        foreach (self::FORMS as $code => [$name, $file, $keywords, $priority]) {
            $source = resource_path('document-templates/'.$file);

            if (! is_file($source)) {
                $this->warn("ข้าม {$code}: ไม่พบไฟล์ {$file}");

                continue;
            }

            $template = DocumentTemplate::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'module' => 'leave',
                    'document_type' => 'FORM',
                    'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
                    'description' => 'แบบฟอร์มราชการต้นฉบับ นำเข้าโดยคำสั่ง document:import-forms',
                    'paper_size' => 'A4',
                    'orientation' => 'portrait',
                    'content_html' => '',
                    'is_active' => true,
                ],
            );

            $draft = $template->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->latest('version')->first();

            if ($draft !== null) {
                $this->line("มีฉบับร่างอยู่แล้ว: {$code} (เวอร์ชัน {$draft->version})");

                continue;
            }

            // นับเวอร์ชันจากตารางโดยตรง — แม่แบบที่เพิ่งสร้างมีเวอร์ชันที่ระบบออกให้อัตโนมัติอยู่แล้ว
            $next = (int) DocumentTemplateVersion::where('document_template_id', $template->id)->max('version') + 1;

            $path = 'document-templates/'.$code.'-v'.$next.'.pdf';
            $disk->put($path, (string) file_get_contents($source));

            $version = $template->versions()->create([
                'version' => $next,
                'status' => DocumentTemplateVersion::STATUS_DRAFT,
                'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
                'pdf_file_path' => $path,
                'page_count' => $this->pageCount($disk->path($path)),
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                // แบบฟอร์ม PDF ไม่ใช้ระยะขอบ (วางฟิลด์ด้วยพิกัด) แต่คอลัมน์เดิมบังคับให้มีค่า
                'margin_top' => $template->margin_top ?? 0,
                'margin_bottom' => $template->margin_bottom ?? 0,
                'margin_left' => $template->margin_left ?? 0,
                'margin_right' => $template->margin_right ?? 0,
                'content_html' => '',
                'effective_from' => now()->toDateString(),
                'is_active' => false,
            ]);

            $this->info("นำเข้า {$name} → เวอร์ชัน {$version->version} (ฉบับร่าง {$version->page_count} หน้า)");

            if ($this->option('bind')) {
                $this->bind($template, $keywords, $code === 'form_leave_cancel', $priority);
            }
        }

        $this->newLine();
        $this->comment('ขั้นตอนถัดไป: เปิดศูนย์จัดการเอกสาร → วางตำแหน่งฟิลด์ → พรีวิว → เผยแพร่');

        return self::SUCCESS;
    }

    /** @param array<int,string> $keywords */
    private function bind(DocumentTemplate $template, array $keywords, bool $isCancellation, int $priority): void
    {
        $types = LeaveType::query()
            ->when($keywords !== [], function ($query) use ($keywords) {
                $query->where(function ($sub) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $sub->orWhere('name', 'like', '%'.$keyword.'%');
                    }
                });
            }, fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        foreach ($types as $type) {
            DocumentBindingRule::updateOrCreate(
                [
                    'module' => 'LEAVE',
                    'entity_type' => $isCancellation ? 'LEAVE_CANCELLATION' : 'LEAVE_TYPE',
                    'entity_id' => $type->id,
                    'document_template_id' => $template->id,
                ],
                ['priority' => $priority, 'is_active' => true],
            );

            $this->line("  ผูกกับประเภทการลา: {$type->name}");
        }

        if ($isCancellation) {
            // แบบฟอร์มยกเลิกใช้กับทุกประเภทการลา จึงผูกแบบครอบ (entity_id = null)
            DocumentBindingRule::updateOrCreate(
                ['module' => 'LEAVE', 'entity_type' => 'LEAVE_CANCELLATION', 'entity_id' => null, 'document_template_id' => $template->id],
                ['priority' => 200, 'is_active' => true],
            );

            $this->line('  ผูกเป็นแบบฟอร์มยกเลิกวันลาของทุกประเภท');
        }
    }

    private function pageCount(string $absolutePath): int
    {
        try {
            return (new Fpdi)->setSourceFile($absolutePath);
        } catch (\Throwable $exception) {
            report($exception);

            return 1;
        }
    }
}
