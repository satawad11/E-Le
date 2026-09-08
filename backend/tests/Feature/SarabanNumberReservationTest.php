<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * การจองและคืนเลขหนังสือผ่านหน้าจอ (SoT §9 · NAV §4.3)
 *
 * **ที่มา:** การตรวจ navigation เมื่อ 2026-09-05 พบว่า `NumberingService`
 * **ไม่ถูกเปิดผ่าน HTTP เลย** — เลขออกอัตโนมัติภายในสายงานเท่านั้น · สิทธิ์สี่ข้อ
 * (`number.reserve` `allocate` `release` `cancel`) จึงมีในสารบัญโดยไม่มี route
 * ใดใช้ และเจ้าหน้าที่ธุรการ **จองเลขล่วงหน้าไม่ได้เลย** ทั้งที่เป็นงานปกติ
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. จองแล้วได้เลขจริงและอยู่ในทะเบียน
 *   2. **คืนเลขแล้วเลขกลับมาใช้ได้** — ไม่เกิดช่องว่างในทะเบียนโดยไม่จำเป็น
 *   3. **ยกเลิกเลขต้องมีเหตุผลและยืนยันตัวตนซ้ำ** — เลขนั้นจะไม่ถูกใช้อีกเลย
 *   4. ทุก endpoint อยู่หลังด่านสิทธิ์ของตัวเอง
 */
class SarabanNumberReservationTest extends TestCase
{
    use RefreshDatabase;

    private NumberSeries $series;

