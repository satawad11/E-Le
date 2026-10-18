# R-02 REPORT — Schema baseline

วันที่: 2026-10-19 · Branch: `rebuild/R-02` (แตกจาก `rebuild/2569` @ `6246691`) · ฐานข้อมูลที่ใช้: ทดสอบเท่านั้น
(sqlite `:memory:` ของ phpunit, sqlite ไฟล์ชั่วคราว, MySQL `eleave_r02_legacy` / `eleave_r02_new` — ไม่แตะ `eleave` และ `eleave_trial`)

## 1. สิ่งที่ทำ (TASKS R02-1 … R02-13)

| Task | ผล |
|---|---|
| R02-1 | migration เดิม 141 ไฟล์ → `backend/database/migrations-legacy/` (+ README) · `database/migrations/` เหลือ baseline 14 ไฟล์ |
| R02-2 | `2026_10_18_200000_r02_2_create_framework_tables` — users (รูป CORE-03), password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, personal_access_tokens, notifications |
| R02-3 | `…200100_r02_3_create_core_organization_tables` — org_unit_types, org_units (`path` ascii index, `depth`), position_groups, positions (`kind`, `org_unit_type_id` unique) |
| R02-4 | `…200200_r02_4_create_core_personnel_tables` — 11 ตารางตาม CORE-02 (ฟิลด์ sensitive เป็น text สำหรับค่าเข้ารหัส, `national_id_hash`, ไม่มีเชื้อชาติ/ศาสนา) |
| R02-5 | `…200300_r02_5_create_core_identity_tables` — `users.personnel_id` (unique FK), mfa_credentials, login_events |
| R02-6 | `…200400_r02_6_create_core_authorization_tables` — 12 ตารางตาม CORE-04 (`role_grants.order_number`/`expires_at` NOT NULL, `retired_at` บน modules/features/permissions/templates) |
| R02-7 | `…200500` – `…201300` (`r02_7a` … `r02_7i`) — ตารางโมดูลเดิม 69 ตารางในรูปสุดท้าย แปลงจาก information_schema ของฐานทดสอบที่รัน migration เดิมครบ (ADR-R-011) |
| R02-8 | ถอด `spatie/laravel-permission` (composer.json/lock, `config/permission.php`) · ลบ seeders ตาม PLAN §4.3 + `MenuSeeder`, `LeaveWorkflowSeeder` |
| R02-9 | seeders ใหม่: `OrgUnitTypeSeeder`, `OrganizationBaselineSeeder`, `PersonnelTypeSeeder`, `LeaveRegulationSeeder`, `NotificationCatalogSeeder` (+ fixture `database/seeders/data/*.json`) · คงเดิม: ปีงบประมาณ, วันหยุด 2569–2570, ประเภทการลา, แบบฟอร์ม, ตารางเวลา, สารบรรณ, document engine |
| R02-10 | model ใหม่ 25 ตัว + เขียนใหม่ 6 ตัว (`App\Models\*`, `App\Models\Authz\*`) cast `encrypted`, trait `AppendOnly` · ลบ model ของตารางที่ตัด 9 ตัว · ปรับ model โมดูลเดิม 7 ตัวตามคอลัมน์ที่เปลี่ยน |
| R02-11 | ถอด allow-all ก่อน Gate · ลบทุกจุดเรียก spatie · `DenyUntilPermissionEngine` (403 ทุก route ที่ยืนยันตัวตน ยกเว้น 3 route) · alias `permission:` → `RequirePermission` (ปฏิเสธเสมอ) |
| R02-12 | เทสต์ใหม่ 5 ไฟล์ `tests/Feature/Rebuild/*` (รายละเอียดข้อ 4) |
| R02-13 | รายงานนี้ |

## 2. ตาราง

