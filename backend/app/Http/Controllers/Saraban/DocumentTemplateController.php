<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\Overtime\OvertimeOrder;
use App\Services\Saraban\OfficialDocumentTemplateService;
use App\Services\Saraban\OfficialEmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * จัดการแม่แบบเอกสารราชการ ข้อความเกษียน และเทมเพลตอีเมลกลาง
 */
class DocumentTemplateController extends Controller
{
    public function __construct(
        private readonly OfficialDocumentTemplateService $templateService,
        private readonly OfficialEmailTemplateService $emailService,
    ) {}

    /**
     * ดึงรายการแม่แบบเอกสารราชการและชุดข้อความเกษียน ๔ ฝ่าย
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'templates' => $this->templateService->getTemplates(),
            'endorsement_presets' => $this->templateService->getVocationalEndorsementPresets(),
        ]);
    }

    /**
     * ดึงข้อความ Disclaimer สองภาษา และแม่แบบ Auto-reply
     */
    public function disclaimer(Request $request): JsonResponse
    {
        $institutionName = $request->query('institution', 'สถานศึกษาอาชีวศึกษา');

        return response()->json([
            'disclaimer' => $this->emailService->getBilingualDisclaimer(),
            'auto_reply_sample' => $this->emailService->getAutoReplyMessage($institutionName),
        ]);
    }

    /**
     * ดึงโครงร่างเอกสารราชการที่แปลงจากโมดูลอื่น (Cross-Module Draft Bridge)
     */
    public function crossModuleDraft(Request $request): JsonResponse
    {
        $sourceType = $request->query('source_type');
        $sourceId = $request->query('source_id');

        if ($sourceType === 'leave' && $sourceId) {
            $leave = Leave::with(['user', 'leaveType'])->findOrFail($sourceId);
            $draft = $this->templateService->createDraftPayloadFromLeave($leave);
        } elseif ($sourceType === 'overtime' && $sourceId) {
            $order = OvertimeOrder::findOrFail($sourceId);
            $draft = $this->templateService->createDraftPayloadFromOvertime($order);
        } elseif ($sourceType === 'evaluation') {
            $draft = $this->templateService->createDraftPayloadFromEvaluation($request->all());
        } else {
            return response()->json(['message' => 'ไม่พบข้อมูลต้นทางที่ระบุ'], 422);
        }

        return response()->json(['draft' => $draft]);
    }
}
