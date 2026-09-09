<?php

namespace App\Services;

use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

/**
 * LEGACY-07 · 07-1 — เลือกและเรนเดอร์แบบฟอร์มใบลาจาก **เวอร์ชันที่ใบลานั้น pin ไว้**
 *
 * ปัญหาเดิม (LEAVE-003):
 *
 * 1. ระบบมีเทมเพลตสองชุดขนานกันโดยไม่มีใครรู้
 *    - `resources/views/pdf/*.blade.php` — ตัวที่พิมพ์ออกมาจริง
 *    - `document_templates.content_html` — ตัวที่หน้าผู้ดูแลแก้ไขได้
 *    ผลคือ **ผู้ดูแลแก้เทมเพลตแล้วไม่มีอะไรเปลี่ยน** เพราะการพิมพ์ใช้ blade
 *
 * 2. `Leave::booted()` pin `document_template_version_id` โดยเลือก
 *    "เทมเพลต module=leave ที่ id น้อยที่สุด" ซึ่ง **ไม่ใช่แบบฟอร์มของใบลานั้น**
 *    ใบลาพักผ่อนจึง pin แบบฟอร์มลาป่วยไว้ การ pin จึงไม่มีความหมาย
 *
 * แนวทางที่ใช้ (backward-safe):
 *   - pin เวอร์ชันของแบบฟอร์มที่ตรงกับใบลานั้นจริง
 *   - เรนเดอร์จาก `content_html` ของเวอร์ชันที่ pin ไว้เมื่อหาได้
 *   - **ถอยไปใช้ blade เดิมเมื่อไม่มีเวอร์ชันที่ตรง** — แบบฟอร์มลาไปประกอบพิธีฮัจย์
 *     ลาไปช่วยภริยา และลาศึกษาต่อ ยังไม่มีแถวในตารางเทมเพลต จึงต้องไม่พัง
 *
 * `content_html` เป็นสำเนาของไฟล์ blade แบบตรงตัว (ดู `DefaultDocumentTemplateService`)
 * การเรนเดอร์ผ่าน `Blade::render()` จึงให้ผลเหมือนเดิมทุกประการ
 */
class LeaveDocumentTemplateResolver
{
    /**
     * ชื่อ view => code ของเทมเพลตในฐานข้อมูล
     *
     * view ที่ไม่อยู่ในตารางนี้แปลว่ายังไม่มีเทมเพลตที่จัดการได้จากหน้าผู้ดูแล
     * และจะถูกเรนเดอร์จาก blade ตามเดิม
     *
     * @var array<string,string>
     */
    /**
     * แบบอักษรที่ระบบมีไฟล์อยู่จริงใน `public/fonts/THSarabunNew`
     *
     * ตัวเลือกในหน้าตั้งค่าต้องมีเฉพาะฟอนต์ที่ติดตั้งไว้ มิฉะนั้น dompdf จะถอยไปใช้ฟอนต์ละติน
     * แล้วภาษาไทยกลายเป็นกล่องสี่เหลี่ยมทั้งฉบับ
     *
     * `family` คือชื่อที่ประกาศใน CSS — ตั้งให้ตรงกับชื่อที่แม่แบบเดิมใช้อยู่ (`THSarabunNew`)
     * เมื่อเลือกฟอนต์ค่าเริ่มต้น เอกสารจึงไม่เกิดฟอนต์ซ้ำในแคชของ dompdf และแม่แบบที่อ้าง
     * ชื่อฟอนต์ไว้เองยังใช้ได้เหมือนเดิม
     *
     * @var array<string, array{family: string, regular: string, bold: string}>
     */
    public const FONT_FILES = [
        'TH Sarabun New' => ['family' => 'THSarabunNew', 'regular' => 'THSarabunNew.ttf', 'bold' => 'THSarabunNew Bold.ttf'],
        'TH Sarabun' => ['family' => 'THSarabun', 'regular' => 'THSarabun.ttf', 'bold' => 'THSarabun Bold.ttf'],
        'TH SarabunIT๙' => ['family' => 'THSarabunIT9', 'regular' => 'THSarabunIT๙.ttf', 'bold' => 'THSarabunIT๙ Bold.ttf'],
    ];

    public const VIEW_TO_TEMPLATE = [
        'pdf.leave' => 'leave_sick_personal',
        'pdf.leave_vacation' => 'leave_vacation',
        'pdf.leave_paternity' => 'leave_paternity',
        'pdf.leave_ordination' => 'leave_ordination',
        'pdf.leave_cancel' => 'leave_cancel',
        'pdf.official_travel' => 'official_travel',
        'pdf.leave_hajj' => 'leave_hajj',
        'pdf.leave_spouse' => 'leave_spouse',
        'pdf.leave_study' => 'leave_study',
    ];

