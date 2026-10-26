<?php

namespace App\Services\Personnel;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * คำขอแก้ไขข้อมูลบุคลากร (CORE-02 · Maker–Checker)
 *
 * - ยื่นได้เฉพาะเจ้าของข้อมูล และเฉพาะฟิลด์ `self_with_review` (ฟิลด์ `self` แก้เองได้ ฟิลด์ `hr_only` ยื่นไม่ได้)
 * - ผู้พิจารณาต้อง ≠ ผู้ยื่น และ ≠ เจ้าของข้อมูล และต้องมีสิทธิ์พิจารณา (canReviewChanges)
 * - ค่าเดิมแสดงต่อจนกว่าคำขอได้รับอนุมัติ — อนุมัติแล้วจึงบันทึกค่าใหม่ผ่าน PersonnelEditor::apply
 * - R-06: ยื่นเพิ่ม/แก้ประวัติรายหมวด (การศึกษา การอบรม ใบอนุญาต) ได้ด้วย — `entity_id` = แถวที่ขอแก้ (null = เพิ่มใหม่)
 *   อนุมัติแล้วบันทึกผ่าน PersonnelRecordService
 */
class PersonnelChangeRequestService
{
    public const DECISIONS = ['approved', 'returned', 'rejected'];

    public function __construct(
        private readonly PersonnelFieldAccess $access,
        private readonly FieldClassificationRegistry $classes,
        private readonly PersonnelEditor $editor,
        private readonly AuditLogger $audit,
        private readonly PersonnelRecordService $records,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $evidenceDocumentUuids
     */
    public function submit(User $requester, Personnel $personnel, string $entity, array $payload, array $evidenceDocumentUuids = [], ?string $recordUuid = null): PersonnelChangeRequest
    {
        if ($requester->personnel_id === null || $requester->personnel_id !== $personnel->id) {
            throw new AuthorizationException('ยื่นคำขอแก้ไขได้เฉพาะข้อมูลของตนเอง');
        }
        if ($payload === []) {
            throw ValidationException::withMessages(['payload' => 'ต้องระบุข้อมูลที่ต้องการแก้ไข']);
        }

        $entityId = null;
        if (isset(PersonnelRecordService::ENTITIES[$entity])) {
            $type = PersonnelRecordService::ENTITIES[$entity];
            if ($recordUuid !== null) {
                $model = PersonnelRecordService::modelOf($type);
                $entityId = $model::where('uuid', $recordUuid)->where('personnel_id', $personnel->id)->value('id');
                if ($entityId === null) {
                    throw ValidationException::withMessages(['record_uuid' => 'ไม่พบรายการที่ขอแก้ไขในข้อมูลของคุณ']);
                }
            }
            $payload = $this->records->validate($type, $payload, $recordUuid !== null);
        } elseif ($recordUuid !== null) {
            throw ValidationException::withMessages(['record_uuid' => 'หมวดนี้ไม่มีรายการย่อย']);
        }

        foreach (array_keys($payload) as $field) {
            if (! $this->classes->isClassified($entity, $field)) {
                throw ValidationException::withMessages([$field => "ไม่มีฟิลด์ {$field} ที่แก้ไขได้"]);
            }
            if ($this->classes->editableBy($entity, $field) !== 'self_with_review') {
                throw ValidationException::withMessages([$field => "ฟิลด์ {$field} ยื่นคำขอแก้ไขไม่ได้"]);
            }
        }

        $documentIds = $personnel->documents()->whereIn('uuid', $evidenceDocumentUuids)->pluck('id')->all();
        if (count($documentIds) !== count(array_unique($evidenceDocumentUuids))) {
            throw ValidationException::withMessages(['evidence' => 'เอกสารหลักฐานต้องเป็นของบุคลากรคนนี้']);
        }

        $request = PersonnelChangeRequest::create([
            'personnel_id' => $personnel->id,
            'requested_by' => $requester->id,
            'entity' => $entity,
            'entity_id' => $entityId,
            'payload' => $payload,
            'evidence_document_ids' => $documentIds,
            'status' => 'pending',
        ]);

        $this->audit->log('personnel_change_request.submitted', $request, null, null, ['entity' => $entity, 'fields' => array_keys($payload)], null, $requester->id);

        return $request;
    }

    public function review(User $reviewer, PersonnelChangeRequest $request, string $decision, ?string $note = null): PersonnelChangeRequest
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'ผลการพิจารณาไม่ถูกต้อง']);
        }
        if ($decision !== 'approved' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['review_note' => 'การส่งกลับหรือไม่อนุมัติต้องระบุเหตุผล']);
        }

        return DB::transaction(function () use ($reviewer, $request, $decision, $note): PersonnelChangeRequest {
            $request = PersonnelChangeRequest::with('personnel')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            // Maker–Checker: ผู้พิจารณาต้องไม่ใช่ผู้ยื่นและไม่ใช่เจ้าของข้อมูล
            if ($reviewer->id === $request->requested_by || ($reviewer->personnel_id !== null && $reviewer->personnel_id === $request->personnel_id)) {
                throw new AuthorizationException('ผู้ยื่นหรือเจ้าของข้อมูลพิจารณาคำขอนี้ไม่ได้');
            }
            if (! $this->access->canReviewChanges($reviewer, $request->personnel)) {
                throw new AuthorizationException('ไม่มีสิทธิ์พิจารณาคำขอแก้ไขข้อมูลของบุคลากรคนนี้');
            }
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'คำขอนี้พิจารณาไปแล้ว']);
            }

            if ($decision === 'approved') {
                $this->applyApproved($request, $reviewer);
            }

            $request->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->audit->log('personnel_change_request.'.$decision, $request, null, null, ['status' => $decision, 'note' => $note], $note, $reviewer->id);

            return $request;
        });
    }

    private function applyApproved(PersonnelChangeRequest $request, User $reviewer): void
    {
        $type = PersonnelRecordService::ENTITIES[$request->entity] ?? null;
        if ($type === null) {
            $this->editor->apply($request->personnel, $request->entity, $request->payload, $reviewer->id, 'personnel.change_request_applied');

            return;
        }

        if ($request->entity_id === null) {
            $this->records->create($type, $request->personnel, $request->payload, $reviewer->id, 'personnel.change_request_applied');

            return;
        }

        $model = PersonnelRecordService::modelOf($type);
        $record = $model::where('personnel_id', $request->personnel_id)->find($request->entity_id);
        if ($record === null) {
            throw ValidationException::withMessages(['entity_id' => 'รายการที่ขอแก้ไขถูกลบไปแล้ว — ส่งกลับให้ผู้ยื่น']);
        }
        $this->records->update($type, $record, $request->payload, $reviewer->id, 'personnel.change_request_applied');
    }
}
