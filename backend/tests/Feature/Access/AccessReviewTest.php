<?php

namespace Tests\Feature\Access;

use App\Models\Authz\AccessReviewItem;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * ทบทวนสิทธิ์รายภาคเรียน (CORE-04 · R05-5) — ออกรอบ · หัวหน้าหน่วยยืนยันตาม scope · ถอนอัตโนมัติเมื่อเลยกำหนด
 */
class AccessReviewTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    private function openRound(): string
    {
        Sanctum::actingAs($this->f->users['auditor']);

        return $this->postJson('/api/access/reviews', [
            'name' => 'ภาคเรียนที่ 2/2569', 'period_start' => '2026-11-01', 'period_end' => '2027-03-31', 'due_at' => '2026-11-15',
        ])->assertCreated()->json('uuid');
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsFor(string $persona, string $review): array
    {
        Sanctum::actingAs($this->f->users[$persona]);

        return $this->getJson("/api/access/reviews/{$review}/items")->assertOk()->json('data');
    }

    public function test_round_covers_every_active_grant_and_allow_override(): void
    {
        $this->f->grant('teacher', 'audit.auditor', 'คำสั่ง 70/2569');
        PermissionOverride::create([
            'user_id' => $this->f->users['program_head']->id, 'permission_id' => Permission::where('name', 'personnel.report.view')->value('id'),
            'effect' => 'allow', 'scope' => 'subtree', 'reason' => 'ช่วยทำรายงาน', 'granted_by' => $this->f->users['admin']->id, 'expires_at' => '2027-03-31',
        ]);
        PermissionOverride::create([
            'user_id' => $this->f->users['program_head']->id, 'permission_id' => Permission::where('name', 'personnel.profile.view')->value('id'),
            'effect' => 'deny', 'reason' => 'ส่วนได้เสีย', 'granted_by' => $this->f->users['admin']->id,
        ]);

        $review = $this->openRound();

        // hr, admin, auditor (fixture) + teacher + override allow 1 — deny ไม่ต้องทบทวน
        $this->assertSame(5, AccessReviewItem::count());
        Sanctum::actingAs($this->f->users['auditor']);
        $this->getJson('/api/access/reviews')->assertOk()->assertJsonPath('data.0.total', 5)->assertJsonPath('data.0.pending', 5);
        $this->postJson('/api/access/reviews', ['name' => 'ซ้ำ', 'period_start' => '2026-11-01', 'period_end' => '2027-03-31', 'due_at' => '2026-11-15'])
            ->assertStatus(422);
        $this->assertNotNull($review);
    }

    public function test_unit_head_confirms_only_items_in_their_subtree_and_never_their_own(): void
    {
        // ชุดสิทธิ์งานบุคคลไม่มี access.review.* — ผู้รับจึงไม่กลายเป็นผู้ดูแลรอบทบทวนเอง
        $teacherGrant = $this->f->grant('teacher', 'personnel.hr_officer', 'คำสั่ง 71/2569');
        $headGrant = $this->f->grant('section_head', 'personnel.hr_officer', 'คำสั่ง 72/2569');
        $review = $this->openRound();
        $teacherItem = AccessReviewItem::where('subject_id', $teacherGrant->id)->where('subject_type', 'role_grant')->firstOrFail();
        $headItem = AccessReviewItem::where('subject_id', $headGrant->id)->where('subject_type', 'role_grant')->firstOrFail();
        $hrItem = AccessReviewItem::where('user_id', $this->f->users['hr']->id)->firstOrFail();

        // หัวหน้าสาขางานยานยนต์เห็นและยืนยันรายการของครูในสาขาได้
        $items = $this->itemsFor('program_head', $review);
        $this->assertSame([$teacherItem->id], array_column($items, 'id'));
        $this->assertTrue($items[0]['can_decide']);
        $this->postJson("/api/access/reviews/{$review}/items/{$teacherItem->id}/decide", ['decision' => 'confirmed'])->assertOk()->assertJsonPath('decision', 'confirmed');

        // หัวหน้างานบุคลากรเห็นรายการของงานบุคลากร ยกเว้นของตนเอง · รายการนอกหน่วย = 404
        $ids = array_column($this->itemsFor('section_head', $review), 'id');
        $this->assertContains($hrItem->id, $ids);
        $this->assertNotContains($headItem->id, $ids);
        $this->postJson("/api/access/reviews/{$review}/items/{$headItem->id}/decide", ['decision' => 'confirmed'])->assertForbidden();
        $this->postJson("/api/access/reviews/{$review}/items/{$teacherItem->id}/decide", ['decision' => 'confirmed'])->assertNotFound();

        // ครูไม่มีสิทธิ์ยืนยัน
        Sanctum::actingAs($this->f->users['teacher']);
        $this->getJson("/api/access/reviews/{$review}/items")->assertForbidden();
    }

    public function test_revoking_an_item_ends_the_grant_immediately(): void
    {
        $grant = $this->f->grant('teacher', 'audit.auditor', 'คำสั่ง 73/2569');
        $review = $this->openRound();
        $item = AccessReviewItem::where('subject_id', $grant->id)->where('subject_type', 'role_grant')->firstOrFail();

        Sanctum::actingAs($this->f->users['program_head']);
        $this->postJson("/api/access/reviews/{$review}/items/{$item->id}/decide", ['decision' => 'revoked'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/access/reviews/{$review}/items/{$item->id}/decide", ['decision' => 'revoked', 'note' => 'ไม่ได้ทำหน้าที่แล้ว'])->assertOk();
        $this->postJson("/api/access/reviews/{$review}/items/{$item->id}/decide", ['decision' => 'confirmed'])->assertStatus(422);

        $this->assertNotNull($grant->fresh()->ended_at);
        $this->assertStringContainsString('ไม่ได้ทำหน้าที่แล้ว', $grant->fresh()->end_reason);
    }

    public function test_unconfirmed_items_are_revoked_automatically_when_the_round_is_overdue(): void
    {
        $confirmed = $this->f->grant('teacher', 'audit.auditor', 'คำสั่ง 74/2569');
        $review = $this->openRound();
        $item = AccessReviewItem::where('subject_id', $confirmed->id)->where('subject_type', 'role_grant')->firstOrFail();
        Sanctum::actingAs($this->f->users['program_head']);
        $this->postJson("/api/access/reviews/{$review}/items/{$item->id}/decide", ['decision' => 'confirmed'])->assertOk();

        $this->travelTo('2026-11-16');
        $this->artisan('accounts:reconcile')->assertSuccessful();

        $this->assertNull($confirmed->fresh()->ended_at, 'รายการที่ยืนยันแล้วคงอยู่');
        $hrGrant = $this->f->users['hr']->roleGrants()->firstOrFail();
        $this->assertNotNull($hrGrant->ended_at, 'ไม่มีผู้ยืนยัน → ถอนอัตโนมัติ');
        $this->assertSame(3, AccessReviewItem::where('decision', AccessReviewItem::EXPIRED)->count(), 'hr, admin, auditor ของ fixture');

        Sanctum::actingAs($this->f->users['director']->fresh()); // ผู้ตรวจสอบเองก็ถูกถอนในรอบนี้ — ดูผลด้วยสิทธิ์ยืนยันของ ผอ.
        $this->getJson('/api/access/reviews')->assertOk()->assertJsonPath('data.0.status', 'closed')->assertJsonPath('data.0.revoked', 3);
    }

    public function test_only_review_managers_open_and_close_rounds(): void
    {
        Sanctum::actingAs($this->f->users['program_head']);
        $this->postJson('/api/access/reviews', ['name' => 'x', 'period_start' => '2026-11-01', 'period_end' => '2027-03-31', 'due_at' => '2026-11-15'])->assertForbidden();

        $review = $this->openRound();
        Sanctum::actingAs($this->f->users['program_head']);
        $this->postJson("/api/access/reviews/{$review}/close")->assertForbidden();

        Sanctum::actingAs($this->f->users['admin']);
        $this->postJson("/api/access/reviews/{$review}/close")->assertOk()->assertJsonPath('status', 'closed');
    }
}