### 2.1 ตารางใหม่ของ core (40)
- **Framework (R02-2):** `users`*, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`, `notifications`
- **CORE-01:** `org_unit_types`, `org_units`*, `position_groups`, `positions`*
- **CORE-02:** `personnel_types`*, `personnel`*, `personnel_profiles`, `personnel_educations`, `personnel_trainings`, `personnel_licenses`, `personnel_documents`, `assignments`, `field_classifications`, `personnel_change_requests`, `sensitive_access_logs`
- **CORE-03:** `mfa_credentials`, `login_events` (+ `users.personnel_id`)
- **CORE-04:** `modules`, `module_features`, `permissions`*, `permission_templates`, `template_permissions`, `position_templates`, `role_grants`, `permission_overrides`, `permission_change_requests`, `access_reviews`*, `access_review_items`, `permission_audit_logs`

\* ชื่อซ้ำกับตารางเดิมแต่ **โครงสร้างใหม่ทั้งหมด** (เช่น `users` ไม่มี name/department_id/is_active, `personnel` ไม่มี user_id, `permissions` ไม่ใช่ของ spatie)

### 2.2 ตารางที่ตัด (18)
`departments`, `department_org_unit_map`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`,
`permission_aliases`, `permission_denies`, `permission_scopes`, `user_role_grants`, `org_unit_members`, `org_unit_managers`,
`personnel_assignments`, `user_profiles`, `approval_steps`, `workflow_shadow_comparisons`, `menus`, `menu_permissions`

### 2.3 ตารางโมดูลเดิมที่เก็บโครงสร้าง (69) และสิ่งที่เปลี่ยน
ปฏิทิน/เวลา 6 · ตั้งค่า/audit 5 · workflow engine 8 · document engine 9 · ลา 10 · ลงเวลา 6 · OT 3 · สารบรรณ 14 · แจ้งเตือน 6 (รายชื่อเต็มใน `SchemaBaselineTest::MODULE_TABLES`)

| ตาราง | การเปลี่ยน |
|---|---|
| `saraban_documents`, `saraban_document_sequences`, `work_calendar_exceptions` | `department_id` → `org_unit_id` (FK `org_units`, RESTRICT) · unique key ตามชื่อใหม่ |
| `leave_approvals` | ตัด `required_role_id` |
| `workflow_steps`, `workflow_tasks` | ตัด `assignee_role_id` |
| `workflow_delegations` | ตัด `scope_role_id` |
| `leaves` | CHECK `leaves_status_check` ใส่เฉพาะ MySQL (เหมือนชุดเดิม) |

ตรวจด้วยการเทียบ `mysqldump --no-data` ของ `eleave_r02_legacy` กับ `eleave_r02_new`: จาก 85 ตารางที่ชื่อตรงกัน ต่างกัน 18 ตาราง
ทั้งหมดเป็นตาราง core ที่ออกแบบใหม่ (ข้อ 2.1) หรือการเปลี่ยนตามตารางด้านบน · ส่วนต่างที่เหลือคือ FK แบบ `NO ACTION` เขียนเป็น `RESTRICT` (InnoDB ทำงานเหมือนกัน)

## 3. โค้ดที่เปลี่ยน

