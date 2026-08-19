<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentGeneratedFile;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Services\DocumentEngine\Providers\LeaveRequestDataProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Compatibility Layer ระหว่าง E-Leave กับ Document Engine
 *
 *   Existing System → (คลาสนี้) → Document Engine
 *
 * โมดูลการลาเรียกที่นี่ที่เดียว และที่นี่เป็นที่เดียวที่ตัดสินว่า
 * ใบลาใบหนึ่งควรออกเอกสารด้วย engine ใหม่หรือด้วยเส้นทางเดิม
 *
 * กติกาที่ยึดเสมอ:
 *   1. ปิดธง = เส้นทางเดิมทั้งหมด (LEGACY MODE)
 *   2. engine ล้มด้วยเหตุใดก็ตาม = ถอยไปเส้นทางเดิม ไม่ปล่อยให้ผู้ใช้พิมพ์เอกสารไม่ได้
 *   3. เอกสารของใบลาที่อนุมัติแล้วและเคยออกเป็นฉบับสมบูรณ์ = คืนไฟล์เดิมเสมอ ไม่เรนเดอร์ใหม่
 */
class LeaveDocumentGateway
{
    public const SOURCE_TYPE = 'Leave';

    public const SOURCE_TYPE_CANCELLATION = 'LeaveCancellation';

    public function __construct(private readonly DocumentEngine $engine) {}

    /** ใบลาใบนี้ออกเอกสารด้วย engine ใหม่ได้หรือไม่ */
    public function handles(Leave $leave): bool
    {
        if (! config('document_engine.enabled', false)) {
            return false;
        }

        $allowed = (array) config('document_engine.entities.leave_types', []);

        if ($allowed === []) {
            return false;
        }

        return in_array('*', $allowed, true)
            || in_array((string) $leave->leave_type_id, array_map('strval', $allowed), true);
    }

    /**
     * เนื้อไฟล์ PDF ของใบลา — คืน null เมื่อควรใช้เส้นทางเดิม
     *
     * เอกสารย้อนหลังยึด "ไฟล์ฉบับสมบูรณ์ที่เก็บไว้" ก่อนเสมอ ไม่เรนเดอร์ใหม่จาก
     * ข้อมูลบุคคล/ผู้บริหาร/แบบฟอร์มปัจจุบัน ตามข้อกำหนดเอกสารย้อนหลัง
     */
    public function pdfFor(Leave $leave, bool $isCancel = false, ?User $actor = null): ?string
    {
        if (! $this->handles($leave)) {
            return null;
        }

        $sourceType = $isCancel ? self::SOURCE_TYPE_CANCELLATION : self::SOURCE_TYPE;

        $existing = $this->engine->latestFor($sourceType, (string) $leave->id);

        if ($existing !== null && $existing->status === DocumentGeneratedFile::STATUS_FINALIZED) {
            $disk = Storage::disk('private_uploads');

            if ($disk->exists($existing->file_path)) {
                return $disk->get($existing->file_path);
            }
        }

        try {
            $result = $this->engine->generate(
                module: 'LEAVE',
                entityType: $isCancel ? 'LEAVE_CANCELLATION' : 'LEAVE_TYPE',
                entityId: $leave->leave_type_id,
                dataSource: LeaveRequestDataProvider::CODE,
                context: [
                    'leave' => $leave,
                    'stage' => $isCancel ? LeaveApproval::STAGE_CANCELLATION : LeaveApproval::STAGE_REQUEST,
                ],
                pinnedVersionId: null,
                actor: $actor,
            );
        } catch (\Throwable $exception) {
            /*
             * ยังไม่ได้ผูกแบบฟอร์ม ยังไม่ได้อัปโหลด PDF ต้นฉบับ หรือเรนเดอร์ล้ม
             * ล้วนต้องไม่ทำให้ผู้ใช้พิมพ์ใบลาไม่ได้ — ถอยไปเส้นทางเดิมเงียบ ๆ แล้วบันทึกไว้
             */
            Log::warning('Document Engine ออกเอกสารใบลาไม่สำเร็จ ใช้เส้นทางเดิมแทน', [
                'leave_id' => $leave->id,
                'is_cancel' => $isCancel,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $shouldFinalize = ! $isCancel && $leave->status === 'Approved';

        if ($shouldFinalize || config('document_engine.store_every_render', false)) {
            $this->engine->store(
                result: $result,
                module: 'LEAVE',
                sourceType: $sourceType,
                sourceId: (string) $leave->id,
                finalize: $shouldFinalize,
                actor: $actor,
            );
        }

        return $result->content;
    }
}
