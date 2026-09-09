<?php

namespace App\Console\Commands;

use App\Models\Document\DocumentBindingRule;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * แนบไฟล์แบบฟอร์มราชการต้นฉบับ (PDF) เข้ากับแม่แบบที่มีอยู่แล้ว เป็น "ฉบับร่าง"
 *
 * ไฟล์ต้นฉบับอยู่ใน `resources/document-templates` — เป็นแบบฟอร์มทางการที่หน่วยงานใช้จริง
 *
 * เดิมคำสั่งนี้สร้างแม่แบบ **ชุดใหม่** ชื่อขึ้นต้น `form_*` ขนานกับแม่แบบเดิมที่ผูกกับ
 * ประเภทการลาอยู่แล้ว ผลคือมีแม่แบบสองชุดของแบบฟอร์มเดียวกัน ซึ่งเป็นปัญหาที่
 * DOC-ENGINE-09 เคยตามยุบรวมไปครั้งหนึ่งแล้ว — ตอนนี้จึงแนบไฟล์เข้ากับแม่แบบเดิมโดยตรง
 *
 * กติกาที่ยึด:
 *   1. สร้างได้เฉพาะ "ฉบับร่าง" — ไม่เผยแพร่ ไม่แตะเวอร์ชันที่ใช้งานอยู่ และไม่เปลี่ยน
 *      `render_mode` ของแม่แบบ (การเผยแพร่เป็นผู้ทำสิ่งนั้นเอง) เอกสารที่ออกอยู่ทุกวันนี้
 *      จึงไม่เปลี่ยนแม้แต่ฉบับเดียวจากการรันคำสั่งนี้
 *   2. ฉบับร่างเปล่าที่มีอยู่แล้วจะถูกเติมไฟล์ให้ แทนการสร้างฉบับร่างซ้อนอีกใบ
 *   3. ฉบับร่างที่มีไฟล์อยู่แล้วจะถูกข้าม — รันซ้ำได้โดยไม่ทับงานที่ผู้ดูแลทำค้างไว้
 *   4. `--dry-run` ดูผลก่อนได้เสมอ
 */
class ImportDocumentFormTemplates extends Command
{
    protected $signature = 'document:import-forms
        {--bind : ผูกกติกาให้ประเภทการลาที่ชื่อตรงกันด้วย}
        {--dry-run : แสดงผลที่จะเกิดโดยไม่เขียนฐานข้อมูล}';

    protected $description = 'แนบไฟล์แบบฟอร์มราชการ (PDF) เข้ากับแม่แบบเดิมเป็นฉบับร่าง เพื่อให้ผู้ดูแลวางตำแหน่งฟิลด์ต่อ';

    /**
     * code ของแม่แบบเดิม => [ชื่อไทย, ไฟล์ต้นฉบับ, คำที่ใช้จับคู่ประเภทการลา, ลำดับความสำคัญ]
     *
     * ใช้ code เดียวกับแม่แบบที่ระบบผูกกับประเภทการลาอยู่แล้ว (`leave_types.document_template_code`)
     * แบบฟอร์มเฉพาะทาง (อุปสมบท/ช่วยเหลือภริยา) ต้องมีลำดับเหนือแบบฟอร์มรวม
     * เพราะชื่อประเภทการลาบางชื่อเข้าเงื่อนไขของทั้งสองแบบ เช่น "ลาไปช่วยเหลือภริยาที่คลอดบุตร"
     */
    private const FORMS = [
        'leave_vacation' => ['แบบใบขอลาพักผ่อน', 'leave_vacation.pdf', ['พักผ่อน'], 100],
        'leave_sick_personal' => ['แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว', 'leave_sick_personal.pdf', ['ป่วย', 'กิจ', 'คลอด'], 150],
        'leave_cancel' => ['แบบใบขอยกเลิกวันลา', 'leave_cancel.pdf', [], 100],
        'leave_ordination' => ['แบบใบลาอุปสมบท', 'leave_ordination.pdf', ['อุปสมบท'], 50],
        'leave_paternity' => ['แบบใบลาไปช่วยเหลือภริยาที่คลอดบุตร', 'leave_paternity.pdf', ['ช่วยเหลือภริยา'], 50],
    ];