    private OrgUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-NUM',
            'name' => 'ฝ่ายอำนวยการ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->series = NumberSeries::create([
            'code' => 'GEN',
            'name' => 'หนังสือทั่วไป',
            'category' => DocumentType::CATEGORY_INTERNAL,
            'prefix' => 'วท',
            'is_active' => true,
        ]);
    }

    /** @param array<int,string> $permissions */
    private function officer(array $permissions): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-horse-battery-staple'),
            'org_unit_id' => $this->unit->id,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function actAs(User $user, bool $recentlyAuthenticated = true): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill([
            'last_authenticated_at' => $recentlyAuthenticated ? now() : null,
        ])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    public function test_an_officer_can_reserve_the_next_number(): void
    {
        $this->actAs($this->officer(['saraban.number.reserve']));

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->assertSame(DocumentNumber::STATUS_RESERVED, $number['status']);
        $this->assertNotEmpty($number['formatted']);
        $this->assertDatabaseHas('saraban_document_numbers', [
            'id' => $number['id'],
            'status' => DocumentNumber::STATUS_RESERVED,
        ]);
    }

    /** คิวเลขที่จองไว้ต้องแสดงเฉพาะเลขที่ยังทำอะไรต่อได้ */
    public function test_the_reserved_list_shows_numbers_that_are_still_in_play(): void
    {
        $officer = $this->officer(['saraban.number.reserve']);
        $this->actAs($officer);

        $this->postJson('/api/saraban/numbers/reserve', ['number_series_id' => $this->series->id])->assertCreated();

        $body = $this->getJson('/api/saraban/numbers/reserved')->assertOk()->json();

        $this->assertCount(1, $body['reserved']);
        $this->assertNotEmpty($body['series']);
        $this->assertGreaterThan(2500, $body['current_year']);
    }

    /**
     * **คืนเลขแล้วเลขต้องกลับมาใช้ได้**
     *
     * ไม่ใช่แค่เปลี่ยนสถานะ — การจองครั้งถัดไปต้องได้เลขเดิมคืนมา มิฉะนั้น
     * ทะเบียนจะมีช่องว่างที่อธิบายไม่ได้ทุกครั้งที่มีคนจองผิด
     */
    public function test_a_released_number_returns_to_the_pool(): void
    {
        $this->actAs($this->officer(['saraban.number.reserve', 'saraban.number.release']));

        $first = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$first['id']}/release", ['reason' => 'จองผิดชุด'])->assertOk();

        $second = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->assertSame(
            $first['running_number'],
            $second['running_number'],
            'เลขที่คืนแล้วต้องถูกนำกลับมาใช้ก่อนเดินตัวนับไปข้างหน้า',
        );
    }

    /** ยกเลิกเลขต้องมีเหตุผลที่อ่านแล้วเข้าใจ — "ผิด" ไม่ใช่เหตุผล */
    public function test_cancelling_a_number_requires_a_real_reason(): void
    {
        $this->actAs($this->officer(['saraban.number.reserve', 'saraban.number.cancel']));

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$number['id']}/cancel", ['reason' => 'ผิด'])
            ->assertStatus(422);

        $this->postJson("/api/saraban/numbers/{$number['id']}/cancel", [
            'reason' => 'ออกเลขซ้ำกับหนังสือฉบับอื่นแล้ว',
        ])->assertOk();

        $this->assertDatabaseHas('saraban_document_numbers', [
            'id' => $number['id'],
            'status' => DocumentNumber::STATUS_CANCELLED,
        ]);
    }

    /**
     * **การยกเลิกเลขต้องยืนยันตัวตนซ้ำ** (SoT §24 · NAV §3.2)
     *
     * เลขที่ยกเลิกแล้วจะไม่ถูกใช้อีก และทะเบียนจะมีช่องว่างถาวรที่ผู้ตรวจสอบ
     * ต้องอธิบายได้ — ต่างจากการคืนเลขซึ่งย้อนกลับได้
     */
    public function test_cancelling_a_number_requires_recent_re_authentication(): void
    {
        $officer = $this->officer(['saraban.number.reserve', 'saraban.number.cancel']);
        $this->actAs($officer);

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->actAs($officer, false);

        $this->postJson("/api/saraban/numbers/{$number['id']}/cancel", [
            'reason' => 'ออกเลขซ้ำกับหนังสือฉบับอื่นแล้ว',
        ])->assertStatus(423);
    }

    /** คืนเลขไม่ต้องยืนยันตัวตนซ้ำ — เป็นงานประจำวันที่ย้อนกลับได้ */
    public function test_releasing_a_number_does_not_require_re_authentication(): void
    {
        $officer = $this->officer(['saraban.number.reserve', 'saraban.number.release']);
        $this->actAs($officer);

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->actAs($officer, false);

        $this->postJson("/api/saraban/numbers/{$number['id']}/release")->assertOk();
    }

    public function test_every_numbering_endpoint_sits_behind_its_own_permission(): void
    {
        $this->actAs($this->officer([]));

        $this->getJson('/api/saraban/numbers/reserved')->assertForbidden();
        $this->postJson('/api/saraban/numbers/reserve', ['number_series_id' => $this->series->id])->assertForbidden();

        // ผู้ที่จองได้อย่างเดียว ต้องยกเลิกไม่ได้
        $this->actAs($this->officer(['saraban.number.reserve']));

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$number['id']}/cancel", [
            'reason' => 'ออกเลขซ้ำกับหนังสือฉบับอื่นแล้ว',
        ])->assertForbidden();

        $this->postJson("/api/saraban/numbers/{$number['id']}/release")->assertForbidden();
        $this->postJson("/api/saraban/numbers/{$number['id']}/allocate", ['document_id' => 1])->assertForbidden();
    }

    // ── ผูกเลขที่จองไว้เข้ากับหนังสือ (SoT §9) ──────────────────────────────

    /**
     * ข้อที่สำคัญที่สุดของการจอง — **เลขที่จองต้องไปอยู่บนหนังสือได้จริง**
     *
     * ก่อนหน้านี้เลขที่จองไว้ไม่มีทางถูกใช้เลย เพราะสายงานจองเลขใหม่ทุกครั้ง
     * ตอนลงนาม · เจ้าหน้าที่ที่จองเลขไว้เพื่อพิมพ์ลงหัวหนังสือจะได้เลขคนละตัว
     * กับที่พิมพ์ไปแล้ว
     */
    public function test_a_reserved_number_can_be_bound_to_a_document(): void
    {
        $officer = $this->officer([
            'saraban.number.reserve', 'saraban.number.allocate',
            'saraban.document.view', 'saraban.document.create', 'saraban.document.update',
        ]);

        $this->actAs($officer);
        $document = $this->documentFor($officer);

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $bound = $this->postJson("/api/saraban/numbers/{$number['id']}/allocate", [
            'document_id' => $document->id,
        ])->assertOk()->json('number');

        $this->assertSame(DocumentNumber::STATUS_ALLOCATED, $bound['status']);
        $this->assertDatabaseHas('saraban_document_numbers', [
            'id' => $number['id'],
            'document_id' => $document->id,
            'status' => DocumentNumber::STATUS_ALLOCATED,
        ]);

        /*
         * **ผูกแล้วยังไม่ใช่การออกเลข** — หนังสือยังไม่มีเลขจนกว่าจะลงนาม
         * ถ้าผูกแล้วนับว่าออกเลยจะได้ทะเบียนที่มีเลขของหนังสือที่อาจไม่ได้ลงนาม
         */
        $this->assertNull($document->fresh()->document_number_id);
    }

    /** ชุดเลขต้องตรงประเภทของหนังสือ ไม่งั้นทะเบียนจะอ่านไม่ได้ */
    public function test_a_number_from_the_wrong_series_cannot_be_bound(): void
    {
        $officer = $this->officer([
            'saraban.number.reserve', 'saraban.number.allocate',
            'saraban.document.view', 'saraban.document.create', 'saraban.document.update',
        ]);

        $incomingSeries = NumberSeries::create([
            'code' => 'RCV',
            'name' => 'ทะเบียนรับ',
            'category' => DocumentType::CATEGORY_INCOMING,
            'prefix' => 'รับ',
            'is_active' => true,
        ]);

        $this->actAs($officer);
        $document = $this->documentFor($officer);

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $incomingSeries->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$number['id']}/allocate", [
            'document_id' => $document->id,
        ])->assertStatus(422);
    }

    /** หนังสือที่มีเลขแล้วต้องผูกเลขใบที่สองไม่ได้ */
    public function test_a_document_that_already_has_a_number_cannot_take_another(): void
    {
        $officer = $this->officer([
            'saraban.number.reserve', 'saraban.number.allocate',
            'saraban.document.view', 'saraban.document.create', 'saraban.document.update',
        ]);

        $this->actAs($officer);
        $document = $this->documentFor($officer);

        $first = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$first['id']}/allocate", [
            'document_id' => $document->id,
        ])->assertOk();

        $document->update(['document_number_id' => $first['id']]);

        $second = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$second['id']}/allocate", [
            'document_id' => $document->id,
        ])->assertStatus(422);
    }

    /** ผูกเลขกับหนังสือที่ตนแก้ไขไม่ได้ ต้องทำไม่ได้ — ตรวจสิทธิ์รายฉบับ */
    public function test_binding_a_number_checks_permission_on_that_document(): void
    {
        $owner = $this->officer([
            'saraban.document.view', 'saraban.document.create', 'saraban.document.update',
        ]);

        $this->actAs($owner);
        $document = $this->documentFor($owner);

        $outsider = $this->officer(['saraban.number.reserve', 'saraban.number.allocate']);
        $this->actAs($outsider);

        $number = $this->postJson('/api/saraban/numbers/reserve', [
            'number_series_id' => $this->series->id,
        ])->assertCreated()->json('number');

        $this->postJson("/api/saraban/numbers/{$number['id']}/allocate", [
            'document_id' => $document->id,
        ])->assertForbidden();
    }

    /** หนังสือฉบับร่างของผู้เรียก พร้อมผูกเลข */
    private function documentFor(User $officer): Document
    {
        $type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );

        return Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'ขอเชิญประชุม',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'status' => Document::STATUS_DRAFT,
            'owner_org_unit_id' => $officer->org_unit_id,
            'created_by' => $officer->id,
        ]);
    }
}
