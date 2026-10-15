<?php

namespace App\Console\Commands;

use App\Models\Document\DocumentBindingRule;
use App\Models\Document\DocumentFieldMapping;
use App\Models\Document\DocumentGeneratedFile;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * รวมแม่แบบที่ซ้ำกันให้เหลือฉบับเดียวต่อหนึ่งแบบฟอร์ม
 *
 * ตอนนำแบบฟอร์ม PDF เข้าระบบ ถูกสร้างเป็นแม่แบบชุดใหม่ (`form_leave_*`) แยกจาก
 * แม่แบบ HTML เดิม (`leave_*`) ผู้ดูแลจึงเห็นชื่อซ้ำกันสองรายการต่อหนึ่งแบบฟอร์ม
 *
 * คำสั่งนี้ย้าย "เวอร์ชันที่มีไฟล์ PDF" ไปเป็นเวอร์ชันถัดไปของแม่แบบเดิม
 * ผลลัพธ์คือหนึ่งแม่แบบมีประวัติเดียวที่ไล่จาก HTML → PDF ตามลำดับเวลา
 * ใบลาเก่าที่ pin เวอร์ชัน HTML ไว้ยังพิมพ์ได้เหมือนเดิมทุกใบ เพราะเวอร์ชันนั้นไม่ถูกแตะ
 *
 * ลบเฉพาะสิ่งที่ไม่มีใครอ้างถึงจริง ๆ เท่านั้น — เวอร์ชันที่มีใบลาหรือไฟล์เอกสารอ้างอยู่
 * จะไม่ถูกลบไม่ว่ากรณีใด
 */
class ConsolidateDocumentTemplates extends Command
{
    protected $signature = 'document:consolidate-forms {--dry-run : แสดงผลที่จะเกิดขึ้นโดยไม่บันทึก}';

    protected $description = 'รวมแม่แบบเอกสารที่ซ้ำกันให้เหลือรายการเดียวต่อแบบฟอร์ม';

    /** แม่แบบที่นำเข้ามาใหม่ => แม่แบบเดิมที่จะเก็บไว้เป็นฉบับหลัก */
    private const MERGE_INTO = [
        'form_leave_vacation' => 'leave_vacation',
        'form_leave_sick_personal' => 'leave_sick_personal',
        'form_leave_cancel' => 'leave_cancel',
        'form_leave_ordination' => 'leave_ordination',
        'form_leave_paternity' => 'leave_paternity',
    ];

