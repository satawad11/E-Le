<?php

namespace App\Http\Controllers\Personnel;

use App\Authz\Grant;
use App\Authz\PermissionEngine;
use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Authz\RoleGrant;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelLicense;
use App\Models\PersonnelTraining;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * รายชื่อบุคลากร (ต้นแบบหน้า 3 และ 12) และรายงานบุคลากร (R-06)
 *
 * - รายการ จำนวน และ chip ทุกตัวมาจาก PermissionEngine::scopeQuery ชุดเดียวกัน — ไม่มีการกรองที่หน้าเว็บ
 * - ค่าต่อแถวปิดบังตามชั้นที่ผู้ดูเห็นของบุคคลนั้น (PersonnelFieldAccess) แบบเดียวกับ PersonnelPresenter
 * - `access` บอกว่าผู้ดูเห็นรายการนี้ด้วยสิทธิ์จากแหล่งใด (แถบขอบเขตของหน้า 3/12) · `capabilities` = ทำได้/ทำไม่ได้ในหน้านี้
 */
class PersonnelDirectoryController extends Controller
{
    public const CHIPS = ['license_expiring', 'incomplete', 'pending_start', 'pending_order'];

    public function __construct(
        private readonly PermissionEngine $engine,
        private readonly PersonnelFieldAccess $fields,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'org_unit' => ['nullable', 'uuid'],
            'type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(Personnel::STATUSES)],
            'chip' => ['nullable', Rule::in(self::CHIPS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $actor = $request->user();
        $scoped = fn (): Builder => $this->engine->scopeQuery($actor, 'personnel.profile.view', Personnel::query());

        $query = $this->filter($scoped(), $data)->with(['type'])->orderBy('personnel_code');
        $page = $query->paginate($data['per_page'] ?? 25);
        $people = collect($page->items());

        $assignments = Assignment::with(['position', 'orgUnit.type'])->whereIn('personnel_id', $people->pluck('id'))
            ->effectiveOn(today()->toDateString())->get()->groupBy('personnel_id');
        $licenses = PersonnelLicense::whereIn('personnel_id', $people->pluck('id'))->whereNotNull('expires_at')
            ->orderBy('expires_at')->get()->groupBy('personnel_id');
        [$fyStart, $fyEnd] = self::fiscalYear();
        $hours = PersonnelTraining::whereIn('personnel_id', $people->pluck('id'))->whereBetween('start_date', [$fyStart, $fyEnd])
            ->selectRaw('personnel_id, sum(hours) as total')->groupBy('personnel_id')->pluck('total', 'personnel_id');

        $statusCounts = $scoped()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => $people->map(fn (Personnel $p) => $this->row($actor, $p, $assignments[$p->id] ?? collect(), $licenses[$p->id] ?? collect(), $hours[$p->id] ?? null))->values(),
            'meta' => [
                'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'counts' => collect(Personnel::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($statusCounts[$s] ?? 0)])->put('all', (int) $statusCounts->sum()),
                'chips' => collect(self::CHIPS)->mapWithKeys(fn ($chip) => [$chip => $this->filter($scoped(), ['chip' => $chip])->count()]),
                'fiscal_year' => (int) Carbon::parse($fyEnd)->year + 543,
                'access' => $this->accessSources($actor, 'personnel.profile.view'),
                'capabilities' => $this->capabilities($actor),
                'filters' => [
                    'units' => $this->engine->scopeQuery($actor, 'org.unit.view', OrgUnit::query())->with('type')->orderBy('path')->get()
                        ->map(fn (OrgUnit $u) => ['uuid' => $u->uuid, 'name' => $u->name, 'level' => $u->type?->level, 'depth' => $u->depth])->values(),
                    'types' => PersonnelType::where('is_active', true)->orderBy('id')->get(['code', 'name']),
                ],
            ],
        ]);
    }

