# TASKS (REBUILD-2569)

> รายการงานเดิมดูได้จาก tag `legacy-final` · agent ติ๊ก `[x]` เมื่อผ่าน Definition of Done
> อ้างอิงหลัก: `docs/REBUILD-PLAN-2569.md` (PLAN), `docs/modules/CORE-0x-*.md`

## รอตัดสินใจ (เจ้าของระบบ)
- [ ] Q1 ยืนยันเก็บระเบียบการลา/วันหยุดเป็นข้อมูลตั้งต้น (ADR-R-005 ใช้ค่าเสนอไปก่อน)
- [ ] Q2 ยืนยันเปิดระบบเดิมต่อจนโมดูลลาใหม่พร้อม (ADR-R-004)
- [ ] Q3 ระยะเก็บไฟล์ export และผู้ดูแล
- [ ] Q4 ชื่อประเภทหน่วย L4 และรายชื่อหน่วยที่มี L4
- [ ] Q5 ผู้ลงนามรับรอง Permission Matrix
- [ ] Q6 ยืนยัน MFA แบบ TOTP
- [x] Q7 (R-02 → ตัดสิน 2026-10-19) อนุมัติพัก `backend/tests/Legacy/` · รายงาน checkpoint ทุกเฟสต้องระบุเทสต์ใน tests/Legacy ที่ย้ายหรือลบในเฟสนั้น · `tests/Legacy` ต้องว่างก่อนผ่าน R-09 (ADR-R-010)
- [ ] Q8 (R-02) ระเบียบการลาของบุคลากร 8 ประเภทใหม่ใช้ชุดของประเภทเดิม 4 ประเภท (`LeaveRegulationSeeder::LEGACY_TYPE_MAP`: ข้าราชการ→ข้าราชการครู/ครูผู้ช่วย/ม.38 ค.(2) · ลูกจ้างชั่วคราว→ครูอัตราจ้าง/ลูกจ้างชั่วคราว/เจ้าหน้าที่ตามสัญญา) และค่า `has_license`/`has_contract_end` ใน `PersonnelTypeSeeder` — งานบุคคลยืนยันหรือแก้
- [x] Q9 (R-02 → ตัดสิน 2026-10-19) ใช้ `role_grants.attributes` (json) ไม่มีตาราง `grant_attributes` · ช่องทาง LINE/Telegram เก็บในตาราง `notification_channels` ของโมดูลแจ้งเตือน (R-08) (ADR-R-012)
- [x] Q10 (R-03 ตัดสิน 2026-10-19) ล็อกอินด้วยเลขประจำตัวประชาชนปิดเป็นค่าเริ่มต้น (`config auth.allow_national_id_login` = false) · ล็อกอินหลักคืออีเมลหรือ `personnel_code` (ADR-R-013)

## R-00 สำรองและปิดระบบเดิม (เจ้าของระบบ)
- [ ] R00-1 backup ฐานข้อมูล + storage และทดสอบ restore บนเครื่องแยก
- [ ] R00-2 export ใบลา บัตรลา ลงเวลา OT สารบรรณ เป็น PDF/CSV เก็บถาวร
- [x] R00-3 สร้าง tag `legacy-final` บน main และ push
- [ ] R00-4 ผู้บริหารลงนามรับทราบการล้างข้อมูลและ Permission Matrix

## R-01 กติกาใหม่
- [x] R01-1 ลบเอกสารกติกา/checkpoint/audit ชุดเดิม (PLAN §4.1)
- [x] R01-2 เขียน PROJECT_RULES, AGENTS, CURRENT_PHASE, START-HERE, README, TASKS
- [x] R01-3 เขียน CORE-01..04 ใหม่ + ส่วนเพิ่ม CORE-05
- [x] R01-4 เขียน 00, 02, 06, 08, 18, MODULE-EXTENSION-GUIDE, REQUIREMENTS, TRACEABILITY, ACCEPTANCE, DECISION-LOG
- [x] R01-5 เขียน `docs/CODEX-RUNBOOK.md`
- [x] R01-6 รายงาน `docs/checkpoints/R-01-REPORT.md`

