<?php

namespace App\Services\Webhooks;

/**
 * รายชื่อเหตุการณ์ที่ระบบส่งออกทาง webhook ได้ (SoT §17)
 *
 * **เป็นรายการปิด ไม่ใช่รายการเปิด** — ปลายทางสมัครรับได้เฉพาะชื่อในนี้
 * ถ้ารับชื่ออะไรก็ได้ วันหนึ่งจะมีคนสมัครรับชื่อที่พิมพ์ผิดแล้วรอข้อความที่
 * ไม่มีวันมา โดยระบบไม่เคยบอกว่าผิด
 *
 * เหตุการณ์ที่เพิ่มใหม่ต้องมาพร้อมการตัดสินใจว่า **payload มีอะไรบ้าง** —
 * หลักคือส่งเฉพาะ *ข้อเท็จจริงที่เกิดขึ้น* กับ *ตัวชี้* ไม่ส่งสาระสำคัญของหนังสือ
 * หรือไฟล์แนบออกนอกระบบ ปลายทางที่ต้องการรายละเอียดต้องเรียก API กลับเข้ามา
 * ด้วยสิทธิ์ของตนเอง
 */
final class WebhookCatalogue
{
    public const DOCUMENT_FINALIZED = 'saraban.document.finalized';

    public const DOCUMENT_DISTRIBUTED = 'saraban.document.distributed';

    public const DOCUMENT_ACKNOWLEDGED = 'saraban.document.acknowledged';

    /** @var array<int,string> */
    public const EVENTS = [
        self::DOCUMENT_FINALIZED,
        self::DOCUMENT_DISTRIBUTED,
        self::DOCUMENT_ACKNOWLEDGED,
    ];
}
