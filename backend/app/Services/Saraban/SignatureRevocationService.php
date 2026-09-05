<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\SignatureEvidence;
use App\Models\Saraban\SignatureRevocation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\StepUpAuthentication;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\DB;

/**
 * วงจรการเพิกถอนลายเซ็นและหนังสือที่ออกไปแล้ว (SoT §14, §24, §25)
 *
 * **หลักสามข้อที่บริการนี้บังคับ**
 *
 *   1. **เหตุผลบังคับเสมอ** — การเพิกถอนที่ไม่มีเหตุผลคือการลบร่องรอย
 *   2. **ผู้ขอกับผู้อนุมัติต้องเป็นคนละคน** (separation of duties · SoT §26)
 *      แบบเดียวกับการทำลายเอกสารใน INT-19 · หนังสือราชการที่ออกไปแล้วเพิกถอน
 *      ด้วยการตัดสินใจของคนเดียวไม่ได้
 *   3. **ยืนยันตัวตนซ้ำทั้งตอนขอและตอนตัดสิน** — เป็นการกระทำที่เปลี่ยนสถานะ
 *      ของเอกสารที่เผยแพร่ออกไปแล้ว (SoT §24)
 *
 * **ไม่ลบอะไรเลย** — การอนุมัติเพิกถอนเปลี่ยนสถานะหนังสือเป็น `cancelled`
 * ไฟล์ หลักฐานการลงนาม และทะเบียนยังอยู่ครบ · ผู้ที่ถือกระดาษอยู่ในมือต้อง
 * ตรวจสอบได้ว่าฉบับนั้นถูกเพิกถอนแล้ว ซึ่งทำไม่ได้ถ้าลบข้อมูลทิ้ง
 */
