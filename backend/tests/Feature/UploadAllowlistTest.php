<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SEC-023R — allowlist ของไฟล์อัปโหลดหลังถอดโมดูลสารบรรณ
 *
 * เดิม allowlist สองชั้น (ชนิดไฟล์จริง + นามสกุล) อยู่ที่ `config/saraban.php`
 * ที่เดียว และหายไปพร้อมโมดูล (ADR-MODULE-01) ส่วนที่เหลือของระบบตรวจด้วย
 * `mimes:` อย่างเดียวบ้าง ปล่อย `svg`/`gif` บ้าง แล้วแต่จุด
 *
 * ชุดนี้ตรึงสองอย่าง:
 *   1. ตัว allowlist ต้องไม่มีชนิดที่รันได้เมื่อเบราว์เซอร์เปิด
 *   2. เส้นทางที่รับไฟล์จริงต้องปฏิเสธไฟล์เหล่านั้น ไม่ใช่แค่ config สวย
 */
class UploadAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('private_uploads');

        $this->leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 60]);
    }

    // ------------------------------------------------------ ตัว allowlist

    public function test_no_allowlist_contains_an_executable_type(): void
    {
        $forbiddenMimes = (array) config('uploads.never_allowed.mime_types');
        $forbiddenExtensions = (array) config('uploads.never_allowed.extensions');

        $this->assertNotEmpty($forbiddenMimes);
        $this->assertNotEmpty($forbiddenExtensions);

        foreach (['attachments', 'images', 'document_templates'] as $profile) {
            $mimeTypes = (array) config("uploads.{$profile}.mime_types");
            $extensions = (array) config("uploads.{$profile}.extensions");

            $this->assertNotEmpty($mimeTypes, "allowlist `{$profile}` ต้องไม่ว่าง");
            $this->assertNotEmpty($extensions, "allowlist `{$profile}` ต้องไม่ว่าง");

            $this->assertSame([], array_values(array_intersect($forbiddenMimes, $mimeTypes)),
                "allowlist `{$profile}` มีชนิดไฟล์ที่รันได้เมื่อเบราว์เซอร์เปิด");
            $this->assertSame([], array_values(array_intersect($forbiddenExtensions, $extensions)),
                "allowlist `{$profile}` มีนามสกุลที่รันได้เมื่อเบราว์เซอร์เปิด");
        }
    }

    /**
     * กฎที่สร้างออกมาต้องมีสองชั้นเสมอ — ตรวจนามสกุลอย่างเดียวไม่พอ
     * เพราะ `mimes:` ผ่านได้ด้วยการเปลี่ยนชื่อไฟล์ในบางกรณี
     */
    public function test_every_upload_rule_checks_both_content_and_extension(): void
    {
        foreach ([UploadRules::attachment(), UploadRules::image(), UploadRules::documentTemplate()] as $rules) {
            $joined = implode('|', $rules);

            $this->assertStringContainsString('mimetypes:', $joined);
            $this->assertStringContainsString('extensions:', $joined);
            $this->assertStringContainsString('max:', $joined);
        }
    }

    // --------------------------------------------- เส้นทางที่รับไฟล์จริง

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function dangerousFiles(): array
    {
        return [
            'php' => ['payload.php', '<?php system($_GET["c"]); ?>'],
            'html' => ['payload.html', '<script>alert(1)</script>'],
            'svg' => ['payload.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'exe' => ['payload.exe', 'MZ'.str_repeat('A', 64)],
            'sh' => ['payload.sh', "#!/bin/sh\nrm -rf /"],
        ];
    }

    #[DataProvider('dangerousFiles')]
    public function test_leave_evidence_upload_rejects_dangerous_files(string $name, string $contents): void
    {
        $owner = User::factory()->create();
        $leave = $this->leaveFor($owner);

        $this->actingAs($owner)
            ->postJson("/api/leaves/{$leave->id}/evidences", [
                'evidence_type' => 'medical_certificate',
                'files' => [UploadedFile::fake()->createWithContent($name, $contents)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0');

        $this->assertSame(0, $leave->evidences()->count());
    }

    /**
     * เปลี่ยนนามสกุลเป็น `.pdf` แต่เนื้อไฟล์เป็นสคริปต์ — ต้องไม่ผ่านด่านเนื้อไฟล์
     */
    public function test_leave_evidence_upload_rejects_a_script_disguised_as_a_pdf(): void
    {
        $owner = User::factory()->create();
        $leave = $this->leaveFor($owner);

        $this->actingAs($owner)
            ->postJson("/api/leaves/{$leave->id}/evidences", [
                'evidence_type' => 'medical_certificate',
                'files' => [$this->realUpload('cert.pdf', '<?php system($_GET["c"]); ?>')],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $leave->evidences()->count());
    }

    public function test_leave_evidence_upload_still_accepts_a_real_pdf(): void
    {
        $owner = User::factory()->create();
        $leave = $this->leaveFor($owner);

        $this->actingAs($owner)
            ->postJson("/api/leaves/{$leave->id}/evidences", [
                'evidence_type' => 'medical_certificate',
                'files' => [UploadedFile::fake()->createWithContent('cert.pdf', $this->minimalPdf())],
            ])
            ->assertCreated();

        $this->assertSame(1, $leave->evidences()->count());
    }

    /**
     * ไฟล์อัปโหลดที่มีเนื้อไฟล์จริงบนดิสก์
     *
     * `UploadedFile::fake()` เดาชนิดไฟล์จาก **นามสกุล** ไม่ใช่เนื้อไฟล์ จึงใช้
     * ทดสอบด่าน `mimetypes:` (magic bytes) ไม่ได้ — ต้องเขียนไฟล์จริงแล้วให้
     * Symfony เดาชนิดจากเนื้อไฟล์เองเหมือนคำขอจริง
     */
    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function leaveFor(User $owner): Leave
    {
        return Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-18',
            'total_days' => 2,
            'reason' => 'ป่วย',
            'status' => 'Pending',
            'approval_level' => 1,
        ])->fresh();
    }

    /**
     * PDF ที่เล็กที่สุดที่ยังถูกอ่านเป็น `application/pdf` จาก magic bytes
     */
    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
