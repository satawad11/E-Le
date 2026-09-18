<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Services\OrgScopeResolver;
use App\Services\Saraban\InterAgencyDispatchService;
use Illuminate\Http\Request;

/**
 * จัดการการส่งออกและนำเข้าหนังสือราชการอิเล็กทรอนิกส์ข้ามสถานศึกษา / หน่วยงาน
 */
class InterAgencyDispatchController extends Controller
{
    public function __construct(
        private readonly InterAgencyDispatchService $dispatchService,
        private readonly OrgScopeResolver $scopeResolver,
    ) {}

    /**
     * ดาวน์โหลดแพ็กเกจหลักฐานอิเล็กทรอนิกส์ (Electronic Evidence Package ZIP)
     */
    public function downloadPackage(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $document = Document::findOrFail($id);
        $this->ensureScopedAccess($user, $document);

        $targetAgency = $request->query('target_agency_name', 'หน่วยงานภายนอก');
        $note = $request->query('note');

        $package = $this->dispatchService->buildEvidencePackage($document, $user, [
            'target_agency_name' => $targetAgency,
            'note' => $note,
        ]);

        return response()->download($package['zip_path'], $package['zip_filename'])->deleteFileAfterSend(true);
    }

    /**
     * ส่งแพ็กเกจหนังสือราชการไปยัง Webhook ปลายทางข้ามหน่วยงาน
     */
    public function dispatchWebhook(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureCanDistribute($user);

        $document = Document::findOrFail($id);
        $this->ensureScopedAccess($user, $document);

        $data = $request->validate([
            'webhook_url' => 'required|url',
            'secret_key' => 'required|string|min:8',
            'target_agency_name' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        $result = $this->dispatchService->dispatchToWebhook(
            $document,
            $data['webhook_url'],
            $data['secret_key'],
            $user,
            [
                'target_agency_name' => $data['target_agency_name'] ?? null,
                'note' => $data['note'] ?? null,
            ]
        );

        return response()->json([
            'message' => $result['success']
                ? 'ส่งหนังสือราชการไปยังหน่วยงานปลายทางสำเร็จ'
                : 'เกิดข้อผิดพลาดในการส่งหนังสือไปยังหน่วยงานปลายทาง',
            'data' => $result,
        ], $result['success'] ? 200 : 502);
    }

    /**
     * นำเข้าหนังสือราชการจากแพ็กเกจข้ามหน่วยงาน
     */
    public function importPackage(Request $request)
    {
        $user = $request->user();
        if (! $user->can('saraban.incoming.register')) {
            abort(403, 'ไม่มีสิทธิ์ลงทะเบียนรับหนังสือ');
        }

        $data = $request->validate([
            'manifest' => 'required|array',
            'manifest.document.subject' => 'required|string',
            'pdf_file' => 'nullable|file|mimes:pdf|max:20480',
            'owner_org_unit_id' => 'nullable|integer|exists:org_units,id',
        ]);

        $ownerUnit = null;
        if (! empty($data['owner_org_unit_id'])) {
            $ownerUnit = OrgUnit::find($data['owner_org_unit_id']);
        }

        if (! $ownerUnit) {
            $ownerUnit = $user->orgUnit ?? OrgUnit::first();
        }

        if (! $ownerUnit) {
            abort(422, 'ไม่พบหน่วยงานสำหรับจัดเก็บหนังสือรับเข้า');
        }

        $document = $this->dispatchService->importPackage(
            $data['manifest'],
            $user,
            $ownerUnit,
            $request->file('pdf_file')
        );

        return response()->json([
            'message' => 'นำเข้าหนังสือราชการจากหน่วยงานภายนอกสำเร็จ',
            'document' => $document,
        ], 201);
    }

    private function ensureCanView($user): void
    {
        if (! $user->can('saraban.document.view') && ! $user->can('saraban.distribution.track')) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึงหนังสือ');
        }
    }

    private function ensureCanDistribute($user): void
    {
        if (! $user->can('saraban.distribution.track')) {
            abort(403, 'ไม่มีสิทธิ์ส่งเวียนหรือส่งหนังสือข้ามหน่วยงาน');
        }
    }

    private function ensureScopedAccess($user, Document $document): void
    {
        $allowedUnitIds = $this->scopeResolver->visibleOrgUnitIds($user, 'saraban.document.view', OrgScopeResolver::UNIT);
        if ($allowedUnitIds !== null && ! in_array($document->owner_org_unit_id, $allowedUnitIds, true)) {
            abort(403, 'หนังสือนี้อยู่นอกขอบเขตหน่วยงานที่ท่านมีสิทธิ์เข้าถึง');
        }
    }
}
