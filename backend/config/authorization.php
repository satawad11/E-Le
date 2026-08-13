<?php

return [

    /*
    |--------------------------------------------------------------------------
    | เครื่องมือตัดสินสิทธิ์รุ่นใหม่ (LEGACY-04)
    |--------------------------------------------------------------------------
    |
    | เปิดใช้ PermissionEvaluator ซึ่งเพิ่ม explicit deny และการเทียบชื่อ
    | permission ข้ามรุ่น (ชื่อเก่า <-> ชื่อ canonical) ทับบน Spatie
    |
    | ตั้งเป็น false เพื่อย้อนกลับไปใช้พฤติกรรมเดิมทันทีโดยไม่ต้อง revert โค้ด
    | ตามที่ docs/LEGACY-MIGRATION-PLAN.md กำหนดไว้เป็นแผน rollback ของเฟสนี้
    |
    */

    'use_new_engine' => (bool) env('USE_NEW_PERMISSION_ENGINE', true),

];
