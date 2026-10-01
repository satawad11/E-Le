<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\Policy;
use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowTask;
use App\Services\Operations\ComplianceEvidence;
use App\Services\Operations\ExceptionQueue;
use App\Services\Saraban\PolicyCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-20 — ตรวจความปลอดภัย สมรรถนะ และหลักฐานการปฏิบัติตามข้อกำหนด
 * (SoT §20, §23, §26)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **IDOR** — ทุก endpoint ที่รับ id ของทรัพยากร ต้องตรวจสิทธิ์รายชิ้น
 *   2. **ไฟล์อยู่บน private storage เสมอ** และเข้าถึงได้เฉพาะผ่านด่านสิทธิ์
 *   3. **ตรวจชนิดไฟล์จาก magic byte ไม่ใช่จากนามสกุล**
 *   4. **ทุก endpoint ที่เปลี่ยนแปลงข้อมูลมี rate limit**
 *   5. **ห้าม claim PASS โดยไม่มี evidence จริง** (SoT §23)
 *   6. **แจ้งเตือนเมื่องานตามเวลาหยุดทำงาน** (ปิด RISK-46)
 */
class SarabanSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private OrgUnit $unit;

    private OrgUnit $otherUnit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unit = $this->orgUnit('UNIT-SEC', 'ฝ่ายอำนวยการ');
        $this->otherUnit = $this->orgUnit('UNIT-SEC-OTHER', 'ฝ่ายวิชาการ');

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    private function orgUnit(string $code, string $name): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions, ?int $orgUnitId = null): User
    {
        $user = User::factory()->create([
            'org_unit_id' => $orgUnitId ?? $this->unit->id,
            'password' => Hash::make(self::PASSWORD),
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill(['last_authenticated_at' => now()])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    private function realPdf(): string
    {
        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<<>>>>endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    }

    private function documentOwnedBy(int $orgUnitId): Document
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create'], $orgUnitId);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'เรื่องทดสอบความปลอดภัย',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $orgUnitId,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        return Document::findOrFail($created['id']);
    }

    // ── IDOR (SoT §26) ──────────────────────────────────────────────────────

    /**
     * ผู้ที่ **มีสิทธิ์ระดับความสามารถครบ** แต่อยู่นอกขอบเขต ต้องเข้าไม่ถึง
     *
     * นี่คือรูปแบบ IDOR ที่อันตรายที่สุด — ด่าน `ability:` ผ่าน แต่ต้องถูก
     * ปฏิเสธที่ policy รายชิ้น · ถ้า endpoint ใดลืมเรียก `authorize()`
     * มันจะหลุดตรงนี้
     */
    public function test_a_fully_permissioned_outsider_cannot_reach_another_units_document(): void
    {
        $document = $this->documentOwnedBy($this->otherUnit->id);

        $outsider = $this->userWith([
            'saraban.document.view',
            'saraban.document.update',
            'saraban.incoming.stamp',
            'saraban.endorse.create',
            'saraban.distribution.track',
        ], $this->unit->id);

        $this->actAs($outsider);

        $blocked = [
            ['GET', "/api/saraban/documents/{$document->id}"],
            ['GET', "/api/saraban/documents/{$document->id}/annotations"],
            ['GET', "/api/saraban/documents/{$document->id}/evidence"],
            ['GET', "/api/saraban/documents/{$document->id}/distributions"],
            ['GET', "/api/saraban/documents/{$document->id}/redactions"],
            ['GET', "/api/saraban/documents/{$document->id}/retention"],
        ];

        foreach ($blocked as [$method, $uri]) {
            /*
             * 403 หรือ 404 ก็ได้ — บาง endpoint ตอบ 404 โดยตั้งใจเพื่อไม่ยืนยัน
             * ว่าทรัพยากรนั้นมีอยู่จริง · สิ่งที่ต้องไม่เกิดคือ 2xx
             */
            $status = $this->json($method, $uri)->getStatusCode();

            $this->assertContains(
                $status,
                [403, 404],
                "{$method} {$uri} ตอบ {$status} — ผู้ที่อยู่นอกขอบเขตต้องเข้าไม่ถึง",
            );
        }
    }

    // ── ชั้นความลับตามระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544 ────

    /**
     * **หนังสือลับต้องไม่เปิดได้ด้วยสิทธิ์ทะเบียนธรรมดา**
     *
     * ที่มา (การตรวจทาน 2026-09-05): `DocumentPolicy` ไม่เคยดูชั้นความลับเลย
     * หนังสือชั้นลับที่สุดกับหนังสือทั่วไปถูกตัดสินด้วยเกณฑ์เดียวกันทุกประการ
     * ทั้งที่ฟิลด์ชั้นความลับมีอยู่ในข้อมูลตั้งแต่ INT-05
     */
    public function test_a_classified_document_needs_clearance_not_just_registry_access(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'secret'])->save();

        // อยู่ในหน่วยงานเดียวกัน มีสิทธิ์ทะเบียนครบ แต่ไม่ได้รับอนุญาตชั้นความลับ
        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));
        $this->getJson("/api/saraban/documents/{$document->id}")->assertForbidden();

        $this->actAs($this->userWith(
            ['saraban.document.view', 'saraban.secret.view'],
            $this->unit->id,
        ));

        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();
    }

    /**
     * **ผู้ได้รับอนุญาตชั้นสูงกว่า เข้าถึงชั้นที่ต่ำกว่าได้** (ระเบียบฯ ข้อ 26)
     *
     * ถ้าไม่มีข้อนี้ ผู้ที่ได้รับอนุญาตชั้นลับที่สุดจะเปิดหนังสือชั้นลับธรรมดาไม่ได้
     * ซึ่งขัดกับการปฏิบัติจริง และจะบังคับให้ต้องแจกสิทธิ์ทั้งสามข้อให้คนเดียวกันเสมอ
     */
    public function test_clearance_for_a_higher_level_covers_the_levels_below_it(): void
    {
        $cleared = $this->userWith(
            ['saraban.document.view', 'saraban.top_secret.view'],
            $this->unit->id,
        );

        foreach (['confidential', 'secret', 'top_secret'] as $level) {
            $document = $this->documentOwnedBy($this->unit->id);
            $document->forceFill(['confidentiality' => $level])->save();

            $this->actAs($cleared);
            $this->getJson("/api/saraban/documents/{$document->id}")
                ->assertOk();
        }
    }

    /** แต่ชั้นต่ำกว่าไม่เปิดชั้นที่สูงกว่า — ลำดับต้องเดินทางเดียว */
    public function test_clearance_for_a_lower_level_does_not_unlock_the_levels_above_it(): void
    {
        $cleared = $this->userWith(
            ['saraban.document.view', 'saraban.confidential.view'],
            $this->unit->id,
        );

        foreach (['secret', 'top_secret'] as $level) {
            $document = $this->documentOwnedBy($this->unit->id);
            $document->forceFill(['confidentiality' => $level])->save();

            $this->actAs($cleared);
            $this->getJson("/api/saraban/documents/{$document->id}")
                ->assertForbidden();
        }
    }

    /**
     * **ผู้เกี่ยวข้องในเรื่องเข้าถึงเรื่องนั้นได้ แม้เป็นหนังสือลับที่สุด**
     *
     * หลักความจำเป็นต้องรู้ (ระเบียบฯ ข้อ 26) — ผู้ที่ได้รับมอบหมายให้พิจารณา
     * เรื่องหนึ่ง ย่อมเข้าถึงเรื่องนั้นได้ แม้ไม่ได้รับอนุญาตชั้นความลับไว้ล่วงหน้า
     * และแม้อยู่นอกขอบเขตหน่วยงาน
     *
     * ถ้าไม่มีข้อนี้ หนังสือลับที่เดินมาถึงโต๊ะใครตามสายงาน จะกลายเป็นหนังสือ
     * ที่เขาเปิดไม่ได้ทั้งที่ต้องเป็นผู้พิจารณา — งานค้างทันที
     */
    public function test_someone_working_on_the_matter_can_open_it_whatever_its_level(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'top_secret'])->save();

        // อยู่คนละหน่วยงาน ไม่ได้รับอนุญาตชั้นความลับใดเลย
        $reviewer = $this->userWith(['saraban.document.view'], $this->otherUnit->id);

        $this->actAs($reviewer);
        $this->getJson("/api/saraban/documents/{$document->id}")->assertForbidden();

        // เรื่องเดินมาถึงเขาตามสายงาน — ตั้งแต่นั้นต้องเปิดอ่านได้
        $instance = WorkflowInstance::create([
            'uuid' => (string) Str::uuid(),
            'workflow_version_id' => $this->workflowVersionId(),
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        WorkflowTask::create([
            'uuid' => (string) Str::uuid(),
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $this->workflowStepId(),
            'status' => 'pending',
            'assignee_user_id' => $reviewer->id,
        ]);

        $this->actAs($reviewer);
        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();
    }

    /** แต่ผู้ที่ไม่เกี่ยวข้องเลยยังเข้าไม่ถึง — ด่านไม่ได้เปิดกว้างให้ทุกคน */
    public function test_someone_with_no_part_in_the_matter_still_cannot_open_it(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'top_secret'])->save();

        $instance = WorkflowInstance::create([
            'uuid' => (string) Str::uuid(),
            'workflow_version_id' => $this->workflowVersionId(),
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        WorkflowTask::create([
            'uuid' => (string) Str::uuid(),
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $this->workflowStepId(),
            'status' => 'pending',
            'assignee_user_id' => $this->userWith([], $this->unit->id)->id,
        ]);

        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));
        $this->getJson("/api/saraban/documents/{$document->id}")->assertForbidden();
    }

    private function workflowVersionId(): int
    {
        return (int) DB::table('workflow_versions')->value('id');
    }

    private function workflowStepId(): int
    {
        return (int) DB::table('workflow_steps')->value('id');
    }

    /**
     * **ทะเบียนการเข้าถึงข้อมูลข่าวสารลับ** (ระเบียบฯ หมวด 4)
     *
     * ระเบียบกำหนดให้บันทึกว่าใครเข้าถึงเอกสารลับเมื่อใด · บันทึกลงห่วงโซ่ audit
     * เดียวกับการกระทำอื่น จึงแก้ย้อนหลังไม่ได้
     */
    public function test_opening_a_classified_document_is_written_into_the_access_register(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'secret'])->save();

        $reader = $this->userWith(
            ['saraban.document.view', 'saraban.secret.view'],
            $this->unit->id,
        );

        $this->actAs($reader);
        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.document.classified_accessed',
            'resource_id' => (string) $document->id,
            'actor_id' => $reader->id,
        ]);
    }

    /** การเปิดหนังสือทั่วไปไม่ต้องเข้าทะเบียน — ไม่งั้นทะเบียนท่วมจนหาของจริงไม่เจอ */
    public function test_opening_an_ordinary_document_is_not_written_into_the_access_register(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);

        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));
        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'saraban.document.classified_accessed',
            'resource_id' => (string) $document->id,
        ]);
    }

    /**
     * **การถูกปฏิเสธไม่ใช่การเข้าถึง** — ทะเบียนต้องมีแต่การเข้าถึงที่เกิดขึ้นจริง
     *
     * ถ้าบันทึกความพยายามที่ถูกปฏิเสธปนเข้าไปด้วย ทะเบียนจะตอบคำถามว่า
     * "ใครเคยอ่านเอกสารฉบับนี้บ้าง" ผิด ซึ่งเป็นคำถามหลักที่ระเบียบต้องการ
     */
    public function test_a_refused_attempt_does_not_appear_in_the_access_register(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'top_secret'])->save();

        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));
        $this->getJson("/api/saraban/documents/{$document->id}")->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'saraban.document.classified_accessed',
            'resource_id' => (string) $document->id,
        ]);
    }

    /**
     * ชั้นความลับที่ยังไม่มีใครกำหนดกติกา = **ปฏิเสธ** ไม่ใช่ปล่อยผ่าน
     *
     * ชั้นใหม่ที่ถูกเพิ่มเข้าข้อมูลโดยไม่มีใครแก้นโยบายให้ครบ ต้องไม่กลายเป็น
     * ชั้นที่เปิดกว้างที่สุดในระบบโดยอัตโนมัติ
     */
    public function test_a_level_the_policy_does_not_describe_is_denied(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'cosmic'])->save();

        $this->actAs($this->userWith([
            'saraban.document.view',
            'saraban.confidential.view',
            'saraban.secret.view',
            'saraban.top_secret.view',
        ], $this->unit->id));

        $this->getJson("/api/saraban/documents/{$document->id}")->assertForbidden();
    }

    /** หนังสือทั่วไปต้องไม่ถูกด่านใหม่นี้ปิดกั้น — ด่านเพิ่ม ไม่ใช่ด่านแทน */
    public function test_an_ordinary_document_is_unaffected_by_the_clearance_gate(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);

        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));

        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();
    }

    /**
     * กติกาชั้นความลับ **แก้ได้จากศูนย์นโยบาย** ไม่ได้ฝังในโค้ด (SoT §18)
     *
     * เป็นข้อบังคับของ SoT §18 โดยตรง — ถ้าแก้ไม่ได้จากนโยบาย แปลว่าเรายัด
     * กฎราชการของหน่วยงานหนึ่งลงไปในโค้ดของทุกหน่วยงาน
     */
    public function test_the_clearance_rule_comes_from_the_policy_centre(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $document->forceFill(['confidentiality' => 'secret'])->save();

        $officer = $this->userWith(['saraban.policy.manage', 'saraban.document.view'], $this->unit->id);
        $policies = app(PolicyCenter::class);

        $policies->draft(Policy::KEY_CONFIDENTIALITY, [
            'levels' => [
                'normal' => ['rank' => 0, 'label' => 'ไม่ลับ', 'permission' => null],
                // หน่วยงานนี้ถือว่าชั้นลับมากเป็นเรื่องภายในที่ทุกคนในทะเบียนดูได้
                'secret' => ['rank' => 0, 'label' => 'ลับมาก', 'permission' => null],
            ],
            'owner_and_recipient_bypass' => true,
            'log_access_from_rank' => 1,
        ], $officer, 'ผ่อนกติกาชั้นลับมากตามระเบียบของหน่วยงาน');

        $policies->publish(Policy::KEY_CONFIDENTIALITY, $officer);

        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));
        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();
    }

    /** ไฟล์ของหนังสือที่ตนเข้าไม่ถึง ต้องเปิดไม่ได้เช่นกัน */
    public function test_the_file_of_an_unreachable_document_cannot_be_downloaded(): void
    {
        $document = $this->documentOwnedBy($this->otherUnit->id);
        $path = $document->currentVersion->pdf_file_path;

        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));

        $this->get('/api/files/'.$path)->assertForbidden();
    }

    /** ไฟล์ทั้งหมดต้องอยู่บน private disk — ไม่มีทางเปิดตรงผ่าน URL สาธารณะ */
    public function test_document_files_are_stored_on_the_private_disk_only(): void
    {
        $document = $this->documentOwnedBy($this->unit->id);
        $path = $document->currentVersion->pdf_file_path;

        Storage::disk('private_uploads')->assertExists($path);
        $this->assertStringNotContainsString('public', $path);
        $this->assertStringNotContainsString('http', $path);
    }

    // ── การตรวจชนิดไฟล์ (SoT §26) ───────────────────────────────────────────

    /**
     * นามสกุล `.pdf` ไม่ใช่หลักฐานว่าเป็น PDF — ต้องตรวจจากเนื้อไฟล์จริง
     *
     * **ใช้ไฟล์จริงบนดิสก์ ไม่ใช่ `UploadedFile::fake()`** — ไฟล์ปลอมของ Laravel
     * ประกาศ MIME ตามนามสกุลที่ตั้งให้ ทำให้เทสต์ที่ใช้มันวัด "ของปลอม"
     * ไม่ใช่ตัวกติกา · ไฟล์จริงทำให้ `mimetypes:` เรียก finfo อ่านเนื้อไฟล์จริง
     */
    public function test_a_file_that_only_pretends_to_be_a_pdf_is_refused(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($author);

        $path = tempnam(sys_get_temp_dir(), 'fake-pdf-');
        file_put_contents($path, "<?php echo 'evil'; ?>\n".str_repeat('A', 512));

        try {
            $this->post('/api/saraban/documents', [
                'document_type_id' => $this->type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'ไฟล์ปลอม',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unit->id,
                // สคริปต์จริงที่ตั้งชื่อเป็น .pdf
                'file' => new UploadedFile($path, 'ห.pdf', null, null, true),
            ])->assertStatus(422);
        } finally {
            @unlink($path);
        }
    }

    /** ไฟล์ PDF จริงต้องผ่าน — ไม่งั้นเทสต์ข้างบนอาจผ่านด้วยเหตุผลที่ผิด */
    public function test_a_genuine_pdf_is_accepted(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($author);

        $path = tempnam(sys_get_temp_dir(), 'real-pdf-');
        file_put_contents($path, $this->realPdf());

        try {
            $this->post('/api/saraban/documents', [
                'document_type_id' => $this->type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'ไฟล์จริง',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unit->id,
                'file' => new UploadedFile($path, 'ห.pdf', null, null, true),
            ])->assertCreated();
        } finally {
            @unlink($path);
        }
    }

    // ── การจำกัดอัตรา (SoT §26) ─────────────────────────────────────────────

    /**
     * ทุก endpoint ของสารบรรณต้องอยู่ใต้ throttle
     *
     * กลุ่มใหญ่ใช้ `throttle:api` อยู่แล้ว — เทสต์นี้กันไม่ให้เช็คพอยต์ถัดไป
     * เผลอวาง route ไว้นอกกลุ่มนั้น
     */
    public function test_every_saraban_route_is_rate_limited(): void
    {
        $unlimited = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/saraban/')
                || str_starts_with($route->uri(), 'api/v1/saraban/'))
            ->reject(fn ($route) => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware) => str_starts_with($middleware, 'throttle:')))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unlimited);
    }

    /** ทุก endpoint ที่เปลี่ยนแปลงข้อมูลต้องมีด่านสิทธิ์ ไม่ใช่แค่ยืนยันตัวตน */
    public function test_every_writing_saraban_route_has_an_authorization_gate(): void
    {
        $ungated = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/saraban/'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            /*
             * ยกเว้นสองกลุ่มที่ขอบเขตมาจากความเป็นเจ้าของ ไม่ใช่จาก permission
             * — มุมมองที่บันทึกไว้ (ของผู้เรียกเอง) และกล่องหนังสือเข้า
             *   (มีด่าน `saraban.inbox.view` อยู่แล้วแต่ผูกที่ระดับกลุ่ม)
             */
            ->reject(fn ($route) => str_contains($route->uri(), 'saved-views'))
            ->reject(fn ($route) => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware) => str_starts_with($middleware, 'ability:')
                    || str_starts_with($middleware, 'can:')))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $ungated);
    }

    // ── Observability · ปิด RISK-46 (SoT §20) ───────────────────────────────

    /**
     * งานตามเวลาที่หยุดทำงานต้องกลายเป็น **งานที่มีเจ้าของ** ไม่ใช่แค่ตัวเลข
     * บนหน้าจอที่ไม่มีใครเปิดดู
     */
    public function test_a_stale_scheduled_task_is_raised_into_the_exception_queue(): void
    {
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        ScheduledTaskHeartbeat::query()
            ->where('task_key', 'notifications:dispatch-outbox')
            ->update(['last_run_at' => now()->subHours(3)]);

        $this->artisan('operations:check-heartbeats')->assertSuccessful();

        $this->assertDatabaseHas('system_exceptions', [
            'type' => 'scheduler.task_stale',
            'severity' => SystemException::SEVERITY_CRITICAL,
        ]);
    }

    public function test_a_healthy_scheduled_task_raises_nothing(): void
    {
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();
        $this->artisan('operations:check-heartbeats')->assertSuccessful();

        $this->assertSame(0, SystemException::query()->where('type', 'scheduler.task_stale')->count());
    }

    // ── ทะเบียนหลักฐาน (SoT §23) ────────────────────────────────────────────

    /**
     * **ข้อบังคับที่สำคัญที่สุดของ §23** — ไม่มีทางที่หัวข้อซึ่งตรวจอัตโนมัติ
     * ไม่ได้ จะกลายเป็น PASS
     */
    public function test_topics_that_need_a_human_can_never_report_pass(): void
    {
        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');

        foreach (['restore', 'disaster_recovery', 'uat', 'accessibility'] as $key) {
            $this->assertSame(
                ComplianceEvidence::NO_EVIDENCE,
                $topics[$key]['status'],
                "หัวข้อ {$key} ต้องเป็น NO_EVIDENCE เสมอ เพราะระบบตรวจแทนคนไม่ได้",
            );
            $this->assertNotEmpty($topics[$key]['method']);
        }
    }

    /** ทุกหัวข้อต้องมี "วิธีตรวจ" และ "หลักฐานดิบ" ให้ผู้ตรวจสอบเห็น */
    public function test_every_topic_states_how_it_was_checked(): void
    {
        foreach (app(ComplianceEvidence::class)->collect() as $topic) {
            $this->assertArrayHasKey('evidence', $topic);
            $this->assertNotEmpty($topic['method'], "หัวข้อ {$topic['key']} ต้องบอกวิธีตรวจ");
            $this->assertContains($topic['status'], [
                ComplianceEvidence::PASS,
                ComplianceEvidence::PARTIAL,
                ComplianceEvidence::FAIL,
                ComplianceEvidence::NO_EVIDENCE,
                ComplianceEvidence::NOT_APPLICABLE,
            ]);
        }
    }

    /** ห่วงโซ่ audit ที่ถูกแก้ต้องรายงาน FAIL ไม่ใช่ PASS */
    public function test_a_tampered_audit_chain_reports_fail(): void
    {
        $this->documentOwnedBy($this->unit->id);

        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');
        $this->assertSame(ComplianceEvidence::PASS, $topics['audit']['status']);

        // แก้ไขห่วงโซ่โดยตรงที่ฐานข้อมูล
        DB::table('audit_logs')
            ->orderByDesc('id')
            ->limit(1)
            ->update(['previous_hash' => str_repeat('0', 64)]);

        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');
        $this->assertSame(ComplianceEvidence::FAIL, $topics['audit']['status']);
    }

    /** ข้อผิดพลาดที่ค้างอยู่ต้องทำให้หัวข้อ incidents เป็น FAIL */
    public function test_open_incidents_make_the_register_report_fail(): void
    {
        app(ExceptionQueue::class)->raise(
            type: 'test.failure',
            title: 'มีบางอย่างล้มเหลว',
            dedupeKey: 'test.failure',
        );

        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');

        $this->assertSame(ComplianceEvidence::FAIL, $topics['incidents']['status']);
        $this->assertSame(1, $topics['incidents']['evidence']['open_exceptions']);
    }

    public function test_the_compliance_register_requires_a_permission(): void
    {
        $this->actAs($this->userWith([]));
        $this->getJson('/api/admin/operations/compliance')->assertForbidden();

        $this->actAs($this->userWith(['system.operations.view']));
        $this->getJson('/api/admin/operations/compliance')->assertOk();
    }

    /** ทะเบียนหลักฐานต้องอ่านอย่างเดียว — ไม่มีเส้นทางตั้งสถานะเอง */
    public function test_no_route_can_write_a_compliance_status(): void
    {
        $writable = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'compliance'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $writable);
    }

    // ── สมรรถนะ (SoT §26) ───────────────────────────────────────────────────

    /** ดัชนีที่ query จริงเรียกใช้ต้องมีอยู่ */
    public function test_the_indexes_the_hot_queries_need_exist(): void
    {
        $connection = DB::connection();

        foreach ([
            'saraban_documents' => ['saraban_documents_created_by_index', 'saraban_documents_status_finalized_index'],
            'saraban_distribution_recipients' => ['saraban_recipient_ack_index'],
        ] as $table => $expected) {
            $names = collect($connection->getSchemaBuilder()->getIndexes($table))->pluck('name');

            foreach ($expected as $index) {
                $this->assertTrue($names->contains($index), "ตาราง {$table} ต้องมีดัชนี {$index}");
            }
        }
    }

    /** รายการหนังสือต้อง eager load — ไม่งั้นเป็น N+1 ที่โตตามจำนวนแถว */
    public function test_the_document_list_does_not_run_a_query_per_row(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);

        foreach (range(1, 5) as $ignored) {
            $this->documentOwnedBy($this->unit->id);
        }

        $this->actAs($author);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/api/saraban/documents')->assertOk();

        /*
         * เกณฑ์หลวม ๆ โดยตั้งใจ — สิ่งที่ต้องจับคือ "จำนวน query โตตามจำนวนแถว"
         * ไม่ใช่ตัวเลขที่แน่นอน ซึ่งจะพังทุกครั้งที่มีการเพิ่ม middleware
         */
        $this->assertLessThan(25, $queries, "รายการหนังสือใช้ query {$queries} ครั้ง — น่าจะมี N+1");
    }
}
