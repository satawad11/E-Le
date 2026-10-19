<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Services\Import\PersonnelImporter;
use App\Services\Personnel\PersonnelChangeRequestService;
use App\Services\Personnel\PersonnelPresenter;
use App\Services\Personnel\SensitiveReveal;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API ข้อมูลบุคลากร (R03-3/R03-5 · CORE-02) — ทุกการส่งออกผ่าน PersonnelPresenter และ SensitiveReveal
 */
class PersonnelController extends Controller
{
    public function show(Request $request, Personnel $personnel, PersonnelPresenter $presenter): JsonResponse
    {
        $this->authorizeResource('personnel.profile.view', $personnel);

        return response()->json($presenter->present($personnel->load(['type', 'profile']), $request->user()));
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
            'entity' => ['required', Rule::in(['personnel', 'personnel_profile'])],
            'payload' => ['required', 'array', 'min:1'],
            'evidence_document_uuids' => ['nullable', 'array'],
            'evidence_document_uuids.*' => ['uuid'],
        ]);

        $changeRequest = $service->submit($request->user(), $personnel, $data['entity'], $data['payload'], $data['evidence_document_uuids'] ?? []);

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
}
