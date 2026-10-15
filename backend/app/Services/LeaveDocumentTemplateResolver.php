<?php

namespace App\Services;

use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;

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
 *
 * TPL-SEC-01 — **ไม่เรนเดอร์ `content_html` จากฐานข้อมูลอีกต่อไป**
 * การส่งข้อความที่ผู้ดูแลแก้ได้เข้า `Blade::render()` คือการให้รันโค้ด PHP บนเซิร์ฟเวอร์
 * (`@php ... @endphp`, `{{ system(...) }}`) แก่ทุกคนที่มีสิทธิ์ตั้งค่า
 * แบบฟอร์ม HTML จึงมาจากไฟล์ blade ในโค้ดเท่านั้น (ผ่าน code review)
 * ส่วนการออกแบบแบบฟอร์มโดยผู้ดูแลให้ใช้ Document Engine (วางฟิลด์บน PDF ต้นฉบับ)
 * เวอร์ชันที่ pin ไว้ยังใช้กำหนดขนาดกระดาษ และบอกว่าใบลานั้นออกด้วยแบบฟอร์มใด
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
        'pdf.leave_military' => 'leave_military',
        'pdf.leave_international' => 'leave_international',
        'pdf.leave_rehabilitation' => 'leave_rehabilitation',
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
     * เรนเดอร์แบบฟอร์มเป็น HTML จากไฟล์ blade ในโค้ดเสมอ — ดู TPL-SEC-01 ด้านบน
     *
     * @param  array<string,mixed>  $data
     */
    public function render(Leave $leave, string $viewName, array $data): string
    {
        // ตัวแปรมาตรฐานของแบบฟอร์ม (ดู LeaveDocumentFields)
        $data = array_merge(app(LeaveDocumentFields::class)->build($leave), $data);

        return view($viewName, $data)->render();
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
        $type = $leave->type ?? ($leave->leave_type_id ? LeaveType::find($leave->leave_type_id) : null);

        if ($type?->document_template_code) {
            return $type->document_template_code;
        }

        return $this->templateCodeForLeaveType($type?->name);
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
            str_contains($name, 'ตรวจเลือก'), str_contains($name, 'เตรียมพล') => 'leave_military',
            str_contains($name, 'องค์การระหว่างประเทศ') => 'leave_international',
            str_contains($name, 'ฟื้นฟูสมรรถภาพ') => 'leave_rehabilitation',
            str_contains($name, 'ไปราชการ') => 'official_travel',
            str_contains($name, 'ป่วย'), str_contains($name, 'กิจ'), str_contains($name, 'คลอด') => 'leave_sick_personal',
            default => null,
        };
    }
}
