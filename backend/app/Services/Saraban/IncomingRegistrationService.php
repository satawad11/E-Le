<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\IncomingRegistration;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ลงทะเบียนรับหนังสือ (SoT §10 · INT-07)
 *
 * ลำดับตามที่ SoT §10 กำหนด:
 *
 *   ตรวจข้อมูล → ตรวจหนังสือซ้ำ → ลงทะเบียน → ออกเลขรับ → เวลาอ้างอิง
 *   → บันทึกต้นฉบับเป็นเวอร์ชันแรก → hash → audit
 *
 * ทั้งหมดอยู่ใน transaction เดียว — ถ้าขั้นใดล้ม ต้องไม่เหลือหนังสือที่ลงทะเบียน
 * ไปแล้วแต่ไม่มีเลขรับ หรือเลขรับที่ถูกออกไปแล้วแต่ไม่มีหนังสือผูกอยู่
 *
 * **การประทับตรารับในเช็คพอยต์นี้เป็น "ข้อมูล" ไม่ใช่ "ภาพบน PDF"** — เลขรับ
 * และวันเวลาที่รับถูกบันทึกไว้ครบแล้ว การวางตราลงบนหน้ากระดาษเป็นงานของ
 * PDF Workspace (INT-10) ซึ่งจะอ่านข้อมูลชุดนี้ไปวาง
 */
class IncomingRegistrationService
{
    /** ชุดเลขที่ใช้ออกเลขทะเบียนรับ (สร้างโดย migration ของ INT-07) */
    private const REGISTER_SERIES_CODE = 'incoming-register';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
        private readonly NumberingService $numbering,
        private readonly DocumentService $documents,
    ) {}

    /**
     * หนังสือที่อาจเป็นฉบับเดียวกับที่กำลังจะลงทะเบียน
     *
     * ตรวจจาก "เลขที่ต้นทาง + ผู้ส่ง + วันที่ต้นทาง" ซึ่งเป็นชุดที่ระบุหนังสือ
     * ของผู้ส่งได้จริง · คืนรายการให้ผู้ใช้ตัดสิน **ไม่ปฏิเสธเอง** เพราะการรับซ้ำ
     * อาจถูกต้องได้ (ผู้ส่งส่งซ้ำจริง และหน่วยงานเลือกรับทั้งสองฉบับ)
     *
     * @return Collection<int,IncomingRegistration>
     */
    public function findPossibleDuplicates(?string $sourceNumber, string $senderName, ?string $sourceDate)
    {
        if (blank($sourceNumber)) {
            // ไม่มีเลขต้นทางก็ระบุตัวหนังสือไม่ได้ — ไม่เดาว่าซ้ำ
            return collect();
        }

        return IncomingRegistration::query()
            ->with('document:id,subject,status')
            ->where('source_number', $sourceNumber)
            ->where('sender_name', $senderName)
            ->when($sourceDate !== null, fn ($query) => $query->whereDate('source_date', $sourceDate))
            ->orderByDesc('received_at')
            ->get();
    }

    /**
     * ลงทะเบียนรับหนังสือ
     *
     * @param  array<string,mixed>  $attributes
     */
    public function register(array $attributes, User $registrar, ?UploadedFile $file = null): IncomingRegistration
    {
        return DB::transaction(function () use ($attributes, $registrar, $file) {
            $receivedAt = $this->clock->now();

            $document = Document::create([
                'document_type_id' => $attributes['document_type_id'],
                'direction' => Document::DIRECTION_INCOMING,
                'subject' => $attributes['subject'],
                'summary' => $attributes['summary'] ?? null,
                'confidentiality' => $attributes['confidentiality'],
                'urgency' => $attributes['urgency'],
                'owner_org_unit_id' => $attributes['owner_org_unit_id'],
                'status' => Document::STATUS_REGISTERED,
                'created_by' => $registrar->id,
            ]);

            if ($file !== null) {
                $this->documents->addVersion($document, $file, $registrar, DocumentVersion::STAGE_ORIGINAL);
            }

            $number = $this->issueRegisterNumber($document, $registrar);

            $registration = IncomingRegistration::create([
                'document_id' => $document->id,
                'source_number' => $attributes['source_number'] ?? null,
                'source_date' => $attributes['source_date'] ?? null,
                'sender_name' => $attributes['sender_name'],
                'document_number_id' => $number->id,
                'received_at' => $receivedAt,
                'registered_by' => $registrar->id,
                'note' => $attributes['note'] ?? null,
            ]);

            $this->audit->log('saraban.incoming.registered', $document, $document->id, null, [
                'register_number' => $number->formatted,
                'received_at' => $receivedAt->toDateTimeString(),
                'source_number' => $registration->source_number,
                'sender_name' => $registration->sender_name,
            ], null, $registrar->id);

            return $registration->fresh(['document.currentVersion', 'registerNumber']);
        }, 5);
    }

    /**
     * ออกเลขทะเบียนรับให้หนังสือฉบับนี้
     *
     * เดินครบสามขั้นของเครื่องออกเลข (จอง → ผูก → ออก) ในคราวเดียว เพราะการรับ
     * หนังสือเป็นเหตุการณ์ที่เกิดขึ้นแล้วจริง ไม่ใช่การจองเลขไว้ล่วงหน้า
     */
    private function issueRegisterNumber(Document $document, User $registrar)
    {
        $series = NumberSeries::query()
            ->where('code', self::REGISTER_SERIES_CODE)
            ->where('is_active', true)
            ->first();

        if ($series === null) {
            throw new NumberingException('ยังไม่ได้ตั้งค่าชุดเลขทะเบียนรับ กรุณาติดต่อผู้ดูแลระบบ');
        }

        $number = $this->numbering->reserve($series, $registrar);
        $number = $this->numbering->allocate($number, $document, $registrar);

        return $this->numbering->issue($number, $registrar);
    }
}