| ประเภท | รายการ |
|---|---|
| ลบ (51 ไฟล์) | model: ApprovalStep, Department, Menu, OrgUnitManager, OrgUnitMember, PersonnelAssignment, Role, UserProfile, UserRoleGrant · observer 3 · service: PermissionEvaluator, PermissionScopeService, RoleGrantService, AccessReviewService, MenuService, PersonnelSyncService, LeaveWorkflowShadow, LeaveWorkflowProvisioner · command 10 (role/scope/access-review/shadow/school-structure/position-match) · controller: Admin\{User,Department,Permission,OrgStructure,Position,Workflow}, Api\{Role,Menu} · config: permission, authorization, school_structure · seeders 10 |
| เพิ่ม | middleware `DenyUntilPermissionEngine`, `RequirePermission` · `App\Support\Leave\SignerCapacity` · model core ใหม่ 25 (+ เขียนใหม่ User, Personnel, PersonnelType, OrgUnit, Position, PositionGroup) · factory `PersonnelFactory` · seeders 5 + fixture 4 |
| แก้ | `AppServiceProvider` (ไม่มี allow-all ก่อน Gate) · `bootstrap/app.php` · `User` · `AuthController` (login ด้วยอีเมล/เลขประจำตัวผ่าน hash, เฉพาะบัญชี active, ไม่คืน role) · `OrgScopeResolver` (fail-closed) · `TimeService`/`WorkingCalendar`/`WorkCalendarController` (org_unit_id) · ตัด shadow ใน `LeaveService`/`GovernmentApprovalController` · ตัด Super Admin bypass ใน `ApproverResolverService`/`LeaveApprovalSigner` · ผู้รับแจ้งเตือนตาม spatie → ว่าง (6 จุด) · `config/workflow.php` (`approver_role_fallback` = false ถาวร) |
| API ที่ถอด | `/api/admin/users*`, `/api/admin/roles*`, `/api/admin/permissions*`, `/api/admin/departments*`, `/api/admin/org-units*`, `/api/admin/org-unit-*`, `/api/admin/workflow-steps*`, `/api/admin/menus*`, `/api/user/menus`, `/api/users/list`, `/api/positions`, `/api/admin/position*` |

## 4. ผลการตรวจ

| การตรวจ | ผล |
|---|---|
| `php artisan test` (phpunit, sqlite) | ✅ **92 tests, 787 assertions, 0 failures** |
| `vendor/bin/pint --test` | ✅ 595 files PASS |
| `migrate:fresh --seed` — MySQL 8 (`eleave_r02_new`) | ✅ 14 migrations + 16 seeders |
| `migrate:fresh --seed` — sqlite | ✅ |
| seed ซ้ำ (idempotent) | ✅ `BaselineSeederTest::test_seeders_are_idempotent` |
| `route:list` / `artisan list` | ✅ 210 routes · 139 commands โหลดได้ |
| โหลดทุกคลาสใน `app/` และ `database/` | ✅ 311 คลาส ไม่มี parent/trait ที่หายไป |
| grep `Spatie\Permission`, `hasRole(`, `Gate::before`, `HasRoles`, `User::permission(` ในโค้ดที่โหลด | ✅ ไม่พบ (`ForbiddenPatternsTest`) — พบเฉพาะใน `migrations-legacy/` ที่ไม่ถูกโหลด |
| `npm run build` | ไม่ได้รัน — R-02 ไม่แก้ frontend |

เทสต์ใหม่:
- `SchemaBaselineTest` (29) — ตารางที่ตัดไม่มี · ตาราง core/โมดูลครบ · คอลัมน์ที่ตัดไม่มี · FK ไป org_units · โหลดเฉพาะ baseline
- `BaselineSeederTest` (7) — ประเภทหน่วย L1–L4 · root + 4 ฝ่าย + path · 8 ประเภทบุคลากร · นโยบายการลาครบทุกประเภท · ไม่มีบัญชี/permission ตั้งต้น · idempotent
- `DenyByDefaultTest` (6) — **ทุก route ที่ยืนยันตัวตน (ยกเว้น 3) ตอบ 403** · ไม่ยืนยันตัวตน = 401 · alias `permission:` ปฏิเสธ · `can()` เป็น false ทุก ability แม้บัญชี break-glass
- `ForbiddenPatternsTest` (4) — grep · แพ็กเกจ spatie หายจาก composer/lock · seeders และ model ที่แผนสั่งลบไม่มีแล้ว
- `CoreModelsTest` (7) — เข้ารหัส sensitive + ค้นด้วย hash · MFA secret เข้ารหัส · append-only แก้/ลบไม่ได้ · assignment ตามวันที่มีผล · login เฉพาะ active · ตัด token เมื่อพ้นสถานะ active