## R-02 Schema baseline
- [x] R02-1 ย้าย migration เดิมทั้งหมดไป `backend/database/migrations-legacy/` (ไม่โหลด) แล้วสร้างชุด baseline ใหม่ใน `backend/database/migrations/`
- [x] R02-2 baseline: ตาราง framework (users, cache, jobs, sessions, personal_access_tokens, notifications)
- [x] R02-3 baseline core: CORE-01 (org_unit_types, org_units, position_groups, positions)
- [x] R02-4 baseline core: CORE-02 (personnel_types, personnel, personnel_profiles, personnel_educations, personnel_trainings, personnel_licenses, personnel_documents, assignments, field_classifications, personnel_change_requests, sensitive_access_logs)
- [x] R02-5 baseline core: CORE-03 (users ใหม่, mfa_credentials, login_events)
- [x] R02-6 baseline core: CORE-04 (modules, module_features, permissions, permission_templates, template_permissions, position_templates, role_grants, permission_overrides, permission_change_requests, access_reviews, access_review_items, permission_audit_logs)
- [x] R02-7 baseline โมดูลเดิม: สร้างตารางลา ลงเวลา OT สารบรรณ แจ้งเตือน document engine workflow engine ปฏิทิน ในรูปสุดท้าย (รวม alter ทั้งหมดของชุดเดิม) โดย **ตัด** `departments`, `department_org_unit_map`, ตาราง spatie, `permission_aliases`, `permission_denies`, `permission_scopes`, `user_role_grants`, `org_unit_members`, `org_unit_managers`, `personnel_assignments`, `user_profiles`, `approval_steps`, `workflow_shadow_comparisons`, `menus`, คอลัมน์ `users.custom_permissions`, `users.department_id`; FK ที่ชี้ `users.department_id` เปลี่ยนเป็น `org_unit_id` หรือ `personnel_id` ตามความหมาย
- [x] R02-8 ถอด `spatie/laravel-permission` จาก composer และ config/permission.php · ลบ seeders ตาม PLAN §4.3
- [x] R02-9 seeders ข้อมูลตั้งต้น: org_unit_types, root + 4 ฝ่าย, personnel_types, ระเบียบการลา/ประเภทการลา, วันหยุดราชการ 2569–2570, ปีงบประมาณ (ADR-R-005)
- [x] R02-10 Model ใหม่ของ core (Eloquent + casts encrypted) · ลบ/แยก Model ที่อ้างตารางที่ตัด
- [x] R02-11 ลบ `Gate::before` และทุกจุดที่เรียก spatie · ระหว่าง R-02–R-04 ให้ทุก route ที่ต้องการสิทธิ์ตอบ 403 (deny by default) จน R-04 ต่อ engine
- [x] R02-12 เทสต์: `migrate:fresh --seed` ผ่าน · schema test ตรวจว่าตารางที่ตัดไม่มีอยู่ · grep ไม่พบ `Spatie\\Permission`, `hasRole(`, `Gate::before`
- [x] R02-13 รายงาน `docs/checkpoints/R-02-REPORT.md`

## R-03 Core องค์กรและบุคลากร
- [x] R03-1 `OrgTree` service + path maintenance + tests (CORE-01)
- [x] R03-2 API องค์กร: CRUD หน่วย (ปิดแทนลบ), ย้าย parent, ตำแหน่ง
- [x] R03-3 Personnel models + `PersonnelPresenter` (ปิดบังตามชั้น) + `field_classifications` seed
- [x] R03-4 Assignment service: เพิ่ม/ปิด/รักษาการ, กฎ primary/management ซ้อน, event `AssignmentChanged`
- [x] R03-5 นำเข้า Excel: หน่วยงาน, บุคลากร, assignment พร้อม dry-run และรายงานข้อผิดพลาดรายแถว
- [x] R03-6 เทสต์ CORE-01, CORE-02 ขั้นต่ำครบ
- [x] R03-7 รายงาน R-03

## R-04 Permission Engine + manifest + เมนู
- [ ] R04-1 รูปแบบ manifest + `ModuleRegistry` + คำสั่ง `modules:sync` (idempotent, retire ไม่ลบ)
- [ ] R04-2 manifest ของ core: `personnel`, `org`, `access`, `audit`, `system` ตาม PLAN §8.2 ข., ช., ซ.
- [ ] R04-3 `PermissionEngine` (`can`, `scopeQuery`, `effective`, `explain`) + cache + invalidation
- [ ] R04-4 กฎกลาง `not_own`, `maker_checker`, `hr_field_guard`, `grant_ceiling`, `two_person`
- [ ] R04-5 middleware/route macro `->permission('x.y.z')` แทนการตรวจแบบเดิม · ต่อทุก route ของ core
- [ ] R04-6 `GET /api/me/permissions`, `GET /api/me/menu` (กรองสิทธิ์ ชื่อตาม scope badge)
- [ ] R04-7 Workflow resolver `head_of_level`, `functional_template`, `specific_assignment` + skip/merge rules (CORE-05)
- [ ] R04-8 Permission Matrix fixture + persona tests ตาม PLAN §8.3 + IDOR tests + CI grep
- [ ] R04-9 รายงาน R-04

## R-05 บัญชีและความปลอดภัย
- [ ] R05-1 สถานะบัญชี + listener JML + job `accounts:reconcile`
- [ ] R05-2 MFA TOTP (enroll, verify, recovery codes, บังคับตามกลุ่ม) + ADR ไลบรารี
- [ ] R05-3 login events + ล็อกบัญชี + แจ้งเตือนอุปกรณ์ใหม่
- [ ] R05-4 role grants + overrides API (grant_ceiling, วันหมดอายุบังคับ, two_person)
- [ ] R05-5 access reviews: ออกรอบ ยืนยัน ถอนอัตโนมัติ
- [ ] R05-6 หน้า 1 เข้าสู่ระบบ + MFA และ 7 บัญชีผู้ใช้ (ตามต้นแบบ)
- [ ] R05-7 เทสต์ CORE-03 ขั้นต่ำครบ · รายงาน R-05

