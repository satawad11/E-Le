<?php

namespace App\Services\Saraban;

/**
 * บริการเทมเพลตอีเมลราชการและการแจ้งเตือนอัตโนมัติตามระเบียบสำนักนายกรัฐมนตรีฯ ภาคผนวก ๗
 */
class OfficialEmailTemplateService
{
    public const THAI_DISCLAIMER = 'ข้อความและไฟล์แนบในไปรษณีย์อิเล็กทรอนิกส์นี้เป็นข้อมูลราชการ ซึ่งอาจมีข้อมูลลับหรือข้อมูลที่ได้รับความคุ้มครองตามกฎหมาย หากท่านไม่ใช่ผู้รับตามเจตนา โปรดแจ้งผู้ส่งทันทีและลบข้อความนี้พร้อมไฟล์แนบทั้งหมดออกจากระบบของท่าน โดยห้ามมิให้นำไปใช้ เปิดเผย คัดลอก หรือเผยแพร่แก่บุคคลอื่นโดยเด็ดขาด';

    public const ENGLISH_DISCLAIMER = 'CONFIDENTIALITY NOTICE: This e-mail and any files transmitted with it are official government communications and may contain confidential or legally privileged information. If you are not the intended recipient, please notify the sender immediately and delete this e-mail and all attachments from your system. Any unauthorized review, use, disclosure, copying or distribution is strictly prohibited.';

    /**
     * ดึงข้อความปฏิเสธความรับผิดชอบ (Legal Disclaimer) สองภาษา
     */
    public function getBilingualDisclaimer(): array
    {
        return [
            'th' => self::THAI_DISCLAIMER,
            'en' => self::ENGLISH_DISCLAIMER,
            'formatted' => "----------------------------------------\n".
                self::THAI_DISCLAIMER."\n\n".
                self::ENGLISH_DISCLAIMER,
        ];
    }

    /**
     * สร้างข้อความตอบรับอัตโนมัติ (Auto-reply) สำหรับอีเมลหนังสือรับเข้า
     */
    public function getAutoReplyMessage(string $institutionName, ?string $trackingNumber = null): string
    {
        $regInfo = $trackingNumber
            ? "ระบบได้บันทึกรหัสติดตามเบื้องต้นหมายเลข: {$trackingNumber}\n"
            : '';

        return "เรียน ท่านผู้ส่งสาร\n\n".
            "{$institutionName} ได้รับไปรษณีย์อิเล็กทรอนิกส์ของท่านเรียบร้อยแล้ว\n".
            $regInfo.
            "เจ้าหน้าที่งานสารบรรณจะดำเนินการตรวจสอบเอกสารและลงทะเบียนรับตามระเบียบสำนักนายกรัฐมนตรี ว่าด้วยงานสารบรรณ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔ ต่อไป\n\n".
            "ขอแสดงความนับถือ\n".
            "งานสารบรรณ {$institutionName}\n\n".
            $this->getBilingualDisclaimer()['formatted'];
    }

    /**
     * จัดรูปแบบหัวข้ออีเมลราชการตามชั้นความเร็วและเลขที่หนังสือ
     */
    public function formatEmailSubject(string $subject, string $speedClass = 'normal', ?string $docNumber = null): string
    {
        $prefix = '';
        if ($speedClass === 'most_urgent') {
            $prefix = '[ด่วนที่สุด] ';
        } elseif ($speedClass === 'very_urgent') {
            $prefix = '[ด่วนมาก] ';
        } elseif ($speedClass === 'urgent') {
            $prefix = '[ด่วน] ';
        }

        $numberPart = $docNumber ? "ที่ {$docNumber} " : '';

        return "{$prefix}{$numberPart}{$subject}";
    }

    /**
     * แนบท้ายข้อความอีเมลด้วย Disclaimer ๒ ภาษา
     */
    public function appendDisclaimer(string $body): string
    {
        return rtrim($body)."\n\n".$this->getBilingualDisclaimer()['formatted'];
    }
}