    /** รายงานบุคลากร (R06-7): อัตรากำลัง · ใบอนุญาตใกล้หมดอายุ · ข้อมูลไม่ครบ — ในขอบเขต `personnel.report.view` */
    public function reports(Request $request): JsonResponse
    {
        $actor = $request->user();
        $scoped = fn (): Builder => $this->engine->scopeQuery($actor, 'personnel.report.view', Personnel::query());
        $working = fn (): Builder => $scoped()->whereIn('status', ['active', 'seconded', 'study_leave']);

        $byType = $working()->join('personnel_types', 'personnel_types.id', '=', 'personnel.personnel_type_id')
            ->selectRaw('personnel_types.name as name, count(*) as n')->groupBy('personnel_types.name')->orderByDesc('n')->get();

        $perUnit = Assignment::query()
            ->where('kind', Assignment::KIND_PRIMARY)->effectiveOn(today()->toDateString())
            ->whereIn('personnel_id', $working()->select('personnel.id'))
            ->selectRaw('org_unit_id, count(*) as n')->groupBy('org_unit_id')->pluck('n', 'org_unit_id');
        $byUnit = OrgUnit::whereIn('id', $perUnit->keys())->orderBy('path')->get(['id', 'name', 'path'])
            ->map(fn (OrgUnit $u) => (object) ['name' => $u->name, 'path' => $u->path, 'n' => $perUnit[$u->id]]);

        $expiring = PersonnelLicense::with('personnel')->whereIn('personnel_id', $working()->select('personnel.id'))
            ->whereBetween('expires_at', [today()->toDateString(), today()->addDays(90)->toDateString()])->orderBy('expires_at')->get();

        $incomplete = $this->filter($working(), ['chip' => 'incomplete'])->with('profile')->orderBy('personnel_code')->limit(200)->get();

        return response()->json([
            'headcount' => ['total' => $working()->count(), 'by_type' => $byType, 'by_unit' => $byUnit->map(fn ($r) => ['name' => $r->name, 'depth' => substr_count((string) $r->path, '/') - 2, 'n' => (int) $r->n])->values()],
            'licenses_expiring' => $expiring->map(fn ($l) => [
                'personnel' => ['uuid' => $l->personnel->uuid, 'personnel_code' => $l->personnel->personnel_code, 'full_name' => $l->personnel->full_name],
                'license_type' => $l->license_type, 'expires_at' => $l->expires_at?->toDateString(), 'days_left' => today()->diffInDays($l->expires_at),
            ])->values(),
            'incomplete' => $incomplete->map(fn (Personnel $p) => [
                'uuid' => $p->uuid, 'personnel_code' => $p->personnel_code, 'full_name' => $p->full_name,
                'missing' => array_values(array_filter([
                    blank($p->profile?->email_work) ? 'อีเมลวิทยาลัย' : null,
                    ! Assignment::where('personnel_id', $p->id)->where('kind', Assignment::KIND_PRIMARY)->effectiveOn(today()->toDateString())->exists() ? 'ตำแหน่งหลัก' : null,
                    $p->type?->has_license && ! $p->licenses()->exists() ? 'ใบอนุญาตประกอบวิชาชีพ' : null,
                ])),
            ])->values(),
            'access' => $this->accessSources($actor, 'personnel.report.view'),
        ]);
    }

    // ------------------------------------------------------------------ ภายใน

