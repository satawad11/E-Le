# LEGACY GAP ANALYSIS

> เปรียบเทียบระบบเดิมกับ Target Architecture และ Requirements ของ E-la Platform
>
> **สถานะ:** กรอกข้อมูลจริงแล้ว — รอบที่ 1 | **วันที่:** 2026-08-12
> **อ้างอิงหลักฐาน:** `docs/LEGACY-SYSTEM-ASSESSMENT.md` (ทุก SEC-xxx / DB-RISK-xxx อ้างถึงเอกสารนั้น)

## สถานะมาตรฐาน

- `PASS` — มีครบและตรงมาตรฐาน
- `PARTIAL` — มีบางส่วนแต่ต้องปรับ
- `MISSING` — ยังไม่มี
- `RISK` — มีแต่มีความเสี่ยงสูง
- `NOT_APPLICABLE` — ยังไม่เกี่ยวข้อง

## วิธีใช้งาน

1. อ่าน `REQUIREMENTS-MATRIX.md`
2. ตรวจ Source Code และ Database จริง
3. ผูก Requirement กับ Implementation ปัจจุบัน
4. ห้ามใช้การคาดเดา
5. ทุก `PARTIAL`, `MISSING`, `RISK` ต้องมี Recommended Action

## Priority

- `P0` — Security/Data loss/Production blocker
- `P1` — Core architecture หรือสิทธิ์
- `P2` — Functional gap สำคัญ
- `P3` — UX/Optimization

---

## Gap Matrix

