<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Services\OrgScopeResolver;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\Gate;

/**
 * คิวงาน — "หนังสือฉบับใดรอฉันดำเนินการอยู่" (SoT §11 · NAV §4.7)
 *
 * **ที่มา:** การตรวจ navigation เมื่อ 2026-09-05 พบว่าระบบตอบคำถามได้เฉพาะ
 * "ฉันทำอะไรกับหนังสือ**ฉบับนี้**ได้บ้าง" (`DocumentWorkflowService::stateFor`)
 * แต่ **ไม่มีคำถามย้อนกลับ** · รองผู้อำนวยการและผู้อำนวยการซึ่งงานประจำวันคือ
 * การพิจารณาและลงนาม จึงต้องเปิดทะเบียนแล้วไล่เปิดทีละฉบับเพื่อหาว่าฉบับไหน
 * ถึงคิวตน
 *
 * **ไม่สร้างกติกาสิทธิ์ใหม่แม้แต่ข้อเดียว**
 *
 * ใครทำอะไรได้ ตัดสินด้วย `WorkflowEngine::availableTransitions()` ซึ่งเป็น
 * ตัวเดียวกับที่หน้ารายละเอียดหนังสือและ endpoint การลงมือทำใช้ · ถ้าคิวนี้
 * ตัดสินเอง มันจะแสดงงานที่กดแล้วถูกปฏิเสธ หรือซ่อนงานที่ทำได้จริง — ทั้งสองอย่าง
 * แย่กว่าไม่มีคิวเลย
 *
 * กรองด้วย `DocumentPolicy::view()` อีกชั้น เพราะการเห็นรายการหนังสือคือการ
 * เปิดเผยว่ามีหนังสือฉบับนั้นอยู่
 */
class WorkQueueService
{
    /**
     * จำนวนเรื่องที่กำลังเดินอยู่สูงสุดที่ยอมไล่ตรวจในหนึ่งคำขอ
     *
     * การตัดสินว่าใครทำอะไรได้ต้องถามตัวเครื่องยนต์ทีละเรื่อง จึงเป็น query
     * ต่อเรื่อง · ขอบเขตนี้กันไม่ให้หน้าจอเดียวลากฐานข้อมูลทั้งระบบเมื่อมีเรื่อง
     * ค้างมาก · เรื่องที่เกินขอบเขตยังหาเจอได้จากทะเบียนหนังสือตามปกติ
     */
    private const SCAN_LIMIT = 300;

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly OrgScopeResolver $scopes,
    ) {}

    /**
     * หนังสือที่ผู้ใช้คนนี้ลงมือทำได้ ณ ตอนนี้
     *
     * @return array<string,mixed>
     */
    public function pendingFor(User $user): array
    {
        $instances = WorkflowInstance::query()
            ->where('subject_type', (new Document)->getMorphClass())
            ->where('status', WorkflowInstance::STATUS_RUNNING)
            ->latest('id')
            ->limit(self::SCAN_LIMIT)
            ->get();

        $documents = Document::query()
            ->whereIn('id', $instances->pluck('subject_id')->unique())
            ->with(['documentType:id,name', 'ownerOrgUnit:id,name', 'documentNumber:id,formatted'])
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($instances as $instance) {
            $document = $documents->get($instance->subject_id);

            if ($document === null || ! Gate::forUser($user)->allows('view', $document)) {
                continue;
            }

            $actions = $this->engine->availableTransitions($instance, $user);

            if ($actions === []) {
                continue;
            }

            $step = $this->engine->currentStep($instance);

            $items[] = [
                'document_id' => $document->id,
                'uuid' => $document->uuid,
                'subject' => $document->subject,
                'direction' => $document->direction,
                'urgency' => $document->urgency,
                'confidentiality' => $document->confidentiality,
                'document_type' => $document->documentType?->name,
                'owner_org_unit' => $document->ownerOrgUnit?->name,
                'document_number' => $document->documentNumber?->formatted,
                'current_step' => $step?->name,
                'step_code' => $step?->code,
                // ปุ่มที่หน้าจอจะแสดง มาจากตัวตัดสินเดียวกับที่ endpoint ใช้
                'actions' => $actions,
                'waiting_since' => $instance->updated_at?->toIso8601String(),
            ];
        }

        return [
            'pending' => $items,
            'count' => count($items),
            /*
             * บอกตรง ๆ เมื่อไล่ตรวจไม่ครบ — คิวที่ตัดท้ายเงียบ ๆ ทำให้ผู้ใช้เชื่อว่า
             * ไม่มีงานเหลือแล้ว ทั้งที่ยังมี
             */
            'scan_limited' => $instances->count() >= self::SCAN_LIMIT,
        ];
    }

    /**
     * จำนวนงานที่รออยู่ — สำหรับป้ายบนเมนู
     *
     * ใช้เส้นทางเดียวกับรายการเต็ม เพื่อไม่ให้ตัวเลขกับรายการไม่ตรงกัน ซึ่งเป็น
     * ความผิดพลาดที่ผู้ใช้จับได้ทันทีและทำให้ไม่เชื่อถือทั้งหน้าจอ
     */
    public function pendingCountFor(User $user): int
    {
        return $this->pendingFor($user)['count'];
    }
}
