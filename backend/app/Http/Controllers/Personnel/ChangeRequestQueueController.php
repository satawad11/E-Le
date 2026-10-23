<?php

namespace App\Http\Controllers\Personnel;

use App\Authz\PermissionEngine;
use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\PersonnelChangeRequest;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Services\Personnel\FieldClassificationRegistry;
use App\Services\Personnel\PersonnelPresenter;
use App\Services\Personnel\PersonnelRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * คำขอแก้ไขรอตรวจ (ต้นแบบหน้า 5 · R06-5) — `personnel.change_request.review` ตาม scope ของผู้ตรวจ
 *
 * - รายการกรองด้วย scopeQuery · เทียบค่าปัจจุบันกับค่าที่ขอ (ค่าเดิมยังมีผลจนกว่าอนุมัติ)
 * - ฟิลด์ชั้นอ่อนไหวแสดงแบบปิดบังทั้งสองฝั่ง — ผู้ตรวจเปิดดูค่าปัจจุบันได้ผ่าน reveal ตามปกติ
 * - คำขอที่ผู้ตรวจยื่นเองหรือเป็นข้อมูลของตนเอง แสดงแบบล็อกพร้อมเหตุผล (Maker–Checker)
 */
class ChangeRequestQueueController extends Controller
{
    public function __construct(
        private readonly PermissionEngine $engine,
        private readonly FieldClassificationRegistry $classes,
        private readonly PersonnelFieldAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(PersonnelChangeRequest::STATUSES)]]);
        $actor = $request->user();

        $rows = $this->engine->scopeQuery($actor, 'personnel.change_request.review', PersonnelChangeRequest::query())
            ->where('status', $data['status'] ?? 'pending')
            ->with(['personnel.profile', 'requester.personnel', 'reviewer.personnel'])
            ->orderBy('created_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (PersonnelChangeRequest $r) => $this->present($actor, $r))->values()]);
    }

    /** @return array<string, mixed> */
    private function present(User $actor, PersonnelChangeRequest $r): array
    {
        $personnel = $r->personnel;
        $type = PersonnelRecordService::ENTITIES[$r->entity] ?? null;
        $record = null;
        if ($type !== null && $r->entity_id !== null) {
            $model = PersonnelRecordService::modelOf($type);
            $record = $model::find($r->entity_id);
        }
        $current = match (true) {
            $r->entity === 'personnel' => $personnel,
            $r->entity === 'personnel_profile' => $personnel->profile,
            default => $record,
        };
        $visible = $this->access->visibleClasses($actor, $personnel);

        $fields = [];
        foreach ($r->payload ?? [] as $field => $requested) {
            $class = $this->classes->isClassified($r->entity, $field) ? $this->classes->classOf($r->entity, $field) : 'sensitive';
            $before = $current?->{$field};
            $before = $before instanceof \DateTimeInterface ? $before->format('Y-m-d') : $before;
            $hidden = $class === 'sensitive' || ! in_array($class, $visible, true);
            $fields[] = [
                'field' => $field,
                'class' => $class,
                'current' => $hidden ? ($before === null ? null : PersonnelPresenter::mask((string) $before)) : $before,
                'requested' => $hidden ? ($requested === null ? null : PersonnelPresenter::mask((string) $requested)) : $requested,
                'masked' => $hidden,
            ];
        }

        $lock = match (true) {
            $r->requested_by === $actor->id => 'คุณตรวจคำขอนี้ไม่ได้ เพราะเป็นผู้ยื่นเอง ระบบส่งให้ผู้ตรวจคนอื่นตามหลัก Maker–Checker',
            $actor->personnel_id === $r->personnel_id => 'คุณตรวจคำขอแก้ไขข้อมูลของตนเองไม่ได้',
            default => null,
        };
        $home = Assignment::with('orgUnit')->where('personnel_id', $personnel->id)->where('kind', Assignment::KIND_PRIMARY)
            ->effectiveOn(today()->toDateString())->first();

        return [
            'uuid' => $r->uuid,
            'entity' => $r->entity,
            'is_new_record' => $type !== null && $r->entity_id === null,
            'status' => $r->status,
            'personnel' => ['uuid' => $personnel->uuid, 'full_name' => $personnel->full_name, 'personnel_code' => $personnel->personnel_code, 'unit' => $home?->orgUnit?->name],
            'requested_by' => $r->requester?->personnel?->full_name ?? $r->requester?->username,
            'created_at' => $r->created_at?->toIso8601String(),
            'reviewed_by' => $r->reviewer?->personnel?->full_name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'review_note' => $r->review_note,
            'fields' => $fields,
            'evidence' => PersonnelDocument::whereIn('id', $r->evidence_document_ids ?? [])->get()
                ->map(fn (PersonnelDocument $d) => ['uuid' => $d->uuid, 'original_name' => $d->original_name, 'mime' => $d->mime])->values(),
            'locked_reason' => $lock,
            'can_review' => $lock === null && $r->status === 'pending' && $this->engine->allows($actor, 'personnel.change_request.review', $r),
        ];
    }
}