เทสต์เดิมที่ยังผ่านและอยู่ในชุดปกติ 9 ไฟล์ (ProductionEnvironmentGuard, LeaveControllerSplit, SecurityHeaders, RequestContext, RouteAuthorizationAudit, BaselineReferenceDataSeeder, DocumentTemplateVersion, Example ×2)
**เทสต์เดิม 85 ไฟล์พักไว้ที่ `backend/tests/Legacy/`** (ไม่ลบ ไม่แก้ assertion) — ADR-R-010 · README ระบุเฟสที่เขียนใหม่ทีละไฟล์ · รอคำตอบ Q7

## 5. Go/No-Go R-02

| เกณฑ์ (runbook) | ผล |
|---|---|
| `migrate:fresh --seed` ผ่าน | ✅ MySQL + sqlite |
| grep ไม่พบ spatie / allow-all ก่อน Gate | ✅ |
| เทสต์ schema ผ่าน | ✅ |
| รายงานบอกชัดว่าอะไรยังพัง | ✅ ข้อ 6 |

**ผล: Go** โดยมีเงื่อนไขให้เจ้าของระบบยืนยัน Q7 (การพักเทสต์เดิม) ก่อน merge

## 6. ส่วนของระบบเดิมที่ยังใช้งานไม่ได้

ทุก endpoint ที่ต้องยืนยันตัวตนตอบ 403 จนถึง R-04 จึงไม่มีผลกระทบที่ผู้ใช้เข้าถึงได้ แต่โค้ดภายในยังพังตามรายการนี้

| โมดูล | ที่พัง / ปิดอยู่ | สาเหตุ | เฟสที่แก้ |
|---|---|---|---|
| ทุกโมดูล | ทุก route ที่ต้องยืนยันตัวตน (ยกเว้น logout, refresh-token, user) | deny by default | R-04 (`->permission()` + PermissionEngine) |
| บัญชี/สิทธิ์/เมนู | จัดการผู้ใช้ บทบาท สิทธิ์รายบุคคล เมนู ทะเบียนหัวหน้าหน่วย ทบทวนสิทธิ์ (API ถอดแล้ว) | ตารางเดิมถูกตัด | R-04 (เมนู), R-05 (บัญชี/grant/review), R-07 (หน้าจอ) |
| องค์กร/บุคลากร | โครงสร้างหน่วยงาน ตำแหน่ง ข้อมูลส่วนตัว (`ProfileController`, `PersonalDataController`, `EncryptSensitiveDataCommand`, `SecureFileAccessService` ยังอ่าน `user_profiles`) | ย้ายไป `personnel*` | R-03, R-06 |
| ลา | ยื่น/อนุมัติ/ยกเลิกใบลา (`LeaveService`, `LeaveApprovalFlow`, `LeaveApproverBlocks`, `GovernmentApprovalController`, `LeaveCancellationController`, `LeaveRequestController` ยังเรียก `ApprovalStep`) · ผู้อนุมัติตามบทบาทคืนว่าง · ใบลา/บัตรลา PDF อ่าน `user.department/profile` | `approval_steps`, spatie, `user_profiles` ถูกตัด | R-08 leave (resolver `head_of_level`) |
| ลา — รายงาน | `GovernmentLeaveReportController` (แยกตาม department) · `LeaveCardController`, `PromotionScreeningController`, รายงานกลาง (`OrgScopeResolver` คืนขอบเขตว่าง) | departments ถูกตัด, scope fail-closed | R-04 (scopeQuery) + R-08 |
| ลงเวลา | รายการ/รายงานทั้งองค์กร (`AttendanceScopeService` → ขอบเขตว่าง) · แจ้งผู้ตรวจเมื่อสาย/ออกก่อน/ขอแก้ไข → ไม่มีผู้รับ | spatie `User::permission()` ถูกตัด | R-08 attendance |
| OT | ผู้อนุมัติ (`OvertimeAssigneeResolver`, `OvertimeOrderWorkflowProvisioner` อ้าง `OrgUnitManager`/`Role`) · แจ้งผู้ตรวจ → ไม่มีผู้รับ | ตารางเดิมถูกตัด | R-08 overtime |
| สารบรรณ | `DocumentController`/`ReportController`/`Document` model เดิมอ้าง department · แจ้งเจ้าหน้าที่ทะเบียน → ไม่มีผู้รับ | `department_id` → `org_unit_id` | R-08 saraban |
| แจ้งเตือน | ส่ง LINE/Telegram/อีเมลรายบุคคล — ไม่มี `telegram_chat_id`, `line_user_id`, `notification_channel`, `phone` ใน `users` แล้ว | CORE-03 | R-08 notification (Q9) |
| Workflow engine | ผู้รับงานแบบ role/permission คืนว่าง · ไม่มี workflow definition ของการลา (เดิมสร้างจาก approval_steps) | ADR-R-006 | R-04 resolver + R-08 |
| Document engine | พรีวิวแม่แบบใช้ข้อมูลตัวอย่างที่ไม่มีสังกัด/ตำแหน่ง · บล็อกผู้ลงนามไม่อ่านชื่อบทบาทแล้ว (ใช้ `capacity`) | user_profiles/departments ถูกตัด | R-08 |
| Frontend | หน้าจอเดิมเรียก endpoint ที่ถอด/ตอบ 403 (เมนู, ผู้ใช้, ฯลฯ) — R-02 ไม่แก้ frontend | — | R-06, R-07 |
| Dev | ฐานข้อมูลใหม่ไม่มีบัญชีใดเลย จึงยังเข้าสู่ระบบไม่ได้ | ตั้งใจ (ไม่มีรหัสผ่านตั้งต้น) | R-03 นำเข้าบุคลากร / R-05 JML |

