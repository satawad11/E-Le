# MASTER BLUEPRINT (REBUILD-2569)

## Vision
แพลตฟอร์มบริหารงานสถานศึกษาที่มี **ข้อมูลบุคลากร** และ **ระบบสิทธิ์ 5 ระดับ** เป็นแกนกลาง
โมดูลทุกตัวใช้ตัวตน โครงสร้างองค์กร สิทธิ์ สายอนุมัติ audit และแจ้งเตือนจากแกนกลางเดียวกัน
เพิ่มโมดูลใหม่ได้โดยประกาศ manifest ไม่ต้องแก้แกนกลาง

## Core domains
| Domain | Spec | สาระ |
|---|---|---|
| Organization | `modules/CORE-01-ORGANIZATION.md` | ต้นไม้หน่วยงาน 5 ระดับตามประเภทหน่วย · ตำแหน่ง |
| Personnel | `modules/CORE-02-PERSONNEL.md` | ข้อมูลบุคลากรครบหมวด · assignment 4 ประเภท · ชั้นความลับฟิลด์ · คำขอแก้ไข |
| Identity & Account | `modules/CORE-03-AUTHENTICATION.md` | บัญชี · วงจร JML · MFA · login events |
| Authorization | `modules/CORE-04-RBAC-ABAC.md` | manifest · PermissionEngine · scope · กฎกลาง · override · simulator |
| Workflow | `modules/CORE-05-WORKFLOW.md` | engine เดิม + resolver ตามประเภทหน่วย |
| Module Registry | `08-MODULE-STANDARDS.md` | manifest · `modules:sync` · เมนู |
| Audit | `12-AUDIT-LOGGING.md` | data access · permission change · login |
| Notification, Documents, Calendar, Reporting | ตามเอกสารมาตรฐานเดิม | |

## Business modules (ย้ายเข้าแกนกลางใหม่ใน R-08)
ระบบการลา · ลงเวลา · ปฏิบัติงานล่วงเวลา · สารบรรณอิเล็กทรอนิกส์ · แจ้งเตือน

## Future modules (หลัง R-09)
แผนและงบประมาณ · จองห้อง/รถ · พัสดุ · การเงิน · ครุภัณฑ์ · ซ่อมบำรุง · ประชุม

## Architecture principles
Personnel-driven · Assignment-based authorization · Manifest-declared permissions · Deny by default ·
Workflow by org tree · Audit everything · Privacy by design (PDPA) · Modular by design
