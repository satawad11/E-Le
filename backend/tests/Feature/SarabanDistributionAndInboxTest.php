<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Distribution;
use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-13 — การเวียนหนังสือ กล่องหนังสือเข้า และการรับทราบ (SoT §15)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **เปิดอ่าน ≠ รับทราบ** — ข้อบังคับที่ชัดที่สุดของ SoT §15
 *   2. **Recipient Snapshot บังคับ** และไม่เปลี่ยนตามเมื่อผู้รับย้ายหน่วยงาน
 *   3. **ผู้รับเปิดหนังสือได้แม้อยู่นอกขอบเขตหน่วยงาน** — ไม่งั้นเท่ากับส่งไม่ถึง
 *   4. **ผู้ส่งส่งได้เฉพาะในขอบเขตของตน**
 *   5. **กล่องของใครก็ของคนนั้น** — ไม่มีเส้นทางอ่านหรือแก้กล่องของคนอื่น
 *   6. **สถานะการดำเนินการถอยหลังไม่ได้**
 *   7. **เวียนได้เฉพาะหนังสือที่เป็นทางการแล้ว**
 */
class SarabanDistributionAndInboxTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private OrgUnit $unit;

    private OrgUnit $childUnit;

    private OrgUnit $otherUnit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = $this->orgUnit('UNIT-DIST', 'ฝ่ายอำนวยการ');
        $this->childUnit = $this->orgUnit('UNIT-DIST-SUB', 'งานสารบรรณ', $this->unit->id);
        $this->otherUnit = $this->orgUnit('UNIT-OTHER', 'ฝ่ายวิชาการ');

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );

        NumberSeries::query()->firstOrCreate(
            ['code' => 'internal-dist'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL, 'prefix' => 'ศธ 04001'],
        );
    }

    private function orgUnit(string $code, string $name, ?int $parentId = null): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'type' => 'department',
            'parent_id' => $parentId,
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

    /** หนังสือที่ออกฉบับสมบูรณ์แล้ว พร้อมเวียน */
    private function finalizedDocument(): Document
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'ขอเชิญประชุมคณะกรรมการ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        $document = Document::findOrFail($created['id']);
        $document->update([
            'status' => Document::STATUS_SIGNED,
            'finalized_at' => now(),
        ]);

        return $document->fresh();
    }

    private function sender(): User
    {
        return $this->userWith([
            'saraban.document.view',
            'saraban.distribution.send',
            'saraban.distribution.track',
        ]);
    }

    // ── การเวียนหนังสือ (SoT §15) ────────────────────────────────────────────

    public function test_distributing_to_a_person_records_a_recipient_with_a_snapshot(): void
    {
        $document = $this->finalizedDocument();
        $sender = $this->sender();
        $recipient = $this->userWith([], $this->unit->id);

        /*
         * `User::factory()` สร้างการมอบหมายให้อยู่แล้ว — เติมตำแหน่งลงในแถวนั้น
         * แทนการเพิ่มแถวใหม่ ไม่งั้นจะมีการมอบหมายสองแถวและ `currentAssignment`
         * เลือกแถวที่ใหม่กว่าซึ่งไม่มีตำแหน่ง
         */
        DB::table('personnel_assignments')
            ->where('user_id', $recipient->id)
            ->update(['position' => 'หัวหน้างานพัสดุ', 'org_unit_id' => $this->unit->id]);

        $this->actAs($sender);

        $response = $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'user', 'id' => $recipient->id, 'role' => 'to']],
            'note' => 'เพื่อทราบและถือปฏิบัติ',
        ])->assertCreated()->json();

        $this->assertCount(1, $response['distribution']['recipients']);

        $row = $response['distribution']['recipients'][0];
        $this->assertSame($recipient->name, $row['recipient_name_snapshot']);
        $this->assertSame('หัวหน้างานพัสดุ', $row['position_snapshot']);
        $this->assertSame('ฝ่ายอำนวยการ', $row['org_unit_snapshot']);
        $this->assertSame(DistributionRecipient::STATUS_DELIVERED, $row['status']);
        $this->assertNull($row['acknowledged_at']);
    }

    public function test_recipient_snapshot_does_not_follow_the_person_when_they_move(): void
    {
        $document = $this->finalizedDocument();
        $sender = $this->sender();
        $recipient = $this->userWith([], $this->unit->id);

        $this->actAs($sender);
        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'user', 'id' => $recipient->id]],
        ])->assertCreated();

        $row = DistributionRecipient::query()->where('recipient_id', $recipient->id)->firstOrFail();
        $snapshot = $row->org_unit_snapshot;

        // ย้ายหน่วยงานหลังได้รับหนังสือแล้ว
        $recipient->forceFill(['org_unit_id' => $this->otherUnit->id])->save();

        $this->assertSame($snapshot, $row->fresh()->org_unit_snapshot);
        $this->assertSame('ฝ่ายอำนวยการ', $snapshot);
    }

    public function test_distributing_to_a_subtree_reaches_child_units(): void
    {
        $document = $this->finalizedDocument();
        $sender = $this->sender();

        $inParent = $this->userWith([], $this->unit->id);
        $inChild = $this->userWith([], $this->childUnit->id);
        $outside = $this->userWith([], $this->otherUnit->id);

        /*
         * ผู้ส่งต้องมองเห็นทั้งต้นไม้ จึงกำหนดขอบเขตเป็น DESCENDANTS อย่างชัดแจ้ง
         * ผ่านทะเบียนขอบเขตกลาง ไม่ใช่กติกาของสารบรรณเอง
         */
        $this->grantScope($sender, 'saraban.distribution.send', 'DESCENDANTS', $this->unit->id);

        $this->actAs($sender);
        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'org_subtree', 'id' => $this->unit->id]],
        ])->assertCreated();

        $reached = DistributionRecipient::query()->pluck('recipient_id')->all();

        $this->assertContains($inParent->id, $reached);
        $this->assertContains($inChild->id, $reached);
        $this->assertNotContains($outside->id, $reached);
    }

    public function test_sender_cannot_distribute_outside_their_own_scope(): void
    {
        $document = $this->finalizedDocument();
        $sender = $this->sender();
        $stranger = $this->userWith([], $this->otherUnit->id);

        $this->actAs($sender);

        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'user', 'id' => $stranger->id]],
        ])->assertStatus(422);

        $this->assertSame(0, DistributionRecipient::query()->count());
    }

    public function test_a_draft_document_cannot_be_distributed(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'ยังไม่เสนอ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        $sender = $this->sender();
        $recipient = $this->userWith([], $this->unit->id);
        $this->actAs($sender);

        $this->postJson("/api/saraban/documents/{$created['id']}/distributions", [
            'targets' => [['type' => 'user', 'id' => $recipient->id]],
        ])->assertStatus(422);
    }

    public function test_distribution_requires_the_send_permission(): void
    {
        $document = $this->finalizedDocument();
        $withoutPermission = $this->userWith(['saraban.document.view']);
        $recipient = $this->userWith([], $this->unit->id);

        $this->actAs($withoutPermission);

        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'user', 'id' => $recipient->id]],
        ])->assertForbidden();
    }

    // ── กล่องหนังสือเข้า (SoT §15) ───────────────────────────────────────────

    public function test_recipient_sees_the_document_in_their_inbox(): void
    {
        [$document, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);

        $response = $this->getJson('/api/saraban/inbox')->assertOk()->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame($row->id, $response['data'][0]['id']);
        $this->assertSame($document->subject, $response['data'][0]['document']['subject']);
        $this->assertSame(1, $response['unacknowledged']);
    }

    public function test_inbox_only_ever_shows_your_own_items(): void
    {
        [, , $recipient] = $this->distributedDocument();
        $someoneElse = $this->userWith(['saraban.inbox.view'], $this->unit->id);

        $this->actAs($someoneElse);

        $this->assertSame([], $this->getJson('/api/saraban/inbox')->assertOk()->json('data'));
    }

    public function test_opening_someone_elses_inbox_item_returns_not_found(): void
    {
        [, $row] = $this->distributedDocument();
        $someoneElse = $this->userWith(['saraban.inbox.view'], $this->unit->id);

        $this->actAs($someoneElse);

        $this->getJson("/api/saraban/inbox/{$row->id}")->assertNotFound();
        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertNotFound();
    }

    /**
     * ข้อบังคับที่ชัดที่สุดของ SoT §15 — เปิดอ่านคือข้อเท็จจริงทางเทคนิค
     * ส่วนรับทราบคือการแสดงเจตนาที่ใช้อ้างอิงทางราชการได้
     */
    public function test_opening_a_document_is_not_acknowledging_it(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);

        $item = $this->getJson("/api/saraban/inbox/{$row->id}")->assertOk()->json('item');

        $this->assertNotNull($item['opened_at']);
        $this->assertNull($item['acknowledged_at']);
        $this->assertSame(DistributionRecipient::STATUS_OPENED, $item['status']);

        $this->assertSame(1, $this->getJson('/api/saraban/inbox')->json('unacknowledged'));
    }

    public function test_acknowledging_records_intent_and_clears_the_pending_count(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);

        $item = $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge", [
            'note' => 'รับทราบและจะดำเนินการ',
        ])->assertOk()->json('item');

        $this->assertNotNull($item['acknowledged_at']);
        $this->assertSame(DistributionRecipient::STATUS_ACKNOWLEDGED, $item['status']);
        $this->assertSame('รับทราบและจะดำเนินการ', $item['response_note']);

        $this->assertSame(0, $this->getJson('/api/saraban/inbox')->json('unacknowledged'));
    }

    public function test_acknowledging_twice_is_rejected(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);

        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertOk();
        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertStatus(422);
    }

    // ── การดำเนินการตามหนังสือ (SoT §15) ────────────────────────────────────

    public function test_progress_cannot_be_reported_before_acknowledgement(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);

        $this->postJson("/api/saraban/inbox/{$row->id}/progress", [
            'status' => DistributionRecipient::STATUS_IN_PROGRESS,
        ])->assertStatus(422);
    }

    public function test_progress_moves_forward_through_the_documented_states(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);
        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertOk();

        foreach ([
            DistributionRecipient::STATUS_ACCEPTED,
            DistributionRecipient::STATUS_IN_PROGRESS,
            DistributionRecipient::STATUS_COMPLETED,
        ] as $status) {
            $item = $this->postJson("/api/saraban/inbox/{$row->id}/progress", ['status' => $status])
                ->assertOk()
                ->json('item');

            $this->assertSame($status, $item['status']);
        }

        $row->refresh();
        $this->assertNotNull($row->accepted_at);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->completed_at);
    }

    public function test_progress_cannot_go_backwards(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);
        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertOk();
        $this->postJson("/api/saraban/inbox/{$row->id}/progress", [
            'status' => DistributionRecipient::STATUS_COMPLETED,
        ])->assertOk();

        $this->postJson("/api/saraban/inbox/{$row->id}/progress", [
            'status' => DistributionRecipient::STATUS_IN_PROGRESS,
        ])->assertStatus(422);
    }

    // ── การเข้าถึงหนังสือของผู้รับ (SoT §15) ─────────────────────────────────

    /**
     * ผู้รับที่อยู่นอกขอบเขตหน่วยงานของหนังสือต้องเปิดไฟล์ได้
     *
     * การเวียนหนังสือข้ามหน่วยงานเป็นเรื่องปกติของงานสารบรรณ ถ้าขอบเขตหน่วยงาน
     * ปิดกั้นผู้รับ ก็เท่ากับหนังสือส่งไม่ถึง
     */
    public function test_a_recipient_outside_the_owning_unit_can_still_open_the_file(): void
    {
        $document = $this->finalizedDocument();
        $sender = $this->sender();
        $recipient = $this->userWith(['saraban.document.view'], $this->otherUnit->id);

        $this->grantScope($sender, 'saraban.distribution.send', 'ORGANIZATION', null);

        $this->actAs($sender);
        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'user', 'id' => $recipient->id]],
        ])->assertCreated();

        $this->actAs($recipient);

        // ขอบเขตหน่วยงานอย่างเดียวจะปฏิเสธ — ผ่านได้เพราะเป็นผู้รับ
        $this->getJson("/api/saraban/documents/{$document->id}")->assertOk();
    }

    // ── การติดตามผล (SoT §15, §28) ──────────────────────────────────────────

    public function test_tracking_shows_every_round_and_each_recipients_state(): void
    {
        [$document, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);
        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertOk();

        $sender = User::findOrFail(Distribution::query()->firstOrFail()->sent_by);
        $this->actAs($sender);

        $tracking = $this->getJson("/api/saraban/documents/{$document->id}/distributions")
            ->assertOk()
            ->json('distributions');

        $this->assertCount(1, $tracking);
        $this->assertSame(
            DistributionRecipient::STATUS_ACKNOWLEDGED,
            $tracking[0]['recipients'][0]['status'],
        );
    }

    public function test_tracking_requires_the_track_permission(): void
    {
        [$document] = $this->distributedDocument();

        $this->actAs($this->userWith(['saraban.document.view']));

        $this->getJson("/api/saraban/documents/{$document->id}/distributions")->assertForbidden();
    }

    // ── การแจ้งเตือนและร่องรอยการตรวจสอบ (SoT §16, §25) ─────────────────────

    /** REUSE ศูนย์แจ้งเตือนกลาง — สารบรรณไม่มีชั้นแจ้งเตือนของตัวเอง */
    public function test_distribution_emits_through_the_core_notification_center(): void
    {
        [, , $recipient] = $this->distributedDocument();

        $this->assertDatabaseHas('notification_events', [
            'notification_type_id' => DB::table('notification_types')
                ->where('code', 'saraban.document.distributed')
                ->value('id'),
        ]);

        $this->assertDatabaseHas('notification_recipients', ['user_id' => $recipient->id]);
    }

    /**
     * SoT §16 — ธุรกรรมทางธุรกิจห้าม rollback เพราะผู้ให้บริการแจ้งเตือนล้มเหลว
     *
     * ปิดชนิดการแจ้งเตือนทำให้ `NotificationCenter::emit()` โยน exception ออกมา
     * ซึ่งเป็นกรณีเดียวกับที่ระบบแจ้งเตือนล่ม — การเวียนหนังสือต้องยังสำเร็จ
     * และผู้รับต้องเห็นหนังสือในกล่องตามปกติ
     */
    public function test_distribution_still_succeeds_when_notifications_fail(): void
    {
        DB::table('notification_types')
            ->where('code', 'saraban.document.distributed')
            ->update(['is_active' => false]);

        [, $row, $recipient] = $this->distributedDocument();

        $this->assertSame(DistributionRecipient::STATUS_DELIVERED, $row->status);
        $this->assertDatabaseCount('notification_events', 0);

        $this->actAs($recipient);
        $this->assertCount(1, $this->getJson('/api/saraban/inbox')->assertOk()->json('data'));
    }

    public function test_every_distribution_step_is_written_to_the_central_audit_log(): void
    {
        [, $row, $recipient] = $this->distributedDocument();

        $this->actAs($recipient);
        $this->postJson("/api/saraban/inbox/{$row->id}/acknowledge")->assertOk();
        $this->postJson("/api/saraban/inbox/{$row->id}/progress", [
            'status' => DistributionRecipient::STATUS_ACCEPTED,
        ])->assertOk();

        foreach ([
            'saraban.distribution.sent',
            'saraban.distribution.acknowledged',
            'saraban.distribution.progress_reported',
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    /**
     * หนังสือที่เวียนแล้วหนึ่งฉบับ พร้อมผู้รับหนึ่งคน
     *
     * @return array{0:Document,1:DistributionRecipient,2:User}
     */
    private function distributedDocument(): array
    {
        $document = $this->finalizedDocument();
        $sender = $this->sender();
        $recipient = $this->userWith(['saraban.inbox.view'], $this->unit->id);

        $this->actAs($sender);
        $this->postJson("/api/saraban/documents/{$document->id}/distributions", [
            'targets' => [['type' => 'user', 'id' => $recipient->id, 'role' => 'to']],
            'note' => 'เพื่อทราบ',
        ])->assertCreated();

        return [
            $document,
            DistributionRecipient::query()->where('recipient_id', $recipient->id)->firstOrFail(),
            $recipient,
        ];
    }

    // ── หน้ารวมการติดตามการกระจาย (NAV §2 · §4.8) ──────────────────────────

    /** หน้ารวมต้องสรุปยอดตรงกับสถานะจริงของผู้รับ */
    public function test_the_distribution_overview_lists_what_was_sent_with_acknowledgement_counts(): void
    {
        [$document, $recipient, $reader] = $this->distributedDocument();

        $this->actAs($this->sender());
        $before = $this->getJson('/api/saraban/distributions')->assertOk()->json();

        $this->assertSame(1, $before['meta']['total']);
        $this->assertSame($document->id, $before['data'][0]['document_id']);
        $this->assertSame(1, $before['data'][0]['total']);
        $this->assertSame(0, $before['data'][0]['acknowledged'], 'ยังไม่มีใครรับทราบ');

        /*
         * นับ "รับทราบแล้ว" ให้รวมผู้ที่เดินหน้าต่อไปแล้วด้วย — ผู้ที่รับเรื่อง
         * ไปดำเนินการต้องไม่ถูกนับว่ายังไม่รับทราบ ไม่งั้นหน้าจอจะสั่งให้ตามคน
         * ที่ทำงานอยู่แล้ว
         */
        $recipient->forceFill([
            'status' => DistributionRecipient::STATUS_IN_PROGRESS,
            'acknowledged_at' => now(),
        ])->save();

        $this->actAs($this->sender());
        $after = $this->getJson('/api/saraban/distributions')->assertOk()->json();

        $this->assertSame(1, $after['data'][0]['acknowledged']);
        $this->assertNotNull($reader->id);
    }

    /**
     * ขอบเขตของหน้ารวมต้องเป็นชุดเดียวกับการติดตามรายฉบับ
     *
     * ถ้าหน้ารวมกว้างกว่า มันจะกลายเป็นช่องบอกว่ามีหนังสือเรื่องอะไรเวียนอยู่
     * ในหน่วยงานอื่น ทั้งที่ผู้เรียกกดเข้าไปดูรายฉบับไม่ได้
     */
    public function test_the_distribution_overview_hides_documents_outside_the_viewers_scope(): void
    {
        $this->distributedDocument();

        $outsider = $this->userWith([
            'saraban.document.view',
            'saraban.distribution.track',
        ], $this->otherUnit->id);

        $this->grantScope($outsider, 'saraban.distribution.track', 'UNIT', $this->otherUnit->id);

        $this->actAs($outsider);
        $response = $this->getJson('/api/saraban/distributions')->assertOk()->json();

        $this->assertSame([], $response['data']);
    }

    /** ผู้ที่ไม่มีสิทธิ์ติดตาม เปิดหน้ารวมไม่ได้ */
    public function test_the_distribution_overview_requires_the_tracking_permission(): void
    {
        $this->actAs($this->userWith(['saraban.document.view']));

        $this->getJson('/api/saraban/distributions')->assertForbidden();
    }

    /** ปิด RISK-35 — ผู้รักษาการแทน/ผู้รับมอบอำนาจ สามารถเปิดอ่านและรับทราบแทนได้ */
    public function test_active_delegate_can_view_and_acknowledge_delegators_inbox_item(): void
    {
        [$document, $recipientRow, $delegator] = $this->distributedDocument();

        $delegate = $this->userWith(['saraban.inbox.view']);

        // ตั้งการมอบอำนาจ active วันนี้
        DB::table('user_delegations')->insert([
            'user_id' => $delegator->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ผู้รักษาการเปิดกล่องหนังสือ เห็นรายการของผู้มอบอำนาจ
        $this->actAs($delegate);
        $inbox = $this->getJson('/api/saraban/inbox')->assertOk()->json();
        $this->assertCount(1, $inbox['data']);
        $this->assertTrue($inbox['data'][0]['is_delegated']);
        $this->assertCount(1, $inbox['delegators']);

        // ผู้รักษาการกดรับทราบแทน
        $ack = $this->postJson("/api/saraban/inbox/{$recipientRow->id}/acknowledge", [
            'note' => 'รับทราบแทนผู้อำนวยการ',
        ])->assertOk()->json();

        $this->assertSame(DistributionRecipient::STATUS_ACKNOWLEDGED, $ack['item']['status']);
        $this->assertStringContainsString('รับทราบแทน', $ack['item']['response_note']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.distribution.acknowledged',
            'actor_id' => $delegate->id,
        ]);
    }

    /** การรับทราบแทนโดยไม่พิมพ์ข้อความใหม่ ต้องไม่ลบบันทึกเดิมของผู้รับออกจากทะเบียน */
    public function test_delegated_acknowledgement_keeps_the_recipients_existing_note(): void
    {
        [, $recipientRow, $delegator] = $this->distributedDocument();

        $delegate = $this->userWith(['saraban.inbox.view']);

        DB::table('user_delegations')->insert([
            'user_id' => $delegator->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recipientRow->forceFill(['response_note' => 'ส่งเรื่องให้งานพัสดุแล้ว'])->save();

        $this->actAs($delegate);
        $note = $this->postJson("/api/saraban/inbox/{$recipientRow->id}/acknowledge", [])
            ->assertOk()
            ->json('item.response_note');

        $this->assertStringContainsString('ส่งเรื่องให้งานพัสดุแล้ว', $note);
        $this->assertStringContainsString('รับทราบแทนโดย', $note);
    }

    /** กำหนดขอบเขตให้ผู้ใช้ผ่านทะเบียนขอบเขตกลาง ไม่ใช่กติกาของสารบรรณ */
    private function grantScope(User $user, string $ability, string $scope, ?int $orgUnitId): void
    {
        DB::table('permission_scopes')->insert([
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
            'permission_name' => $ability,
            'scope' => $scope,
            'org_unit_id' => $orgUnitId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
