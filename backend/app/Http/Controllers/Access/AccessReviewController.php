<?php

namespace App\Http\Controllers\Access;

use App\Authz\PermissionEngine;
use App\Http\Controllers\Controller;
use App\Models\Authz\AccessReview;
use App\Models\Authz\AccessReviewItem;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\RoleGrant;
use App\Services\Access\AccessReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ทบทวนสิทธิ์รายภาคเรียน (PLAN §8.2 ช.5 · R05-5)
 *
 * - ออกรอบ/ปิดรอบ: `access.review.manage`
 * - ดูและยืนยันรายการ: `access.review.confirm` ตาม scope หน่วย (หัวหน้าหน่วย) · ผู้ถือ manage เห็นทุกรายการแต่ยืนยันไม่ได้ถ้าไม่มี confirm
 */
class AccessReviewController extends Controller
{
    public function __construct(
        private readonly AccessReviewService $reviews,
        private readonly PermissionEngine $engine,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AccessReview::orderByDesc('id')->limit(20)->get()->map(fn (AccessReview $r) => [
                'uuid' => $r->uuid,
                'name' => $r->name,
                'status' => $r->status,
                'period_start' => $r->period_start?->toDateString(),
                'period_end' => $r->period_end?->toDateString(),
                'due_at' => $r->due_at?->toDateString(),
                'closed_at' => $r->closed_at?->toIso8601String(),
            ] + $this->reviews->progress($r))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $review = $this->reviews->open($request->user(), $data);

        return response()->json(['uuid' => $review->uuid, 'status' => $review->status] + $this->reviews->progress($review), 201);
    }

    /** รายการที่ผู้ใช้ยืนยันได้ (confirm ตาม scope) — ผู้ถือ manage เห็นทุกรายการแบบอ่านอย่างเดียว */
    public function items(Request $request, AccessReview $review): JsonResponse
    {
        $actor = $request->user();
        $manage = $this->engine->allows($actor, 'access.review.manage');

        $items = $review->items()->with('user.personnel')->get()
            ->map(fn (AccessReviewItem $item) => [$item, $this->engine->allows($actor, 'access.review.confirm', $item)])
            ->filter(fn (array $pair) => $manage || $pair[1])
            ->map(fn (array $pair) => $this->presentItem($pair[0], $pair[1] && $review->status === 'open' && $pair[0]->decision === null))
            ->values();

        return response()->json(['data' => $items]);
    }

    public function decide(Request $request, AccessReview $review, AccessReviewItem $item): JsonResponse
    {
        abort_unless($item->access_review_id === $review->id, 404);
        $this->authorizeResource('access.review.confirm', $item);

        $data = $request->validate([
            'decision' => ['required', Rule::in([AccessReviewItem::CONFIRMED, AccessReviewItem::REVOKED])],
            'note' => ['required_if:decision,revoked', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->presentItem($this->reviews->decide($request->user(), $item, $data['decision'], $data['note'] ?? null)->load('user.personnel'), false));
    }

    public function close(Request $request, AccessReview $review): JsonResponse
    {
        $review = $this->reviews->close($review, $request->user());

        return response()->json(['uuid' => $review->uuid, 'status' => $review->status] + $this->reviews->progress($review));
    }

    /** @return array<string, mixed> */
    private function presentItem(AccessReviewItem $item, bool $canDecide): array
    {
        $subject = $item->subject();

        return [
            'id' => $item->id,
            'user' => ['uuid' => $item->user?->uuid, 'name' => $item->user?->personnel?->full_name ?? $item->user?->username],
            'subject_type' => $item->subject_type,
            'subject' => match (true) {
                $subject instanceof RoleGrant => ['label' => $subject->template?->name, 'order_number' => $subject->order_number, 'expires_at' => $subject->expires_at?->toDateString()],
                $subject instanceof PermissionOverride => ['label' => $subject->permission?->label, 'scope' => $subject->scope, 'expires_at' => $subject->expires_at?->toDateString()],
                default => null,
            },
            'decision' => $item->decision,
            'decided_at' => $item->decided_at?->toIso8601String(),
            'note' => $item->note,
            'can_decide' => $canDecide,
        ];
    }
}
