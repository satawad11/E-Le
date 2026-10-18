# CORE-01 Organization (REBUILD-2569)

อ้างอิง: `REBUILD-PLAN-2569.md` §3.1 · เฟส R-02 (schema), R-03 (service + UI)

## แนวคิด
ต้นไม้หน่วยงานความลึกไม่จำกัด ระดับ L1–L4 มาจาก **ประเภทหน่วย** ไม่ใช่ความลึก หน่วยที่ไม่มี L4 ข้ามได้
L5 คือบุคลากร ไม่ใช่หน่วย

## Schema

### `org_unit_types`
| คอลัมน์ | ชนิด | หมายเหตุ |
|---|---|---|
| id | bigint PK | |
| code | varchar(40) unique | `institution`, `division`, `section`, `department`, `subsection`, `program` |
| name | varchar(120) | ชื่อไทย เช่น "ฝ่าย", "งาน", "แผนกวิชา", "สาขางาน" |
| level | tinyint | 1–4 |
| head_title | varchar(120) | "ผู้อำนวยการ", "รองผู้อำนวยการ", "หัวหน้างาน", "หัวหน้าแผนกวิชา" ฯลฯ |
| is_active | bool | |

### `org_units`
| คอลัมน์ | ชนิด | หมายเหตุ |
|---|---|---|
| id | bigint PK | |
| uuid | char(36) unique | public id |
| parent_id | FK org_units null | root เท่านั้นที่เป็น null (มีได้ 1 แถว active) |
| org_unit_type_id | FK | |
| code | varchar(80) unique | |
| name | varchar(255) | |
| path | varchar(1000) index | materialized path เช่น `/1/4/17/` อัปเดตเมื่อย้าย parent |
| depth | tinyint | ความลึกจริงในต้นไม้ (ไม่ใช่ level) |
| sort_order | int | |
| effective_from / effective_to | date | |
| timestamps | | |

กฎ: level ของลูกต้องมากกว่า level ของ parent · ห้ามวงวน · ย้าย parent ต้องอัปเดต `path` ของ subtree ใน transaction · ปิดหน่วยด้วย `effective_to` ไม่ลบ

### `position_groups`, `positions`
เก็บรายการตำแหน่งงาน (ครู, ครูผู้ช่วย, เจ้าหน้าที่ ฯลฯ) และตำแหน่งบริหาร
`positions.kind`: `line` (ตำแหน่งงาน) · `management` (ผูกกับประเภทหน่วยผ่าน `positions.org_unit_type_id`)
ตำแหน่งบริหารหนึ่งตำแหน่งต่อหนึ่งประเภทหน่วย เช่น "หัวหน้าแผนกวิชา" ↔ `department`

## Service contract: `OrgTree`
- `ancestors(OrgUnit $u): Collection` — จากหน่วยขึ้นไปถึง root
- `subtreeIds(OrgUnit $u): array` — ใช้ `path LIKE '/1/4/%'`
- `nearestOfLevel(OrgUnit $u, int $level): ?OrgUnit` — หน่วยบรรพบุรุษที่ใกล้ที่สุดซึ่ง level ตรง (ใช้โดย resolver)
- `headOf(OrgUnit $u, Carbon $at): ?Assignment` — ผู้ดำรงตำแหน่งบริหารของหน่วย ณ วันที่ (รวม acting)

## Seed ตั้งต้น
ประเภทหน่วย 6 แบบข้างต้น · root "วิทยาลัยเทคนิคดอนเมือง" · 4 ฝ่ายตามระเบียบบริหารสถานศึกษา พ.ศ. 2569
งาน/แผนกวิชานำเข้าใน R-03 จากไฟล์ที่งานบุคคลยืนยัน

## Tests ขั้นต่ำ
path ถูกต้องหลังย้าย subtree · ห้ามวงวน · level ลูก > parent · `nearestOfLevel` ข้ามชั้นที่ไม่มีได้ · `headOf` คืน acting ในช่วงคำสั่ง
