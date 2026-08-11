# REQUIREMENTS MATRIX
| ID | Requirement | Priority | Module |
|---|---|---|---|
| REQ-CORE-001 | เพิ่มโมดูลใหม่ได้โดยไม่รื้อ Core | Must | Core |
| REQ-PER-001 | รองรับข้าราชการ พนักงานราชการ ลูกจ้างประจำ ลูกจ้างชั่วคราว นักศึกษาฝึกสอน และเพิ่มประเภทใหม่ได้ | Must | Personnel |
| REQ-LEAVE-001 | รองรับการลาหลายประเภทและ Policy แยกตามบุคลากร | Must | Leave |
| REQ-LEAVE-002 | สรุปจำนวนครั้งและจำนวนวันแยกกัน | Must | Leave |
| REQ-LEAVE-003 | พิมพ์แบบฟอร์มตามประเภทลาและเก็บ template version | Must | Leave/Documents |
| REQ-EVAL-001 | สรุปข้อมูลลา/เวลาเพื่อประกอบการประเมิน/เลื่อนเงินเดือนหรือค่าตอบแทนตาม policy | Must | Evaluation |
| REQ-ATT-001 | กำหนดเวลาเข้าออกได้ถึงระดับรายบุคคล | Must | Time/Attendance |
| REQ-ATT-002 | รองรับเครื่องสแกนหน้า/ลายนิ้วมือผ่านระบบผู้ผลิต | Must | Integration |
| REQ-ATT-003 | รองรับ raw event, mapping, dedup, sync/reconcile | Must | Integration |
| REQ-OT-001 | ลงเวลาและรับรองการทำงานนอกเวลา พร้อมตรวจเวลาซ้ำ | Must | OT |
| REQ-NOT-001 | แจ้งเตือน/ส่งเอกสาร LINE OA, Telegram, Email, In-App | Must | Notification |
| REQ-SEC-001 | RBAC+ABAC, server authz, anti-IDOR, audit | Must | Security |
