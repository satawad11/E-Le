<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Distribution;
use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\TrustedClock;
use App\Services\Webhooks\WebhookCatalogue;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * การเวียนหนังสือและการติดตามผล (SoT §15, §16 · INT-13)
 *
 * หลักที่คลาสนี้บังคับ:
 *
 *   1. **เวียนได้เฉพาะหนังสือที่เป็นทางการแล้ว** — หนังสือรับที่ลงทะเบียนแล้ว
 *      หรือหนังสือที่ออกฉบับสมบูรณ์แล้ว · ฉบับร่างที่ยังแก้ได้ห้ามเวียน
 *      เพราะผู้รับจะอ่านเนื้อหาที่เปลี่ยนใต้มือได้ภายหลัง
 *   2. **Recipient Snapshot บังคับ** — เก็บชื่อ ตำแหน่ง หน่วยงาน ณ วันที่ส่ง
 *   3. **เปิดอ่าน ≠ รับทราบ** — `markOpened()` ไม่แตะ `acknowledged_at` เด็ดขาด
 *   4. **สถานะถอยหลังไม่ได้** — รายงานว่าเสร็จแล้วย้อนกลับเป็นกำลังทำไม่ได้
 *   5. **การแจ้งเตือนล้มเหลวห้ามล้มการเวียนหนังสือ** (SoT §16) — หนังสือส่งถึง
 *      ผู้รับในระบบแล้ว ต่อให้ผู้ให้บริการแจ้งเตือนล่ม
 */
