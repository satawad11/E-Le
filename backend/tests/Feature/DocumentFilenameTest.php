<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\DocumentFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ชื่อไฟล์ของเอกสารที่ผู้ใช้กดดาวน์โหลด
 *
 * ผู้ใช้จริงเก็บเอกสารเหล่านี้เข้าแฟ้มและส่งต่อทางอีเมล ชื่อไฟล์จึงเป็นส่วนหนึ่งของเอกสาร
 * ไม่ใช่รายละเอียดปลีกย่อย — ต้องบอกได้ว่าเป็นเอกสารอะไร ของใคร เลขที่เท่าไร
 * และต้องบันทึกลงเครื่อง Windows ได้โดยไม่ถูกปฏิเสธเพราะอักขระต้องห้าม
 */
class DocumentFilenameTest extends TestCase
{
    use RefreshDatabase;

    private function leave(string $typeName, string $userName): Leave
    {
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $dept = OrgUnit::factory()->create(['name' => 'ทดสอบ']);

        $user = User::factory()->create(['name' => $userName, 'org_unit_id' => $dept->id]);
        $user->assignRole('Employee');

        $type = LeaveType::create(['name' => $typeName, 'max_days' => 30]);

        return Leave::create([
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Pending',
            'document_number' => 'สธ 0412/1234',
        ]);
    }

    public function test_ตัวช่วยตัดอักขระที่ระบบไฟล์ห้ามใช้ออก(): void
    {
        // `/` ในเลขที่หนังสือราชการเป็นกรณีที่เกิดขึ้นจริงทุกใบ
        $filename = DocumentFilename::make(['ใบลา', 'ลา ป่วย', 'สม:ชาย*ใจ?ดี', 'สธ 0412/1234'], 'pdf');

        $this->assertSame('ใบลา_ลา_ป่วย_สม_ชาย_ใจ_ดี_สธ_0412_1234.pdf', $filename);
        $this->assertSame(0, preg_match('/[\/\\\\:*?"<>|\s]/u', $filename));
    }

    public function test_ตัวช่วยข้ามส่วนที่ว่างแทนที่จะทิ้งขีดล่างค้างไว้(): void
    {
        $this->assertSame('ใบลา_1.pdf', DocumentFilename::make(['ใบลา', null, '', '  ', 1], 'pdf'));
        $this->assertSame('เอกสาร.pdf', DocumentFilename::make([null, ''], 'pdf'));
    }

    public function test_header_มีทั้งชื่อสำรอง_ascii_และชื่อไทยตาม_rfc_6266(): void
    {
        $header = DocumentFilename::disposition('ใบลา_ลาป่วย_2569.pdf');

        // เบราว์เซอร์ปัจจุบันอ่าน filename* — ต้องเป็น UTF-8 ที่ถอดกลับได้ตรงตัว
        $this->assertStringContainsString("filename*=UTF-8''".rawurlencode('ใบลา_ลาป่วย_2569.pdf'), $header);

        // ตัวอ่านรุ่นเก่าอ่าน filename ธรรมดา ซึ่งต้องเป็น ASCII ล้วนและคงนามสกุลไว้
        preg_match('/filename="([^"]+)"/', $header, $matches);
        $this->assertSame('document_2569.pdf', $matches[1]);
        $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $matches[1]));
    }

    public function test_ใบลาได้ชื่อไฟล์ภาษาไทยที่บอกชนิดเอกสาร(): void
    {
        $leave = $this->leave('ลาไปศึกษาต่อ ดูงาน ฝึกอบรม', 'สมชาย ใจดี');

        $response = $this->actingAs($leave->user)->get("/api/leaves/{$leave->id}/pdf");

        $response->assertStatus(200);

        $expected = 'ใบลา_ลาไปศึกษาต่อ_ดูงาน_ฝึกอบรม_สมชาย_ใจดี_สธ_0412_1234.pdf';
        $this->assertStringContainsString(
            "filename*=UTF-8''".rawurlencode($expected),
            $response->headers->get('content-disposition'),
        );
    }

    public function test_ใบยกเลิกวันลาแยกชื่อไฟล์ออกจากใบลาเป็นภาษาไทย(): void
    {
        $leave = $this->leave('ลาป่วย', 'สมหญิง รักดี');
        $leave->update(['cancellation_status' => 'Pending', 'cancellation_reason' => 'ติดภารกิจ']);

        $response = $this->actingAs($leave->user)->get("/api/leaves/{$leave->id}/pdf?form=cancel");

        $response->assertStatus(200);

        $disposition = $response->headers->get('content-disposition');
        $expected = 'ใบยกเลิกวันลา_ลาป่วย_สมหญิง_รักดี_สธ_0412_1234.pdf';
        $this->assertStringContainsString("filename*=UTF-8''".rawurlencode($expected), $disposition);

        // เดิมใช้คำนำหน้าภาษาอังกฤษ `cancel_` ปนอยู่ในชื่อไฟล์ที่เหลือเป็นภาษาไทยทั้งหมด
        $this->assertStringNotContainsString('cancel_', $disposition);
    }

    public function test_ไฟล์_word_ใช้ชื่อชุดเดียวกับ_pdf_ต่างแค่นามสกุล(): void
    {
        $leave = $this->leave('ลากิจส่วนตัว', 'สมชาย ใจดี');

        $response = $this->actingAs($leave->user)->get("/api/leaves/{$leave->id}/word");

        $response->assertStatus(200);

        $expected = 'ใบลา_ลากิจส่วนตัว_สมชาย_ใจดี_สธ_0412_1234.doc';
        $this->assertStringContainsString(
            "filename*=UTF-8''".rawurlencode($expected),
            $response->headers->get('content-disposition'),
        );
    }
}
