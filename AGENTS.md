# AGENTS.md — Codex/Agent Operating Contract (REBUILD-2569)

## Mission
ปรับ E-la เป็นแพลตฟอร์มบุคลากรและสิทธิ์ 5 ระดับตาม `docs/REBUILD-PLAN-2569.md`
บน Laravel + Vue 3 + MySQL 8 แบบ Modular Monolith ที่ปลอดภัย ขยายโมดูลได้ และ UI ภาษาไทย 100%

## Mandatory reading order
ก่อนแก้โค้ดทุกครั้งให้อ่าน:
1. `PROJECT_RULES.md`
2. `CURRENT_PHASE.md` — ทำเฉพาะงานของเฟสปัจจุบัน
3. `TASKS.md` — รายการงานของเฟส
4. `docs/REBUILD-PLAN-2569.md` — แบบจำลองเป้าหมาย รายการลบ/แทนที่ และผังเมนู
5. spec ของ core ที่เกี่ยวข้องใน `docs/modules/CORE-*.md`
6. `docs/CODEX-RUNBOOK.md` — prompt และเกณฑ์ผ่านของเฟส

## Hard gates
- ทำงานบน branch `rebuild/2569` หรือ branch ย่อย `rebuild/R-0x` เท่านั้น ห้าม push หรือ merge เข้า `main`
- ทำทีละเฟสตาม `CURRENT_PHASE.md` ห้ามข้ามเฟส ห้ามเริ่มเฟสถัดไปก่อนเขียนรายงาน checkpoint
- ทุก endpoint ต้องเรียก `PermissionEngine` (server-side authorization + scope) และป้องกัน IDOR ด้วย UUID + scope query
- ห้ามใช้ `hasRole()`, ชื่อ role, หรือ `Gate::before` แบบ allow-all ในตรรกะ
- ทุก mutation สำคัญต้อง audit · การเข้าถึงฟิลด์ `sensitive` ต้องบันทึก
- ห้ามเขียนทับประวัติที่มีวันที่มีผล
- ห้าม bypass หรือลบเทสต์เพื่อให้ CI ผ่าน
- ถ้าพบสิ่งที่แผนไม่ได้กำหนด หรือกฎราชการไม่ชัด: หยุด เพิ่มคำถามใน `TASKS.md` หัวข้อ "รอตัดสินใจ" แล้วรายงาน

## Delivery contract (ทุก task)
- รัน `composer test` (หรือ `php artisan test`), `vendor/bin/pint --test`, `npm run build` ใน frontend
- อัปเดต `TASKS.md` (ติ๊กงาน), `docs/TRACEABILITY-MATRIX.md` (REQ → ไฟล์ → เทสต์), `docs/CHANGELOG.md`
- บันทึก ADR ใหม่ใน `docs/DECISION-LOG.md` เมื่อตัดสินใจเชิงสถาปัตยกรรม
- commit ย่อยต่องาน ข้อความ commit ขึ้นต้นด้วยรหัสเฟส เช่น `R-02: create authz baseline tables`

## Checkpoint (จบเฟส)
สร้าง `docs/checkpoints/R-xx-REPORT.md`: สิ่งที่ทำ · ไฟล์/migration/API ที่เปลี่ยน · ผลเทสต์ (จำนวน test/assertion) · เกณฑ์ Go/No-Go ของเฟสผ่านหรือไม่ · ข้อจำกัดที่เหลือ แล้ว **หยุดรอคำสั่ง**

## Language
UI/copy ภาษาไทย 100% · code/schema/identifier ภาษาอังกฤษ
