<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\LegalHold;
use App\Models\Saraban\NumberSeries;
use App\Models\Saraban\RetentionSchedule;
use App\Models\User;
use App\Services\Saraban\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-19 — การเก็บรักษา การอายัด การทำลาย และรายงานทะเบียน (SoT §25, §28)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **หนังสือที่ออกไปแล้วลบไม่ได้** — บังคับที่ระดับโมเดล ไม่ใช่แค่ไม่มีปุ่ม
 *   2. **การอายัดชนะกำหนดการทำลายเสมอ**
 *   3. **ทำลายไฟล์ ไม่ทำลายทะเบียน** — พร้อม hash ของสิ่งที่ถูกทำลาย
 *   4. **อนุมัติกับลงมือทำลายเป็นคนละการกระทำ** (separation of duties)
 *   5. **รายงานถูกจำกัดด้วยขอบเขตเหมือนหน้าทะเบียนปกติ**
 */
class SarabanRetentionAndReportsTest extends TestCase
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
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = $this->orgUnit('UNIT-RET', 'ฝ่ายอำนวยการ');
        $this->otherUnit = $this->orgUnit('UNIT-RET-OTHER', 'ฝ่ายวิชาการ');

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

    private function createDocument(?int $orgUnitId = null): Document
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create'], $orgUnitId);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'เรื่องทดสอบการเก็บรักษา',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $orgUnitId ?? $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        return Document::findOrFail($created['id']);
    }

    private function officer(): User
    {
        return $this->userWith([
            'saraban.document.view',
            'saraban.config.manage',
            'saraban.policy.manage',
        ]);
    }

    // ── หนังสือที่ออกแล้วห้ามลบ (SoT §25) ───────────────────────────────────

    /** "ไม่มีปุ่มลบ" ไม่เท่ากับ "ลบไม่ได้" — ด่านต้องอยู่ที่โมเดล */
    public function test_an_issued_document_cannot_be_deleted_even_from_code(): void
    {
        $document = $this->createDocument();
        $document->forceFill(['status' => Document::STATUS_SIGNED, 'finalized_at' => now()])->save();

        $this->expectException(RuntimeException::class);
        $document->delete();
    }

    public function test_a_draft_can_still_be_deleted(): void
    {
        $document = $this->createDocument();

        $document->delete();

        $this->assertNull(Document::query()->find($document->id));
    }

    // ── การอายัด (SoT §25) ──────────────────────────────────────────────────

    public function test_placing_and_releasing_a_hold_is_recorded_and_audited(): void
    {
        $document = $this->createDocument();
        $officer = $this->officer();
        $this->actAs($officer);

        $this->postJson("/api/saraban/documents/{$document->id}/holds", [
            'reference' => 'คดีหมายเลข 123/2569',
            'reason' => 'อยู่ระหว่างการสอบสวนข้อเท็จจริง',
        ])->assertCreated();

        $this->assertTrue(app(RetentionService::class)->isOnHold($document));

        $hold = LegalHold::query()->firstOrFail();
        $this->postJson("/api/saraban/holds/{$hold->id}/release", ['note' => 'คดีสิ้นสุดแล้ว'])->assertOk();

        $this->assertFalse(app(RetentionService::class)->isOnHold($document->fresh()));

        // แถวเดิมยังอยู่ — ต้องตอบได้ว่าเคยถูกอายัดช่วงใด
        $this->assertSame(1, LegalHold::query()->count());
        $this->assertNotNull($hold->fresh()->released_at);

        foreach (['saraban.document.hold_placed', 'saraban.document.hold_released'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_a_document_cannot_be_held_twice(): void
    {
        $document = $this->createDocument();
        $this->actAs($this->officer());

        $payload = ['reference' => 'คดี 1/2569', 'reason' => 'อยู่ระหว่างสอบสวน'];

        $this->postJson("/api/saraban/documents/{$document->id}/holds", $payload)->assertCreated();
        $this->postJson("/api/saraban/documents/{$document->id}/holds", $payload)->assertStatus(422);
    }

    // ── การทำลาย (SoT §25, §26) ─────────────────────────────────────────────

    /** **ข้อบังคับที่สำคัญที่สุด** — การอายัดชนะกำหนดการทำลายเสมอ */
    public function test_a_held_document_cannot_be_disposed_even_when_due_and_approved(): void
    {
        $document = $this->createDocument();
        $officer = $this->officer();
        $this->actAs($officer);

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();

        $schedule = RetentionSchedule::query()->firstOrFail();
        $schedule->forceFill(['retain_until' => now()->subDay()->toDateString()])->save();

        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertOk();

        // อายัดหลังจากได้รับอนุมัติแล้ว — ต้องยังทำลายไม่ได้
        $this->postJson("/api/saraban/documents/{$document->id}/holds", [
            'reference' => 'คดี 9/2569',
            'reason' => 'อยู่ระหว่างการสอบสวน',
        ])->assertCreated();

        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertStatus(422);

        $this->assertSame(RetentionSchedule::STATUS_APPROVED, $schedule->fresh()->status);
        Storage::disk('private_uploads')->assertExists($document->currentVersion->pdf_file_path);
    }

    /**
     * **การอายัดที่มาถึงระหว่างการทำลายกำลังทำงานอยู่ ต้องชนะ** (SoT §25)
     *
     * ที่มา: การตรวจทาน 2026-09-05 พบว่า `dispose()` ตรวจการอายัด **นอก**
     * transaction แล้วจึงเปิด transaction ลบไฟล์ · ช่วงระหว่างสองจุดนั้นคือ
     * ช่องที่การอายัดแทรกเข้ามาได้ และ **ไฟล์ที่ถูกลบเรียกคืนไม่ได้**
     *
     * เทสต์นี้แทรกการอายัดเข้าไป "ระหว่างทาง" จริง ๆ ด้วยการดักคิวรีตัวแรก
     * ที่ `dispose()` ยิงหลังเปิด transaction — ถ้าการตรวจยังอยู่นอก transaction
     * เหมือนเดิม ไฟล์จะถูกลบและเทสต์นี้จะล้ม
     *
     * **ข้อจำกัดที่ต้องระบุ:** SQLite ไม่มี row-level lock จริง เทสต์นี้จึงพิสูจน์
     * ว่า **ลำดับการตรวจถูกต้อง** ไม่ได้พิสูจน์ว่าล็อกกันได้จริงภายใต้การแย่งกัน
     * บนฐานข้อมูลจริง — ข้อนั้นพิสูจน์ด้วย `saraban:concurrency-check` บน MySQL
     */
    public function test_a_hold_that_arrives_mid_disposal_still_saves_the_files(): void
    {
        $document = $this->createDocument();
        $officer = $this->officer();
        $this->actAs($officer);

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();

        $schedule = RetentionSchedule::query()->firstOrFail();
        $schedule->forceFill(['retain_until' => now()->subDay()->toDateString()])->save();
        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertOk();

        $path = $document->currentVersion->pdf_file_path;

        /*
         * ผู้แข่งขัน: อายัดเอกสารทันทีที่ `dispose()` เริ่มแตะตารางหนังสือ
         * เขียนด้วยคิวรีดิบ เพื่อไม่ให้เรียกกลับเข้า `placeHold()` ซ้อนกันเอง
         */
        $placed = false;
        DB::listen(function ($query) use ($document, $officer, &$placed) {
            /*
             * เงื่อนไข `transactionLevel() > 0` สำคัญมาก — ทำให้การอายัดถูกแทรก
             * **หลัง transaction เปิดแล้วเท่านั้น** · ถ้าการตรวจการอายัดยังอยู่
             * นอก transaction เหมือนโค้ดเดิม การตรวจจะผ่านไปก่อนที่บรรทัดนี้
             * จะทำงาน แล้วไฟล์จะถูกลบ — ซึ่งคือความล้มเหลวที่เทสต์นี้ต้องจับได้
             */
            if ($placed || DB::transactionLevel() === 0 || ! str_contains($query->sql, 'saraban_')) {
                return;
            }

            $placed = true;

            DB::table('saraban_legal_holds')->insert([
                'uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'reference' => 'คดีที่มาถึงระหว่างทาง',
                'reason' => 'อยู่ระหว่างการสอบสวนข้อเท็จจริง',
                'placed_at' => now(),
                'placed_by' => $officer->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertStatus(422);

        $this->assertTrue($placed, 'ไม่ได้แทรกการอายัดระหว่างทางเลย — เทสต์นี้ไม่ได้ตรวจอะไร');
        Storage::disk('private_uploads')->assertExists($path);
        $this->assertSame(RetentionSchedule::STATUS_APPROVED, $schedule->fresh()->status);
        $this->assertNull($schedule->fresh()->disposed_at);
    }

    public function test_disposal_requires_approval_first(): void
    {
        $document = $this->createDocument();
        $this->actAs($this->officer());

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();
        $schedule = RetentionSchedule::query()->firstOrFail();
        $schedule->forceFill(['retain_until' => now()->subDay()->toDateString()])->save();

        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertStatus(422);
    }

    public function test_disposal_before_the_retention_period_ends_is_refused(): void
    {
        $document = $this->createDocument();
        $this->actAs($this->officer());

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 10])->assertCreated();
        $schedule = RetentionSchedule::query()->firstOrFail();

        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertOk();
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertStatus(422);
    }

    /** ทำลายไฟล์ แต่ทะเบียนต้องอยู่ครบพร้อม hash ของสิ่งที่ถูกทำลาย */
    public function test_disposal_removes_files_but_keeps_the_register(): void
    {
        $document = $this->createDocument();
        $path = $document->currentVersion->pdf_file_path;

        $this->actAs($this->officer());
        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();

        $schedule = RetentionSchedule::query()->firstOrFail();
        $schedule->forceFill(['retain_until' => now()->subDay()->toDateString()])->save();

        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertOk();
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose", ['note' => 'ทำลายตามระเบียบ'])->assertOk();

        Storage::disk('private_uploads')->assertMissing($path);

        // ทะเบียนยังอยู่ครบ
        $this->assertNotNull(Document::query()->find($document->id));
        $schedule->refresh();
        $this->assertSame(RetentionSchedule::STATUS_DISPOSED, $schedule->status);
        $this->assertNotNull($schedule->disposed_file_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.retention.disposed']);
    }

    public function test_disposing_twice_is_refused(): void
    {
        $document = $this->createDocument();
        $this->actAs($this->officer());

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();
        $schedule = RetentionSchedule::query()->firstOrFail();
        $schedule->forceFill(['retain_until' => now()->subDay()->toDateString()])->save();

        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertOk();
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertOk();
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertStatus(422);
    }

    /** SoT §24 — การทำลายเอกสารต้องยืนยันตัวตนซ้ำ */
    public function test_approval_and_disposal_require_recent_authentication(): void
    {
        $document = $this->createDocument();
        $officer = $this->officer();
        $this->actAs($officer);

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();
        $schedule = RetentionSchedule::query()->firstOrFail();

        // โทเคนที่ยืนยันตัวตนไว้นานแล้ว
        $this->app['auth']->forgetGuards();
        $token = $officer->createToken('stale-token');
        $token->accessToken->forceFill(['last_authenticated_at' => now()->subHours(5)])->save();
        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);

        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertStatus(423);
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertStatus(423);
    }

    /** คิวทำลายต้องไม่แสดงเอกสารที่ถูกอายัด — กดไม่ได้อยู่ดี */
    public function test_the_disposal_queue_excludes_held_documents(): void
    {
        $document = $this->createDocument();
        $this->actAs($this->officer());

        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])->assertCreated();
        RetentionSchedule::query()->firstOrFail()->forceFill(['retain_until' => now()->subDay()->toDateString()])->save();

        $this->assertCount(1, $this->getJson('/api/saraban/retention/due')->assertOk()->json('due'));

        $this->postJson("/api/saraban/documents/{$document->id}/holds", [
            'reference' => 'คดี 5/2569',
            'reason' => 'อยู่ระหว่างการสอบสวน',
        ])->assertCreated();

        $this->assertCount(0, $this->getJson('/api/saraban/retention/due')->assertOk()->json('due'));
    }

    /**
     * **สั่งทำลายหนังสือที่ตนเปิดอ่านไม่ได้ ต้องไม่ได้** (SoT §6, §25)
     *
     * ที่มา: การตรวจทานเมื่อ 2026-09-05 พบว่า `dispose()` ตรวจแค่
     * `ability:saraban.config.manage` กับ `step_up` — **ไม่มีด่านรายฉบับเลย** ·
     * ผู้ที่มีสิทธิ์ทะเบียนจึงลบไฟล์ของหนังสือนอกขอบเขตตนได้ ซึ่งย้อนกลับไม่ได้
     */
    public function test_a_document_outside_the_actors_scope_cannot_be_disposed(): void
    {
        $document = $this->createDocument($this->otherUnit->id);

        $this->actAs($this->officer());
        $this->postJson("/api/saraban/documents/{$document->id}/retention", ['retain_years' => 1])
            ->assertForbidden();

        // ตั้งกำหนดการโดยตรง เพื่อพิสูจน์ด่านของ `approve`/`dispose` เองไม่ใช่ของ `schedule`
        $schedule = RetentionSchedule::create([
            'document_id' => $document->id,
            'retain_years' => 1,
            'retain_until' => now()->subDay()->toDateString(),
            'status' => RetentionSchedule::STATUS_APPROVED,
            'approved_by' => $document->created_by,
            'approved_at' => now(),
        ]);

        $path = $document->versions()->firstOrFail()->pdf_file_path;

        $this->postJson("/api/saraban/retention/{$schedule->id}/approve")->assertForbidden();
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")->assertForbidden();

        $this->assertTrue(Storage::disk('private_uploads')->exists($path), 'ไฟล์นอกขอบเขตถูกลบไปแล้ว');
        $this->assertNull($schedule->fresh()->disposed_at);
    }

    /** คิวทำลายก็เป็นช่องค้นพบหนังสือได้เช่นกัน — ต้องกรองด้วยขอบเขตเดียวกัน */
    public function test_the_disposal_queue_excludes_documents_outside_the_readers_scope(): void
    {
        $foreign = $this->createDocument($this->otherUnit->id);

        RetentionSchedule::create([
            'document_id' => $foreign->id,
            'retain_years' => 1,
            'retain_until' => now()->subDay()->toDateString(),
            'status' => RetentionSchedule::STATUS_PROPOSED,
        ]);

        $this->actAs($this->officer());

        $this->assertSame([], $this->getJson('/api/saraban/retention/due')->assertOk()->json('due'));
    }

    // ── รายงานทะเบียน (SoT §28) ─────────────────────────────────────────────

    /** รายงานเป็นทางลัดที่ข้ามขอบเขตได้ง่ายที่สุด — ต้องถูกจำกัดเหมือนหน้าทะเบียน */
    public function test_the_register_report_is_limited_to_the_readers_scope(): void
    {
        $this->createDocument($this->unit->id);
        $this->createDocument($this->otherUnit->id);

        $this->actAs($this->userWith(['saraban.report.view'], $this->unit->id));

        $rows = $this->getJson('/api/saraban/reports/register')->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('ฝ่ายอำนวยการ', $rows[0]['owner_org_unit']);
    }

    /**
     * **กราฟสรุปต้องจำกัดขอบเขตเหมือนทะเบียน**
     *
     * กราฟที่นับหนังสือของหน่วยงานที่ผู้ดูไม่มีสิทธิ์เห็น คือการรั่วข้อมูลแบบที่
     * มองไม่ออกจากตัวเลข แต่บอกได้ว่าหน่วยงานอื่นมีงานมากน้อยเพียงใด
     */
    public function test_the_summary_chart_is_limited_to_the_readers_scope(): void
    {
        $this->createDocument($this->unit->id);
        $this->createDocument($this->otherUnit->id);
        $this->createDocument($this->otherUnit->id);

        $this->actAs($this->userWith(['saraban.report.view'], $this->unit->id));

        $body = $this->getJson('/api/saraban/reports/summary')->assertOk()->json();

        $this->assertSame(1, $body['total'], 'ต้องนับเฉพาะหนังสือในขอบเขตของผู้ดู');
        $this->assertCount(12, $body['monthly'], 'กราฟต้องมีสิบสองเดือนเสมอ แม้เดือนที่ไม่มีหนังสือ');
        $this->assertSame(1, collect($body['monthly'])->sum('internal'));
    }

    /**
     * เดือนที่ไม่มีหนังสือต้องยังอยู่ในกราฟ
     *
     * กราฟที่ข้ามเดือนว่างไป ทำให้แกนเวลาบิดและอ่านแนวโน้มผิด
     */
    public function test_the_summary_chart_keeps_empty_months(): void
    {
        $this->actAs($this->userWith(['saraban.report.view'], $this->unit->id));

        $body = $this->getJson('/api/saraban/reports/summary')->assertOk()->json();

        $this->assertSame(0, $body['total']);
        $this->assertCount(12, $body['monthly']);
        $this->assertSame([], $body['by_status']);
    }

    public function test_the_summary_chart_requires_the_report_permission(): void
    {
        $this->actAs($this->userWith(['saraban.document.view'], $this->unit->id));

        $this->getJson('/api/saraban/reports/summary')->assertForbidden();
    }

    public function test_the_register_report_filters_by_direction_and_date(): void
    {
        $this->createDocument();

        $this->actAs($this->userWith(['saraban.report.view']));

        $this->assertCount(
            1,
            $this->getJson('/api/saraban/reports/register?direction=internal')->assertOk()->json('rows'),
        );
        $this->assertCount(
            0,
            $this->getJson('/api/saraban/reports/register?direction=incoming')->assertOk()->json('rows'),
        );
    }

    /** รายงานเลขหนังสือต้องแสดงทุกสถานะ รวมเลขที่คืนและยกเลิก (SoT §28) */
    public function test_the_number_report_shows_reserved_issued_and_voided_numbers(): void
    {
        $series = NumberSeries::create([
            'code' => 'outgoing-report',
            'name' => 'หนังสือส่งออก',
            'category' => DocumentType::CATEGORY_OUTGOING,
            'prefix' => 'ศธ 04001',
        ]);

        foreach (['reserved', 'issued', 'released'] as $index => $status) {
            DocumentNumber::create([
                'number_series_id' => $series->id,
                'buddhist_year' => 2569,
                'running_number' => $index + 1,
                'formatted' => 'ศธ 04001/'.($index + 1),
                'status' => $status,
            ]);
        }

        $this->actAs($this->userWith(['saraban.report.view']));

        $body = $this->getJson('/api/saraban/reports/numbers')->assertOk()->json();

        $this->assertSame(3, array_sum($body['summary']));
        $this->assertArrayHasKey('released', $body['summary']);
    }

    /** SoT §28 — ผู้บริหารต้องแยกออกระหว่าง "ยังไม่เปิดอ่าน" กับ "ยังไม่รับทราบ" */
    public function test_the_distribution_report_separates_unopened_from_unacknowledged(): void
    {
        $document = $this->createDocument();
        $document->forceFill(['status' => Document::STATUS_SIGNED, 'finalized_at' => now()])->save();

        $sender = $this->userWith(['saraban.document.view', 'saraban.distribution.send']);
        $recipientA = $this->userWith(['saraban.inbox.view']);
        $recipientB = $this->userWith(['saraban.inbox.view']);

        $this->actAs($sender);
        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [
                ['type' => 'user', 'id' => $recipientA->id],
                ['type' => 'user', 'id' => $recipientB->id],
            ],
        ])->assertCreated();

        // คนแรกเปิดอ่านแต่ไม่รับทราบ
        $rowA = DistributionRecipient::query()->where('recipient_id', $recipientA->id)->firstOrFail();
        $this->actAs($recipientA);
        $this->getJson("/api/saraban/inbox/{$rowA->id}")->assertOk();

        $this->actAs($this->userWith(['saraban.report.view']));

        $report = $this->getJson('/api/saraban/reports/distribution')->assertOk()->json();

        $this->assertSame(2, $report['total_recipients']);
        $this->assertSame(0, $report['acknowledged']);
        $this->assertSame(2, $report['not_acknowledged']);
        // มีคนหนึ่งเปิดอ่านแล้ว จึงเหลือ "ยังไม่เปิดเลย" หนึ่งคน
        $this->assertSame(1, $report['never_opened']);
    }

    public function test_reports_require_the_report_permission(): void
    {
        $this->actAs($this->userWith(['saraban.document.view']));

        $this->getJson('/api/saraban/reports/register')->assertForbidden();
        $this->getJson('/api/saraban/reports/numbers')->assertForbidden();
    }

    public function test_the_register_report_can_be_exported_with_permission(): void
    {
        $this->createDocument();

        // ต้องมีทั้ง saraban.report.view และ saraban.report.export
        $userWithoutExport = $this->userWith(['saraban.report.view']);
        $this->actAs($userWithoutExport);
        $this->get('/api/saraban/reports/export?type=register&format=csv')->assertForbidden();

        $userWithExport = $this->userWith(['saraban.report.view', 'saraban.report.export']);
        $this->actAs($userWithExport);

        $responseCsv = $this->get('/api/saraban/reports/export?type=register&format=csv');
        $responseCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $responseCsv->headers->get('Content-Type'));
        $this->assertStringContainsString('เลขที่หนังสือ', $responseCsv->getContent());

        $responseXlsx = $this->get('/api/saraban/reports/export?type=register&format=xlsx');
        $responseXlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $responseXlsx->headers->get('Content-Type'));

        $responseNumbers = $this->get('/api/saraban/reports/export?type=numbers&format=csv');
        $responseNumbers->assertOk();
        $this->assertStringContainsString('filename', (string) $responseNumbers->headers->get('Content-Disposition'));
    }
}