## R-06 หน้าจอบุคลากร (ต้นแบบหน้า 2–6, 12)
- [ ] R06-1 Layout + sidebar จาก `/api/me/menu` + `v-can`/`useCan`
- [ ] R06-2 หน้าหลัก (งานรอดำเนินการแยกตามตำแหน่ง, ตำแหน่งของฉัน, แจ้งเตือน, โมดูล)
- [ ] R06-3 รายชื่อบุคลากร (ตัวกรอง, chip, scope banner, ชื่อเมนูตาม scope)
- [ ] R06-4 ประวัติบุคลากร 4 แท็บ + reveal พร้อมเหตุผล + ประวัติการเข้าถึง + ข้อมูลของฉัน
- [ ] R06-5 คำขอแก้ไข: ยื่นจากข้อมูลของฉัน + หน้ารอตรวจ (เทียบค่า, หลักฐาน, อนุมัติ/ส่งกลับ/ไม่อนุมัติ, ล็อกเมื่อเป็นผู้ยื่น)
- [ ] R06-6 โครงสร้างองค์กร (ต้นไม้, รายละเอียด, สายอนุมัติที่คำนวณได้) + ตำแหน่ง + คำสั่งแต่งตั้ง
- [ ] R06-7 รายงานบุคลากร (อัตรากำลัง, ใบอนุญาตใกล้หมดอายุ, ข้อมูลไม่ครบ)
- [ ] R06-8 เทสต์ e2e/feature ของทุกหน้า · รายงาน R-06

## R-07 หน้าจอสิทธิ์และ audit (ต้นแบบหน้า 8–11)
- [ ] R07-1 รายละเอียดบัญชี: ที่มาของสิทธิ์ 3 ชั้น + Simulator (`/api/access/simulate`) + สิทธิ์ที่มีผลจริง
- [ ] R07-2 บทบาทและสิทธิ์: รายการ template + Permission Matrix รายโมดูล
- [ ] R07-3 ตั้งค่าสิทธิ์รายโมดูล: ตารางฟีเจอร์ × ชุดสิทธิ์, requires อัตโนมัติ, สรุปภาษาคน, ผลกระทบก่อนบันทึก, two_person
- [ ] R07-4 คำขอเปลี่ยนสิทธิ์รอยืนยัน + ทบทวนสิทธิ์
- [ ] R07-5 บันทึกการใช้งาน 3 แท็บ (อ่านอย่างเดียว ไม่มี endpoint แก้/ลบ)
- [ ] R07-6 เทสต์ · รายงาน R-07

## R-08 ย้ายโมดูลเดิม (ทีละโมดูล ลำดับ: ลา → ลงเวลา → OT → สารบรรณ → แจ้งเตือน)
ต่อโมดูล:
- [ ] manifest (features, templates, defaults, menus ตาม PLAN §8.2 ค.–ฉ., workflows)
- [ ] แทนทุกจุดตรวจสิทธิ์ด้วย `PermissionEngine` · ลบ role-name literals · ลบ legacy permission names
- [ ] สายอนุมัติใช้ resolver ใหม่ · ลบ `LeaveWorkflowShadow` และ fallback
- [ ] ย้าย route ตาม PLAN §8.4 + redirect จาก route เดิม
- [ ] งานรออนุมัติของฉัน (`/inbox`) รวมงานของโมดูล
- [ ] เทสต์ persona + dry-run สายอนุมัติทุกคน × ทุกประเภท (ลา)
- [ ] รายงาน R-08-<module> (ระบุเทสต์ใน `tests/Legacy` ที่ย้าย/ลบ)

งานเฉพาะโมดูล:
- [ ] R08-leave-1 แก้ endpoint สาธารณะ `GET /api/leaves/verify/{hash}` และ `/qrcode` ที่ยังอ้าง `user.profile` ให้อ่านจาก `personnel` (ไม่ส่งข้อมูลอ่อนไหว)
- [ ] R08-notification-1 ตาราง `notification_channels` (ผู้ใช้ × ช่องทาง LINE/Telegram/อีเมล, ค่าระบุตัวผู้รับ, ยืนยันแล้วเมื่อใด) แทนคอลัมน์ `users.telegram_chat_id`/`line_user_id`/`notification_channel` เดิม (Q9, ADR-R-012)

## R-09 UAT และเปิดใช้
- [ ] R09-1 export รอบสุดท้ายจากระบบเดิม · ตัดระบบ · `migrate:fresh --seed` บน production ใหม่
- [ ] R09-2 นำเข้าข้อมูลจริง (หน่วยงาน บุคลากร คำสั่ง) และตั้งค่าสิทธิ์
- [ ] R09-3 UAT ตาม ACCEPTANCE-CRITERIA · ทดสอบเจาะระบบ · ตรวจ PDPA
- [ ] R09-4 อบรมผู้ใช้ · นำร่อง · รายงาน R-09
- [ ] R09-5 `backend/tests/Legacy/` ต้องว่าง (ทุกไฟล์ถูกเขียนใหม่หรือลบตามที่รายงานแต่ละเฟสระบุ) จึงผ่าน R-09 (Q7)
