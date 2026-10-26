<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Services\Personnel\PersonnelRecordService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ประวัติการศึกษา การอบรม ใบอนุญาต (R-06 · CORE-02)
 *
 * - งานบุคคล: `personnel.profile.edit` (+ hr_field_guard ผ่าน engine ด้วยชื่อฟิลด์ที่แก้)
 * - การอบรม: `personnel.training.manage` ก็ได้ (ผู้ได้รับมอบ HR พัฒนาบุคลากร · ต้นแบบหน้า 12)
 * - ข้อมูลของตนเองแก้ตรงไม่ได้ทุกกรณี — ยื่นคำขอแก้ไข (`POST /api/personnel/{uuid}/change-requests`)
 */
class PersonnelRecordController extends Controller
{
    public function __construct(private readonly PersonnelRecordService $records) {}

    public function store(Request $request, Personnel $personnel, string $type): JsonResponse
    {
        $data = $this->records->validate($type, $request->all());
        $this->authorizeWrite($request, $personnel, $type, array_keys($data));

        return response()->json(['uuid' => $this->records->create($type, $personnel, $data, $request->user()->id)->uuid], 201);
    }

    public function update(Request $request, Personnel $personnel, string $type, string $record): JsonResponse
    {
        $model = $this->find($personnel, $type, $record);
        $data = $this->records->validate($type, $request->all(), true);
        $this->authorizeWrite($request, $personnel, $type, array_keys($data));

        return response()->json(['uuid' => $this->records->update($type, $model, $data, $request->user()->id)->uuid]);
    }

    public function destroy(Request $request, Personnel $personnel, string $type, string $record): JsonResponse
    {
        $model = $this->find($personnel, $type, $record);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $this->authorizeWrite($request, $personnel, $type, array_keys($this->records->rules($type)));
        $this->records->delete($type, $model, $request->user(), $reason);

        return response()->json(['message' => 'ลบรายการแล้ว']);
    }

    /** @param  array<int, string>  $fields */
    private function authorizeWrite(Request $request, Personnel $personnel, string $type, array $fields): void
    {
        $context = ['entity' => PersonnelRecordService::entityOf($type), 'fields' => $fields];
        $permissions = $type === 'trainings' ? ['personnel.training.manage', 'personnel.profile.edit'] : ['personnel.profile.edit'];

        $this->authorizeAnyResource($permissions, $personnel, $context);
        abort_if($request->user()->personnel_id === $personnel->id, 403, 'ข้อมูลของตนเองแก้ตรงไม่ได้ กรุณายื่นคำขอแก้ไขให้งานบุคคลตรวจ');
    }

    private function find(Personnel $personnel, string $type, string $uuid): Model
    {
        $model = PersonnelRecordService::modelOf($type);

        return $model::where('personnel_id', $personnel->id)->where('uuid', $uuid)->firstOrFail();
    }
}
