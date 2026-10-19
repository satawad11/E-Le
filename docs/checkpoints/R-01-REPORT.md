# R-01 REPORT — กติกาใหม่

วันที่: 2026-10-18 · Branch: `plan/rebuild-core-2569` (ต้นทางของ `rebuild/2569`)

## สิ่งที่ทำ
| งาน | ผล |
|---|---|
| ลบเอกสารชุดเดิม (PLAN §4.1 + รายงาน PROD-0x, KNOWN-LIMITATIONS, 20-DECISION-LOG) | ลบ 95 ไฟล์ · ประวัติอยู่ใน git |
| เขียนกติกาใหม่ | `PROJECT_RULES.md` 14 ข้อ + Forbidden · `AGENTS.md` (hard gates, delivery, checkpoint, branch policy) |
| สถานะโครงการ | `CURRENT_PHASE.md` ตารางเฟส R-00..R-09 · `TASKS.md` งานทุกเฟสพร้อมรหัส |
| Spec core | `CORE-01` องค์กร · `CORE-02` บุคลากร · `CORE-03` บัญชี · `CORE-04` สิทธิ์ (schema, service contract, tests ขั้นต่ำ) · ส่วนเพิ่ม `CORE-05` resolver ตามระดับหน่วย |
| เอกสารมาตรฐาน | 00, 01 (MySQL), 02 (stack จริง), 06, 08, 18, MODULE-EXTENSION-GUIDE |
| Requirement/traceability | 24 REQ ID · traceability เริ่มใหม่ · acceptance criteria 9 ข้อ |
| Decision log | ADR-R-001..009 · รายการ ADR เดิมที่ยังมีผล |
| สั่งงาน agent | `docs/CODEX-RUNBOOK.md` prompt ทุกเฟส + prompt ทั่วไป + checklist ก่อน merge |

## Go/No-Go R-01
| เกณฑ์ | ผล |
|---|---|
| เอกสารกติกาครบ อ่านแล้วสั่งงาน agent ได้ | ✅ |
| ไม่มีเอกสารที่เหลืออ้างถึงไฟล์ที่ลบ (ยกเว้นการอ้าง tag `legacy-final`) | ✅ ตรวจด้วย grep |
| ไม่มีการแก้โค้ดหรือ schema | ✅ |

## ค่าที่ใช้ชั่วคราว (รอยืนยัน)
Q1 → ADR-R-005 · Q2 → ADR-R-004 · Q4 → ADR-R-009 · Q6 → ADR-R-008 · Q3, Q5 ยังเปิด (ไม่บล็อก R-02)

## สิ่งที่ต้องทำก่อน R-02
1. เจ้าของระบบทำ R-00 (TASKS.md) โดยเฉพาะ tag `legacy-final`
2. สร้าง branch `rebuild/2569` (runbook ข้อ 0) — สร้างให้แล้วพร้อมรายงานนี้
3. สั่ง R-02 ด้วย prompt ใน runbook ข้อ 3

**หยุดรอคำสั่ง**
