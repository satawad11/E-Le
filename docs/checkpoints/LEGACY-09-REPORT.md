# LEGACY-09 — Overtime

**วันที่:** 15 สิงหาคม 2569 (2026-08-15)  
**ต่อจาก:** LEGACY-05 Workflow Engine, LEGACY-06 Time Foundation, LEGACY-08 Attendance Integration  
**ผล:** **LEGACY-09 = PASS**

## 1. ผลลัพธ์

แยก OT ออกจาก `attendances` เป็นโดเมนชัดเจนตามสายข้อมูล:

```text
ot_orders (คำสั่ง + เวลาที่อนุมัติ)
    └── ot_assignments (ผู้ได้รับมอบหมาย)
            └── ot_time_records (เวลาจริง + เวลารับรอง)
```

กฎหลักถูกบังคับทั้ง schema, service, API และ test:

> **approved time != actual time != certified time**

ไม่มีคอลัมน์/บริการคำนวณ rate, amount, pay หรือ compensation

## 2. Schema และความหมาย

Migration: `2026_08_15_210000_create_overtime_domain.php`

| ตาราง | ความจริงที่เก็บ |
|---|---|
| `ot_orders` | คำสั่ง, หน่วยงาน, scheduled window, approved window, workflow status |
| `ot_assignments` | ผู้ได้รับมอบหมาย, ผู้มอบหมาย, สถานะงาน |
| `ot_time_records` | actual window/minutes, certified window/minutes, attendance reconciliation |

เวลาที่อนุมัติอยู่ที่ order เพราะเป็นขอบเขตของคำสั่ง เวลาจริงและเวลารับรอง
อยู่ที่ time record รายบุคคล ห้ามนำค่าหนึ่งไป overwrite อีกค่า

## 3. Workflow

- สร้างคำสั่งด้วย `attendance.ot` ภายใน org scope
- สร้าง non-shadow workflow `overtime.order`
- ขั้นอนุมัติผูก permission `attendance.ot_summary`
- pending/rejected order มอบหมายบุคลากรไม่ได้
- approved order บันทึก `approved_start` และ `approved_end` แยกจาก scheduled time
- บุคลากรลงเวลาได้เฉพาะ assignment ที่ order อนุมัติและเวลาปัจจุบันอยู่ใน approved window

Workflow action และ order mutation เข้า central audit ครบ

## 4. Actual time และ certified time

`OvertimeTimeService` ใช้ `TimeService::now()` จึงยึด timezone/schedule foundation เดียวกัน

- check-in สร้าง `actual_start`
- check-out สร้าง `actual_end` และ `actual_minutes`
- certification ต้องเกิดหลัง actual start/end ครบ
- certification เก็บ `certified_start`, `certified_end`, `certified_minutes`, ผู้รับรองและเหตุผล
- ไม่มีตรรกะบังคับให้ approved/actual/certified เท่ากัน

Critical-path regression ใช้ค่าต่างกันโดยตั้งใจ:

| ประเภทเวลา | นาที |
|---|---:|
| approved | 120 |
| actual | 90 |
| certified | 60 |

ผลทั้งสามคงอยู่และ report คืนแยก field

## 5. Attendance reconciliation และ compatibility

`OvertimeAttendanceReconciliationService` จับคู่ time record กับ attendance ของบุคคล
และ work date เดียวกัน บันทึก `matched` หรือ `missing_attendance` พร้อมรายละเอียด
โดยไม่แก้ผล attendance

คอลัมน์ `attendances.ot_check_in_time`, `ot_check_out_time`, `ot_minutes`:

- คงไว้เพื่อ rollback และข้อมูลย้อนหลัง
- ถอดออกจาก fillable
- model ปฏิเสธการ create/update ค่าใหม่
- endpoint `/attendances/ot-check-in` และ `/ot-check-out` เดิมยังใช้ได้ แต่ route ไป OT service
- response เดิมประกอบ compatibility fields จาก `ot_time_records` โดยไม่เขียนลง DB
- today/history/admin list และ OT summary อ่านข้อมูลโดเมนใหม่