| Requirement ID | Domain | Requirement | Current Implementation | Status | Gap | Risk | Recommended Action | Priority | Evidence |
|---|---|---|---|---|---|---|---|---|---|
| CORE-ORG-001 | Organization | โครงสร้างองค์กรแบบลำดับชั้น | ตาราง `departments` แบนราบ: `name`, `description`, `head_of_department_id`, `is_active` | **MISSING** | ไม่มี `parent_id`, ไม่มีลำดับชั้น, ไม่มี effective dating, ไม่มีประเภทหน่วยงาน | ทำ ABAC scope `UNIT`/`DESCENDANTS` ไม่ได้; approval chain ตามสายบังคับบัญชาทำไม่ได้; รายงานตามฝ่ายทำได้แค่ระดับเดียว | เพิ่มตาราง `org_units` (parent_id, type, effective_from/to) แบบ additive + view/adapter map จาก `departments` แล้ว dual-read | **P1** | `database/migrations/0000_00_00_000001_create_departments_table.php`, `2026_06_20_082600_add_head_and_status_to_departments_table.php` |
| CORE-ORG-002 | Organization | Organization scope ใน authorization | มี hard-code เฉพาะ "หัวหน้าแผนก" 2 จุด | **PARTIAL / RISK** | ไม่มี scope engine; scope ผูกกับชื่อ role | ผู้ใช้ข้ามฝ่ายเห็นข้อมูลข้ามหน่วยงานได้ในหลาย endpoint | สร้าง scope resolver กลาง (SELF/UNIT/DESCENDANTS/ORG/ASSIGNED) | **P1** | `LeaveController.php:44-50`, `ApprovalController.php:73-82` |
| CORE-PER-001 | Personnel | รองรับบุคลากรหลายประเภทและเพิ่มประเภทใหม่ได้ | ตาราง `personnel_types` (code, name, is_active) + `users.personnel_type_id` + UI จัดการ | **PASS** | — | — | รักษาไว้; ต่อยอดเป็น assignment history | — | `2026_08_08_100000_harden_leave_management_for_thai_government.php:16-28`, `Admin/PersonnelTypeController` |
| CORE-PER-002 | Personnel | ประวัติการดำรงตำแหน่ง/สังกัด แบบ effective-dated | เก็บเป็นค่าปัจจุบันบน `users` เท่านั้น | **MISSING** | ไม่มีประวัติ; ย้ายฝ่ายแล้วรายงานย้อนหลังเพี้ยน | ข้อมูลประกอบการประเมิน/เลื่อนเงินเดือนย้อนหลังไม่ถูกต้อง | เพิ่ม `personnel_assignments` (user, org_unit, personnel_type, position, effective_from/to) + backfill จากค่าปัจจุบัน | **P1** | `0001_01_01_000000_create_users_table.php`, `2026_08_10_000300_backfill_missing_personnel_type_on_users.php` |
| CORE-AUTH-001 | Authentication | Secure authentication | Sanctum PAT + bcrypt(12) + throttle 10/min + login ด้วยเลขบัตร (hash) + ตรวจ `is_active` | **RISK** | token ไม่หมดอายุ, ไม่มี lockout, ไม่มี password reset, ไม่มี 2FA, ไม่มี session/device management, ปิดบัญชีไม่เพิกถอน token, ไม่มี Secure cookie flag | XSS ครั้งเดียว = ยึดบัญชีถาวร; พนักงานที่ถูกปลดยังใช้ token เดิมได้ | ตั้ง `sanctum.expiration` + refresh; `tokens()->delete()` ตอน `toggleStatus`; เพิ่ม lockout + password reset; ตั้ง `SESSION_SECURE_COOKIE=true` | **P0** | SEC-012, `config/sanctum.php:46`, `AuthController.php`, `Admin/UserController::toggleStatus` |
| CORE-AUTH-002 | Authentication | ไม่ส่ง credential ผ่าน URL | Export 7 endpoint รับ token ทาง query string | **RISK** | token โผล่ใน access log / referrer / browser history | token รั่วผ่าน log | ใช้ signed URL อายุสั้น หรือ POST + blob download | **P0** | SEC-009, SEC-012, `routes/api.php:246-254`, `frontend/src/views/admin/AttendanceSummaryView.vue:267-281` |
| CORE-AUTHZ-001 | RBAC/ABAC | Authorization ฝั่ง Server ทุก endpoint | Spatie + middleware `can:`/`permission:` บางเส้น; หลายเส้นมีแค่ `auth:sanctum` | **RISK** | endpoint สำคัญไม่มี authorization เลย (ดู SEC-003/004/005/006/009) | ผู้ใช้ทั่วไปอ่าน/แก้/ลบข้อมูลผู้อื่นได้ | Audit ทุก route + บังคับ Policy ทุก resource + IDOR test ครบ | **P0** | SEC-003, SEC-004, SEC-005, SEC-006, SEC-009 |
| CORE-AUTHZ-002 | RBAC/ABAC | Permission format `MODULE.RESOURCE.ACTION` | มี 3 generation ปนกัน: `view_users` / `attendance.view_all` / `attendance.record.view_my` | **PARTIAL** | naming ไม่เป็นมาตรฐาน; frontend ต้องมี alias map 11 ชุด | สิทธิ์ให้ผิดตัวเพราะเข้าใจชื่อผิด; ตรวจสอบยาก | สร้าง canonical permission set + alias adapter ระหว่างเปลี่ยนผ่าน + เลิกใช้ชุดเก่าเมื่อ verify แล้ว | **P1** | `database/seeders/*.php`, `frontend/src/stores/auth.js:63-76` |
| CORE-AUTHZ-003 | RBAC/ABAC | ห้าม hard-code role | `hasRole()/hasAnyRole()` ด้วยชื่อไทยตรง ๆ 31 จุดใน 12 ไฟล์ backend + อีกชุดใน Pinia store | **RISK** | ผิด `PROJECT_RULES` และ `AGENTS.md` โดยตรง | เปลี่ยนชื่อ role ในหน้า admin = สิทธิ์ทั้งระบบพัง; ชื่อ role ยังสะกดไม่ตรงกันระหว่าง backend/frontend (`เจ้าหน้าที่สารบัญ` vs `เจ้าหน้าที่สารบรรณกลาง`) | แทนทุกจุดด้วย permission check; ทำทีละโมดูลพร้อม test | **P1** | `AppServiceProvider.php:25-30`, `LeaveController.php:30-36`, `Api/Saraban/DocumentController.php:38` vs `frontend/src/stores/auth.js:31` |
| CORE-AUTHZ-004 | RBAC/ABAC | Explicit deny > scoped allow > role allow > default deny | Spatie allow-only; `Gate::before` ให้ Super Admin ผ่านทุกอย่างโดยไม่มีทางปฏิเสธ | **MISSING** | ไม่มี deny; ไม่มี precedence engine | ไม่สามารถจำกัดสิทธิ์เฉพาะกรณีได้ (เช่น ห้าม HR ดูใบลาของตัวเอง) | สร้าง permission evaluation service ที่รองรับ deny + scope | **P1** | `AppServiceProvider.php:25-30`, `docs/06-RBAC-ABAC.md` |
| CORE-AUTHZ-005 | RBAC/ABAC | Anti-IDOR | ไม่มี Policy class เลยทั้งโปรเจกต์ (`app/Policies/` ไม่มี); ownership check กระจายเป็น `if` ใน controller | **RISK** | endpoint หลายเส้นไม่ตรวจ ownership | ดาวน์โหลดใบลา/เอกสารลับของผู้อื่นได้ | สร้าง Policy ต่อ model + `authorizeResource` + IDOR test ทุก resource | **P0** | SEC-003, SEC-005, `ls app/` (ไม่มีโฟลเดอร์ Policies) |
| CORE-AUTHZ-006 | RBAC/ABAC | Temporary delegation | `user_delegations` (start/end/is_active) ใช้ในการอนุมัติใบลา | **PARTIAL** | ใช้เฉพาะโมดูล Leave; ไม่ครอบคลุมสารบรรณ/ตั้งค่า; ไม่บันทึก effective actor ใน audit | ประวัติไม่รู้ว่าใครทำแทนใคร | ยกขึ้นเป็น core service + บันทึก `effective_actor` ใน audit กลาง | P2 | `2026_06_21_215700_create_user_delegations_table.php`, `ApprovalController.php:47-61` |
| CORE-WF-001 | Workflow | Workflow configurable + versioned | `approval_steps` (role_id, step_order, name) global + `leaves.approval_level` integer | **RISK** | ไม่มี version, parallel, conditional, escalation, timeout, return; ไม่ผูกกับ department/personnel type/leave type; แก้ workflow กระทบใบลาที่กำลังเดิน | ผิด ADR-002 และ ACCEPTANCE-CRITERIA ("policy/workflow ใหม่ไม่เปลี่ยนผลย้อนหลัง") | **REPLACE** ด้วย engine ตาม `docs/07` + pin version ที่ instance; เก็บ `leave_approvals` เดิมอ่านอย่างเดียว | **P1** | DB-RISK-003, `2026_06_14_000001_create_approval_steps_table.php`, `ApprovalController::approve:105-135` |
| CORE-WF-002 | Workflow | Saraban workflow | มี `saraban_document_workflow_templates` + `_steps` + state machine 7 สถานะ | **PARTIAL / RISK** | schema ดีกว่าฝั่ง Leave แต่ไม่มี version pinning และ **transition ไม่ตรวจสิทธิ์** | ใครก็ submit/approve/reject เอกสารได้ | รวมเข้ากับ workflow engine กลาง + บังคับ authorization ทุก transition | **P1** | `2026_07_13_230752_create_saraban_document_workflow_tables.php`, `Api/Saraban/WorkflowController.php`, `routes/api.php:222-225` |
| CORE-AUDIT-001 | Audit | Audit ทุก mutation สำคัญ พร้อม actor/diff/IP/correlation | มี 4 ตารางแยกกัน: `leave_audit_logs` (มี before/after/ip/ua — ดี), `leave_action_logs`, `saraban_audit_logs`, `personal_data_access_logs` | **PARTIAL** | **ไม่มี audit** ในการแก้ผู้ใช้/บทบาท/สิทธิ์/การตั้งค่า/การลงเวลา; ไม่มี correlation ID; ไม่มี effective actor; ไม่มี append-only enforcement | ตรวจสอบย้อนหลังไม่ครบ — ผิด `docs/12` และ `AGENTS.md` | สร้าง `audit_logs` กลาง + dual-write จาก 4 ตารางเดิม + ครอบคลุม controller ที่ยังขาด | **P1** | SEC-016, DB-RISK-010, grep `audit` ใน `Admin/UserController`, `Admin/PermissionController`, `Api/RoleController`, `AttendanceController`, `SecureSettingController` = 0 hit |
| CORE-MOD-001 | Module Registry | เพิ่ม module ใหม่ได้โดยไม่รื้อ Core (REQ-CORE-001) | `config/modules.php` + ตาราง `menus` + `MenuService` | **PARTIAL** | เป็น sidebar config ไม่ใช่ registry; ไม่มี version/dependencies/enabled flag/permissions declaration ตาม `docs/08` | เพิ่มโมดูลใหม่ต้องแก้โค้ดหลายจุด | ยกระดับเป็น `modules` table ตาม `docs/08-MODULE-STANDARDS.md` | P2 | `backend/config/modules.php`, `2026_08_07_000000_create_menus_tables.php`, `app/Services/MenuService.php` |
| CORE-ID-001 | Database | UUID เป็น public identifier | auto-increment `id` ทุกตาราง ใช้ตรงใน URL | **MISSING** | ผิด `PROJECT_RULES` ข้อ 4; enumeration ง่าย | เดา id เอกสาร/ใบลาของผู้อื่นได้ (ทำให้ IDOR ร้ายแรงขึ้น) | เพิ่มคอลัมน์ `uuid` แบบ additive + backfill + สลับ route model binding + คง id ไว้เป็น internal | P2 | DB-RISK-012, `routes/api.php` ทั้งไฟล์ |
| CORE-DB-001 | Database | Migration forward-only, ตรวจ lock/data risk | 91 migration; มี `down()` ที่ drop table; มีร่องรอย DDL timeout กลางคัน | **RISK** | schema จริงอาจไม่ตรง migration | รัน migration ต่อโดยไม่ยืนยัน = ข้อมูลเสียหาย | **บล็อกทุกงาน** จนกว่าจะ diff `information_schema` กับ migration | **P0** | DB-RISK-006, `2026_08_08_100000...php:11-14`, `2026_08_08_100050_resume_interrupted_leave_hardening.php` |
| CORE-DB-002 | Database | UTC storage / Asia/Bangkok display | เก็บ ค.ศ. ใน DB + แปลง พ.ศ. ที่ presentation ผ่าน `ThaiDateService` | **PASS** | — | — | รักษาไว้ | — | `app/Services/ThaiDateService.php`, `frontend/src/utils/ThaiDateFormatter.js` |
| TIME-SCH-001 | Work Schedule | ตารางเวลารายบุคคล (REQ-ATT-001) | `users.work_start_time`/`work_end_time` + fallback `default_work_start_time` ใน settings | **PARTIAL** | เป็นค่าเดี่ยวต่อคน ไม่ใช่ตาราง; ไม่มี effective date; ไม่มีตารางรายวัน/กะ/วันหยุดรายบุคคล; **ไม่มี grace period** (สายทันทีที่เกิน 1 วินาที) | ผู้สอนที่มีตารางต่างกันในแต่ละวันใช้ไม่ได้ | สร้าง `work_schedules` + `work_schedule_assignments` แบบ effective-dated + grace period ใน policy | P2 | `2026_06_21_000001_add_work_hours_to_users_table.php`, `AttendanceController.php:80-86` |
| TIME-CAL-001 | Calendar | ปฏิทิน/วันหยุด | `holidays` + `work_calendar_exceptions` (มี scope ตาม department) + ใช้ในการคำนวณวันลา | **PASS** | — | — | รักษาไว้ | — | `2026_06_20_180300_create_holidays_table.php`, `2026_08_09_000000_add_partial_leave_adjustments_and_scoped_calendar.php`, `LeaveService::calculateTotalDays` |
| LEAVE-001 | Leave | นับจำนวนครั้งและจำนวนวันแยกกัน (REQ-LEAVE-002) | `leaves` เก็บ `total_days`, `paid_days`, `unpaid_days`, `reserved_days`; รายงานสรุปมี `PromotionScreeningController`, `GovernmentLeaveReportController` | **PASS** | ต้องยืนยันว่ารายงานนับ "ครั้ง" แยกจาก "วัน" จริงในทุกรายงาน | ต่ำ | เขียน test ยืนยัน occurrences vs days ตาม ACCEPTANCE-CRITERIA | P3 | `2026_08_10_000400_add_paid_unpaid_days_to_leaves.php`, `PromotionScreeningTest` (7 test) |
| LEAVE-002 | Leave | Policy ตามประเภทบุคลากร + versioning (REQ-LEAVE-001) | `leave_policies` (personnel_type × leave_type, `effective_from`/`effective_to`, quota, caps, evidence rules, carry forward, period_basis) + `leaves.leave_policy_id` pin ไว้ | **PASS** | ไม่มีคอลัมน์ `version` ชัดเจน (ใช้ effective date แทน) — ยอมรับได้ | ต่ำ | รักษาไว้; พิจารณาเพิ่ม `version` เพื่อการอ้างอิงที่ชัดเจน | P3 | `2026_08_08_100000...php:30-55`, `app/Services/LeavePolicyService.php`, `LeaveEntitlementRulesTest` (8 test) |
| LEAVE-003 | Leave | แบบฟอร์มราชการแบบ Versioned + pin template (REQ-LEAVE-003) | `document_templates` (code, module, paper size, margins, `content_html`, `is_active`) + blade views 7 แบบ + dompdf/Word export | **PARTIAL / RISK** | **ไม่มี `version`, ไม่มี effective dating, `leaves` ไม่ pin template** | แก้เทมเพลตแล้วใบลาที่อนุมัติไปแล้วพิมพ์ออกมาเปลี่ยน — ผิด `docs/14` และ ACCEPTANCE-CRITERIA โดยตรง | เพิ่ม `document_template_versions` + `leaves.document_template_version_id` แบบ additive + backfill เป็น version 1 | **P1** | DB-RISK-004, `2026_08_08_000000_create_document_templates_table.php`, `resources/views/pdf/*.blade.php` |
| LEAVE-004 | Leave | หลักฐานประกอบการลา (ใบรับรองแพทย์) เก็บปลอดภัย | `leave_evidences` (sha256, mime, size, verification workflow) เก็บ disk `local` (private) + download ตรวจสิทธิ์ | **PASS** | ผู้อนุมัติในสายงานยังเข้าถึงหลักฐานไม่ได้ (เฉพาะเจ้าของ/HR/manage) — เป็น functional gap ไม่ใช่ security gap | ต่ำ | ขยาย `authorizeAccess` ให้ครอบคลุมผู้อนุมัติปัจจุบัน | P3 | `LeaveEvidenceController.php` ทั้งไฟล์, `2026_08_08_100000...php:66-83` |
| LEAVE-005 | Leave | ป้องกันการเข้าถึงใบลาของผู้อื่น | `index` มี scope; แต่ `exportPdf`/`exportWord`/`hrVerify` ไม่มี authorization เลย | **RISK** | IDOR + Broken Access Control | ข้อมูลอ่อนไหว (เหตุผลลาป่วย, ที่อยู่, ลายเซ็น) รั่วทั้งองค์กร | เพิ่ม `LeavePolicy` + `authorize()` + IDOR test | **P0** | SEC-003, SEC-004, `LeaveController.php:499-512, 623, 748` |
| LEAVE-006 | Leave | ความสอดคล้องของ role check ในโมดูล Leave | `update`/`destroy` ใช้ `$user->role->name` (legacy relation) ขณะที่เมธอดอื่นใช้ Spatie | **RISK** | ถ้า `users.role_id` เป็น null จะเกิด error 500; และ bypass Spatie | แก้/ลบใบลาไม่ได้ หรือสิทธิ์ผิดตัว | รวมเป็น Spatie อย่างเดียวผ่าน compat adapter | **P1** | DB-RISK-002, `LeaveController.php:562, 597` |
| ATT-001 | Attendance | Check-in/out | GPS + geofencing (`max_attendance_radius`) + เซลฟี่ + server timestamp + คำนวณสาย/ออกก่อน | **PARTIAL** | 1 แถว/คน/วัน; ไม่มี correction workflow; ไม่มี grace period; location เชื่อค่าจาก client ทั้งหมด | ปลอมตำแหน่งได้; แก้ข้อมูลลงเวลาย้อนหลังไม่มีขั้นตอนที่ตรวจสอบได้ | เพิ่ม correction workflow + grace period + บันทึกความน่าเชื่อถือของ location | P2 | `2026_06_21_000000_create_attendances_table.php`, `AttendanceController::checkIn:16-118` |
| ATT-002 | Attendance | Server timestamp เป็น source of truth | ใช้ `Carbon::now()` ฝั่ง server ทุกจุด | **PASS** | — | — | รักษาไว้ (ตรง `PROJECT_RULES` ข้อ 9) | — | `AttendanceController.php:25` |
| ATT-003 | Attendance | ไฟล์ภาพยืนยันตัวตนเก็บปลอดภัย | เขียนลง disk `public` ด้วยชื่อไฟล์ที่เดาได้ `checkin_{user_id}_{unixtime}.{ext}` และเสิร์ฟผ่าน endpoint สาธารณะ | **RISK** | ภาพใบหน้าบุคลากรทั้งองค์กรเปิดสาธารณะและ enumerate ได้ | ละเมิดข้อมูลอ่อนไหวร้ายแรง; ผิด `docs/05` และ PDPA | ย้ายไป private + authorized download + ชื่อไฟล์สุ่ม | **P0** | SEC-001, `AttendanceController.php:63-73`, `routes/api.php:263-273` |
| ATT-004 | Attendance | Upload validation (MIME/ขนาด) | รับ base64 string ไม่จำกัดขนาด และเอานามสกุลจาก MIME ที่ client ส่งมาโดยไม่มี whitelist | **RISK** | เขียนไฟล์นามสกุลใดก็ได้ลง public storage | อาจนำไปสู่ RCE ผ่าน nginx `location ~ \.php$` | whitelist + `finfo` + จำกัดขนาด + เก็บ private | **P0** | SEC-002, เทียบกับ `LeaveController.php:267` ที่ทำถูกต้อง |
| ATT-INT-001 | Integration | รับข้อมูลจาก Vendor scanner system (REQ-ATT-002) | **ไม่มีอะไรเลย** — grep `zkteco/hikvision/biometric/device/scanner` ทั่ว repo = 0 hit ที่เกี่ยวข้อง | **MISSING** | ไม่มี connector, ไม่มี source registry, ไม่มี device mapping, ไม่มี personnel mapping | requirement Must ที่ยังไม่เริ่มเลย | สร้าง adapter pattern ตาม `docs/15` — **ต้องได้ข้อมูล vendor จากผู้ใช้ก่อน** | **P2** (P1 ถ้าเป็น go-live requirement) | §11 ของ Assessment |
| ATT-INT-002 | Integration | Raw event, dedup, incremental sync, reconcile (REQ-ATT-003) | ไม่มี raw event store; `unique(user_id,date)` บังคับให้เก็บได้วันละแถว | **MISSING** | ขัดกับ ADR-003 โดยตรง | event ซ้ำจะสร้าง attendance ซ้ำหรือชนกัน; reconcile ไม่ได้ | เพิ่ม `attendance_raw_events` + `attendance_sources` + `device_registry` แล้วให้ `attendances` เป็นผลลัพธ์ที่คำนวณ | **P2** | DB-RISK-005, `docs/20-DECISION-LOG.md` ADR-003 |
| OT-001 | Overtime | OT order / assignment / approved vs actual vs certified time (REQ-OT-001) | 5 คอลัมน์ใน `attendances`: `ot_check_in_time`, `ot_check_out_time`, `ot_minutes` + endpoint check-in/out + รายงานสรุป | **PARTIAL** | ไม่มีคำสั่ง OT, ไม่มีการมอบหมาย, ไม่มีเวลาที่อนุมัติ/รับรองแยกจากเวลาจริง, **ไม่มี overlap detection กับใบลา/OT อื่น** | เบิกค่าตอบแทนจากข้อมูลที่ไม่มีการรับรอง | สร้างโมดูล OT แยก (`ot_orders`, `ot_assignments`, `ot_time_records`) + ตรวจเวลาซ้ำ | **P2** | `2026_06_21_000002_add_ot_and_minutes_to_attendances_table.php`, `AttendanceController::otCheckIn:232` |
| NOTIFY-001 | Notification | LINE OA (REQ-NOT-001) | `LineNotificationService` เรียก Messaging API push แบบ synchronous | **PARTIAL / RISK** | ไม่มีคิว/retry/backoff/dead-letter/delivery log/template/preference | LINE ล่ม → request ค้าง; ข้อความหายเงียบ (คืน `false` เฉย ๆ) | ย้ายเข้า queue + delivery log + retry ตาม `docs/13` | P2 | `app/Services/LineNotificationService.php:11-43` |
| NOTIFY-002 | Notification | Telegram | มี 2 service ซ้ำซ้อน (`TelegramService`, `TelegramNotificationService`) | **PARTIAL** | ซ้ำซ้อน; ไม่มี retry/log | เหมือน NOTIFY-001 | รวมเป็น provider เดียวใต้ Notification Center | P2 | `app/Services/Telegram*.php` |
| NOTIFY-003 | Notification | Email | SMTP config โหลดจากตาราง `settings` ตอน boot | **PARTIAL** | ไม่มี template versioning; ไม่มี delivery log | เหมือนข้างบน | รวมเข้า Notification Center | P2 | `AppServiceProvider.php:32-52` |
| NOTIFY-004 | Notification | In-App | ตาราง `notifications` + `NotificationController` + `ShouldQueue` | **RISK** | **ไม่มี queue worker รันเลย** → notification ที่เป็น `ShouldQueue` ค้างในตาราง `jobs` ไม่ถูกส่ง | ผู้อนุมัติไม่ได้รับแจ้งเตือนใบลา — ผู้ใช้อาจไม่รู้ตัวว่าระบบไม่ทำงาน | เพิ่ม worker container + monitoring คิว | **P1** | §14 ของ Assessment, `docker-compose.yml`, `app/Notifications/LeaveRequested.php:11` |
| NOTIFY-005 | Notification | External provider ล่มต้องไม่ทำให้ transaction หลัก rollback | `LineNotificationService` จับ exception แล้วคืน `false` — ถูกต้อง; แต่เรียกใน request path จึงยังทำให้ช้า/timeout ได้ | **PARTIAL** | ยังอยู่ใน request path | request ค้างเมื่อ provider ช้า | ย้ายไป queue ทั้งหมด | P2 | `LineNotificationService.php:40-43` |
| REPORT-001 | Reporting | Export PDF/Excel/CSV | dompdf (PDF), `LeaveWordService` (docx), `LeaveExcelService`, CSV หลายจุด | **PASS** | — | — | รักษาไว้ | — | `app/Services/Export/*`, `ReportController`, `GovernmentLeaveReportController::csv` |
| REPORT-002 | Reporting | Export ต้องผ่าน authorization | 7 endpoint export ของ attendance อยู่นอก `auth:sanctum` และไม่ตรวจ permission | **RISK** | ผู้ใช้ธรรมดา export ข้อมูลลงเวลาทั้งองค์กรได้ | ข้อมูลบุคลากรทั้งองค์กรรั่ว | ย้ายเข้า auth group + `can:attendance.view_all` | **P0** | SEC-009, `routes/api.php:246-254` |
| EVAL-001 | Evaluation | ข้อมูลลา/เวลาเพื่อประกอบการประเมิน/เลื่อนเงินเดือน (REQ-EVAL-001) | `PromotionScreeningController` + `leave_types.counts_for_promotion` flag + `LeaveCardController` (บัตรลา) + 7 test | **PARTIAL** | ครอบคลุมเฉพาะข้อมูลลา; ยังไม่รวมข้อมูลเวลา/สาย/OT; policy การประเมินยัง hard-code ในรายงาน | ข้อมูลประกอบการเลื่อนเงินเดือนไม่ครบ | ขยายเป็น evaluation period metrics ตาม policy ที่ configurable | P2 | `PromotionScreeningController`, `2026_08_10_000600_add_promotion_count_flag_to_leave_types.php` |
| SEC-STD-001 | Security | ไฟล์เอกสารบุคคลต้องอยู่ private storage (REQ-SEC-001) | ปนกัน: leave evidence → private (ถูก); ลายเซ็น/เซลฟี่/ไฟล์แนบสารบรรณ → public (ผิด) | **RISK** | endpoint `/api/storage/{path}` สาธารณะ + nginx alias `/storage` | ลายเซ็นบุคลากรถูกดาวน์โหลดไปปลอมแปลงได้ | ย้ายทุกไฟล์ไป private + authorized download; ลบ route และ nginx location | **P0** | SEC-001, `ProfileController.php:63,73`, `LeaveController.php:270` |
| SEC-STD-002 | Security | Security headers | ไม่มี CSP/HSTS/X-Frame-Options/X-Content-Type-Options | **MISSING** | — | clickjacking, MIME sniffing, XSS ขยายผล | เพิ่ม middleware + nginx | P2 | SEC-014, `docker/nginx/default.conf` |
| SEC-STD-003 | Security | Secret management | `.env` ignored ถูกต้อง แต่ credentials โผล่ใน `docker-compose.yml` และ `public/test_db.php`; `phpinfo()` เปิดอยู่ | **RISK** | — | credential รั่วจากเว็บโดยตรง | ลบไฟล์ + หมุนรหัส + secret manager | **P0** | SEC-010, `backend/public/info.php`, `backend/public/test_db.php` |
| SEC-STD-004 | Security | Input validation | มี `$request->validate()` ทั่วถึงพอควร; แต่ `SanitizeInput` เป็น blacklist ที่แก้ข้อมูลผู้ใช้; `StoreLeaveRequest` เป็น scaffold ที่ `authorize()` คืน `false` และไม่มี rules | **PARTIAL** | สร้างความสับสน; ไฟล์ตาย | ข้อมูลผู้ใช้ถูกดัดแปลงโดยไม่ตั้งใจ | ถอด `SanitizeInput`, ทำ escape ที่ output, ลบ/เติม FormRequest ให้ครบ | P2 | SEC-013, `app/Http/Requests/StoreLeaveRequest.php` |
| TEST-001 | Testing | Authorization + IDOR tests บังคับ | ไม่มี IDOR test เลย; ไม่มี role × scope × action matrix | **MISSING** | — | ช่องโหว่ CRITICAL 6 รายการไม่ถูกจับได้เลย | เขียน IDOR/authz test ก่อนเริ่ม LEGACY-02 (test นำการแก้) | **P0** | §13 ของ Assessment |
| TEST-002 | Testing | Test ต้องผ่านก่อนผ่าน phase | `.phpunit.result.cache` มี defect 42 รายการ (ต้องยืนยันด้วยการรันจริง) | **RISK** | ไม่มีตาข่ายนิรภัยสำหรับ refactor | ผิด `AGENTS.md` ("ห้าม bypass tests") | ทำ test เขียวเป็นงานแรกของ LEGACY-01 | **P1** | `backend/.phpunit.result.cache` |
| TEST-003 | Testing | Migration test บน engine เดียวกับ production | test = SQLite in-memory, prod = MySQL 8 | **RISK** | migration ที่พังเฉพาะ MySQL ผ่าน CI ได้ | ซ้ำรอย DDL timeout เดิม | เพิ่ม CI job รัน migration บน MySQL 8 | **P1** | DB-RISK-008, `backend/phpunit.xml` |
| TEST-004 | Testing | Frontend lint/typecheck/test | ไม่มีเลย (scripts มีแค่ dev/build/preview) | **MISSING** | ไม่มี TypeScript ตาม `docs/02` | regression ฝั่ง UI ไม่ถูกจับ | เพิ่ม ESLint + Vitest; พิจารณา TS ทีละไฟล์ | P2 | `frontend/package.json` |
| DEPLOY-001 | Deployment | Production-grade web server | `php artisan serve` (dev server) | **RISK** | single-threaded, ไม่มี TLS | ล่มง่าย, ไม่ปลอดภัย | ใช้ nginx + php-fpm (มี config อยู่แล้วแต่ไม่ได้ต่อ) | **P1** | `docker-compose.yml:47-52` |
| DEPLOY-002 | Deployment | Environment separation | `.env` เดียว `APP_ENV=local`, `APP_DEBUG=true`; `.env.testing` commit เข้า git | **MISSING** | — | debug mode บน production เปิดเผย credentials | แยก env + env validation ตอน boot | **P1** | SEC-017, `backend/.env:2-4` |
| DEPLOY-003 | Deployment | Worker + Scheduler container | ไม่มีทั้งคู่ | **MISSING** | queue และ schedule ทั้ง 4 งานไม่เคยรัน | carry-forward วันลาไม่รัน, governance reminder ไม่ส่ง, backup ไม่ทำงาน | เพิ่ม service `worker` (`queue:work`) + `scheduler` (`schedule:work`) | **P1** | `docker-compose.yml`, `routes/console.php` |
| DEPLOY-004 | Deployment | Docker stack สอดคล้องกัน | มี 2 stack ที่ไม่เชื่อมกัน (compose ใช้ artisan serve; nginx+php-fpm config ไม่มีใครใช้) | **RISK** | สภาพแวดล้อม dev ≠ สิ่งที่ deploy | พฤติกรรมต่างกันระหว่าง dev/prod (เช่น nginx `location /storage` ที่มีเฉพาะ prod) | รวมเป็น stack เดียว | **P1** | `docker-compose.yml` vs `Dockerfile` + `docker/nginx/default.conf` |
| BACKUP-001 | Backup/DR | Backup DB + storage พร้อม retention | `Schedule::command('backup:run')` แต่ **`spatie/laravel-backup` ไม่อยู่ใน `composer.json`** และไม่มี scheduler รัน | **MISSING** | ไม่มี backup ใด ๆ ทำงานจริง | **ข้อมูลสูญถาวรถ้าเกิดเหตุ** — และเป็นเงื่อนไขบังคับก่อนเริ่ม migration ใด ๆ | ติดตั้ง package + scheduler + off-site + ทดสอบ restore | **P0** | §14 ของ Assessment, `routes/console.php:5`, `backend/composer.json` |
| BACKUP-002 | Backup/DR | Restore drill + RPO/RTO | ไม่มีเอกสาร/สคริปต์/การซ้อม | **MISSING** | — | ไม่รู้ว่ากู้ได้จริงหรือไม่ | ทำ restore drill ใน LEGACY-00 | **P0** | `docs/17-BACKUP-DR.md` |
| OBS-001 | Observability | Structured logging + correlation ID + monitoring | `LOG_CHANNEL=stack`, `LOG_LEVEL=debug`, single file; ไม่มี correlation ID; ไม่มี monitoring | **MISSING** | — | สืบสวนปัญหา production ไม่ได้ | เพิ่ม structured log + request ID + health/metrics | P2 | `backend/config/logging.php`, `backend/.env` |
| UI-001 | UI/UX | UI ภาษาไทย 100% | ไทยเกือบทั้งหมด แต่มีข้อความอังกฤษหลุดจาก API และ error ภายในรั่วสู่ผู้ใช้ | **PARTIAL** | — | ผิด ACCEPTANCE-CRITERIA ข้อสุดท้าย | แปลข้อความที่เหลือ + map error เป็นข้อความไทยมาตรฐาน | P3 | `Api/Saraban/DocumentController.php:99,349,378` |
| UI-002 | UI/UX | Responsive + Accessibility | Tailwind 4 (น่าจะ responsive) — **ยืนยันไม่ได้โดยไม่รันแอป**; ไม่มีร่องรอย a11y | **UNKNOWN** / **MISSING** | — | ใช้บนมือถือไม่ได้ (ยังไม่ยืนยัน) | ตรวจจริงเมื่อรันแอปได้ + ทำ a11y pass | P3 | `frontend/src/**` |
| SARABAN-001 | Saraban | เลขที่หนังสือ atomic + ตามปีงบประมาณ | `DocumentNumberGeneratorService` ใช้ transaction + `lockForUpdate` + unique constraint + คำนวณปีงบประมาณ (1 ต.ค.) | **PASS** | มี race เล็กน้อยตอนสร้างแถวแรกของ (dept,type,year) → เกิด integrity error (500) แต่ **ไม่เกิดเลขซ้ำ** | ต่ำ | ใช้ `firstOrCreate` + retry เหมือนที่ `LeaveDocumentNumberService` ทำ | P3 | `app/Services/Saraban/DocumentNumberGeneratorService.php:32-55`, `2026_07_12_020001` |
| SARABAN-002 | Saraban | เอกสารลับต้องจำกัดการเข้าถึง | middleware `CanViewClassifiedDocument` เขียนไว้แต่ **ไม่เคยลงทะเบียนหรือผูกกับ route ใดเลย** | **RISK** | dead code | เอกสาร `secret`/`top_secret` เปิดให้ผู้ใช้ทุกคนอ่านได้ | ผูก middleware + Policy + IDOR test | **P0** | SEC-005, `app/Http/Middleware/CanViewClassifiedDocument.php` |
| SARABAN-003 | Saraban | E-signature ที่มีผลผูกพัน | `saraban_document_signatures` + hash + QR verify + stamp PDF | **RISK** | `mode` จาก client ข้ามการยืนยันรหัสผ่านได้; ผู้สร้างเอกสารลงนามเอกสารตัวเองได้; `verify` คืน `is_valid: true` แบบ hard-code | ลายเซ็นไม่มีผลทางนิติกรรม; ตรวจการปลอมแปลงไม่ได้ | บังคับ re-auth ทุก mode + ตรวจ assignee + คำนวณ hash ใหม่ตอน verify | **P0** | SEC-007, SEC-008, `Api/Saraban/SignatureController.php:26-40, 103-124` |
| SARABAN-004 | Saraban | สิทธิ์การลบ/แก้เอกสาร | `destroy`, `destroyMultiple`, `update` ไม่มี authorization | **RISK** | — | ทำลายทะเบียนหนังสือราชการได้ | บังคับ permission + ownership + audit + soft delete (มี `softDeletes` อยู่แล้ว) | **P0** | SEC-006, `Api/Saraban/DocumentController.php:91-101, 247, 353` |

