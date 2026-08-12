# DECISION LOG

## ADR-004 Leave status uses checked varchar

เลือกเปลี่ยน `leaves.status` จาก MySQL ENUM เป็น `VARCHAR(20)` พร้อม CHECK constraint เพื่อเพิ่ม `Voided` แบบ forward-only โดยไม่แก้ migration เก่าและไม่ทำลายประวัติเอกสารที่ void แล้ว

## ADR-001 Modular Monolith
เลือก Modular Monolith เพื่อความง่ายในการพัฒนา/ดูแลระยะแรก แต่บังคับ domain boundary เพื่อรองรับการแยก service ในอนาคต.

## ADR-002 Policy/Workflow versioning
กฎและ workflow ต้อง versioned/effective-dated เพื่อรักษาความถูกต้องของรายการย้อนหลัง.

## ADR-003 Vendor attendance as raw source
ระบบผู้ผลิตเครื่องสแกนส่ง raw events เข้าระบบกลาง; Attendance Engine ของระบบกลางเป็นผู้ตัดสินสถานะตามตาราง/นโยบาย.
