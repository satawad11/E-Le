# CORE-04 Authorization (REBUILD-2569)

อ้างอิง: `REBUILD-PLAN-2569.md` §3.3–3.4, §8 · ต้นแบบหน้า 8, 9, 10, 11 · เฟส R-02, R-04, R-07

## แนวคิด
```
สิทธิ์ที่มีผล = ⋃ (permission, scope) จากทุก assignment ที่มีผล ณ วันนั้น
              ∪ functional grants ที่ยังไม่หมดอายุ
              ± overrides (deny ชนะ)
              → กฎกลาง → field masking → audit
```
Permission name: `{module}.{feature}.{action}` เช่น `leave.approval.approve`
Scope: `own` · `unit` · `subtree` · `all` · `assigned`

## Schema
| ตาราง | คอลัมน์หลัก |
|---|---|
| `modules` | code, name, version, enabled, manifest_hash, synced_at |
| `module_features` | module_id, code, name, sort_order |
| `permissions` | name unique, module_feature_id, action, label, allowed_scopes (json), requires (json), risk (`low`,`medium`,`high`), rules (json) |
| `permission_templates` | uuid, module_id null, code, name, kind (`positional`,`functional`), description, attributes_schema (json) |
| `template_permissions` | template_id, permission_id, scope |
| `position_templates` | position_id null, org_unit_type_level null, template_id — แนบชุดสิทธิ์กับตำแหน่งหรือระดับ |
| `role_grants` | uuid, user_id, template_id, attributes (json: personnel_type_ids, org_unit_ids, amount_limit…), order_number, granted_by, approved_by, effective_from, expires_at (บังคับ), ended_at, ended_by, end_reason |
| `permission_overrides` | uuid, user_id, permission_id, effect (`allow`,`deny`), scope, org_unit_id null, reason, granted_by, approved_by, expires_at (null ได้เฉพาะ deny), ended_at |
| `permission_change_requests` | uuid, target (json), diff (json), affected_user_count, requested_by, approved_by, status |
| `access_reviews`, `access_review_items` | รอบ, รายการ grant/override, ผู้ยืนยัน, ผล |
| `permission_audit_logs` | append-only ทุกการเปลี่ยน template/grant/override/position_templates |

## PermissionEngine
```php
can(User $u, string $permission, ?Model $resource = null): Decision   // allow/deny + reason + source assignment
scopeQuery(User $u, string $permission, Builder $q): Builder          // กรองรายการตาม scope (ใช้ OrgTree::subtreeIds)
effective(User $u): Collection                                        // (permission, scope, source) ทั้งหมด
explain(User $u, string $permission, ?Model $resource): array         // ขั้นตอนตัดสินสำหรับ Simulator
```
ลำดับตัดสิน: ยืนยันตัวตน → โหลด assignment/grant ที่มีผล → มี permission ไหม → resource อยู่ใน scope ของ **แหล่งเดียวกัน** ไหม → override → กฎกลาง → ผล
Cache ต่อผู้ใช้ (key รวม version) ล้างเมื่อ `AssignmentChanged`, grant/override/template เปลี่ยน

## กฎกลาง (ประกาศใน `rules` ของ permission)
- `not_own` — resource ที่เป็นของผู้กระทำเอง (personnel/requester) → deny
- `maker_checker` — ผู้สร้าง/ผู้ยื่นรายการ ≠ ผู้ตรวจ/ผู้อนุมัติ
- `hr_field_guard` — ฟิลด์ `hr_only` ของตนเองแก้ไม่ได้
- `grant_ceiling` (ตรวจตอนให้สิทธิ์) — ผู้ให้ต้องมี permission นั้นใน scope ที่ ≥ และครอบคลุมหน่วยที่ให้
- `two_person` — permission ความเสี่ยงสูง การเปลี่ยน template/grant ต้องผ่าน `permission_change_requests`

## Manifest และ `modules:sync`
รูปแบบ manifest: `REBUILD-PLAN-2569.md` §3.4 · เมนู: §8
`php artisan modules:sync` — idempotent · สร้าง/อัปเดต modules, features, permissions, templates (ไม่ทับการปรับของผู้ดูแลใน `template_permissions` เว้นแต่ใช้ `--reset-defaults`) · permission ที่หายจาก manifest ถูก mark retired ไม่ลบ

## API ขั้นต่ำ
`GET /api/me/permissions` · `GET /api/me/menu` · `GET /api/access/users/{uuid}/effective` · `POST /api/access/simulate`

## Tests ขั้นต่ำ
- สร้างจาก Permission Matrix: ทุกแถว × ทุก persona (8.3) → ผลตรงตาราง
- คนเดียวหลายตำแหน่งข้ามฝ่าย: สิทธิ์ของ L2 ไม่รั่วไปใช้ใน subtree ของ L3
- acting มีผลเฉพาะช่วงคำสั่ง · override deny ชนะ · grant หมดอายุแล้วไม่มีผล
- IDOR: resource นอก scope → 404 (ไม่บอกว่ามีอยู่)
- CI grep: ไม่มี `hasRole(`, `Gate::before`, ชื่อ role ภาษาไทย/อังกฤษใน `app/`