## 7. ข้อจำกัดและสิ่งที่ต้องทำต่อ

1. **R-00 ยังไม่ครบ:** backup DB + storage และ tag `legacy-final` ทำแล้ว (2026-10-18) แต่ยังไม่ได้ทดสอบ restore บนเครื่องแยก, export ใบลา/ลงเวลาเป็นไฟล์ถาวร และผู้บริหารลงนาม (R00-1, R00-2, R00-4) — ต้องครบก่อน R-09 ตาม ADR-R-002
2. **รอตัดสินใจ:** Q7 (เทสต์เดิม), Q8 (map ระเบียบการลาไป 8 ประเภท + ค่า has_license/has_contract_end), Q9 (ที่เก็บช่องทางแจ้งเตือน + `grant_attributes`) — เพิ่มใน `TASKS.md`
3. ชื่อฝ่ายใช้ตามระเบียบ พ.ศ. 2569 ใน config เดิม (`ฝ่ายกิจการนักเรียน นักศึกษา`) · วันที่มีผลของ root/ฝ่ายตั้งเป็นวันประกาศระเบียบ 2026-03-24 — งานบุคคลปรับได้ใน R-03
4. container ที่รันอยู่ยังมี spatie ใน vendor (image build จาก main) — ระบบใหม่ต้อง build image ใหม่จาก branch นี้ · `composer audit` รายงาน advisory 5 รายการซึ่งมีอยู่ก่อน R-02
5. `LeaveService::workingDaysBetween` ยังส่งพารามิเตอร์ชื่อ `$departmentId` ซึ่งตอนนี้หมายถึง org unit id — ปรับชื่อใน R-08
6. ฐานข้อมูลทดสอบ `eleave_r02_legacy`, `eleave_r02_new` ยังอยู่บน MySQL ของเครื่องพัฒนา ลบได้เมื่อไม่ต้องการเทียบ schema แล้ว

## 8. วิธีตรวจซ้ำ
```bash
cd backend
php artisan test
vendor/bin/pint --test
DB_CONNECTION=sqlite DB_DATABASE=/tmp/r02.sqlite php artisan migrate:fresh --seed --force
```

**หยุดรอคำสั่ง**