class DistributionService
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
        private readonly NotificationCenter $notifications,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /** เวียนหนังสือได้หรือยัง — ใช้ทั้งใน policy และในชั้นบริการ */
    public function canDistribute(Document $document): bool
    {
        if ($document->status === Document::STATUS_CANCELLED) {
            return false;
        }

        /*
         * ระเบียบสำนักนายกรัฐมนตรีว่าด้วยงานสารบรรณ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔ ข้อ ๒๙
         * ห้ามส่งหรือเวียนข้อมูลข่าวสารลับชั้นลับที่สุดด้วยระบบสารบรรณอิเล็กทรอนิกส์
         */
        if ($document->confidentiality === 'top_secret') {
            return false;
        }

        /*
         * หนังสือรับ: เวียนได้ทันทีที่ลงทะเบียนรับแล้ว เพราะการส่งเรื่องต่อให้
         * ผู้เกี่ยวข้องคืองานหลักของงานสารบรรณหลังลงรับ (SoT §10)
         *
         * หนังสือส่งออก/ภายใน: ต้องออกฉบับสมบูรณ์แล้วเท่านั้น (SoT §8)
         */
        if ($document->direction === Document::DIRECTION_INCOMING) {
            return $document->status === Document::STATUS_REGISTERED || $document->finalized_at !== null;
        }

        return $document->finalized_at !== null;
    }

    /**
     * เวียนหนังสือถึงเป้าหมายที่เลือก
     *
     * @param  array<int,array{type:string,id?:int|null,role?:string}>  $targets
     *
     * @throws DistributionException
     */
    public function distribute(Document $document, array $targets, User $sender, ?string $note = null): Distribution
    {
        if ($document->confidentiality === 'top_secret') {
            throw new DistributionException(
                'หนังสือที่มีชั้นความลับชั้นลับที่สุดไม่อาจจัดส่งด้วยวิธีการทางอิเล็กทรอนิกส์ได้ตามระเบียบงานสารบรรณ (ข้อ ๒๙) กรุณาดำเนินการจัดส่งเป็นเอกสารกระดาษ'
            );
        }

        if (! $this->canDistribute($document)) {
            throw new DistributionException('หนังสือฉบับนี้ยังไม่อยู่ในสถานะที่เวียนได้');
        }

        $version = $document->currentVersion;

        if ($version === null) {
            throw new DistributionException('หนังสือฉบับนี้ยังไม่มีไฟล์ที่จะส่งให้ผู้รับ');
        }

        $resolved = $this->recipients->resolve($targets, $sender);
        $sentAt = $this->clock->now();

        $distribution = DB::transaction(function () use ($document, $version, $sender, $note, $resolved, $sentAt) {
            $distribution = Distribution::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'sent_by' => $sender->id,
                'sent_at' => $sentAt,
                'note' => $note,
            ]);

            foreach ($resolved as $entry) {
                /** @var User $user */
                $user = $entry['user'];
                $assignment = $user->currentAssignment;

                DistributionRecipient::create([
                    'distribution_id' => $distribution->id,
                    'recipient_id' => $user->id,
                    'recipient_role' => $entry['role'],
                    'target_type' => $entry['target_type'],
                    'target_id' => $entry['target_id'],

                    // Recipient Snapshot (SoT §15) — ข้อความ ไม่ใช่ join
                    'recipient_name_snapshot' => $user->name,
                    'position_snapshot' => $assignment?->position,
                    'org_unit_snapshot' => $assignment?->orgUnit?->name ?? $user->orgUnit?->name,

                    'status' => DistributionRecipient::STATUS_DELIVERED,
                    'delivered_at' => $sentAt,
                ]);
            }

            $this->audit->log('saraban.distribution.sent', $document, $document->id, null, [
                'distribution_id' => $distribution->id,
                'version_no' => $version->version_no,
                'recipient_count' => $resolved->count(),
            ], null, $sender->id);

            return $distribution;
        });

        $this->notifyRecipients($document, $distribution, $sender);

        /*
         * แจ้งระบบภายนอก (SoT §17 · INT-15) — ส่งเฉพาะข้อเท็จจริงและตัวชี้
         * ไม่ส่งรายชื่อผู้รับออกนอกระบบ เพราะนั่นเป็นข้อมูลบุคคล
         */
        $this->webhooks->dispatch(WebhookCatalogue::DOCUMENT_DISTRIBUTED, [
            'document_uuid' => $document->uuid,
            'distribution_uuid' => $distribution->uuid,
            'recipient_count' => $resolved->count(),
            'sent_at' => $sentAt->toIso8601String(),
        ]);

        return $distribution->fresh(['recipients.recipient:id,name']);
    }

    /**
     * บันทึกว่าผู้รับเปิดอ่านแล้ว
     *
     * **ไม่ใช่การรับทราบ** (SoT §15) — เมธอดนี้จึงไม่แตะ `acknowledged_at`
     * และไม่ดันสถานะให้เกิน `opened` ไม่ว่ากรณีใด
     */
    public function markOpened(DistributionRecipient $recipient): DistributionRecipient
    {
        if ($recipient->opened_at !== null) {
            return $recipient;
        }

        $recipient->opened_at = $this->clock->now();

        // ผู้ที่รับทราบหรือรายงานผลไปแล้ว สถานะต้องไม่ถูกดึงกลับมาเป็น "เปิดอ่าน"
        if ($recipient->status === DistributionRecipient::STATUS_DELIVERED) {
            $recipient->status = DistributionRecipient::STATUS_OPENED;
        }

        $recipient->save();

        return $recipient;
    }

    /**
     * รับทราบหนังสือ — การแสดงเจตนาที่ใช้อ้างอิงทางราชการได้
     *
     * @throws DistributionException
     */
    public function acknowledge(DistributionRecipient $recipient, User $actor, ?string $note = null): DistributionRecipient
    {
        if ($recipient->isAcknowledged()) {
            throw new DistributionException('ท่านรับทราบหนังสือฉบับนี้ไปแล้ว');
        }

        $now = $this->clock->now();

        $isDelegated = (int) $actor->id !== (int) $recipient->recipient_id;
        $notePrefix = $isDelegated ? "รับทราบแทนโดย {$actor->name}" : '';

        /*
         * บันทึกเดิมของผู้รับเป็นหลักฐานในทะเบียน — การรับทราบแทนต้อง "ต่อท้าย"
         * ไม่ใช่ทับทิ้ง แม้ผู้ปฏิบัติแทนจะไม่ได้พิมพ์ข้อความใหม่มาก็ตาม
         */
        $fullNote = $note ?: $recipient->response_note;
        if ($notePrefix !== '') {
            $fullNote = blank($fullNote) ? $notePrefix : "{$notePrefix}: {$fullNote}";
        }

        $recipient->forceFill([
            'acknowledged_at' => $now,
            'opened_at' => $recipient->opened_at ?? $now,
            'status' => DistributionRecipient::STATUS_ACKNOWLEDGED,
            'response_note' => $fullNote,
        ])->save();

        $auditData = [
            'distribution_recipient_id' => $recipient->id,
            'acknowledged_at' => $now->toIso8601String(),
        ];
        if ($isDelegated) {
            $auditData['acting_for_user_id'] = $recipient->recipient_id;
            $auditData['is_delegated'] = true;
        }

        $this->audit->log(
            'saraban.distribution.acknowledged',
            $recipient->distribution->document,
            $recipient->distribution->document_id,
            null,
            $auditData,
            null,
            $actor->id,
        );

        $this->webhooks->dispatch(WebhookCatalogue::DOCUMENT_ACKNOWLEDGED, [
            'document_uuid' => $recipient->distribution->document->uuid,
            'distribution_uuid' => $recipient->distribution->uuid,
            'acknowledged_at' => $now->toIso8601String(),
        ]);

        return $recipient;
    }

    /**
     * รายงานความคืบหน้าการดำเนินการ
     *
     * ต้องรับทราบก่อน และสถานะถอยหลังไม่ได้ — ทะเบียนที่ถอยหลังได้ใช้ตรวจสอบ
     * การปฏิบัติงานไม่ได้เลย
     *
     * @throws DistributionException
     */
    public function reportProgress(DistributionRecipient $recipient, string $status, User $actor, ?string $note = null): DistributionRecipient
    {
        if (! in_array($status, DistributionRecipient::reportableStatuses(), true)) {
            throw new DistributionException('สถานะที่รายงานไม่ถูกต้อง');
        }

        if (! $recipient->isAcknowledged()) {
            throw new DistributionException('ต้องรับทราบหนังสือก่อนจึงจะรายงานผลการดำเนินการได้');
        }

        $progression = DistributionRecipient::progression();

        if ((int) array_search($status, $progression, true) <= $recipient->rank()) {
            throw new DistributionException('สถานะการดำเนินการย้อนกลับไม่ได้');
        }

        $now = $this->clock->now();
        $column = match ($status) {
            DistributionRecipient::STATUS_ACCEPTED => 'accepted_at',
            DistributionRecipient::STATUS_IN_PROGRESS => 'started_at',
            default => 'completed_at',
        };

        $recipient->forceFill([
            'status' => $status,
            $column => $now,
            'response_note' => $note ?? $recipient->response_note,
        ])->save();

        $this->audit->log(
            'saraban.distribution.progress_reported',
            $recipient->distribution->document,
            $recipient->distribution->document_id,
            null,
            ['distribution_recipient_id' => $recipient->id, 'status' => $status],
            null,
            $actor->id,
        );

        return $recipient;
    }

    /**
     * แจ้งเตือนผู้รับผ่านศูนย์แจ้งเตือนกลาง (SoT §16)
     *
     * **ห้ามล้มธุรกรรมเพราะการแจ้งเตือนล้มเหลว** — หนังสือส่งถึงผู้รับในระบบแล้ว
     * และเห็นได้ในกล่องหนังสือเข้าโดยไม่ต้องพึ่งการแจ้งเตือน · การ rollback
     * การเวียนหนังสือเพราะผู้ให้บริการ LINE ล่ม คือการปล่อยให้ระบบภายนอก
     * ควบคุมทะเบียนราชการ
     */
    private function notifyRecipients(Document $document, Distribution $distribution, User $sender): void
    {
        try {
            $this->notifications->emit(
                'saraban.document.distributed',
                $distribution->recipients()->pluck('recipient_id')->all(),
                $document,
                [
                    'subject' => $document->subject,
                    'urgency' => $document->urgency,
                ],
                $sender,
            );
        } catch (Throwable $exception) {
            Log::warning('ส่งการแจ้งเตือนการเวียนหนังสือไม่สำเร็จ', [
                'distribution_id' => $distribution->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
