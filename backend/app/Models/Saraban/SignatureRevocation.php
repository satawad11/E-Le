<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * คำขอเพิกถอนลายเซ็นหรือหนังสือ (SoT §14, §24)
 *
 * **แยกจาก `SignatureEvidence` โดยเจตนา** — หลักฐานการลงนามเป็นเหตุการณ์ที่
 * เกิดขึ้นแล้วและแก้ไม่ได้ · การเพิกถอนเป็นเหตุการณ์ใหม่ที่เกิดทีหลัง และ
 * **อาจถูกปฏิเสธ** · การเขียนทับลงในหลักฐานจะทำลายทั้งสองหลัก
 *
 * **คำขอที่ถูกปฏิเสธยังอยู่ในทะเบียน** — ต้องตอบได้เสมอว่าเคยมีคนขอเพิกถอน
 * หนังสือฉบับนี้ด้วยเหตุผลใด และใครเป็นผู้ปฏิเสธ
 */
#[Fillable([
    'uuid', 'document_id', 'signature_evidence_id', 'requested_by', 'requested_at',
    'reason', 'status', 'decided_by', 'decided_at', 'decision_note',
])]
class SignatureRevocation extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_signature_revocations';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * คำขอที่ตัดสินไปแล้วเปลี่ยนไม่ได้อีก
     *
     * การเปลี่ยนใจต้องเป็นคำขอใหม่ ไม่ใช่การแก้คำตัดสินเดิม — ไม่งั้นทะเบียน
     * จะตอบไม่ได้ว่าเคยตัดสินว่าอย่างไรมาก่อน
     */
    protected static function booted(): void
    {
        static::updating(function (self $revocation) {
            if ($revocation->getOriginal('status') !== self::STATUS_PENDING) {
                throw new LogicException('คำขอเพิกถอนที่ตัดสินไปแล้วแก้ไม่ได้');
            }
        });

        static::deleting(fn () => throw new LogicException('คำขอเพิกถอนลบไม่ได้'));
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function evidence()
    {
        return $this->belongsTo(SignatureEvidence::class, 'signature_evidence_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
