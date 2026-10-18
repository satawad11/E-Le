# CORE-05 Workflow
Versioned definition, steps/transitions, conditions, instance/task/action, delegation/acting assignment, escalation.
Approver resolution may use supervisor/unit manager/role/person/attribute rule.
Active instance pins definition version. Every action must enforce allowed transition and actor eligibility atomically.

## REBUILD-2569 changes (R-04, R-08)

- Workflow engine เป็น source of truth ทันที ยกเลิกโหมด shadow (`LeaveWorkflowShadow`, `workflow_shadow_comparisons`) และตาราง `approval_steps` เดิม
- ขั้นในสายอนุมัติอ้าง **ระดับหน่วย** ไม่อ้างชื่อ role: `{ "resolver": "head_of_level", "level": 3 }` หมายถึงหัวหน้าหน่วยระดับ 3 ที่ใกล้ที่สุดเหนือหน่วยต้นสังกัด (`primary`) ของผู้ยื่น โดยใช้ `OrgTree::nearestOfLevel` + `headOf`
- resolver อื่น: `head_of_level` · `functional_template` (เช่น ผู้ตรวจของงานบุคคล) · `specific_assignment`
- ไม่พบผู้อนุมัติ หรือผู้อนุมัติคือผู้ยื่น → ข้ามขึ้นชั้นถัดไปตาม `skip_policy`; ถ้าหมดชั้น → คิว "ไม่พบผู้อนุมัติ" ของงานบุคคล **ห้าม fallback ตามชื่อ role**
- ชั้นติดกันที่เป็นคนเดียวกันรวมเป็นขั้นเดียว แต่บันทึกทุกตำแหน่งที่ผ่าน
- ทุกการลงนามบันทึก `acting_assignment_id` เพื่อออกเอกสารด้วยชื่อตำแหน่งที่ถูกต้อง
