<?php

namespace App\Http\Controllers\Personnel;

use App\Authz\PermissionEngine;
use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Models\SensitiveAccessLog;
use App\Models\User;
use App\Services\Import\PersonnelImporter;
use App\Services\Personnel\FieldClassificationRegistry;
use App\Services\Personnel\PersonnelChangeRequestService;
use App\Services\Personnel\PersonnelEditor;
use App\Services\Personnel\PersonnelPresenter;
use App\Services\Personnel\PersonnelRecordService;
use App\Services\Personnel\SensitiveReveal;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API ข้อมูลบุคลากร (R03-3/R03-5 · R-06 · CORE-02) — ทุกการส่งออกผ่าน PersonnelPresenter และ SensitiveReveal
 */
class PersonnelController extends Controller
{
    public function __construct(
        private readonly PermissionEngine $engine,
        private readonly PersonnelFieldAccess $access,
        private readonly FieldClassificationRegistry $classes,
    ) {}

    public function show(Request $request, Personnel $personnel, PersonnelPresenter $presenter): JsonResponse
    {
        $this->authorizeResource('personnel.profile.view', $personnel);

        return response()->json($presenter->present($personnel->load(['type', 'profile']), $request->user()));
    }

    /**
     * แก้ข้อมูลรายคน (R-06) — งานบุคคลแก้ของผู้อื่น (`personnel.profile.edit` + hr_field_guard ผ่าน engine)
     * เจ้าของแก้เองได้เฉพาะฟิลด์ `self` (`self_with_review` ต้องยื่นคำขอ · `hr_only` แก้ไม่ได้) — PersonnelEditor บังคับซ้ำ
     */
    public function update(Request $request, Personnel $personnel, PersonnelEditor $editor, PersonnelPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(['personnel', 'personnel_profile'])],
            'changes' => ['required', 'array', 'min:1', 'max:20'],
        ]);
        $actor = $request->user();
        $fields = array_keys($data['changes']);
        $isSelf = $actor->personnel_id === $personnel->id;

        $this->authorizeResource('personnel.profile.view', $personnel); // มองไม่เห็นบุคคลนี้ = 404
        $this->authorizeResource($isSelf ? 'personnel.change_request.create' : 'personnel.profile.edit', $personnel, ['entity' => $data['entity'], 'fields' => $fields]);

        if (! $isSelf) {
            // แก้ได้เฉพาะชั้นที่ตนมองเห็น · ชั้นอ่อนไหวต้องมีสิทธิ์เปิดดู — ไม่เขียนทับค่าที่ผู้แก้ไม่เคยเห็น
            $visible = $this->access->visibleClasses($actor, $personnel);
            foreach ($fields as $field) {
                $class = $this->classes->isClassified($data['entity'], $field) ? $this->classes->classOf($data['entity'], $field) : 'sensitive';
                abort_if($class === 'sensitive' && ! $this->access->canReveal($actor, $personnel), 403, 'แก้ข้อมูลอ่อนไหวได้เฉพาะผู้มีสิทธิ์เปิดดู');
                abort_if($class !== 'sensitive' && ! in_array($class, $visible, true), 403, 'แก้ข้อมูลที่ตนมองไม่เห็นไม่ได้');
            }
        }

        $editor->update($actor, $personnel, $data['entity'], array_map(
            fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v,
            $data['changes'],
        ));

        return response()->json($presenter->present($personnel->fresh(['type', 'profile']), $actor));
    }

    /** ประวัติรายหมวด เอกสาร และคำขอที่รอตรวจของบุคคลนี้ — ปิดบังตามชั้นของผู้ดู */
    public function records(Request $request, Personnel $personnel): JsonResponse
    {
        $this->authorizeResource('personnel.profile.view', $personnel);
        $actor = $request->user();
        $visible = $this->access->visibleClasses($actor, $personnel);
        $isSelf = $actor->personnel_id === $personnel->id;

        $show = function (string $entity, array $row) use ($visible): array {
            $row['masked'] = [];
            foreach ($row as $field => $value) {
                if ($this->classes->isClassified($entity, $field) && ! in_array($this->classes->classOf($entity, $field), $visible, true)) {
                    $row[$field] = null;
                    $row['masked'][] = $field;
                } elseif ($value instanceof \DateTimeInterface) {
                    $row[$field] = $value->format('Y-m-d');
                }
            }

            return $row;
        };

        $canDocs = $isSelf || in_array('confidential', $visible, true);
        $pending = PersonnelChangeRequest::where('personnel_id', $personnel->id)->where('status', 'pending')->get();

        return response()->json([
            'educations' => $personnel->educations()->orderByDesc('graduated_year')->get()->map(fn ($r) => $show('personnel_education', [
                'uuid' => $r->uuid, 'level' => $r->level, 'degree' => $r->degree, 'major' => $r->major,
                'institution' => $r->institution, 'graduated_year' => $r->graduated_year, 'verified' => $r->verified_at !== null,
            ]))->values(),
            'trainings' => $personnel->trainings()->orderByDesc('start_date')->get()->map(fn ($r) => $show('personnel_training', [
                'uuid' => $r->uuid, 'course' => $r->course, 'organizer' => $r->organizer, 'hours' => $r->hours !== null ? (float) $r->hours : null,
                'start_date' => $r->start_date, 'end_date' => $r->end_date, 'verified' => $r->verified_at !== null,
            ]))->values(),
            'licenses' => $personnel->licenses()->orderByDesc('expires_at')->get()->map(fn ($r) => $show('personnel_license', [
                'uuid' => $r->uuid, 'license_type' => $r->license_type, 'license_number' => $r->license_number,
                'issued_at' => $r->issued_at, 'expires_at' => $r->expires_at, 'verified' => $r->verified_at !== null,
            ]))->values(),
            'documents' => $canDocs ? $personnel->documents()->orderByDesc('id')->get()->map(fn ($d) => [
                'uuid' => $d->uuid, 'category' => $d->category, 'original_name' => $d->original_name, 'mime' => $d->mime, 'size' => $d->size,
                'uploaded_at' => $d->created_at?->toIso8601String(), 'verified' => $d->verified_at !== null,
            ])->values() : null,
            'pending_changes' => $pending->map(fn (PersonnelChangeRequest $r) => [
                'uuid' => $r->uuid,
                'entity' => $r->entity,
                'fields' => array_keys($r->payload ?? []),
                'is_new_record' => isset(PersonnelRecordService::ENTITIES[$r->entity]) && $r->entity_id === null,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'can' => [
                'edit' => ! $isSelf && $this->engine->allows($actor, 'personnel.profile.edit', $personnel),
                'manage_training' => ! $isSelf && $this->engine->allows($actor, 'personnel.training.manage', $personnel),
                'request_change' => $isSelf && $this->engine->allows($actor, 'personnel.change_request.create', $personnel),
                'view_documents' => $canDocs,
                'manage_assignments' => $this->engine->allows($actor, 'personnel.assignment.manage', $personnel),
                'view_access_log' => $this->canSeeAccessLog($actor, $personnel),
            ],
        ]);
    }

    /** ประวัติการเปิดดูข้อมูลอ่อนไหว — เจ้าของดูของตนเองได้เสมอ (PDPA มาตรา 30) · ผู้อื่นต้องมีสิทธิ์เปิดดูหรือดูบันทึกการใช้งาน */
    public function accessLog(Request $request, Personnel $personnel): JsonResponse
    {
        $this->authorizeResource('personnel.profile.view', $personnel);
        abort_unless($this->canSeeAccessLog($request->user(), $personnel), 403, 'ไม่มีสิทธิ์ดูประวัติการเข้าถึงข้อมูลของบุคลากรคนนี้');

        $logs = SensitiveAccessLog::with('actor.personnel')->where('personnel_id', $personnel->id)->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $logs->map(fn (SensitiveAccessLog $l) => [
            'at' => $l->created_at?->toIso8601String(),
            'actor' => $l->actor?->personnel?->full_name ?? $l->actor?->username,
            'acting_as' => $l->acting_as,
            'fields' => $l->fields,
            'reason' => $l->reason,
        ])->values()]);
    }

    /** คำขอแก้ไขของบุคคลนี้ทุกสถานะ (เจ้าของดูของตนเอง · ผู้พิจารณาดูได้ตาม scope) */
    public function changeRequests(Personnel $personnel): JsonResponse
    {
        $this->authorizeAnyResource(['personnel.change_request.create', 'personnel.change_request.review'], $personnel);

        $rows = PersonnelChangeRequest::where('personnel_id', $personnel->id)->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (PersonnelChangeRequest $r) => [
            'uuid' => $r->uuid, 'entity' => $r->entity, 'fields' => array_keys($r->payload ?? []), 'status' => $r->status,
            'review_note' => $r->review_note, 'created_at' => $r->created_at?->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ])->values()]);
    }

    /** ชั้นความลับและผู้แก้ของทุกฟิลด์ — หน้าเว็บใช้แสดงปุ่มแก้/ยื่นคำขอ (server ตรวจซ้ำทุกครั้ง) */
    public function fieldRules(): JsonResponse
    {
        $out = [];
        foreach (['personnel', 'personnel_profile', ...array_keys(PersonnelRecordService::ENTITIES)] as $entity) {
            $out[$entity] = $this->classes->entity($entity);
        }

        return response()->json($out);
    }

    public function reveal(Request $request, Personnel $personnel, SensitiveReveal $reveal): JsonResponse
    {
        // นอก scope การดูประวัติ = ไม่บอกว่ามีอยู่ · ใน scope แต่ไม่มีสิทธิ์เปิดดู = 403 จาก SensitiveReveal
        $this->authorizeResource('personnel.profile.view', $personnel);

        $values = $reveal->reveal(
            $personnel->load('profile'),
            (array) $request->input('fields', []),
            $request->input('reason'),
            $request->user(),
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent(), 'acting_as' => ['permission' => 'personnel.profile.reveal']],
        );

        return response()->json(['fields' => $values]);
    }

    public function submitChange(Request $request, Personnel $personnel, PersonnelChangeRequestService $service): JsonResponse
    {
        $this->authorizeResource('personnel.change_request.create', $personnel);

        $data = $request->validate([
            'entity' => ['required', Rule::in(['personnel', 'personnel_profile', ...array_keys(PersonnelRecordService::ENTITIES)])],
            'record_uuid' => ['nullable', 'uuid'],
            'payload' => ['required', 'array', 'min:1'],
            'evidence_document_uuids' => ['nullable', 'array'],
            'evidence_document_uuids.*' => ['uuid'],
        ]);

        $changeRequest = $service->submit($request->user(), $personnel, $data['entity'], $data['payload'], $data['evidence_document_uuids'] ?? [], $data['record_uuid'] ?? null);

        return response()->json(['uuid' => $changeRequest->uuid, 'status' => $changeRequest->status], 201);
    }

    public function reviewChange(Request $request, PersonnelChangeRequest $changeRequest, PersonnelChangeRequestService $service): JsonResponse
    {
        $this->authorizeResource('personnel.change_request.review', $changeRequest);

        $data = $request->validate([
            'decision' => ['required', Rule::in(PersonnelChangeRequestService::DECISIONS)],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $changeRequest = $service->review($request->user(), $changeRequest, $data['decision'], $data['review_note'] ?? null);

        return response()->json(['uuid' => $changeRequest->uuid, 'status' => $changeRequest->status]);
    }

    public function import(Request $request, PersonnelImporter $importer, SheetReader $reader): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'sheet' => ['nullable', Rule::in(PersonnelImporter::SHEETS)],
            'commit' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('file');
        // นามสกุลจากเนื้อไฟล์ (mime) ไม่ใช่ชื่อไฟล์ที่ผู้ใช้ส่งมา — csv อาจได้ txt ซึ่ง SheetReader อ่านเป็น csv
        $extension = $file->guessExtension();
        abort_unless(in_array($extension, ['xlsx', 'csv', 'txt'], true), 422, 'รองรับเฉพาะไฟล์ .xlsx และ .csv');
        abort_if($extension !== 'xlsx' && ! $request->filled('sheet'), 422, 'ไฟล์ .csv ต้องระบุแผ่นงาน (sheet)');
        $path = $file->getRealPath().'.'.$extension;
        copy($file->getRealPath(), $path);

        try {
            $result = $importer->import($reader->read($path, $request->input('sheet')), $request->boolean('commit'), $request->user()->id);
        } finally {
            @unlink($path);
        }

        return response()->json($result, $result['error_count'] === 0 ? 200 : 422);
    }

    private function canSeeAccessLog(User $actor, Personnel $personnel): bool
    {
        return $actor->personnel_id === $personnel->id
            || $this->engine->allows($actor, 'personnel.profile.reveal', $personnel)
            || $this->engine->allows($actor, 'audit.log.view');
    }
}
