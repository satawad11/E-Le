<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Services\Import\PersonnelImporter;
use App\Services\Personnel\PersonnelChangeRequestService;
use App\Services\Personnel\PersonnelPresenter;
use App\Services\Personnel\SensitiveReveal;
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
        return response()->json($presenter->present($personnel->load(['type', 'profile']), $request->user()));
    }

    public function reveal(Request $request, Personnel $personnel, SensitiveReveal $reveal): JsonResponse
    {
        $values = $reveal->reveal(
            $personnel->load('profile'),
            (array) $request->input('fields', []),
            $request->input('reason'),
            $request->user(),
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        );

        return response()->json(['fields' => $values]);
    }

    public function submitChange(Request $request, Personnel $personnel, PersonnelChangeRequestService $service): JsonResponse
    {
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
        $data = $request->validate([
            'decision' => ['required', Rule::in(PersonnelChangeRequestService::DECISIONS)],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $changeRequest = $service->review($request->user(), $changeRequest, $data['decision'], $data['review_note'] ?? null);

        return response()->json(['uuid' => $changeRequest->uuid, 'status' => $changeRequest->status]);
    }

    public function import(Request $request, PersonnelImporter $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'commit' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath().'.'.$file->getClientOriginalExtension();
        copy($file->getRealPath(), $path);

        try {
            $result = $importer->importFile($path, $request->boolean('commit'), $request->user()->id);
        } finally {
            @unlink($path);
        }

        return response()->json($result, $result['error_count'] === 0 ? 200 : 422);
    }
}