    public function handle(): int
    {
        $disk = Storage::disk('private_uploads');
        $dryRun = (bool) $this->option('dry-run');

        $this->warnAboutLegacyDuplicates();

        foreach (self::FORMS as $code => [$name, $file, $keywords, $priority]) {
            $source = resource_path('document-templates/'.$file);

            if (! is_file($source)) {
                $this->warn("ข้าม {$code}: ไม่พบไฟล์ {$file}");

                continue;
            }

            $template = DocumentTemplate::where('code', $code)->first();

            if ($template === null && $dryRun) {
                $this->line("จะสร้างแม่แบบใหม่: {$code} ({$name}) พร้อมฉบับร่างที่แนบไฟล์ {$file}");

                continue;
            }

            $template ??= $this->createTemplate($code, $name);

            $draft = $template->versions()
                ->where('status', DocumentTemplateVersion::STATUS_DRAFT)
                ->latest('version')
                ->first();

            if ($draft !== null && filled($draft->pdf_file_path) && $disk->exists($draft->pdf_file_path)) {
                $this->line("ข้าม {$code}: ฉบับร่างเวอร์ชัน {$draft->version} มีไฟล์ต้นฉบับอยู่แล้ว");

                continue;
            }

            if ($dryRun) {
                $this->line($draft !== null
                    ? "จะแนบไฟล์ {$file} เข้ากับฉบับร่างเวอร์ชัน {$draft->version} ของ {$code}"
                    : "จะสร้างฉบับร่างใหม่ของ {$code} พร้อมไฟล์ {$file}");

                continue;
            }

            $version = $this->attach($template, $draft, $source, $disk);

            $this->info(sprintf(
                '%s → ฉบับร่างเวอร์ชัน %d (%d หน้า)%s',
                $name,
                $version->version,
                $version->page_count,
                $draft !== null ? ' — เติมไฟล์ให้ฉบับร่างเดิม' : '',
            ));

            if ($this->option('bind')) {
                $this->bind($template, $keywords, $code === 'leave_cancel', $priority);
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('ทดลองเท่านั้น — ยังไม่มีการเขียนฐานข้อมูลหรือไฟล์ใด');

            return self::SUCCESS;
        }

        $this->comment('ขั้นตอนถัดไป: ศูนย์จัดการเอกสาร → เปิดหน้าออกแบบ → วางตำแหน่งฟิลด์ → ตรวจ → พรีวิว → เผยแพร่');
        $this->comment('แบบฟอร์มยังไม่ถูกใช้งานจริงจนกว่าจะเผยแพร่และเปิดใช้รายประเภทการลา');

        return self::SUCCESS;
    }

    /**
     * เตือนเมื่อพบแม่แบบชุด `form_*` ที่คำสั่งรุ่นเก่าเคยสร้างไว้
     *
     * ไม่ลบให้เอง เพราะอาจมีเอกสารอ้างอิงอยู่ — ใช้ `document:consolidate-templates` จัดการ
     */
    private function warnAboutLegacyDuplicates(): void
    {
        // กรองในภาษา PHP เพราะการ escape `_` ของ LIKE ต่างกันระหว่าง MySQL กับ SQLite
        $legacy = DocumentTemplate::pluck('code')->filter(fn (string $code) => str_starts_with($code, 'form_'));

        if ($legacy->isEmpty()) {
            return;
        }

        $this->warn('พบแม่แบบชุดซ้ำที่คำสั่งรุ่นเก่าสร้างไว้: '.$legacy->implode(', '));
        $this->warn('คำสั่งนี้ไม่ใช้แม่แบบชุดนั้นแล้ว — ตรวจและยุบรวมด้วย document:consolidate-templates');
        $this->newLine();
    }

    private function createTemplate(string $code, string $name): DocumentTemplate
    {
        return DocumentTemplate::create([
            'code' => $code,
            'name' => $name,
            'module' => 'leave',
            'document_type' => 'FORM',
            // แม่แบบยังเป็น html จนกว่าจะเผยแพร่ฉบับร่างชนิด pdf_overlay
            'render_mode' => DocumentTemplate::RENDER_HTML,
            'description' => 'แบบฟอร์มราชการต้นฉบับ นำเข้าโดยคำสั่ง document:import-forms',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'content_html' => '',
            'is_active' => true,
        ]);
    }

    /** เติมไฟล์ให้ฉบับร่างเดิม หรือสร้างฉบับร่างใหม่เมื่อยังไม่มี */
    private function attach(
        DocumentTemplate $template,
        ?DocumentTemplateVersion $draft,
        string $source,
        Filesystem $disk,
    ): DocumentTemplateVersion {
        $next = $draft?->version ?? ((int) DocumentTemplateVersion::where('document_template_id', $template->id)->max('version') + 1);

        $path = 'document-templates/'.$template->code.'-v'.$next.'.pdf';
        $disk->put($path, (string) file_get_contents($source));

        $attributes = [
            'status' => DocumentTemplateVersion::STATUS_DRAFT,
            'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'pdf_file_path' => $path,
            'page_count' => $this->pageCount($disk->path($path)),
            'is_active' => false,
        ];

        if ($draft !== null) {
            $draft->update($attributes);

            return $draft->fresh();
        }

        return $template->versions()->create($attributes + [
            'version' => $next,
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            // แบบฟอร์ม PDF ไม่ใช้ระยะขอบ (วางฟิลด์ด้วยพิกัด) แต่คอลัมน์เดิมบังคับให้มีค่า
            'margin_top' => $template->margin_top ?? 0,
            'margin_bottom' => $template->margin_bottom ?? 0,
            'margin_left' => $template->margin_left ?? 0,
            'margin_right' => $template->margin_right ?? 0,
            'content_html' => '',
            'effective_from' => now()->toDateString(),
        ]);
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
