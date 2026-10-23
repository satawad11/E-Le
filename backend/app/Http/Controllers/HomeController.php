<?php

namespace App\Http\Controllers;

use App\Authz\Grant;
use App\Authz\Manifest\ModuleRegistry;
use App\Authz\PermissionEngine;
use App\Models\Assignment;
use App\Models\Authz\AccessReview;
use App\Models\Authz\AccessReviewItem;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\RoleGrant;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Models\PersonnelLicense;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * หน้าหลัก (ต้นแบบหน้า 2 · R06-2) — ทุกตัวเลขคำนวณในขอบเขตสิทธิ์ของผู้ใช้ผ่าน PermissionEngine
 *
 * การ์ดสรุปแสดงเฉพาะที่ผู้ใช้มีสิทธิ์ · งานรอดำเนินการแยกตาม "ในฐานะตำแหน่งใด" · ตำแหน่งของฉัน · แจ้งเตือนที่เกี่ยวกับตนเอง
 * อยู่ใน SELF_SERVICE แบบเดียวกับ /api/me/menu เพราะเป็นข้อมูลของผู้ใช้เองที่สรุปจากสิทธิ์ที่มี (ทุกคนที่เข้าสู่ระบบต้องมีหน้าหลัก)
 */
class HomeController extends Controller
{
    public function __construct(private readonly PermissionEngine $engine, private readonly ModuleRegistry $registry) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = today()->toDateString();