---

## Security Gap Summary

| Finding | Status | Priority | Action |
|---|---|---|---|
| Server-side authorization ทุก endpoint | **RISK** | **P0** | Audit ทั้ง 168 route; บังคับ `can:`/Policy ทุกเส้น; เขียน test ที่ล้มถ้ามี route ไม่มี authorization |
| IDOR protection | **MISSING** | **P0** | สร้าง Policy ทุก model (`app/Policies/` ยังไม่มีเลย) + IDOR test ต่อ resource |
| Organization scope | **MISSING** | **P1** | สร้าง org hierarchy (CORE-ORG-001) แล้วต่อด้วย scope resolver |
| Audit log | **PARTIAL** | **P1** | รวม 4 ตารางเป็น audit กลาง + ครอบคลุม users/roles/permissions/settings/attendance |
| Secure session/token | **RISK** | **P0** | ตั้ง token expiration; เพิกถอน token เมื่อปิดบัญชี; เลิกส่ง token ใน URL; ตั้ง Secure cookie |
| File security | **RISK** | **P0** | ทุกไฟล์บุคคล → private + authorized download; ลบ `/api/storage/{path}` และ nginx `location /storage`; whitelist upload |
| Secret handling | **RISK** | **P0** | ลบ `public/info.php`, `public/test.php`, `public/test_db.php`, `routes/web.php::/fix-leaves`; หมุน DB credentials; ตั้งรหัส MySQL |
| Rate limiting | **PARTIAL** | P2 | เพิ่ม throttle เฉพาะ export/download/verify |
| Security headers | **MISSING** | P2 | middleware + nginx |

