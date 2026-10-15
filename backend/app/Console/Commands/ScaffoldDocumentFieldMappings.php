<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use Illuminate\Console\Command;

/**
 * วางชุดฟิลด์ตั้งต้นให้แบบฟอร์มราชการที่นำเข้ามา
 *
 * **ตำแหน่งที่ใส่ให้เป็นค่าประมาณตามเลย์เอาต์มาตรฐานของแบบฟอร์มราชการ ไม่ใช่ค่าที่วัดจากไฟล์จริง**
 * จุดประสงค์คือให้ผู้ดูแลไม่ต้องเพิ่มฟิลด์ทีละช่องจากหน้าว่าง — เปิดหน้าออกแบบแล้ว
 * ลากจัดตำแหน่งให้ตรงช่องจริง จากนั้นพรีวิวและเผยแพร่
 *
 * ทำงานกับ "ฉบับร่าง" เท่านั้น และข้ามเวอร์ชันที่มีฟิลด์อยู่แล้ว จึงไม่มีทางทับงานที่ทำไว้
 */
class ScaffoldDocumentFieldMappings extends Command
{
    protected $signature = 'document:scaffold-fields {--template= : เจาะจงรหัสแม่แบบ} {--force : ทับฟิลด์เดิมของฉบับร่าง}';

    protected $description = 'วางชุดฟิลด์ตั้งต้น (ตำแหน่งโดยประมาณ) ให้แบบฟอร์มที่นำเข้า เพื่อให้ผู้ดูแลจัดตำแหน่งต่อได้เร็วขึ้น';

    /** ฟิลด์ที่ทุกแบบฟอร์มใบลามีเหมือนกัน — เรียงตามลำดับที่ปรากฏบนกระดาษ A4 (595 × 842 pt) */
    private const COMMON = [
        ['leave.document_number', 'TEXT', 430, 92, 120, 18],
        ['organization.name', 'TEXT', 330, 118, 220, 18],
        ['leave.document_date', 'DATE', 330, 146, 220, 18],
        ['leave.type', 'TEXT', 150, 176, 240, 18],
        ['organization.director_position', 'TEXT', 120, 202, 260, 18],
        ['employee.full_name', 'TEXT', 150, 232, 220, 18],
        ['employee.position', 'TEXT', 400, 232, 150, 18],
        ['employee.academic_rank', 'TEXT', 150, 256, 150, 18],
        ['employee.department', 'TEXT', 330, 256, 220, 18],
        ['leave.reason', 'MULTILINE_TEXT', 120, 286, 430, 36],
        ['leave.start_date', 'DATE', 180, 330, 150, 18],
        ['leave.end_date', 'DATE', 380, 330, 150, 18],
        ['leave.total_days', 'NUMBER', 150, 356, 60, 18],
        ['leave.contact_address', 'MULTILINE_TEXT', 150, 384, 400, 32],
        ['employee.phone', 'TEXT', 400, 420, 150, 18],
        ['leave.signature', 'SIGNATURE', 360, 452, 110, 40],
        ['employee.full_name', 'TEXT', 350, 500, 180, 18],
        ['approval.supervisor.signature', 'SIGNATURE', 90, 640, 110, 40],
        ['approval.supervisor.name', 'TEXT', 70, 688, 180, 18],
        ['approval.supervisor.position', 'TEXT', 70, 708, 180, 18],
        ['approval.authorizer.signature', 'SIGNATURE', 360, 640, 110, 40],
        ['approval.authorizer.name', 'TEXT', 340, 688, 180, 18],
        ['approval.authorizer.position', 'TEXT', 340, 708, 180, 18],
    ];

    /** ฟิลด์เฉพาะของแต่ละแบบฟอร์ม */
    private const EXTRA = [
        'form_leave_sick_personal' => [
            ['leave.is_sick', 'CHECKBOX', 148, 176, 12, 12],
            ['leave.is_personal', 'CHECKBOX', 248, 176, 12, 12],
            ['leave.is_maternity', 'CHECKBOX', 348, 176, 12, 12],
        ],
    ];

    public function handle(): int
    {
        $query = DocumentTemplate::where('render_mode', DocumentTemplate::RENDER_PDF_OVERLAY);

        if ($this->option('template')) {
            $query->where('code', $this->option('template'));
        }

        $templates = $query->get();

        if ($templates->isEmpty()) {
            $this->warn('ไม่พบแม่แบบชนิดวางฟิลด์บน PDF — รัน `php artisan document:import-forms` ก่อน');

            return self::FAILURE;
        }

        foreach ($templates as $template) {
            $version = $template->versions()
                ->where('status', DocumentTemplateVersion::STATUS_DRAFT)
                ->whereNotNull('pdf_file_path')
                ->orderByDesc('version')
                ->first();

            if ($version === null) {
                $this->line("ข้าม {$template->code}: ไม่มีฉบับร่างที่มีไฟล์ต้นฉบับ");

                continue;
            }

            if ($version->fieldMappings()->exists()) {
                if (! $this->option('force')) {
                    $this->line("ข้าม {$template->code}: ฉบับร่างมีฟิลด์อยู่แล้ว (ใช้ --force เพื่อทับ)");

                    continue;
                }

                $version->fieldMappings()->delete();
            }

            $fields = array_merge(self::COMMON, self::EXTRA[$template->code] ?? []);

            foreach ($fields as [$code, $type, $x, $y, $width, $height]) {
                $version->fieldMappings()->create([
                    'field_code' => $code,
                    'field_type' => $type,
                    'page' => 1,
                    'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height,
                    'font_size' => $type === 'MULTILINE_TEXT' ? 13 : 14,
                    'align' => in_array($type, ['NUMBER', 'CHECKBOX'], true) ? 'center' : 'left',
                    'overflow' => $type === 'MULTILINE_TEXT' ? 'wrap' : 'shrink',
                    'is_active' => true,
                ]);
            }

            $this->info("วางฟิลด์ตั้งต้น {$template->name}: ".count($fields).' ช่อง (เวอร์ชัน '.$version->version.')');
        }

        $this->newLine();
        $this->comment('ตำแหน่งที่วางให้เป็นค่าประมาณ — เปิดศูนย์จัดการเอกสาร → ออกแบบ → ลากให้ตรงช่องจริง → พรีวิว → เผยแพร่');

        return self::SUCCESS;
    }
}