        return response()->json([
            'greeting' => ['name' => $user->personnel?->first_name ?? $user->username, 'full_name' => $user->personnel?->full_name ?? $user->username, 'date' => $today],
            'cards' => $this->cards($user),
            'work' => $this->work($user),
            'positions' => $this->positions($user),
            'notices' => $this->notices($user),
            // โมดูลที่มีกลุ่มเมนูของตนเอง (ไม่รวมส่วนบุคคล) — โมดูลบริการภายใน เช่น workflow/document ไม่แสดง
            'modules' => collect($this->registry->all())->filter(fn (array $m) => $m['code'] !== 'core' && $m['menu_groups'] !== [])
                ->map(fn (array $m) => ['code' => $m['code'], 'name' => $m['name'], 'enabled' => $this->registry->isEnabled($m['code'])])->values(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(User $user): array
    {
        $cards = [];
        $wide = fn (string $p) => collect($this->engine->scopesOf($user, $p))->contains(fn ($s) => $s !== 'own');

        if ($wide('personnel.profile.view')) {
            $grants = $this->engine->effective($user)->filter(fn (Grant $g) => $g->permission === 'personnel.profile.view' && $g->scope !== 'own');
            $cards[] = ['code' => 'personnel', 'label' => 'บุคลากรปฏิบัติงาน', 'route' => '/personnel',
                'value' => $this->engine->scopeQuery($user, 'personnel.profile.view', Personnel::query())->where('status', 'active')->count(),
                'note' => 'ขอบเขต: '.($grants->contains(fn (Grant $g) => $g->scope === 'all') ? 'ทั้งวิทยาลัย' : 'หน่วยงานในความรับผิดชอบ').' ('.$grants->first()?->label.')'];

            $expiring = PersonnelLicense::whereIn('personnel_id', $this->engine->scopeQuery($user, 'personnel.profile.view', Personnel::query())->select('personnel.id'))
                ->whereBetween('expires_at', [today()->toDateString(), today()->addDays(90)->toDateString()])->distinct()->count('personnel_id');
            $cards[] = ['code' => 'licenses', 'label' => 'ใบอนุญาตฯ ใกล้หมดอายุ', 'route' => '/personnel?chip=license_expiring', 'value' => $expiring, 'note' => 'ภายใน 90 วัน'];
        }
        if ($wide('personnel.change_request.review')) {
            $pending = $this->reviewQueue($user);
            $oldest = $pending->min('created_at');
            $cards[] = ['code' => 'change_requests', 'label' => 'คำขอแก้ไขรอตรวจ', 'route' => '/personnel/change-requests', 'value' => $pending->count(),
                'note' => $oldest ? 'เก่าสุด '.(int) $oldest->diffInDays(now()).' วัน' : 'ไม่มีคำขอค้าง'];
        }
        if ($this->engine->allows($user, 'access.account.view')) {
            $cards[] = ['code' => 'accounts_pending', 'label' => 'บัญชีรอเปิดใช้', 'route' => '/access/accounts?status=pending',
                'value' => $this->engine->scopeQuery($user, 'access.account.view', User::query())->where('status', User::STATUS_PENDING)->count(),
                'note' => 'เปิดอัตโนมัติเมื่อบุคลากรเริ่มงาน'];
        }

        return $cards;
    }

    /** @return array<int, array<string, mixed>> งานรอดำเนินการ แยกตามบทบาทที่ทำให้ต้องทำงานนั้น */
    private function work(User $user): array
    {
        $groups = [];

        $queue = $this->reviewQueue($user);
        if ($queue->isNotEmpty()) {
            $grant = $this->engine->can($user, 'personnel.change_request.review')->grant;
            $groups[] = [
                'as' => $grant?->label ?? 'ผู้ตรวจข้อมูลบุคลากร',
                'items' => $queue->take(5)->map(fn (PersonnelChangeRequest $r) => [
                    'title' => 'ขอแก้ไข'.self::ENTITY_LABELS[$r->entity].' · '.$r->personnel->full_name,
                    'meta' => 'ยื่น',
                    'date' => $r->created_at?->toDateString(),
                    'route' => '/personnel/change-requests',
                    'action' => 'ตรวจสอบ',
                ])->values(),
                'total' => $queue->count(),
                'route' => '/personnel/change-requests',
                'note' => 'คำขอแก้ไขที่คุณยื่นเองจะไม่ปรากฏในรายการนี้ ระบบส่งให้เจ้าหน้าที่คนอื่นตรวจตามหลัก Maker–Checker',
            ];
        }

        $review = AccessReview::where('status', 'open')->latest('id')->first();
        if ($review !== null && $this->engine->allows($user, 'access.review.confirm')) {
            $items = $review->items()->whereNull('decision')->with('user.personnel')->get()
                ->filter(fn (AccessReviewItem $i) => $this->engine->allows($user, 'access.review.confirm', $i));
            if ($items->isNotEmpty()) {
                $groups[] = [
                    'as' => $this->engine->can($user, 'access.review.confirm')->grant?->label ?? 'ผู้ยืนยันสิทธิ์ของหน่วย',
                    'items' => $items->take(5)->map(fn (AccessReviewItem $i) => [
                        'title' => 'ยืนยันสิทธิ์พิเศษ · '.($i->user?->personnel?->full_name ?? $i->user?->username),
                        'meta' => $review->name.' · ครบกำหนด',
                        'date' => $review->due_at?->toDateString(),
                        'route' => null,
                        'action' => null,
                    ])->values(),
                    'total' => $items->count(),
                    'route' => null,
                    'note' => 'รายการที่ไม่ยืนยันภายในกำหนดจะถูกถอนอัตโนมัติ',
                ];
            }
        }

        if ($this->engine->allows($user, 'access.change.approve')) {
            $requests = PermissionChangeRequest::where('status', 'pending')->where('requested_by', '!=', $user->id)->orderBy('created_at')->get()
                ->filter(fn (PermissionChangeRequest $r) => ($r->target['user_id'] ?? null) !== $user->id);
            if ($requests->isNotEmpty()) {
                $groups[] = [
                    'as' => $this->engine->can($user, 'access.change.approve')->grant?->label ?? 'ผู้ยืนยันคำขอเปลี่ยนสิทธิ์',
                    'items' => $requests->take(5)->map(fn (PermissionChangeRequest $r) => [
                        'title' => 'คำขอเปลี่ยนสิทธิ์ · '.($r->diff['template_name'] ?? $r->diff['permission'] ?? $r->diff['action'] ?? ''),
                        'meta' => 'ยื่น',
                        'date' => $r->created_at?->toDateString(),
                        'route' => null,
                        'action' => null,
                    ])->values(),
                    'total' => $requests->count(),
                    'route' => null,
                    'note' => null,
                ];
            }
        }

        return $groups;
    }

    /** @return array<int, array<string, mixed>> */
    private function positions(User $user): array
    {
        $out = [];
        if ($user->personnel_id !== null) {
            $rows = Assignment::with(['position', 'orgUnit.type'])->where('personnel_id', $user->personnel_id)->whereNull('end_reason')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()->toDateString()))
                ->orderByRaw("case kind when 'primary' then 0 when 'management' then 1 when 'acting' then 2 else 3 end")->get();
            foreach ($rows as $a) {
                $out[] = [
                    'kind' => $a->kind,
                    'level' => $a->kind === Assignment::KIND_PRIMARY ? 5 : $a->orgUnit?->type?->level,
                    'position' => $a->position?->name,
                    'unit' => $a->orgUnit?->name,
                    'order_number' => $a->order_number,
                    'effective_from' => $a->effective_from?->toDateString(),
                    'effective_to' => $a->effective_to?->toDateString(),
                    'upcoming' => $a->effective_from?->isFuture() ?? false,
                ];
            }
        }

        $grants = RoleGrant::with('template')->where('user_id', $user->id)->whereNull('ended_at')
            ->whereDate('expires_at', '>=', today()->toDateString())->get();
        foreach ($grants as $g) {
            $out[] = ['kind' => 'grant', 'level' => null, 'position' => $g->template?->name, 'unit' => null, 'order_number' => $g->order_number,
                'effective_from' => $g->effective_from?->toDateString(), 'effective_to' => $g->expires_at?->toDateString(), 'upcoming' => false];
        }

        return $out;
    }

    /** @return array<int, array<string, ?string>> แจ้งเตือนที่เกี่ยวกับผู้ใช้เอง */
    private function notices(User $user): array
    {
        $notices = [];
        $soon = today()->addDays(30)->toDateString();

        if ($user->personnel_id !== null) {
            foreach (Assignment::with(['position', 'orgUnit'])->where('personnel_id', $user->personnel_id)->whereNull('end_reason')->get() as $a) {
                if ($a->effective_from?->isFuture() && $a->effective_from->toDateString() <= $soon) {
                    $notices[] = ['title' => 'คำสั่งมีผล', 'date' => $a->effective_from->toDateString(), 'text' => $a->position?->name.' · '.$a->orgUnit?->name.' — สิทธิ์จะปรับอัตโนมัติเมื่อถึงวัน'];
                }
                if ($a->effective_to !== null && ! $a->effective_to->isPast() && $a->effective_to->toDateString() <= $soon) {
                    $notices[] = ['title' => $a->kind === Assignment::KIND_ACTING ? 'รักษาการหมดวาระ' : 'สิ้นสุดตำแหน่ง', 'date' => $a->effective_to->toDateString(), 'text' => $a->position?->name.' · '.$a->orgUnit?->name];
                }
            }
            $returned = PersonnelChangeRequest::where('personnel_id', $user->personnel_id)->where('status', 'returned')->latest('reviewed_at')->first();
            if ($returned !== null) {
                $notices[] = ['title' => 'คำขอแก้ไขถูกส่งกลับ', 'date' => $returned->reviewed_at?->toDateString(), 'text' => (string) $returned->review_note];
            }
        }
        foreach (RoleGrant::with('template')->where('user_id', $user->id)->whereNull('ended_at')->whereBetween('expires_at', [today()->toDateString(), $soon])->get() as $g) {
            $notices[] = ['title' => 'ชุดสิทธิ์ใกล้หมดอายุ', 'date' => $g->expires_at->toDateString(), 'text' => (string) $g->template?->name];
        }

        return $notices;
    }

    /** @return Collection<int, PersonnelChangeRequest> */
    private function reviewQueue(User $user)
    {
        return $this->engine->scopeQuery($user, 'personnel.change_request.review', PersonnelChangeRequest::where('status', 'pending'))
            ->where('requested_by', '!=', $user->id)
            ->where('personnel_id', '!=', $user->personnel_id ?? 0)
            ->with('personnel')->orderBy('created_at')->get();
    }

    private const ENTITY_LABELS = [
        'personnel' => 'ข้อมูลทั่วไป', 'personnel_profile' => 'ข้อมูลส่วนตัว', 'personnel_education' => 'วุฒิการศึกษา',
        'personnel_training' => 'ประวัติการอบรม', 'personnel_license' => 'ใบอนุญาตประกอบวิชาชีพ',
    ];
}
