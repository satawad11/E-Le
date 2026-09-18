<?php

namespace App\Services\DocumentEngine\Providers;

use App\Models\Saraban\Document;
use App\Models\Setting;
use App\Services\DocumentEngine\DocumentDataProvider;
use App\Services\ThaiDateService;
use Carbon\Carbon;

/**
 * แหล่งข้อมูลหนังสือราชการและงานสารบรรณสำหรับ Document Engine
 *
 * ให้ข้อมูลเลขที่หนังสือ, วันที่, เรื่อง, ผู้รับ, ผู้ส่ง, ชั้นความเร็ว, ชั้นความลับ,
 * และข้อมูลผู้มีอำนาจลงนาม สำหรับเติมลงในแม่แบบหนังสือภายนอก, หนังสือภายใน, คำสั่ง, หรือประกาศ
 */
class OfficialSarabanDataProvider implements DocumentDataProvider
{
    public const CODE = 'SARABAN_DOCUMENT_DETAIL';

    public function code(): string
    {
        return self::CODE;
    }

    public function module(): string
    {
        return 'saraban';
    }

    /**
     * @param  array{document?: Document, stage?: string}  $context
     * @return array<string,mixed>
     */
    public function fields(array $context): array
    {
        $document = $context['document'] ?? null;

        if ($document instanceof Document) {
            $documentNumber = $document->documentNumber?->number_string
                ?: ($document->incomingRegistration?->registration_number ?? '');

            $issuedDate = $document->issued_at ?: ($document->finalized_at ?: ($document->created_at ?: now()));
            $ownerOrgUnit = $document->ownerOrgUnit;

            $subject = (string) $document->subject;
            $summary = (string) ($document->summary ?? '');
            $direction = (string) $document->direction;
            $status = (string) $document->status;
            $urgency = $document->urgency;
            $confidentiality = $document->confidentiality;
            $verificationHash = (string) ($document->verification_hash ?? '');
            $ownerOrgUnitName = $ownerOrgUnit?->name ?: '';
            $ownerOrgUnitCode = $ownerOrgUnit?->code ?: '';
        } elseif (is_array($context)) {
            $documentNumber = (string) ($context['document_number'] ?? $context['doc_no'] ?? '');
            $issuedDate = isset($context['document_date']) ? Carbon::parse($context['document_date']) : now();
            $subject = (string) ($context['subject'] ?? $context['title'] ?? '');
            $summary = (string) ($context['summary'] ?? '');
            $direction = (string) ($context['direction'] ?? 'OUTGOING');
            $status = (string) ($context['status'] ?? 'DRAFT');
            $urgency = $context['urgency'] ?? $context['speed'] ?? 'normal';
            $confidentiality = $context['confidentiality'] ?? $context['secret'] ?? 'normal';
            $verificationHash = (string) ($context['verification_hash'] ?? 'mock_hash');
            $ownerOrgUnitName = (string) ($context['owner_org_unit_name'] ?? $context['org_unit_name'] ?? '');
            $ownerOrgUnitCode = (string) ($context['owner_org_unit_code'] ?? $context['org_unit_code'] ?? '');
        } else {
            throw new \InvalidArgumentException('SARABAN_DOCUMENT_DETAIL ต้องได้รับข้อมูลหนังสือราชการ');
        }

        $schoolName = Setting::get('school_name', 'วิทยาลัยเทคนิคดอนเมือง');
        $schoolAffiliation = Setting::get('school_affiliation', 'สำนักงานคณะกรรมการการอาชีวศึกษา');
        $directorName = Setting::get('director_name', '');
        $directorPosition = Setting::get('director_position', 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง');

        $urgencyLabel = match ($urgency) {
            'urgent', 'URGENT_1' => 'ด่วนที่สุด',
            'very_urgent', 'URGENT_2' => 'ด่วนมาก',
            'quick', 'URGENT_3' => 'ด่วน',
            default => 'ปกติ',
        };

        $secrecyLabel = match ($confidentiality) {
            'top_secret', 'SECRET_3' => 'ลับที่สุด',
            'secret', 'SECRET_2' => 'ลับมาก',
            'confidential', 'SECRET_1' => 'ลับ',
            default => 'ปกติ',
        };

        $publicVerifyUrl = url("/verify/{$verificationHash}");

        $thaiDateService = app(ThaiDateService::class);

        return [
            'saraban.document_number' => $documentNumber,
            'saraban.subject' => $subject,
            'saraban.summary' => $summary,
            'saraban.direction' => $direction,
            'saraban.status' => $status,

            'saraban.urgency' => $urgencyLabel,
            'saraban.confidentiality' => $secrecyLabel,

            'saraban.date_thai' => $thaiDateService->toThaiDate($issuedDate->toDateString()),
            'saraban.date_short_thai' => $thaiDateService->formatToThaiSlash($issuedDate->toDateString()),
            'saraban.year_th' => (string) ($issuedDate->year + 543),

            'saraban.owner_org_unit' => $ownerOrgUnitName,
            'saraban.owner_org_unit_code' => $ownerOrgUnitCode,

            'saraban.verification_hash' => $verificationHash,
            'saraban.verify_url' => $publicVerifyUrl,

            'organization.name' => $schoolName,
            'organization.affiliation' => $schoolAffiliation,
            'organization.director_name' => $directorName,
            'organization.director_position' => $directorPosition,
        ];
    }

    /**
     * @return array<int,array{code:string,label_th:string,group_th:string,data_type:string}>
     */
    public function catalog(): array
    {
        return [
            ['code' => 'saraban.document_number', 'label_th' => 'เลขที่หนังสือ / เลขทะเบียนรับ-ส่ง', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.subject', 'label_th' => 'เรื่อง (ชื่อเรื่องหนังสือ)', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.summary', 'label_th' => 'สรุปสาระสำคัญ', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.urgency', 'label_th' => 'ชั้นความเร็ว (ด่วน/ด่วนมาก/ด่วนที่สุด)', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.confidentiality', 'label_th' => 'ชั้นความลับ (ลับ/ลับมาก/ลับที่สุด)', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.date_thai', 'label_th' => 'วันที่ออกหนังสือ (ไทยเต็ม)', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.date_short_thai', 'label_th' => 'วันที่ออกหนังสือ (ไทยย่อ)', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.year_th', 'label_th' => 'ปี พ.ศ. ของหนังสือ', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.owner_org_unit', 'label_th' => 'หน่วยงานเจ้าของเรื่อง', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],
            ['code' => 'saraban.verify_url', 'label_th' => 'URL ตรวจสอบความถูกต้อง (QR Code)', 'group_th' => 'หนังสือราชการ', 'data_type' => 'TEXT'],

            ['code' => 'organization.name', 'label_th' => 'ชื่อสถานศึกษา', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
            ['code' => 'organization.affiliation', 'label_th' => 'สังกัดสถานศึกษา', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
            ['code' => 'organization.director_name', 'label_th' => 'ชื่อผู้อำนวยการ', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
            ['code' => 'organization.director_position', 'label_th' => 'ตำแหน่งผู้อำนวยการ', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
        ];
    }
}