---

## Database Gap Summary

| Area | Current | Target | Gap | Migration Need |
|---|---|---|---|---|
| Organization | `departments` แบน (name, description, head_of_department_id, is_active) | `org_units` แบบลำดับชั้น + effective-dated | ไม่มี parent, ไม่มีประวัติ, ไม่มีประเภทหน่วยงาน | **Additive** — สร้าง `org_units` + `department_id → org_unit_id` mapping + backfill + dual-read |
| Personnel | `personnel_types` + `users.personnel_type_id` (ค่าปัจจุบัน) | + `personnel_assignments` แบบ effective-dated | ไม่มีประวัติการย้ายฝ่าย/เปลี่ยนประเภท | **Additive** — สร้างตารางประวัติ + backfill 1 แถวจากค่าปัจจุบัน (`effective_from` = วันที่สร้างผู้ใช้) |
| Permission | Spatie + `users.role_id` legacy + `users.custom_permissions` json + permission 3 generation | Spatie เดียว + canonical naming + deny + scope | สิทธิ์ 3 แหล่ง, naming 3 รุ่น, ไม่มี deny/scope | **Additive** — เพิ่ม `permission_scopes`, `permission_denies`; alias table สำหรับ naming เก่า; เลิกใช้ `role_id` หลัง verify |
| Workflow | `approval_steps` (global) + `leaves.approval_level` + `saraban_document_workflow_*` | `workflow_definitions/versions/steps/transitions/instances/tasks/actions/delegations` | ไม่มี version pinning, ไม่มี parallel/conditional/escalation | **Additive + Replace logic** — สร้าง engine ใหม่, เดินคู่ขนาน (shadow), เก็บ `leave_approvals`/`approval_steps` เดิมอ่านอย่างเดียว |
| Leave | `leaves`, `leave_policies` (effective-dated, pinned), `leave_balances` (reserved/used), `leave_evidences` (sha256), `leave_audit_logs` | เพิ่ม template version pinning + occurrences counter | ไม่ pin `document_template`; enum `status` อาจไม่รองรับ `Voided` | **Additive** — `document_template_versions` + `leaves.document_template_version_id`; แปลง enum → string + CHECK (ต้องตรวจ DB จริงก่อน) |
| Attendance | `attendances` (unique user+date), `work_calendar_exceptions` | `attendance_sources`, `devices`, `attendance_raw_events`, `attendances` เป็นผลลัพธ์ | ไม่มี raw event, ไม่มี device, ไม่มี dedup key, 1 แถว/วัน | **Additive** — สร้าง raw event layer ก่อน; คง `attendances` เดิมทั้งหมดในฐานะข้อมูลที่ reconcile แล้ว; **ห้าม drop unique** จนกว่า engine ใหม่ verify |
| OT | 5 คอลัมน์ใน `attendances` | `ot_orders`, `ot_assignments`, `ot_time_records` (approved/actual/certified) | ไม่มี order/assignment/certified time/overlap detection | **Additive** — ตารางใหม่ + backfill จากคอลัมน์เดิมเป็น actual time |
| Audit | 4 ตารางแยก + ไม่ครอบคลุม | `audit_logs` กลาง append-oriented | ไม่มี correlation ID, ไม่มี effective actor, ไม่ครอบคลุม | **Additive** — สร้างตารางกลาง + dual-write + backfill จาก 4 ตารางเดิม |
| Identity | auto-increment id ใน URL | UUID เป็น public id | enumeration ง่าย | **Additive** — เพิ่มคอลัมน์ `uuid` + backfill + สลับ route binding + คง id เป็น internal FK |
| Document Template | `document_templates` (ไม่มี version) | versioned + effective-dated + pinned | แก้เทมเพลตกระทบเอกสารย้อนหลัง | **Additive** — `document_template_versions`, backfill เนื้อหาปัจจุบันเป็น v1 |