## 6. Backfill MySQL 8

ก่อน migration พบ legacy attendance OT 2 แถว หลัง migration:

| รายการ | จำนวน |
|---|---:|
| attendances เดิม | 2 (คงเดิม) |
| legacy attendance OT | 2 (คงเดิม) |
| ot_orders | 2 |
| ot_assignments | 2 |
| ot_time_records | 2 |
| legacy links | 2 |

Backfill กำหนด source=`legacy` และเก็บ actual time ตามข้อมูลเดิม แต่ตั้ง approved/certified
เป็น null เพราะข้อมูลเดิมไม่มีหลักฐาน ห้ามสมมติย้อนหลัง

## 7. Permission, Scope, IDOR และ Audit

- create/punch: `attendance.ot`
- approve/reject/assign/certify/report: `attendance.ot_summary`
- ใช้ `OrgScopeResolver` กับ order org unit
- assignee ต้องอยู่ใน subtree ของหน่วยงานในคำสั่ง
- route binding ใช้ UUID สำหรับ order/time record
- report และ certification ข้าม scope ไม่ได้
- audit: order created/approved/rejected, assignments updated, actual started/completed, time certified

## 8. Reports

API `/api/overtime/reports` คืน approved, actual และ certified เป็นคนละ field
พร้อม reconciliation status และจำกัด scope

OT summary/PDF/Word/Excel endpoint เดิมยังอยู่ แต่เปลี่ยน data source เป็น
`ot_time_records.actual_minutes` เพื่อรักษาหน้ารายงานเดิมระหว่างเปลี่ยนผ่าน

ไม่มีรายงานค่าตอบแทนเพราะยังไม่มี policy requirement

## 9. Verification

| การตรวจ | ผล |
|---|---:|
| OT critical path | **7 tests / 40 assertions PASS** |
| Attendance + Overtime regression | **53 tests / 150 assertions PASS** |
| Full backend regression | **435 tests / 1096 assertions PASS** |
| Laravel Pint | **412 files PASS** |
| Frontend production build | **879 modules PASS** |
| MySQL 8 migration + backfill reconciliation | **PASS** |
| `git diff --check` | **PASS** |

## 10. ZKTeco information received

ข้อมูลสำหรับ LEGACY-08 connector ที่ได้รับพร้อมคำสั่งเฟสนี้:

- vendor: ZKTeco
- personnel identifier: เลขบัตรประชาชน
- protocol: “มาตรฐาน”

ยังไม่สร้าง connector ใน LEGACY-09 เพราะ “มาตรฐาน” ยังไม่ระบุรุ่นและ transport/API
ที่แน่นอน การเดา ZKTeco SDK/ADMS/Push/DB protocol จะขัดข้อกำหนดห้ามผูก logic
กับ vendor โดยไม่มี requirement

## 11. ความเสี่ยงคงเหลือ

1. ยังไม่มี UI จัดทำ/อนุมัติคำสั่ง OT ใหม่; API และหน้า check-in/report เดิมพร้อมใช้งาน
2. policy ค่าตอบแทนยังไม่กำหนด จึงไม่มี calculation โดยตั้งใจ
3. legacy OT columns ต้องคงไว้จนผ่านรอบ reconciliation/rollback observation
4. connector ZKTeco ต้องยืนยัน model, firmware, transport และรูปแบบ identifier ก่อนสร้าง adapter

## 12. สรุป

LEGACY-09 **PASS**: OT order, assignment, approved time, actual time, certified time,
workflow, attendance reconciliation, reports, permission/scope และ audit แยกชัดเจน
ข้อมูลเดิมครบและไม่มีการคำนวณค่าตอบแทนที่ยังไม่มีหลักเกณฑ์
