# PROJECT RULES

## Non-negotiable
1. Modular Monolith แยก domain ชัดเจน
2. PostgreSQL เป็น authoritative transactional store
3. Redis ใช้ cache/session/queue ไม่ใช่ source of truth
4. UUID เป็น public identifier; หลีกเลี่ยง sequential ID ใน URL
5. Deny by default
6. RBAC + ABAC + organization scope
7. Policy/Workflow/Template ต้อง versioned + effective dated
8. Audit log เป็น append-oriented
9. Server timestamp เป็น source of truth ของ attendance ingestion
10. Vendor biometric system เป็น source ของ raw event ไม่ใช่ final attendance status
11. ไม่เก็บ biometric template ในระบบกลางหากไม่จำเป็น
12. เอกสารสำคัญใช้ private storage + signed URL หรือ authenticated download
13. UI ภาษาไทย 100%
14. รองรับ Mobile/Tablet/Desktop
15. เพิ่ม module ใหม่ผ่าน Module Registry

## Forbidden
- hard-code ชื่อฝ่าย/แผนก/ประเภทบุคลากร
- hard-code approval chain
- hard-code leave entitlement ใน UI/API
- authorization เฉพาะการซ่อนเมนู
- direct DB writes จาก client
- overwrite ประวัติ effective-dated
- public bucket สำหรับเอกสารบุคลากร