---

## Final Gap Summary

**นับตาม Gap Matrix (49 รายการ)**

| Status | จำนวน |
|---|---:|
| `PASS` | 8 |
| `PARTIAL` | 16 |
| `MISSING` | 12 |
| `RISK` | 12 |
| `UNKNOWN` | 1 |
| `NOT_APPLICABLE` | 0 |

**นับตาม Priority**

| Priority | จำนวน |
|---|---:|
| **P0** | 14 |
| **P1** | 16 |
| P2 | 14 |
| P3 | 5 |

---

## Top 10 Gaps ที่ต้องแก้ก่อนเพิ่มโมดูลใหม่

| # | Gap | Requirement ID | Priority | เหตุผลที่ต้องมาก่อน |
|---|---|---|---|---|
| 1 | **Schema จริงยังไม่ได้ยืนยันว่าตรงกับ migration** (มีร่องรอย DDL timeout) | CORE-DB-001 | **P0** | ทุกแผนหลังจากนี้ตั้งอยู่บนสมมติฐานนี้ ถ้าผิดคือข้อมูลเสียหาย |
| 2 | **ไม่มี backup ที่ทำงานจริง** (`spatie/laravel-backup` ไม่ได้ติดตั้ง + ไม่มี scheduler) | BACKUP-001/002 | **P0** | ห้ามแตะ schema ใด ๆ ก่อนกู้คืนได้จริง |
| 3 | **ไฟล์ส่วนบุคคลเปิดสาธารณะ** (ลายเซ็น, ภาพใบหน้า, ไฟล์แนบสารบรรณ) | SEC-STD-001, ATT-003 | **P0** | ข้อมูลกำลังรั่วอยู่ ณ ขณะนี้ |
| 4 | **ช่องอัปโหลดที่อาจนำไปสู่ RCE** (นามสกุลไฟล์จาก client) | ATT-004 | **P0** | ยึดเซิร์ฟเวอร์ได้ |
| 5 | **IDOR ทั่วระบบ — ไม่มี Policy class เลย** (ใบลาผู้อื่น, เอกสารลับ, ลบเอกสาร) | CORE-AUTHZ-005, LEAVE-005, SARABAN-002/004 | **P0** | ละเมิดข้อมูลอ่อนไหวและทำลายทะเบียนหนังสือราชการได้ |
| 6 | **E-signature ข้ามการยืนยันตัวตนได้ + verify คืน valid เสมอ** | SARABAN-003 | **P0** | ลายเซ็นไม่มีผลผูกพัน ทำให้ทั้งโมดูลสารบรรณใช้ไม่ได้ตามระเบียบ |
| 7 | **Secret รั่วจากเว็บ + endpoint เขียน DB โดยไม่ต้อง login** | SEC-STD-003 | **P0** | credential รั่วและแก้ข้อมูลได้โดยไม่ยืนยันตัวตน |
| 8 | **Role hard-code 31 จุด + role สองระบบซ้อนกัน** | CORE-AUTHZ-003, LEAVE-006 | **P1** | บล็อกงาน RBAC/ABAC ทั้งหมด และทำให้สิทธิ์ผิดตัวได้ตอนนี้ |
| 9 | **ไม่มี queue worker / scheduler** → แจ้งเตือน, carry-forward, governance ไม่ทำงาน | NOTIFY-004, DEPLOY-003 | **P1** | ผู้ใช้เชื่อว่าระบบแจ้งเตือนแล้ว ทั้งที่ไม่ได้ส่ง |
| 10 | **ไม่มี org hierarchy + ไม่มี test เขียวที่ baseline** | CORE-ORG-001, TEST-002 | **P1** | ORG บล็อก ABAC; test แดงทำให้ไม่มีตาข่ายนิรภัยสำหรับ refactor ทุกข้อข้างบน |

---

## หมายเหตุสำคัญ — ความขัดแย้งของ Target Architecture

`docs/02-TECH-STACK.md` และ `README.md` ระบุ Target Stack เป็น **Next.js + TypeScript + PostgreSQL + Redis + MinIO** แต่ระบบเดิมคือ **Laravel + Vue 3 + MySQL + local disk**

การเปลี่ยน stack ตามนั้นคือ **rewrite ทั้งระบบ** ซึ่งขัดกับกฎสูงสุดข้อ 1-2 ของ `LEGACY-UPGRADE-PROMPT.md` โดยตรง

Gap Analysis ฉบับนี้จึงตีความ Target Architecture เป็น **หลักการเชิงสถาปัตยกรรม** (Modular Monolith, policy-driven, workflow-driven, permission-driven, event-driven, audit everything, secure by default) ไม่ใช่การบังคับเทคโนโลยี — Laravel รองรับหลักการเหล่านี้ได้ครบ

> **ต้องได้คำยืนยันจากผู้ใช้** ว่าตีความถูกต้อง ก่อนเริ่ม Phase ใด ๆ (ดู Open Question #4 ใน Assessment)

---

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-12 | เสร็จรอบที่ 1 |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
