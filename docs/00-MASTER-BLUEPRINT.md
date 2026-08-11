# MASTER BLUEPRINT

## Vision
แพลตฟอร์มบริหารงานสถานศึกษาแบบ Modular โดยมี Core กลางและเพิ่มโมดูลได้ในอนาคต

## Core domains
Organization, Personnel, Identity, Authorization, Workflow, Module Registry, Audit, Calendar, Documents, Notification, Reporting, Integration.

## Initial business modules
- e-Leave: หลายประเภทบุคลากร, policy versioning, จำนวนครั้ง+จำนวนวัน, แบบฟอร์มราชการ
- Attendance: ตารางเวลารายบุคคล, web/mobile/device events, correction
- OT: order/assignment/actual/certified time/conflict
- Notification: In-App, LINE OA, Telegram, Email
- Evaluation support: สรุปข้อมูลลา/เวลาเพื่อประกอบการประเมินและเลื่อนเงินเดือน/ค่าตอบแทน

## Future modules
สารบรรณ, แผนและงบประมาณ, จองห้อง, จองรถ, พัสดุ, การเงิน, ครุภัณฑ์, ซ่อมบำรุง, ประชุม.

## Architecture principles
Configuration over Hard-code; Policy-driven; Workflow-driven; Permission-driven; Event-driven; Audit everything; Secure by default; Modular by design.