class SignatureRevocationService
{
    public function __construct(
        private readonly StepUpAuthentication $stepUp,
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ยื่นคำขอเพิกถอน
     *
     * @throws SignatureException
     */
    public function request(
        Document $document,
        User $requester,
        string $reason,
        ?SignatureEvidence $evidence = null,
    ): SignatureRevocation {
        $this->requireRecentAuthentication($requester);

        if ($evidence !== null && (int) $evidence->document_id !== (int) $document->id) {
            throw new SignatureException('หลักฐานการลงนามที่อ้างถึงไม่ใช่ของหนังสือฉบับนี้');
        }

        /*
         * หนังสือที่ยังไม่ออกฉบับสมบูรณ์ไม่ต้องเพิกถอน — แก้ที่ตัวร่างได้เลย
         * การเปิดให้เพิกถอนร่างจะทำให้ทะเบียนเต็มไปด้วยคำขอที่ไม่มีความหมาย
         */
        if ($document->finalized_at === null) {
            throw new SignatureException('หนังสือที่ยังไม่ออกฉบับสมบูรณ์ ไม่ต้องใช้การเพิกถอน');
        }

        if ($document->status === Document::STATUS_CANCELLED) {
            throw new SignatureException('หนังสือฉบับนี้ถูกยกเลิกไปแล้ว');
        }

        return DB::transaction(function () use ($document, $requester, $reason, $evidence) {
            /*
             * ล็อกแถวหนังสือก่อนตรวจว่ามีคำขอค้างอยู่ไหม — ไม่งั้นสองคนที่กด
             * พร้อมกันจะสร้างคำขอซ้อนกันได้ และผู้อนุมัติจะเห็นสองคำขอที่เหมือนกัน
             * โดยไม่รู้ว่าควรตัดสินอันไหน (บทเรียนเดียวกับ TOCTOU ของการทำลายเอกสาร)
             */
            Document::query()->whereKey($document->id)->lockForUpdate()->first();

            $pending = SignatureRevocation::query()
                ->where('document_id', $document->id)
                ->where('status', SignatureRevocation::STATUS_PENDING)
                ->exists();

            if ($pending) {
                throw new SignatureException('มีคำขอเพิกถอนของหนังสือฉบับนี้ที่รอการตัดสินอยู่แล้ว');
            }

            $revocation = SignatureRevocation::create([
                'document_id' => $document->id,
                'signature_evidence_id' => $evidence?->id,
                'requested_by' => $requester->id,
                'requested_at' => $this->clock->now(),
                'reason' => $reason,
                'status' => SignatureRevocation::STATUS_PENDING,
            ]);

            $this->audit->log('saraban.signature.revocation_requested', $document, $document->id, null, [
                'revocation_id' => $revocation->id,
                'signature_evidence_id' => $evidence?->id,
                'reason' => $reason,
            ], $reason, $requester->id);

            return $revocation;
        }, 5);
    }

    /**
     * อนุมัติการเพิกถอน — หนังสือเข้าสถานะยกเลิก
     *
     * @throws SignatureException
     */
    public function approve(SignatureRevocation $revocation, User $approver, ?string $note = null): SignatureRevocation
    {
        $this->requireRecentAuthentication($approver);
        $this->requireDifferentPerson($revocation, $approver);

        return DB::transaction(function () use ($revocation, $approver, $note) {
            $revocation->refresh();

            if (! $revocation->isPending()) {
                throw new SignatureException('คำขอนี้ถูกตัดสินไปแล้ว');
            }

            $revocation->forceFill([
                'status' => SignatureRevocation::STATUS_APPROVED,
                'decided_by' => $approver->id,
                'decided_at' => $this->clock->now(),
                'decision_note' => $note,
            ])->save();

            $document = $revocation->document;

            /*
             * เปลี่ยนสถานะ **ไม่ลบอะไร** — ไฟล์ หลักฐานการลงนาม เลขหนังสือ
             * และทะเบียนยังอยู่ครบ · ผู้ที่ถือกระดาษต้องสแกน QR แล้วเห็นว่า
             * ฉบับนั้นถูกเพิกถอนแล้ว ซึ่งเป็นไปไม่ได้ถ้าลบข้อมูลทิ้ง
             */
            $document->forceFill(['status' => Document::STATUS_CANCELLED])->save();

            $this->audit->log('saraban.signature.revocation_approved', $document, $document->id, null, [
                'revocation_id' => $revocation->id,
                'reason' => $revocation->reason,
                'note' => $note,
            ], $note, $approver->id);

            return $revocation;
        }, 5);
    }

    /**
     * ปฏิเสธคำขอ — หนังสือยังใช้ได้ตามเดิม
     *
     * @throws SignatureException
     */
    public function reject(SignatureRevocation $revocation, User $approver, string $note): SignatureRevocation
    {
        $this->requireRecentAuthentication($approver);
        $this->requireDifferentPerson($revocation, $approver);

        return DB::transaction(function () use ($revocation, $approver, $note) {
            $revocation->refresh();

            if (! $revocation->isPending()) {
                throw new SignatureException('คำขอนี้ถูกตัดสินไปแล้ว');
            }

            $revocation->forceFill([
                'status' => SignatureRevocation::STATUS_REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => $this->clock->now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->log(
                'saraban.signature.revocation_rejected',
                $revocation->document,
                $revocation->document_id,
                null,
                ['revocation_id' => $revocation->id, 'note' => $note],
                $note,
                $approver->id,
            );

            return $revocation;
        }, 5);
    }

    /** @throws SignatureException */
    private function requireRecentAuthentication(User $actor): void
    {
        /*
         * ตรวจในบริการอีกชั้นแม้ route จะมีด่าน `step_up` แล้ว — เหตุผลเดียวกับ
         * การลงนาม: การต่อ route ผิดพลาดครั้งเดียวไม่ควรเปิดช่องให้การกระทำที่
         * เปลี่ยนสถานะเอกสารที่เผยแพร่ไปแล้ว
         */
        if (! $this->stepUp->isRecent($actor)) {
            throw new SignatureException('ต้องยืนยันตัวตนอีกครั้งก่อนดำเนินการเพิกถอน');
        }
    }

    /** @throws SignatureException */
    private function requireDifferentPerson(SignatureRevocation $revocation, User $approver): void
    {
        if ((int) $revocation->requested_by === (int) $approver->id) {
            throw new SignatureException('ผู้ขอเพิกถอนกับผู้ตัดสินต้องไม่ใช่คนเดียวกัน');
        }
    }
}
