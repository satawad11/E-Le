# RBAC + ABAC (REBUILD-2569)

สรุปย่อ — spec เต็มอยู่ที่ `modules/CORE-04-RBAC-ABAC.md`

| แกน | ตอบคำถาม | มาจาก |
|---|---|---|
| RBAC | ทำอะไรได้ | ชุดสิทธิ์ (template) ที่แนบกับตำแหน่ง หรือมอบหมายเป็น functional grant |
| ABAC: scope | กับข้อมูลของหน่วยไหน | assignment ที่ให้สิทธิ์นั้น (`own`/`unit`/`subtree`/`all`/`assigned`) |
| ABAC: attributes | ภายใต้เงื่อนไขอะไร | attributes ของ grant (ประเภทบุคลากร, วงเงิน ฯลฯ) และสถานะของ resource |
| กฎกลาง | ข้อห้ามที่ทุกโมดูลต้องตาม | `not_own`, `maker_checker`, `hr_field_guard`, `grant_ceiling`, `two_person` |

5 ระดับ: L1 สถาบัน · L2 ฝ่าย · L3 งาน/แผนกวิชา · L4 กลุ่มงาน/สาขางาน · L5 บุคลากร

ลำดับตัดสิน: deny by default → permission → scope ของแหล่งเดียวกัน → override (deny ชนะ) → กฎกลาง → field masking → audit