    /**
     * เวอร์ชันเทมเพลตที่ควรใช้พิมพ์ใบลาใบนี้ด้วยแบบฟอร์มนี้
     *
     * ลำดับ:
     *   1. เวอร์ชันที่ใบลา pin ไว้ — ถ้าเป็นของแบบฟอร์มเดียวกัน
     *   2. เวอร์ชันที่มีผล ณ วันที่ยื่นใบลา (ไม่ใช่วันนี้ — เอกสารเก่าต้องพิมพ์ได้เหมือนเดิม)
     *   3. ไม่มีเลย → ผู้เรียกถอยไปใช้ blade
     */
    public function versionFor(Leave $leave, string $viewName): ?DocumentTemplateVersion
    {
        $code = self::VIEW_TO_TEMPLATE[$viewName] ?? null;

        if ($code === null) {
            return null;
        }

        $pinned = $leave->document_template_version_id
            ? DocumentTemplateVersion::with('template')->find($leave->document_template_version_id)
            : null;

        if ($pinned !== null && $pinned->template?->code === $code) {
            return $pinned;
        }

        return $this->activeVersionFor($code, $leave->submitted_at ?? $leave->created_at);
    }

    /**
     * เวอร์ชันที่มีผลบังคับใช้ของแบบฟอร์มนี้ ณ วันที่ระบุ
     */
    public function activeVersionFor(string $code, $on = null): ?DocumentTemplateVersion
    {
        $date = $on ? Carbon::parse($on)->toDateString() : now()->toDateString();

        return DocumentTemplateVersion::with('template')
            ->whereHas('template', fn ($query) => $query->where('code', $code))
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('version')
            ->first();
    }

    /**
     * เรนเดอร์แบบฟอร์มเป็น HTML
     *
     * ใช้ `content_html` ของเวอร์ชันที่ pin ไว้เมื่อหาได้ มิฉะนั้นใช้ blade เดิม
     *
     * @param  array<string,mixed>  $data
     */
    public function render(Leave $leave, string $viewName, array $data): string
    {
        // ตัวแปรมาตรฐานของแบบฟอร์ม (ดู LeaveDocumentFields) ต้องมีให้ทั้ง blade และเทมเพลตในฐานข้อมูล
        $data = array_merge(app(LeaveDocumentFields::class)->build($leave), $data);

        $version = $this->versionFor($leave, $viewName);

        $html = ($version === null || blank($version->content_html))
            ? view($viewName, $data)->render()
            : Blade::render($version->content_html, $data);

        return $html.$this->pageStyle($version);
    }

    /**
     * สไตล์หน้ากระดาษและแบบอักษรที่ผู้ดูแลตั้งไว้ ต่อท้ายเอกสารเพื่อให้มีผลจริง
     *
     * เดิมค่าที่ตั้งในหน้าตั้งค่าระบบ (ระยะขอบ/แบบอักษร) ไม่เคยถูกใช้เลย เพราะแม่แบบทุกฉบับ
     * เขียน `@page { margin: 8mm 12mm }` กับชื่อฟอนต์ไว้ตายตัวในตัวเอง — ผู้ดูแลจึงแก้ค่าแล้ว
     * เอกสารออกมาเหมือนเดิมทุกครั้ง
     *
     * ลำดับความสำคัญ: ค่าของ "เวอร์ชันแม่แบบ" (เจาะจงรายแบบฟอร์ม) มาก่อนค่ากลางของระบบ
     * ส่วนแบบอักษรเป็นค่ากลางของทั้งระบบเพราะแม่แบบไม่ได้เก็บฟอนต์ของตัวเอง
     *
     * ประกาศไว้ท้ายเอกสารโดยตั้งใจ CSS ที่มาทีหลังจึงชนะกฎเดิมในตัวแม่แบบ
     */
    private function pageStyle(?DocumentTemplateVersion $version): string
    {
        $settings = Setting::pluck('value', 'key');

        $margins = $version !== null
            ? [(float) $version->margin_top, (float) $version->margin_right, (float) $version->margin_bottom, (float) $version->margin_left]
            : [
                $this->centimetresToMillimetres($settings['margin_top'] ?? null, 8.0),
                $this->centimetresToMillimetres($settings['margin_right'] ?? null, 12.0),
                $this->centimetresToMillimetres($settings['margin_bottom'] ?? null, 8.0),
                $this->centimetresToMillimetres($settings['margin_left'] ?? null, 12.0),
            ];

        $font = self::FONT_FILES[$settings['default_font'] ?? ''] ?? self::FONT_FILES['TH Sarabun New'];
        $family = $font['family'];

        $faces = '';
        foreach (['normal' => $font['regular'], 'bold' => $font['bold']] as $weight => $file) {
            $path = public_path('fonts/THSarabunNew/'.$file);

            if (! is_file($path)) {
                continue;
            }

            $faces .= "@font-face{font-family:'{$family}';font-style:normal;font-weight:{$weight};src:url('{$path}') format('truetype');}";
        }

        $margin = implode(' ', array_map(fn (float $value) => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'mm', $margins));

        return '<style>'.$faces
            ."@page{margin:{$margin};}"
            ."body{font-family:'{$family}','THSarabunNew',sans-serif;}"
            .'</style>';
    }

    private function centimetresToMillimetres(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value * 10 : $default;
    }

