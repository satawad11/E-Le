<?php

namespace App\Http\Controllers\Personnel;

use App\Authz\PermissionEngine;
use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\PersonnelDocument;
use App\Services\AuditLogger;
use App\Services\Personnel\PersonnelFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * เอกสารบุคลากร (R-06 · CORE-02) — เก็บบน disk `local` (storage/app/private) ไม่มี URL สาธารณะ
 *
 * - อัปโหลด: เจ้าของ (หลักฐานประกอบคำขอแก้ไข · `personnel.change_request.create`) หรืองานบุคคล (`personnel.profile.edit`)
 * - ดาวน์โหลด: ขอ signed URL อายุ 5 นาทีที่ผูกกับผู้ขอ แล้วเรียกด้วย token เดิม — route ดาวน์โหลดต้องผ่านทั้ง auth,
 *   `->permission()` และลายเซ็น (`signed:relative`) · ผู้ดูต้องเป็นเจ้าของหรือเห็นข้อมูลชั้นลับของบุคคลนั้น
 * - ลบ: งานบุคคลเท่านั้น ไม่ใช่เอกสารของตนเอง
 */
class PersonnelDocumentController extends Controller
{
    public const CATEGORIES = ['evidence', 'education', 'training', 'license', 'appointment', 'other'];

    public function __construct(
        private readonly PersonnelFiles $files,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, Personnel $personnel): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
        ]);
        $isSelf = $request->user()->personnel_id === $personnel->id;
        $this->authorizeResource($isSelf ? 'personnel.change_request.create' : 'personnel.profile.edit', $personnel);

        $document = $this->files->store($personnel, $data['file'], $data['category'], $request->user(), verified: ! $isSelf);

        return response()->json(['uuid' => $document->uuid, 'original_name' => $document->original_name, 'category' => $document->category], 201);
    }

    public function link(Request $request, Personnel $personnel, PersonnelDocument $document): JsonResponse
    {
        $this->authorizeView($request, $personnel, $document);

        $url = URL::temporarySignedRoute('personnel.documents.download', now()->addMinutes(5), ['document' => $document->uuid, 'u' => $request->user()->id], absolute: false);

        return response()->json(['url' => $url, 'expires_in' => 300]);
    }

    public function download(Request $request, PersonnelDocument $document): StreamedResponse
    {
        abort_unless((int) $request->query('u') === $request->user()->id, 403, 'ลิงก์นี้ออกให้ผู้ใช้อื่น');
        $this->authorizeView($request, $document->personnel, $document);

        $this->audit->log('personnel_document.downloaded', $document->personnel, null, null, ['document' => $document->uuid, 'category' => $document->category], null, $request->user()->id);

        return $this->files->download($document);
    }

    public function destroy(Request $request, Personnel $personnel, PersonnelDocument $document): JsonResponse
    {
        abort_unless($document->personnel_id === $personnel->id, 404);
        $this->authorizeResource('personnel.profile.edit', $personnel);
        abort_if($request->user()->personnel_id === $personnel->id, 403, 'ลบเอกสารของตนเองไม่ได้');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        $this->files->delete($document, $request->user(), $reason);

        return response()->json(['message' => 'ลบเอกสารแล้ว']);
    }

    private function authorizeView(Request $request, Personnel $personnel, PersonnelDocument $document): void
    {
        abort_unless($document->personnel_id === $personnel->id, 404);
        $this->authorizeResource('personnel.profile.view', $personnel);

        // ผู้ดูเห็นบุคคลนี้แล้ว จึงตอบ 403 (ไม่ใช่ 404) เมื่อไม่เห็นข้อมูลชั้นลับ
        abort_unless($request->user()->personnel_id === $personnel->id
            || app(PermissionEngine::class)->allows($request->user(), 'personnel.profile.view_confidential', $personnel), 403, 'เอกสารบุคลากรดูได้เฉพาะเจ้าของและผู้มีสิทธิ์ดูข้อมูลชั้นลับ');
    }
}