    /** ชื่อไทยมาตรฐานของแต่ละแบบฟอร์ม — ให้ทุกที่เรียกเหมือนกัน */
    private const CANONICAL_NAMES = [
        'leave_vacation' => 'แบบใบขอลาพักผ่อน',
        'leave_sick_personal' => 'แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว',
        'leave_cancel' => 'แบบใบขอยกเลิกวันลา',
        'leave_ordination' => 'แบบใบลาอุปสมบท',
        'leave_paternity' => 'แบบใบลาไปช่วยเหลือภริยาที่คลอดบุตร',
        'leave_hajj' => 'แบบใบลาไปประกอบพิธีฮัจญ์',
        'leave_spouse' => 'แบบใบลาติดตามคู่สมรส',
        'leave_study' => 'แบบใบลาไปศึกษาต่อ ฝึกอบรม ดูงาน',
        'official_travel' => 'บันทึกข้อความ ขออนุญาตเดินทางไปราชการ',
    ];

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('โหมดทดลอง — ไม่มีการบันทึกใด ๆ');
        }

        foreach (self::MERGE_INTO as $sourceCode => $targetCode) {
            $this->merge($sourceCode, $targetCode);
        }

        $this->renameCanonically();
        $this->normalizeBindings();
        $this->removeEmptyVersions();
        $this->repairUnusableActiveVersions();

        $this->newLine();
        $this->comment('ตรวจผลได้ที่ศูนย์จัดการเอกสาร → แบบฟอร์มเอกสาร');

        return self::SUCCESS;
    }

    private function merge(string $sourceCode, string $targetCode): void
    {
        $source = DocumentTemplate::where('code', $sourceCode)->first();
        $target = DocumentTemplate::where('code', $targetCode)->first();

        if ($source === null) {
            return;
        }

        if ($target === null) {
            // ไม่มีแม่แบบเดิมให้รวมเข้า — เปลี่ยนรหัสของตัวใหม่ให้เป็นรหัสมาตรฐานแทน
            $this->line("เปลี่ยนรหัส {$sourceCode} → {$targetCode} (ไม่มีแม่แบบเดิมให้รวม)");

            if (! $this->dryRun) {
                $source->forceFill(['code' => $targetCode])->saveQuietly();
            }

            return;
        }

        $moving = $source->versions()
            ->whereNotNull('pdf_file_path')
            ->orderByDesc('version')
            ->get();

        if ($moving->isEmpty()) {
            $this->line("ข้าม {$sourceCode}: ไม่มีเวอร์ชันที่มีไฟล์ PDF ให้ย้าย");
        }

        DB::transaction(function () use ($source, $target, $moving, $sourceCode, $targetCode) {
            $next = (int) DocumentTemplateVersion::where('document_template_id', $target->id)->max('version');

            foreach ($moving->sortBy('version') as $version) {
                $next++;

                $this->info("ย้าย {$sourceCode} v{$version->version} → {$targetCode} v{$next} (".$version->fieldMappings()->count().' ฟิลด์)');

                if ($this->dryRun) {
                    continue;
                }

                // ย้ายเฉพาะเจ้าของกับเลขเวอร์ชัน — ตำแหน่งฟิลด์ผูกกับ id ของเวอร์ชันจึงตามไปเอง
                $version->forceFill([
                    'document_template_id' => $target->id,
                    'version' => $next,
                ])->saveQuietly();
            }

            // ย้ายกติกาผูกแบบฟอร์มไปที่แม่แบบหลัก โดยไม่สร้างกติกาซ้ำ
            foreach (DocumentBindingRule::where('document_template_id', $source->id)->get() as $rule) {
                $duplicate = DocumentBindingRule::where('document_template_id', $target->id)
                    ->where('module', $rule->module)
                    ->where('entity_type', $rule->entity_type)
                    ->where('entity_id', $rule->entity_id)
                    ->exists();

                $this->line('  '.($duplicate ? 'ลบกติกาซ้ำ' : 'ย้ายกติกาผูกแบบฟอร์ม')." ({$rule->entity_type} #{$rule->entity_id})");

                if ($this->dryRun) {
                    continue;
                }

                $duplicate
                    ? $rule->delete()
                    : $rule->forceFill(['document_template_id' => $target->id])->save();
            }

            if ($this->dryRun) {
                return;
            }

            // เวอร์ชันที่เหลืออยู่ของแม่แบบต้นทางเป็นของเปล่าที่ระบบสร้างให้ตอนสร้างแม่แบบ
            foreach ($source->versions()->get() as $leftover) {
                if ($this->isReferenced($leftover)) {
                    $this->warn("  คงเวอร์ชัน {$sourceCode} v{$leftover->version} ไว้เพราะมีเอกสารอ้างอิงอยู่");

                    continue;
                }

                DocumentFieldMapping::where('document_template_version_id', $leftover->id)->delete();
                $leftover->forceDelete();
            }

            if ($source->versions()->exists()) {
                $this->warn("  ไม่ลบแม่แบบ {$sourceCode} เพราะยังมีเวอร์ชันที่ถูกอ้างอิงเหลืออยู่");

                return;
            }

            $source->delete();
            $this->line("  ลบแม่แบบซ้ำ {$sourceCode}");
        }, 5);
    }

    /** ตั้งชื่อไทยให้ตรงกันทุกแม่แบบ */
    private function renameCanonically(): void
    {
        foreach (self::CANONICAL_NAMES as $code => $name) {
            $template = DocumentTemplate::where('code', $code)->first();

            if ($template === null || $template->name === $name) {
                continue;
            }

            $this->line("เปลี่ยนชื่อ {$code}: {$template->name} → {$name}");

            if (! $this->dryRun) {
                // saveQuietly เพื่อไม่ให้ hook ออกเวอร์ชันใหม่เพียงเพราะเปลี่ยนชื่อ
                $template->forceFill(['name' => $name])->saveQuietly();
            }
        }
    }

    /**
     * ลำดับความสำคัญของแบบฟอร์ม — เลขน้อยกว่าชนะ
     *
     * แบบฟอร์มเฉพาะทางต้องเหนือแบบฟอร์มรวมเสมอ เพราะชื่อประเภทการลาบางชื่อ
     * เข้าเงื่อนไขของทั้งสองแบบ เช่น "ลาไปช่วยเหลือภริยาที่คลอดบุตร"
     * ที่มีทั้งคำว่า "คลอด" (แบบรวม) และ "ช่วยเหลือภริยา" (แบบเฉพาะ)
     */
    private const BINDING_PRIORITY = [
        'leave_ordination' => 50,
        'leave_paternity' => 50,
        'leave_hajj' => 50,
        'leave_spouse' => 50,
        'leave_study' => 50,
        'leave_vacation' => 100,
        'leave_cancel' => 100,
        'official_travel' => 100,
        'leave_sick_personal' => 150,
    ];

    /**
     * จัดระเบียบการผูกแบบฟอร์มให้เหลือ "หนึ่งแบบฟอร์มต่อหนึ่งรายการ"
     *
     * หลังการรวมแม่แบบ ประเภทการลาหนึ่งประเภทอาจถูกผูกไว้กับหลายแบบฟอร์มพร้อมกัน
     * ซึ่งอ่านยากและเสี่ยงเลือกแบบฟอร์มผิด จึงตั้งลำดับให้ตรงกันทั้งระบบแล้วเก็บไว้ใบเดียว
     */
    private function normalizeBindings(): void
    {
        foreach (DocumentBindingRule::with('template')->get() as $rule) {
            $priority = self::BINDING_PRIORITY[$rule->template?->code] ?? $rule->priority;

            if ((int) $rule->priority === $priority) {
                continue;
            }

            if (! $this->dryRun) {
                $rule->forceFill(['priority' => $priority])->save();
            }
        }

        $groups = DocumentBindingRule::with('template')
            ->get()
            ->groupBy(fn (DocumentBindingRule $rule) => $rule->module.'|'.$rule->entity_type.'|'.($rule->entity_id ?? 'all'));

        foreach ($groups as $key => $rules) {
            if ($rules->count() < 2) {
                continue;
            }

            $keep = $rules->sortBy([['priority', 'asc'], ['id', 'asc']])->first();

            foreach ($rules as $rule) {
                if ($rule->id === $keep->id) {
                    continue;
                }

                $this->line("ตัดการผูกซ้ำ {$key}: คงไว้ที่ ".($keep->template?->code ?? '-').' ตัดออก '.($rule->template?->code ?? '-'));

                if (! $this->dryRun) {
                    $rule->delete();
                }
            }
        }
    }

    /**
     * เก็บกวาดเวอร์ชันเปล่าที่เกิดจาก hook ออกเวอร์ชันอัตโนมัติ
     *
     * นับว่า "เปล่า" เมื่อไม่มีไฟล์ PDF ไม่มีตำแหน่งฟิลด์ ไม่มีเนื้อหา HTML และไม่มีเอกสารใดอ้างถึง
     * เวอร์ชันเดียวที่แม่แบบมีจะไม่ถูกลบ เพื่อให้ทุกแม่แบบยังมีประวัติอย่างน้อยหนึ่งใบ
     */
    private function removeEmptyVersions(): void
    {
        foreach (DocumentTemplate::with('versions')->get() as $template) {
            if ($template->versions->count() < 2) {
                continue;
            }

            foreach ($template->versions as $version) {
                $isEmpty = blank($version->pdf_file_path)
                    && blank($version->content_html)
                    && $version->fieldMappings()->doesntExist()
                    && ! $this->isReferenced($version);

                if (! $isEmpty) {
                    continue;
                }

                $this->line("ลบเวอร์ชันเปล่า {$template->code} v{$version->version}");

                if (! $this->dryRun) {
                    $version->forceDelete();
                }
            }
        }
    }

    /**
     * ปลดเวอร์ชันที่ประกาศว่าเป็นแบบ PDF แต่ไม่มีไฟล์ต้นฉบับ
     *
     * เกิดจากบั๊กเดิมที่การอัปโหลดไฟล์ลงฉบับร่างไปแก้ค่าของแม่แบบ แล้ว hook ออกเวอร์ชันใหม่
     * ให้อัตโนมัติโดยไม่มีไฟล์ติดมาด้วย ถ้าปล่อยไว้ระบบจะเลือกเวอร์ชันนี้ไปเรนเดอร์แล้วล้ม
     *
     * ไม่ลบทิ้ง เพียงเปลี่ยนสถานะเป็น RETIRED และคืนเวอร์ชันที่ใช้งานได้จริงกลับมาเป็นตัวใช้งาน
     */
    private function repairUnusableActiveVersions(): void
    {
        foreach (DocumentTemplate::with('versions')->get() as $template) {
            $broken = $template->versions
                ->where('status', DocumentTemplateVersion::STATUS_ACTIVE)
                ->filter(fn (DocumentTemplateVersion $version) => $version->isPdfOverlay() && blank($version->pdf_file_path));

            foreach ($broken as $version) {
                $this->warn("ปลดเวอร์ชันที่ใช้งานไม่ได้ {$template->code} v{$version->version} (ประกาศเป็น PDF แต่ไม่มีไฟล์ต้นฉบับ)");

                if ($this->dryRun) {
                    continue;
                }

                $version->forceFill([
                    'status' => DocumentTemplateVersion::STATUS_RETIRED,
                    'is_active' => false,
                    'effective_to' => now()->toDateString(),
                ])->save();
            }

            if ($broken->isEmpty() || $this->dryRun) {
                continue;
            }

            $fallback = $template->versions()
                ->where('status', '!=', DocumentTemplateVersion::STATUS_DRAFT)
                ->where(fn ($query) => $query->whereNotNull('pdf_file_path')->orWhere('render_mode', DocumentTemplate::RENDER_HTML))
                ->orderByDesc('version')
                ->first();

            if ($fallback === null) {
                continue;
            }

            $this->line("  คืนเวอร์ชันใช้งานเป็น {$template->code} v{$fallback->version}");

            $fallback->forceFill([
                'status' => DocumentTemplateVersion::STATUS_ACTIVE,
                'is_active' => true,
                'effective_to' => null,
            ])->save();

            $template->forceFill(['render_mode' => $fallback->render_mode])->saveQuietly();
        }
    }

    private function isReferenced(DocumentTemplateVersion $version): bool
    {
        return Leave::where('document_template_version_id', $version->id)->exists()
            || DocumentGeneratedFile::where('document_template_version_id', $version->id)->exists();
    }
}