    /**
     * ขนาดกระดาษและระยะขอบของเวอร์ชันที่ใช้ — คืน null ให้ผู้เรียกใช้ค่าเดิม
     *
     * @return array{paper: string, orientation: string}|null
     */
    public function paperFor(Leave $leave, string $viewName): ?array
    {
        $version = $this->versionFor($leave, $viewName);

        if ($version === null) {
            return null;
        }

        return [
            'paper' => strtolower((string) $version->paper_size) ?: 'a4',
            'orientation' => strtolower((string) $version->orientation) ?: 'portrait',
        ];
    }

    /**
     * แบบฟอร์มที่ใบลาใบนี้ต้องใช้ — ยึด "ค่าที่ตั้งไว้ในประเภทการลา" เป็นหลัก
     *
     * ใช้ทั้งตอน pin เวอร์ชันขณะสร้างใบลา และตอนเลือก view ที่พิมพ์จริง
     * จึงรับประกันว่าเวอร์ชันที่ pin ไว้ตรงกับแบบฟอร์มที่ออกมาจริง
     *
     * ตกลงมาที่การเดาจากชื่อไทยเฉพาะกรณีที่ยังไม่ได้ตั้งค่า (ข้อมูลเก่าก่อน migration)
     */
    public function templateCodeForLeave(Leave $leave): ?string
    {
        /*
         * LS-04 — เอกสารที่ออกไปแล้วยึดแบบฟอร์มที่ตัวเองถูก pin ไว้ ไม่ใช่การผูกแบบฟอร์มปัจจุบัน
         *
         * เดิมเลือกแบบฟอร์มจาก `leave_types.document_template_code` ณ ตอนพิมพ์เสมอ
         * ผู้ดูแลเปลี่ยนการผูกแบบฟอร์มของประเภทการลาเพียงครั้งเดียว ใบลาเก่าทุกใบจึงเปลี่ยน
         * ไปพิมพ์บนแบบฟอร์มคนละฉบับกับที่เคยออกไป ซึ่งขัดกับกฎเอกสารย้อนหลังของระบบ
         *
         * การเปลี่ยนการผูกจึงมีผลกับ "ใบที่ยื่นใหม่" เท่านั้น เพราะใบใหม่จะ pin เวอร์ชันของ
         * แบบฟอร์มปัจจุบันไว้เองตั้งแต่ตอนยื่น
         */
        $pinnedCode = $this->pinnedTemplateCode($leave);

        if ($pinnedCode !== null) {
            return $pinnedCode;
        }

        $type = $leave->type ?? ($leave->leave_type_id ? LeaveType::find($leave->leave_type_id) : null);

        if ($type?->document_template_code) {
            return $type->document_template_code;
        }

        return $this->templateCodeForLeaveType($type?->name);
    }

    /**
     * code ของแบบฟอร์มตามเวอร์ชันที่ใบลานี้ pin ไว้
     *
     * คืน null เมื่อยังไม่ได้ pin, เวอร์ชันที่ pin หายไป หรือเป็นแบบฟอร์มที่ไม่มี view รองรับ
     * (เช่นแม่แบบที่ผู้ดูแลสร้างเอง) ซึ่งกรณีเหล่านั้นต้องเดินตามเส้นทางเดิมเหมือนก่อน
     */
    private function pinnedTemplateCode(Leave $leave): ?string
    {
        if (! $leave->document_template_version_id) {
            return null;
        }

        $code = DocumentTemplateVersion::with('template')
            ->find($leave->document_template_version_id)?->template?->code;

        return $code !== null && isset(array_flip(self::VIEW_TO_TEMPLATE)[$code]) ? $code : null;
    }

    /**
     * ชื่อ view ของแบบฟอร์มตาม code — ใช้เลือกแบบฟอร์มตอนพิมพ์
     */
    public function viewForCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return array_flip(self::VIEW_TO_TEMPLATE)[$code] ?? null;
    }

    /**
     * เดา code ของแบบฟอร์มจากชื่อไทยของประเภทการลา
     *
     * ตรรกะเดิมก่อนมี `leave_types.document_template_code` — เก็บไว้เป็นทางถอย
     * ให้ประเภทการลาที่ผู้ดูแลยังไม่ได้ผูกแบบฟอร์มไว้เท่านั้น
     */
    public function templateCodeForLeaveType(?string $leaveTypeName): ?string
    {
        $name = (string) $leaveTypeName;

        return match (true) {
            str_contains($name, 'พักผ่อน') => 'leave_vacation',
            str_contains($name, 'ช่วยเหลือภริยา') => 'leave_paternity',
            str_contains($name, 'อุปสมบท') => 'leave_ordination',
            str_contains($name, 'ฮัจญ์') => 'leave_hajj',
            str_contains($name, 'ติดตามคู่สมรส') => 'leave_spouse',
            str_contains($name, 'ศึกษาต่อ') => 'leave_study',
            str_contains($name, 'ไปราชการ') => 'official_travel',
            str_contains($name, 'ป่วย'), str_contains($name, 'กิจ'), str_contains($name, 'คลอด') => 'leave_sick_personal',
            default => null,
        };
    }
}
