# CURRENT PHASE

## Mode
`REBUILD-2569`

**Branch:** งาน rebuild ทั้งหมดอยู่บน `rebuild/2569` · `main` คือระบบเดิมที่ใช้งานจริงจนถึง R-09 ห้าม merge ก่อนนั้น

## Current Phase
`R-04 Permission Engine + manifest + เมนู` — **เสร็จ รอตรวจ** (2026-10-19) · `docs/checkpoints/R-04-REPORT.md`

ถัดไป: `R-05 บัญชีและความปลอดภัย` — เริ่มหลังตรวจและ merge `rebuild/R-04`

| เฟส | งาน | สถานะ |
|---|---|---|
| R-00 | สำรองข้อมูล export ถาวร tag `legacy-final` ผู้บริหารลงนามรับทราบการล้างข้อมูล | ⏳ backup + tag แล้ว (2026-10-18) · เหลือทดสอบ restore, export ถาวร, ลงนาม |
| R-01 | กติกาและเอกสารชุดใหม่ | ✅ เสร็จ · `docs/checkpoints/R-01-REPORT.md` |
| R-02 | Schema baseline · ยุบ migration · ลบ spatie | ✅ เสร็จ (merge PR #31) · `docs/checkpoints/R-02-REPORT.md` |
| R-03 | Core องค์กรและบุคลากร | ✅ เสร็จ (merge PR #32) · `docs/checkpoints/R-03-REPORT.md` |
| R-04 | Permission Engine + manifest + เมนู | ✅ เสร็จ รอตรวจ · `docs/checkpoints/R-04-REPORT.md` |
| R-05 | บัญชี JML MFA ทบทวนสิทธิ์ | ⬜ |
| R-06 | หน้าจอบุคลากร | ⬜ |
| R-07 | หน้าจอสิทธิ์และ audit | ⬜ |
| R-08 | ย้ายโมดูลลา ลงเวลา OT สารบรรณ แจ้งเตือน | ⬜ |
| R-09 | UAT และเปิดใช้ | ⬜ |

## Allowed Work (R-05 เมื่อได้รับคำสั่ง)
ดู `TASKS.md` หัวข้อ R-05 และ `docs/CODEX-RUNBOOK.md`

## Forbidden Work
- รัน `migrate:fresh` หรือคำสั่งล้างข้อมูลกับฐานข้อมูลจริงก่อน R-00 เสร็จ
- งานของเฟสอื่นนอกจากเฟสปัจจุบัน

## ระบบเดิม
ระบบ production เดิม (tag `legacy-final` เมื่อสร้างแล้ว) เปิดใช้ต่อจนโมดูลลาใหม่ผ่าน R-08 ตาม ADR-R-004
