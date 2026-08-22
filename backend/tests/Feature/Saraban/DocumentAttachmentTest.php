<?php

namespace Tests\Feature\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAttachment;
use App\Support\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SEC-023/SEC-024 — เส้นทางไฟล์แนบสารบรรณตั้งแต่อัปโหลดจนถึงดาวน์โหลด
 *
 * เดิมมีสองปัญหาคนละด้าน:
 *   - เขียนไฟล์ลง disk 'private_uploads' แต่ตอนดาวน์โหลดอ่านจาก disk 'public'
 *     ปุ่มดาวน์โหลดจึงตอบ 404 กับไฟล์แนบทุกไฟล์ (เทสต์เดิม fake disk ผิดตัวจึงไม่จับ)
 *   - กฎตรวจไฟล์มีเพียง `file|max:20480` ผู้ที่สร้างหนังสือได้จึงอัปโหลด
 *     .php / .html / .svg เข้าสู่ storage ของระบบได้
 */
class DocumentAttachmentTest extends SarabanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StoredFile::DISK);
    }

    public function test_an_uploaded_attachment_can_be_downloaded_again(): void
    {
        $response = $this->actingAs($this->registrar)
            ->post('/api/saraban/documents', $this->documentPayload([
                'files' => [UploadedFile::fake()->create('รายงานประจำปี.pdf', 12, 'application/pdf')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $document = Document::findOrFail($response->json('id'));
        $attachment = $document->attachments()->firstOrFail();

        Storage::disk(StoredFile::DISK)->assertExists($attachment->path);

        $download = $this->actingAs($this->registrar)
            ->get("/api/saraban/attachments/{$attachment->id}/download")
            ->assertOk();

        $this->assertStringContainsString('attachment', $download->headers->get('content-disposition'));
        $this->assertSame('nosniff', $download->headers->get('x-content-type-options'));
    }

    /** ชื่อบนดิสก์ต้องเป็น UUID ส่วนชื่อเดิมของผู้ใช้เก็บไว้แสดงผลเท่านั้น */
    public function test_the_stored_filename_is_a_uuid_not_the_name_the_user_sent(): void
    {
        $response = $this->actingAs($this->registrar)
            ->post('/api/saraban/documents', $this->documentPayload([
                'files' => [UploadedFile::fake()->create('../../ชื่อไฟล์ของผู้ใช้.pdf', 5, 'application/pdf')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $attachment = Document::findOrFail($response->json('id'))->attachments()->firstOrFail();

        $this->assertMatchesRegularExpression(
            '#^saraban/attachments/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#',
            $attachment->path,
        );
        $this->assertStringNotContainsString('..', $attachment->path);
    }

    /**
     * `mime_type` ต้องมาจากเนื้อไฟล์ ไม่ใช่ Content-Type ที่ client ประกาศมา
     */
    public function test_the_recorded_mime_type_comes_from_the_file_not_the_client(): void
    {
        $response = $this->actingAs($this->registrar)
            ->post('/api/saraban/documents', $this->documentPayload([
                'files' => [UploadedFile::fake()->create('ภาพประกอบ.png', 5, 'image/png')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $attachment = Document::findOrFail($response->json('id'))->attachments()->firstOrFail();

        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('ภาพประกอบ.png', $attachment->name);
    }

    /**
     * @param  array{0:string,1:string}  $file  [ชื่อไฟล์, mime ที่ตรวจได้จากเนื้อไฟล์]
     */
    #[DataProvider('maliciousFileProvider')]
    public function test_a_file_outside_the_allowlist_is_refused(array $file): void
    {
        [$name, $mime] = $file;

        $this->actingAs($this->registrar)
            ->post('/api/saraban/documents', $this->documentPayload([
                'files' => [UploadedFile::fake()->create($name, 4, $mime)],
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0');

        $this->assertSame(0, DocumentAttachment::count());
        $this->assertSame([], Storage::disk(StoredFile::DISK)->allFiles());
    }

    /**
     * @return array<string,array{0:array{0:string,1:string}}>
     */
    public static function maliciousFileProvider(): array
    {
        return [
            // เนื้อไฟล์เป็น PHP ตรง ๆ
            'php webshell' => [['shell.php', 'application/x-httpd-php']],
            // ตั้งชื่อเป็น .php แต่เนื้อในทำตัวเป็น PDF เพื่อหลอกด่านที่ดูแค่ mime
            'php disguised as pdf content' => [['shell.php', 'application/pdf']],
            // เนื้อไฟล์เป็น HTML/SVG ที่รัน script ได้เมื่อเบราว์เซอร์เปิด
            'html' => [['page.html', 'text/html']],
            'svg with script' => [['logo.svg', 'image/svg+xml']],
            // นามสกุลดูปลอดภัยแต่เนื้อในไม่ใช่ชนิดที่อนุญาต
            'pdf extension with html body' => [['report.pdf', 'text/html']],
            'executable' => [['setup.exe', 'application/x-msdownload']],
        ];
    }

    public function test_an_attachment_bigger_than_the_configured_limit_is_refused(): void
    {
        config(['saraban.attachments.max_size_kb' => 64]);

        $this->actingAs($this->registrar)
            ->post('/api/saraban/documents', $this->documentPayload([
                'files' => [UploadedFile::fake()->create('ใหญ่เกิน.pdf', 128, 'application/pdf')],
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0');
    }

    /** ผู้ที่ไม่เกี่ยวข้องกับหนังสือดาวน์โหลดไฟล์แนบไม่ได้ แม้จะรู้เลข id */
    public function test_download_still_enforces_the_document_policy(): void
    {
        $document = $this->makeDocument(['allow_download' => true, 'created_by' => $this->registrar->id]);

        Storage::disk(StoredFile::DISK)->put('saraban/attachments/x.pdf', 'pdf-bytes');

        $attachment = DocumentAttachment::create([
            'document_id' => $document->id,
            'name' => 'x.pdf',
            'type' => 'file',
            'path' => 'saraban/attachments/x.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->registrar->id,
        ]);

        $this->actingAs($this->outsider)
            ->get("/api/saraban/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    /** ไฟล์ที่หายไปจากดิสก์ต้องเป็น 404 ไม่ใช่ 500 */
    public function test_a_missing_file_on_disk_answers_404(): void
    {
        $document = $this->makeDocument(['allow_download' => true, 'created_by' => $this->registrar->id]);

        $attachment = DocumentAttachment::create([
            'document_id' => $document->id,
            'name' => 'gone.pdf',
            'type' => 'file',
            'path' => 'saraban/attachments/gone.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->registrar->id,
        ]);

        $this->actingAs($this->registrar)
            ->get("/api/saraban/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function documentPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'หนังสือทดสอบไฟล์แนบ',
            'type_id' => $this->type->id,
            'department_id' => $this->department->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
        ], $overrides);
    }
}
