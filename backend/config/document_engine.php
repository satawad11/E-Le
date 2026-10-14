<?php

return [
    /*
     * เปิด/ปิด Document Engine ทั้งระบบ
     *
     * ปิดอยู่ = ทุกอย่างเดินเส้นทางเดิม (dompdf + blade) เหมือนก่อนมี engine ทุกประการ
     * เปิดแล้วยังต้องผ่านเงื่อนไขราย entity อีกชั้น (`entities` ด้านล่าง)
     * จึงถอยกลับได้ทันทีโดยไม่ต้อง deploy
     */
    'enabled' => (bool) env('DOCUMENT_ENGINE_ENABLED', false),

    /*
     * รายการที่อนุญาตให้ใช้ engine — ทยอยเปิดทีละประเภทได้
     *
     *   'leave_types' => [1, 5]   เปิดเฉพาะ leave type id ที่ระบุ
     *   'leave_types' => ['*']    เปิดทุกประเภท
     *   'leave_types' => []       ไม่เปิดเลย (ค่าเริ่มต้น)
     */
    'entities' => [
        'leave_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DOCUMENT_ENGINE_LEAVE_TYPES', '')),
        ))),
    ],

    /*
     * บันทึกไฟล์เอกสารทุกครั้งที่ออกหรือไม่
     *
     * false = ออกไฟล์ให้ผู้ใช้อย่างเดียว (เบากว่า)
     * true  = เก็บทุกครั้งเป็น revision (ตรวจสอบย้อนหลังได้ละเอียดที่สุด)
     * ไม่ว่าค่าใด เอกสารของใบลาที่อนุมัติแล้วจะถูกเก็บเป็นฉบับสมบูรณ์เสมอ
     */
    'store_every_render' => (bool) env('DOCUMENT_ENGINE_STORE_EVERY_RENDER', false),
];
