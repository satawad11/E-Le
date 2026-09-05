<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\IncomingRegistration;
use App\Models\User;
use App\Services\TrustedClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-07 — ทะเบียนรับหนังสือ (SoT §10)
 *
 * เรื่องที่ตรึงไว้ เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **เลข/วันที่ของต้นทาง ต้องแยกจากเลขทะเบียนรับ/วันเวลาที่รับ**
 *      ถ้าปนกันจะตรวจสอบย้อนหลังไม่ได้ว่าเลขไหนเป็นของใคร
 *   2. **วันเวลาที่รับต้องมาจากเวลาอ้างอิงของระบบ** ไม่ใช่ค่าที่ผู้กรอกพิมพ์เอง
 *   3. **ตรวจหนังสือซ้ำก่อนรับ** แต่ให้คนตัดสิน ไม่ใช่ระบบปฏิเสธเอง
 *   4. **ทุกฉบับได้เลขทะเบียนรับที่ออกแล้ว (ISSUED) ไม่ซ้ำกัน**
 */
class SarabanIncomingRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-INCOMING',
            'name' => 'งานสารบรรณกลาง',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'incoming'],
            ['name' => 'หนังสือรับ', 'category' => DocumentType::CATEGORY_INCOMING],
        );
    }

    protected function tearDown(): void
    {
        TrustedClock::release();

        parent::tearDown();
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['org_unit_id' => $this->unit->id]);

        foreach (['saraban.document.view', 'saraban.document.create', 'saraban.incoming.register'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'document_type_id' => $this->type->id,
            'subject' => 'ขอความอนุเคราะห์วิทยากร',
            'summary' => 'หนังสือจากสำนักงานเขต',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'sender_name' => 'สำนักงานเขตพื้นที่การศึกษา',
            'source_number' => 'ศธ 04002/1234',
            'source_date' => '2026-09-01',
        ], $overrides);
    }

    // ── การแยกข้อมูลต้นทางออกจากข้อมูลการรับ ────────────────────────────────

    /**
     * ข้อที่สำคัญที่สุดของเช็คพอยต์นี้
     *
     * เลขที่หนังสือต้นทาง (ผู้ส่งออก) กับเลขทะเบียนรับ (เราออก) ต้องอยู่คนละช่อง
     * และมีค่าคนละค่า มิฉะนั้นจะตอบไม่ได้ว่าเลขไหนเป็นของใคร
     */
    public function test_the_source_number_and_the_register_number_are_kept_apart(): void
    {
        $registrar = $this->registrar();

        $response = $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming', $this->payload())
            ->assertCreated();

        $registration = IncomingRegistration::findOrFail($response->json('id'));

        // ของต้นทาง — เก็บตามที่ผู้ส่งออกมา
        $this->assertSame('ศธ 04002/1234', $registration->source_number);
        $this->assertSame('2026-09-01', $registration->source_date->toDateString());
        $this->assertSame('สำนักงานเขตพื้นที่การศึกษา', $registration->sender_name);

        // ของเรา — เลขทะเบียนรับที่ระบบออกให้ ต้องคนละค่ากับเลขต้นทาง
        $this->assertNotNull($registration->document_number_id);
        $this->assertNotSame($registration->source_number, $registration->registerNumber->formatted);
        $this->assertSame(DocumentNumber::STATUS_ISSUED, $registration->registerNumber->status);
    }

    /**
     * วันเวลาที่รับต้องมาจากเวลาอ้างอิงของระบบ (SoT §10)
     *
     * ถ้ารับค่าจากผู้กรอกได้ ทะเบียนรับจะถูกย้อนวันได้ ซึ่งทำลายคุณค่าของทะเบียน
     * ในฐานะหลักฐาน
     */
    public function test_the_received_time_comes_from_the_trusted_clock_not_from_the_request(): void
    {
        TrustedClock::freeze(Carbon::parse('2026-09-04 09:15:00'));

        $response = $this->actingAs($this->registrar())
            ->postJson('/api/saraban/incoming', $this->payload([
                // พยายามกำหนดเวลารับเอง — ต้องไม่มีผล
                'received_at' => '2020-01-01 00:00:00',
            ]))
            ->assertCreated();

        $registration = IncomingRegistration::findOrFail($response->json('id'));

        $this->assertSame('2026-09-04 09:15:00', $registration->received_at->format('Y-m-d H:i:s'));
    }

    // ── การตรวจหนังสือซ้ำ ───────────────────────────────────────────────────

    public function test_registering_the_same_source_document_twice_is_flagged_before_it_is_accepted(): void
    {
        $registrar = $this->registrar();

        $this->actingAs($registrar)->postJson('/api/saraban/incoming', $this->payload())->assertCreated();

        $response = $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming', $this->payload())
            ->assertStatus(409);

        $this->assertNotEmpty($response->json('duplicates'));
        $this->assertSame(1, IncomingRegistration::query()->count(), 'ต้องยังไม่ถูกบันทึกจนกว่าผู้ใช้จะยืนยัน');
    }

    /** ระบบเตือน แต่คนเป็นผู้ตัดสิน — การรับซ้ำจริงต้องทำได้เมื่อยืนยันแล้ว */
    public function test_a_confirmed_duplicate_can_still_be_registered(): void
    {
        $registrar = $this->registrar();

        $this->actingAs($registrar)->postJson('/api/saraban/incoming', $this->payload())->assertCreated();

        $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming', $this->payload(['acknowledge_duplicate' => true]))
            ->assertCreated();

        $this->assertSame(2, IncomingRegistration::query()->count());
    }

    public function test_a_document_without_a_source_number_is_not_guessed_to_be_a_duplicate(): void
    {
        $registrar = $this->registrar();

        $payload = $this->payload(['source_number' => null]);

        $this->actingAs($registrar)->postJson('/api/saraban/incoming', $payload)->assertCreated();
        $this->actingAs($registrar)->postJson('/api/saraban/incoming', $payload)->assertCreated();

        $this->assertSame(2, IncomingRegistration::query()->count());
    }

    public function test_the_duplicate_check_can_be_called_before_submitting_the_form(): void
    {
        $registrar = $this->registrar();
        $this->actingAs($registrar)->postJson('/api/saraban/incoming', $this->payload())->assertCreated();

        $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming/duplicates', [
                'sender_name' => 'สำนักงานเขตพื้นที่การศึกษา',
                'source_number' => 'ศธ 04002/1234',
                'source_date' => '2026-09-01',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'duplicates');
    }

    // ── เลขทะเบียนรับ ───────────────────────────────────────────────────────

    public function test_each_registration_gets_its_own_issued_register_number(): void
    {
        $registrar = $this->registrar();

        $first = $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming', $this->payload(['source_number' => 'A/1']))
            ->assertCreated()
            ->json();

        $second = $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming', $this->payload(['source_number' => 'A/2']))
            ->assertCreated()
            ->json();

        $this->assertNotSame(
            $first['register_number']['formatted'],
            $second['register_number']['formatted'],
        );

        $this->assertSame(1, $first['register_number']['running_number']);
        $this->assertSame(2, $second['register_number']['running_number']);
    }

    public function test_the_incoming_document_and_its_original_file_are_stored_together(): void
    {
        $registrar = $this->registrar();

        $response = $this->actingAs($registrar)
            ->post('/api/saraban/incoming', $this->payload([
                'file' => UploadedFile::fake()->createWithContent('รับ.pdf', '%PDF-1.4 หนังสือรับ'),
            ]))
            ->assertCreated();

        $document = Document::findOrFail($response->json('document_id'));

        $this->assertSame(Document::DIRECTION_INCOMING, $document->direction);
        $this->assertSame(Document::STATUS_REGISTERED, $document->status);
        $this->assertCount(1, $document->versions);
        $this->assertSame('original', $document->versions->first()->stage);
        Storage::disk('private_uploads')->assertExists($document->versions->first()->pdf_file_path);
    }

    // ── สิทธิ์และประวัติ ────────────────────────────────────────────────────

    public function test_registering_requires_the_incoming_register_permission(): void
    {
        $viewer = User::factory()->create(['org_unit_id' => $this->unit->id]);
        $viewer->givePermissionTo(Permission::findOrCreate('saraban.document.view', 'web'));

        $this->actingAs($viewer->fresh())
            ->postJson('/api/saraban/incoming', $this->payload())
            ->assertForbidden();
    }

    public function test_an_outsider_cannot_read_the_incoming_register(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/saraban/incoming')
            ->assertForbidden();
    }

    public function test_the_registration_is_written_to_the_central_audit_log(): void
    {
        $registrar = $this->registrar();

        $response = $this->actingAs($registrar)
            ->postJson('/api/saraban/incoming', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.incoming.registered',
            'resource_id' => (string) $response->json('document_id'),
            'actor_id' => $registrar->id,
        ]);
    }
}
