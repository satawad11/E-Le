# START HERE

## หากเป็นระบบเดิมที่มี Source Code อยู่แล้ว

**ห้ามเริ่ม CORE-01 ทันที** ให้เริ่มด้วย Legacy Assessment ก่อน โดยอ่านและใช้:

- `LEGACY-UPGRADE-PROMPT.md`
- `docs/LEGACY-SYSTEM-ASSESSMENT.md`
- `docs/LEGACY-GAP-ANALYSIS.md`
- `docs/LEGACY-MIGRATION-PLAN.md`

เป้าหมายคือ **ประเมิน → Gap Analysis → Migration Plan → ขออนุมัติ → ค่อย Refactor** เพื่อรักษาข้อมูลและลด Breaking Change

---

# START HERE — คำสั่งแรกสำหรับ Claude Code / Codex

อ่านข้อความทั้งหมดตามลำดับใน `AGENTS.md` และห้ามเริ่มโมดูลธุรกิจก่อน CORE checkpoint ผ่าน

งานแรก:
1. ตรวจ repository ปัจจุบัน
2. สร้าง/ปรับโครงสร้าง Modular Monolith ตาม `docs/01-ARCHITECTURE.md`
3. ทำเฉพาะรายการใน `CURRENT_PHASE.md`
4. เริ่ม CORE-01 แล้วทำตามลำดับจน CORE-05/06/07
5. ทุก task ต้องเชื่อม Requirement -> Implementation -> Test ใน `docs/TRACEABILITY-MATRIX.md`
6. รัน lint/typecheck/test/build
7. เมื่อ CORE เสร็จ สร้าง `CORE_CHECKPOINT_REPORT.md` และหยุดรอผล checkpoint

หากพบ requirement ใหม่ ให้เพิ่ม Requirement ID และอัปเดตเอกสารก่อนลงโค้ด
