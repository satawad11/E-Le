<?php

namespace App\Services\Access;

use App\Models\Authz\AccessReview;
use App\Models\Authz\AccessReviewItem;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\RoleGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ทบทวนสิทธิ์พิเศษรายภาคเรียน (CORE-04 · R05-5)
 *
 * - ออกรอบ (`access.review.manage`): รายการ = role grant และ override แบบอนุญาตที่ยังมีผลทุกรายการ
 * - ยืนยัน/ถอน (`access.review.confirm` ตาม scope หน่วย · `not_own`): ถอน = สิ้นสุดสิทธิ์ทันที
 * - ปิดรอบ (ด้วยมือ หรือ `accounts:reconcile` เมื่อเลยกำหนด): รายการที่ไม่มีผู้ยืนยัน → ถอนอัตโนมัติ (`expired`)
 */
class AccessReviewService
{
    public function __construct(private readonly PermissionAudit $audit) {}

    /** @param  array{name: string, period_start: string, period_end: string, due_at: string}  $data */
    public function open(User $actor, array $data): AccessReview
    {
        if (AccessReview::where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['review' => 'มีรอบทบทวนที่ยังเปิดอยู่ ปิดรอบเดิมก่อน']);
        }

        return DB::transaction(function () use ($actor, $data) {
            $review = AccessReview::create($data + ['status' => 'open', 'created_by' => $actor->id]);
            $today = today()->toDateString();

            $grants = RoleGrant::whereNull('ended_at')->whereDate('expires_at', '>=', $today)->get(['id', 'user_id']);
            foreach ($grants as $grant) {
                $review->items()->create(['subject_type' => AccessReviewItem::SUBJECT_ROLE_GRANT, 'subject_id' => $grant->id, 'user_id' => $grant->user_id]);
            }

            $overrides = PermissionOverride::whereNull('ended_at')->where('effect', PermissionOverride::EFFECT_ALLOW)
                ->whereDate('expires_at', '>=', $today)->get(['id', 'user_id']);
            foreach ($overrides as $override) {
                $review->items()->create(['subject_type' => AccessReviewItem::SUBJECT_OVERRIDE, 'subject_id' => $override->id, 'user_id' => $override->user_id]);
            }

            $this->audit->log('access_review.opened', $review, null, $data + ['items' => $grants->count() + $overrides->count()], $actor->id);

            return $review;
        });
    }

    /** controller ตรวจ access.review.confirm กับรายการผ่าน engine แล้ว (scope หน่วย + not_own) */
    public function decide(User $reviewer, AccessReviewItem $item, string $decision, ?string $note): AccessReviewItem
    {
        if ($item->review->status !== 'open') {
            throw ValidationException::withMessages(['review' => 'รอบทบทวนนี้ปิดแล้ว']);
        }
        if ($item->decision !== null) {
            throw ValidationException::withMessages(['item' => 'รายการนี้ยืนยันไปแล้ว']);
        }

        return DB::transaction(function () use ($reviewer, $item, $decision, $note) {
            $item->forceFill(['reviewer_id' => $reviewer->id, 'decision' => $decision, 'decided_at' => now(), 'note' => $note])->save();
            if ($decision === AccessReviewItem::REVOKED) {
                $this->endSubject($item, 'ทบทวนสิทธิ์: หัวหน้าหน่วยไม่ยืนยัน'.($note ? ' — '.$note : ''), $reviewer->id);
            }
            $this->audit->log('access_review_item.'.$decision, $item, null, ['decision' => $decision, 'note' => $note], $reviewer->id);

            return $item;
        });
    }

    public function close(AccessReview $review, ?User $actor = null): AccessReview
    {
        if ($review->status !== 'open') {
            throw ValidationException::withMessages(['review' => 'รอบทบทวนนี้ปิดแล้ว']);
        }

        return DB::transaction(function () use ($review, $actor) {
            $expired = 0;
            foreach ($review->items()->whereNull('decision')->get() as $item) {
                $item->forceFill(['decision' => AccessReviewItem::EXPIRED, 'decided_at' => now(), 'note' => 'ไม่มีผู้ยืนยันภายในกำหนด'])->save();
                $this->endSubject($item, 'ทบทวนสิทธิ์: ไม่มีผู้ยืนยันภายในกำหนด ถอนอัตโนมัติ', $actor?->id);
                $expired++;
            }

            $review->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $this->audit->log('access_review.closed', $review, ['status' => 'open'], ['status' => 'closed', 'auto_revoked' => $expired], $actor?->id);

            return $review;
        });
    }

    /** ปิดรอบที่เลยกำหนด — เรียกจาก `accounts:reconcile` */
    public function closeDue(): int
    {
        $due = AccessReview::where('status', 'open')->whereDate('due_at', '<', today()->toDateString())->get();
        foreach ($due as $review) {
            $this->close($review);
        }

        return $due->count();
    }

    /** @return array{total: int, decided: int, confirmed: int, revoked: int, pending: int} */
    public function progress(AccessReview $review): array
    {
        $decisions = $review->items()->pluck('decision');
        $pending = $decisions->filter(fn ($d) => $d === null)->count();

        return [
            'total' => $decisions->count(),
            'decided' => $decisions->count() - $pending,
            'confirmed' => $decisions->filter(fn ($d) => $d === AccessReviewItem::CONFIRMED)->count(),
            'revoked' => $decisions->filter(fn ($d) => in_array($d, [AccessReviewItem::REVOKED, AccessReviewItem::EXPIRED], true))->count(),
            'pending' => $pending,
        ];
    }

    private function endSubject(AccessReviewItem $item, string $reason, ?int $actorId): void
    {
        $subject = $item->subject();
        if ($subject === null || $subject->ended_at !== null) {
            return;
        }

        $subject instanceof RoleGrant
            ? $subject->forceFill(['ended_at' => now(), 'ended_by' => $actorId, 'end_reason' => $reason])->save()
            : $subject->forceFill(['ended_at' => now()])->save();

        $this->audit->log($item->subject_type.'.ended', $subject, ['ended_at' => null], ['ended_at' => now()->toIso8601String(), 'end_reason' => $reason], $actorId);
    }
}