    /** @param  array<string, mixed>  $data */
    private function filter(Builder $query, array $data): Builder
    {
        $today = today()->toDateString();

        if (! empty($data['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $data['q']).'%';
            $query->where(fn (Builder $w) => $w->where('personnel_code', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
        if (! empty($data['status'])) {
            $query->where('personnel.status', $data['status']);
        }
        if (! empty($data['type'])) {
            $query->whereHas('type', fn (Builder $t) => $t->where('code', $data['type']));
        }
        if (! empty($data['org_unit'])) {
            $path = OrgUnit::where('uuid', $data['org_unit'])->value('path');
            $query->whereIn('personnel.id', Assignment::where('kind', Assignment::KIND_PRIMARY)->effectiveOn($today)
                ->whereIn('org_unit_id', OrgUnit::where('path', 'like', ($path ?? '#none#').'%')->select('id'))->select('personnel_id'));
        }

        return match ($data['chip'] ?? null) {
            'license_expiring' => $query->whereHas('licenses', fn (Builder $l) => $l->whereBetween('expires_at', [$today, today()->addDays(90)->toDateString()])),
            'incomplete' => $query->where(fn (Builder $w) => $w
                ->whereDoesntHave('profile', fn (Builder $p) => $p->whereNotNull('email_work')->where('email_work', '!=', ''))
                ->orWhereNotIn('personnel.id', Assignment::where('kind', Assignment::KIND_PRIMARY)->effectiveOn($today)->select('personnel_id'))
                ->orWhere(fn (Builder $l) => $l->whereHas('type', fn (Builder $t) => $t->where('has_license', true))->whereDoesntHave('licenses'))),
            'pending_start' => $query->where('personnel.status', 'pending'),
            'pending_order' => $query->whereIn('personnel.id', Assignment::whereDate('effective_from', '>', $today)->whereNull('end_reason')->select('personnel_id')),
            default => $query,
        };
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     * @param  Collection<int, PersonnelLicense>  $licenses
     * @return array<string, mixed>
     */
    private function row(User $actor, Personnel $p, Collection $assignments, Collection $licenses, mixed $hours): array
    {
        $visible = $this->fields->visibleClasses($actor, $p);
        $internal = in_array('internal', $visible, true);
        $primary = $assignments->firstWhere('kind', Assignment::KIND_PRIMARY);
        $next = $licenses->first(fn ($l) => $l->expires_at?->gte(today())) ?? $licenses->last();
        $isSelf = $actor->personnel_id === $p->id;

        return [
            'uuid' => $p->uuid,
            'personnel_code' => $internal ? $p->personnel_code : null,
            'full_name' => $p->full_name,
            'type' => $p->type?->name,
            'status' => $internal ? $p->status : null,
            'started_at' => $internal ? $p->started_at?->toDateString() : null,
            'primary' => $primary ? ['position' => $primary->position?->name, 'unit' => $primary->orgUnit?->name] : null,
            'roles' => $assignments->filter(fn (Assignment $a) => $a->kind !== Assignment::KIND_PRIMARY)->map(fn (Assignment $a) => [
                'kind' => $a->kind, 'position' => $a->position?->name, 'unit' => $a->orgUnit?->name, 'level' => $a->orgUnit?->type?->level,
                'effective_to' => $a->effective_to?->toDateString(),
            ])->values(),
            'license' => ! $internal ? null : ($p->type?->has_license === false && $licenses->isEmpty() ? ['required' => false] : [
                'required' => true,
                'expires_at' => $next?->expires_at?->toDateString(),
                'days_left' => $next?->expires_at ? (int) today()->diffInDays($next->expires_at, false) : null,
            ]),
            'training_hours' => $internal ? ($hours !== null ? (float) $hours : 0.0) : null,
            'is_self' => $isSelf,
            'abilities' => [
                'manage_training' => ! $isSelf && $this->engine->allows($actor, 'personnel.training.manage', $p),
                'edit' => ! $isSelf && $this->engine->allows($actor, 'personnel.profile.edit', $p),
            ],
        ];
    }

    /**
     * แหล่งสิทธิ์ที่ทำให้เห็นรายการนี้ (แถบขอบเขตของหน้า 3/12)
     *
     * @return array<int, array<string, mixed>>
     */
    private function accessSources(User $actor, string $permission): array
    {
        $grants = $this->engine->effective($actor)->filter(fn (Grant $g) => $g->permission === $permission && $g->scope !== 'own');
        $unitNames = OrgUnit::whereIn('id', $grants->flatMap(fn (Grant $g) => array_keys($g->anchors))->unique())->pluck('name', 'id');
        $roleGrants = RoleGrant::with('approver.personnel')->whereIn('id', $grants->where('sourceType', Grant::SOURCE_ROLE_GRANT)->pluck('sourceId'))->get()->keyBy('id');

        return $grants->map(function (Grant $g) use ($unitNames, $roleGrants) {
            $rg = $g->sourceType === Grant::SOURCE_ROLE_GRANT ? $roleGrants[$g->sourceId] ?? null : null;

            return [
                'scope' => $g->scope,
                'source_type' => $g->sourceType,
                'label' => $g->label,
                'template' => $g->template,
                'units' => $g->scope === 'all' ? [] : collect(array_keys($g->anchors))->map(fn ($id) => $unitNames[$id] ?? null)->filter()->values()->all(),
                'expires_at' => $rg?->expires_at?->toDateString(),
                'order_number' => $rg?->order_number,
                'approved_by' => $rg?->approver?->personnel?->full_name,
            ];
        })->unique(fn ($a) => $a['label'].$a['scope'])->values()->all();
    }

    /** @return array<string, mixed> */
    private function capabilities(User $actor): array
    {
        $wide = fn (string $permission) => collect($this->engine->scopesOf($actor, $permission))->contains(fn ($s) => $s !== 'own');

        return [
            'view_scope' => $this->engine->widestScope($actor, 'personnel.profile.view'),
            'view_confidential' => $wide('personnel.profile.view_confidential'),
            'reveal' => $wide('personnel.profile.reveal'),
            'edit' => $wide('personnel.profile.edit'),
            'manage_training' => $wide('personnel.training.manage'),
            'report' => $wide('personnel.report.view'),
            'import' => $wide('personnel.import.manage'),
            'review' => $wide('personnel.change_request.review'),
            'manage_assignments' => $wide('personnel.assignment.manage'),
        ];
    }

    /** @return array{0: string, 1: string} ปีงบประมาณปัจจุบัน (1 ต.ค. – 30 ก.ย.) */
    public static function fiscalYear(): array
    {
        $start = today()->month >= 10 ? today()->copy()->setDate(today()->year, 10, 1) : today()->copy()->setDate(today()->year - 1, 10, 1);

        return [$start->toDateString(), $start->copy()->addYear()->subDay()->toDateString()];
    }
}
