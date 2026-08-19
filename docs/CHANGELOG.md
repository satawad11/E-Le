# CHANGELOG

All notable changes to the E-la project will be documented in this file.

## [Unreleased]

### [DOC-ENGINE-05, 07] - 2026-08-20
#### Added
- หน้าออกแบบรายงาน (`ReportDesignerView.vue`) — เลือกแหล่งข้อมูล เลือก/เรียงคอลัมน์ ตั้งชื่อหัวคอลัมน์ กำหนดผลรวมรายคอลัมน์ (COUNT/SUM/AVG/MIN/MAX) จัดกลุ่ม เพิ่มช่องลงนาม และทดลองประมวลผลได้ในหน้าเดียว
- คำสั่ง `document:scaffold-fields` วางชุดฟิลด์ตั้งต้น 23–26 ช่องให้แบบฟอร์มราชการที่นำเข้า (ตำแหน่งโดยประมาณ) เพื่อให้ผู้ดูแลลากจัดตำแหน่งต่อแทนการเพิ่มทีละช่อง

#### Fixed
- เมนู "ศูนย์จัดการเอกสารและรายงาน" ไม่ปรากฏในแถบเมนู — sidebar ของระบบเขียนไว้ใน `App.vue` ไม่ได้อ่านจากตาราง `menus` การ seed เมนูจึงไม่มีผล เพิ่มรายการเมนูทั้งเดสก์ท็อปและมือถือ พร้อม getter สิทธิ์ `canManageDocumentCenter`

### [DOC-ENGINE-01..04, 06] - 2026-08-20
#### Added
- **Universal Document & Report Engine** — เครื่องมือออกเอกสารและรายงานกลางของระบบ ใช้ได้ทุกโมดูล ไม่ผูกกับ E-Leave
- Assessment ระบบเดิมก่อนลงมือ: `docs/checkpoints/DOC-ENGINE-00-ASSESSMENT.md` (17 หัวข้อ, ความเสี่ยง breaking change 12 ข้อ, migration/rollback plan)
- Migration แบบ additive ล้วน `2026_08_20_000100..000500`: `document_templates.document_type/render_mode`, `document_template_versions.status/pdf_file_path/published_*`, ตารางใหม่ `document_field_definitions`, `document_data_sources`, `document_binding_rules`, `document_field_mappings`, `document_generated_files`, `document_print_calibrations`, `document_report_definitions` และ snapshot บน `leaves`
- Renderer วางฟิลด์บนแบบฟอร์ม PDF ต้นฉบับด้วย FPDI พร้อมฟอนต์ไทยฝังในระบบ (`php artisan document:install-fonts`) รองรับ TEXT/MULTILINE/DATE/NUMBER/CHECKBOX/SIGNATURE/IMAGE
- Data Provider Layer (`LEAVE_REQUEST_DETAIL`, `LEAVE_MONTHLY_SUMMARY`) + Field Dictionary 84 ตัวแปร — เทมเพลตไม่แตะฐานข้อมูลเอง และไม่มีจุดใดรับ SQL จากหน้าจอ
- เอกสารฉบับสมบูรณ์เก็บเป็นไฟล์พร้อม sha256 และ revision — แก้ไม่ได้ ลบไม่ได้ ออกซ้ำเป็นฉบับใหม่ ของเดิมเป็น SUPERSEDED
- ค่าชดเชยเครื่องพิมพ์ (global → แม่แบบ → ผู้ใช้) แยกจากตำแหน่งฟิลด์
- ศูนย์จัดการเอกสารและรายงาน (frontend): ภาพรวม แม่แบบ การผูกแบบฟอร์ม Field Dictionary แหล่งข้อมูล รายงาน เอกสารที่สร้างแล้ว ตั้งค่าการพิมพ์ และ **หน้าออกแบบวางฟิลด์บน PDF** (ลาก/ปรับขนาด/หลายหน้า/พรีวิว/เผยแพร่)
- Report Engine: นิยามรายงานเก็บในฐานข้อมูล รองรับ grouping + subtotal + grand total + aggregate (COUNT/SUM/AVG/MIN/MAX) ส่งออก PDF/Excel/CSV พร้อมรายงานตัวอย่าง "สถิติการลาประจำเดือน"
- คำสั่ง `document:import-forms` นำแบบฟอร์มราชการ 5 ฉบับ (ลาพักผ่อน / ลาป่วย-ลากิจ-ลาคลอด / ยกเลิกวันลา / อุปสมบท / ช่วยเหลือภริยาที่คลอดบุตร) เข้าเป็นแม่แบบฉบับร่าง
- สิทธิ์ชุดใหม่ `document.template.*`, `document.report.*`, `document.generate`, `document.final.*`, `document.audit.view` และเมนู "ศูนย์จัดการเอกสารและรายงาน"
- Test suites `DocumentEngineTest` (23 tests) และ `DocumentReportEngineTest` (6 tests)

#### Changed
- `/api/leaves/{leave}/pdf` ลองใช้ Document Engine ก่อนเมื่อธงเปิดและประเภทการลานั้นถูกเปิดใช้ — นอกนั้นใช้เส้นทางเดิมทั้งหมด
- Feature flag `DOCUMENT_ENGINE_ENABLED` + `DOCUMENT_ENGINE_LEAVE_TYPES` เปิดทีละประเภทการลาได้ ปิดแล้วกลับเส้นทางเดิมทันทีโดยไม่ต้อง deploy

### [LEAVE-FORM-01] - 2026-08-19
#### Fixed
- พิมพ์ใบลาไม่ได้ (HTTP 429): `throttle:x,y` แบบ inline ของ Laravel ตั้งกุญแจเป็น sha1(user id) เท่านั้น ทุก route ที่ประกาศแบบนี้จึงนับรวมถังเดียวกัน และถูกนับเพิ่มโดย throttle ของกลุ่มใหญ่ทุก request — เปิดหน้าเว็บไม่กี่หน้าก็ทำให้ปุ่มพิมพ์ตอบ 429 ทั้งที่ยังไม่เคยสั่งพิมพ์ (SEC-019)
- ปุ่ม "ขอยกเลิก" ใบลาที่อนุมัติแล้วหายไปเมื่อคำขอยกเลิกรอบก่อนถูกปฏิเสธ หรือเมื่อ `cancellation_status` เป็น null (ข้อมูลเก่า)
- ข้อความผิดพลาดของการดาวน์โหลดเอกสารถูกกลบ เพราะ error body เป็น Blob จึงแสดงเพียง "ไม่สามารถดาวน์โหลด PDF ได้"
- แบบฟอร์มที่ใช้พิมพ์ถูกเลือกจากการเทียบชื่อไทยของประเภทการลาแบบตายตัวในโค้ด
- ชื่อสถานศึกษา/ผู้อำนวยการถูกเขียนตายในไฟล์ blade และตารางสถิติการลาในแบบฟอร์มถูกส่งเป็นค่าว่างเสมอ
- ยื่นคำขอยกเลิกซ้ำทับคำขอที่ยังรออนุมัติได้ (เหตุผลเดิมถูกเขียนทับ)

#### Added
- Named rate limiters แยกถังต่อกลุ่มการใช้งานใน `AppServiceProvider` (`api`, `public-api`, `verify`, `login`, `password`, `token-refresh`, `personal-data`, `documents`, `exports`, `files`)
- `App\Services\LeaveDocumentFields` — ชุดตัวแปรกลางของแบบฟอร์มใบลา พร้อม `catalog()` (คำอธิบายไทยสำหรับหน้าผู้ดูแล) และ `sample()` (ข้อมูลตัวอย่างสำหรับพรีวิว)
- Migration `2026_08_19_160000_add_document_binding_to_leave_types.php` เพิ่ม `leave_types.document_template_code` และ `leave_types.extra_fields` พร้อม backfill ตามตรรกะเดิม
- ช่องกรอกเฉพาะแบบฟอร์ม: ผู้ดูแลกำหนดช่องเองได้ที่หน้าประเภทการลา ผู้ยื่นกรอกในหน้ายื่นใบลา ค่าเก็บใน `leaves.meta_data.extra_fields` และเติมลงแบบฟอร์มผ่าน `$extra[...]`
- `GET /api/admin/document-templates/fields` (รายการตัวแปร) และ `POST /api/admin/document-templates/preview` (พรีวิว PDF ด้วยข้อมูลตัวอย่าง)
- แบบฟอร์มตั้งต้นสำหรับฮัจญ์ / ติดตามคู่สมรส / ศึกษาต่อ เข้าสู่ระบบแม่แบบที่แก้ไขได้
- `Database\Seeders\LeaveFormExtraFieldSeeder` ตั้งช่องกรอกเริ่มต้นของใบลาอุปสมบทและใบลาช่วยเหลือภริยาที่คลอดบุตร
- Test suites `RateLimitBucketIsolationTest`, `LeaveCancellationRequestTest`, `LeaveFormBindingTest`

#### Changed
- เขียนแบบฟอร์มใหม่ให้ตรงแบบราชการ 5 ฉบับ (ลาป่วย/ลาคลอด/ลากิจส่วนตัว, ลาพักผ่อน, ขอยกเลิกวันลา, ลาอุปสมบท, ลาไปช่วยเหลือภริยาที่คลอดบุตร) โดยดึงค่าจากชุดตัวแปรกลางทั้งหมด
- `DefaultDocumentTemplateService::sync()` รองรับ "คืนค่าแบบฟอร์มตั้งต้น" (เขียนทับแบบเก็บเวอร์ชันเดิมไว้)
- หน้าแม่แบบเอกสารแสดงรายการตัวแปรจริงจาก API แทนรายการที่เขียนตายไว้ และพรีวิวผ่าน dompdf ตัวเดียวกับตอนพิมพ์

### [APPROVAL-02] - 2026-08-19
#### Fixed
- ผู้อนุมัติตัวจริงถูกปฏิเสธสิทธิ์ในขั้นตอนของตนเอง (403): snapshot ใน `leave_approvals` ไม่เคยเก็บ `approver_type` ทำให้ทุกขั้นตอนถูกลดรูปเป็นแบบ `ROLE` ตอนยื่นใบลา
- ช่องโหว่ fail-open ใน `ApproverResolverService`: ขั้นตอนที่ไม่มี role/permission กำกับ (เช่นแบบ `SPECIFIC_USER`) เคยคืนผู้ใช้ที่ active ทั้งหมดเป็นผู้มีสิทธิ์อนุมัติ ปัจจุบันคืนรายการว่าง (fail-closed)
- `Admin\WorkflowController` เคยเติม `role_id` (Super Admin/Director/HR Head) ให้ขั้นตอนอัตโนมัติแบบเงียบ ๆ ทำให้สิทธิ์อนุมัติรั่วและหน้าจอไม่ตรงกับที่ระบบใช้จริง
- รายการ "รออนุมัติ" กับการกดอนุมัติใช้คนละตรรกะ ทำให้หัวหน้าแผนกต้นสังกัดไม่เห็นใบลาที่ต้องอนุมัติ
- สายอนุมัติคำขอยกเลิกใบลาเทียบ `role_id` ตรง ๆ ไม่รองรับ resolver/การมอบอำนาจ และไม่ตรวจสถานะคำขอยกเลิกหรือห้ามอนุมัติของตนเอง

#### Added
- Migration `2026_08_19_090000_add_resolver_snapshot_to_leave_approvals.php` เพิ่ม `approver_type`, `specific_user_id`, `permission_name` ใน `leave_approvals` พร้อม backfill ใบลาที่ยังรออนุมัติ
- Automated test suite `Tests\Feature\LeaveApprovalChainSnapshotTest` (end-to-end ตั้งค่า → ยื่น → เห็นในรายการ → อนุมัติ)

#### Changed
- `ApprovalController` เหลือเฉพาะรายการรออนุมัติ และคัดด้วย `ApproverResolverService` ตัวเดียวกับตอนอนุมัติจริง
- `LeaveService::createLeaveRequest` และ `GovernmentApprovalController::step()` คัดลอกวิธีหาตัวผู้อนุมัติลง snapshot ครบทุกฟิลด์
- `LeaveCancellationController` ใช้ snapshot + resolver ชุดเดียวกับการอนุมัติใบลา

#### Removed
- `ApprovalController::approve()` / `reject()` ที่ไม่มี route ชี้ถึงตั้งแต่ย้ายไป `routes/leave_government.php` (รวมตรรกะ auto-approve ของ Super Admin ที่ไม่ถูกเรียกใช้แล้ว)

### [APPROVAL-05] - 2026-08-19
#### Added
- ตั้งค่า "สายอนุมัติคำขอยกเลิกใบลา" แยกจากสายของใบลาได้ — migration `2026_08_19_170000_add_chain_to_approval_steps.php` เพิ่ม `approval_steps.chain` (`leave` / `cancellation`)
- `GET|POST /api/admin/workflow-steps` และ `/preview` รับพารามิเตอร์ `chain` (ค่าเริ่มต้น `leave`) พร้อมธง `inherits_leave_chain`
- แท็บสลับสายอนุมัติในหน้าจัดการลำดับการอนุมัติ พร้อมปุ่ม "กลับไปใช้สายเดียวกับใบลา"

#### Changed
- **สายยกเลิกที่ยังไม่ได้ตั้งค่า = ใช้สายเดียวกับใบลา** (`ApprovalStep::stepsFor()`) ระบบที่ติดตั้งอยู่แล้วจึงทำงานเหมือนเดิมทุกประการ
- บันทึกสายใดสายหนึ่งไม่กระทบอีกสาย (ลบ/สร้างใหม่และ fingerprint แยกตามสาย) และ workflow engine (shadow) สะท้อนเฉพาะสายของใบลา
- สายของใบลายังต้องมีอย่างน้อย 1 ขั้นเสมอ ส่วนสายยกเลิกบันทึกเป็นค่าว่างได้ = ใช้สายของใบลา

### [APPROVAL-04] - 2026-08-19
#### Added
- สายอนุมัติของ "คำขอยกเลิกใบลา" แบบเดียวกับการขอลา — migration `2026_08_19_140000_add_cancellation_approval_chain.php` เพิ่ม `leave_approvals.stage` (`request` / `cancellation`) และ `leaves.cancellation_approval_level` พร้อม backfill คำขอยกเลิกที่ค้างอยู่
- บันทึกรายขั้นของคำขอยกเลิก: ผู้อนุมัติ ตำแหน่ง ลายเซ็น ความเห็น เวลา และผู้มอบอำนาจ เช่นเดียวกับการอนุมัติใบลา (`LeaveAuditLog` action `cancellation_approved` / `cancellation_rejected`)
- แสดงลำดับการอนุมัติยกเลิกในหน้า "อนุมัติยกเลิกใบลา" และเปิดให้ลงลายเซ็นตอนอนุมัติคำขอยกเลิก
- Automated test suite `Tests\Feature\LeaveCancellationChainTest` (9 tests, 86 assertions)

#### Fixed
- อนุมัติคำขอยกเลิกใบลาที่อนุมัติแล้ว วันลาไม่ถูกคืนเข้าสิทธิ์ — เดิมเปลี่ยนสถานะใบลาเป็น `Cancelled` ก่อนเรียก `restoreBalance()` ซึ่งคืนโควตาเฉพาะใบที่ยังเป็น `Approved`
- คำขอยกเลิกเคยเขียนทับ `leaves.approval_level` ของใบลาเดิม ทำให้ประวัติว่าใบลาผ่านอนุมัติถึงขั้นใดหายไป
- คำขอยกเลิกที่ค้างอยู่เคยเปลี่ยนตามเมื่อผู้ดูแลแก้สายอนุมัติ ปัจจุบันยึด snapshot ณ วันที่ขอยกเลิก
- รายการรออนุมัติเคยตัดสินสิทธิ์ของคำขอยกเลิกด้วยสายอนุมัติของการขอลา (ซึ่งอนุมัติจบไปแล้ว) จึงตอบผิดคน

### [APPROVAL-03] - 2026-08-19
#### Added
- `POST /api/admin/workflow-steps/preview` (สิทธิ์ `manage_workflow_steps`) ทดลองว่าใครคือผู้อนุมัติจริงของแต่ละขั้นสำหรับผู้ยื่นที่เลือก ใช้ `ApproverResolverService` ตัวเดียวกับตอนอนุมัติจริง และรองรับการทดลองสายอนุมัติที่ยังไม่บันทึก
- ปุ่ม "ดูตัวอย่างผู้อนุมัติจริง" พร้อมหน้าต่างแสดงรายชื่อผู้อนุมัติรายขั้นใน `WorkflowManagementView.vue` แจ้งเตือนเมื่อขั้นตอนใดไม่มีผู้อนุมัติ หรือได้ผู้ยื่นเป็นผู้อนุมัติของตัวเอง

#### Fixed
- หน้า "อนุมัติใบลา" ค้างที่ skeleton เมื่อมีใบลารออนุมัติ — `ApprovalsView.vue` เรียก `ThaiDateFormatter` โดยไม่ได้ import ทำให้ render ล้มทั้งหน้า (`Cannot read properties of undefined (reading 'toThaiSlash')`)

### [APPROVAL-01] - 2026-08-16
#### Added
- Migration `2026_08_16_020000_add_resolver_fields_to_approval_steps.php` adding `approver_type`, `specific_user_id`, `permission_name` to `approval_steps`.
- Service `App\Services\Workflow\ApproverResolverService` supporting dynamic approver resolution across 7 resolver types (`DEPARTMENT_HEAD`, `REQUESTER_SUPERVISOR`, `DIVISION_DIRECTOR`, `DIRECTOR`, `HR_OFFICER`, `ROLE`, `SPECIFIC_USER`).
- Automated test suite `Tests\Feature\ApprovalResolverAndWorkflowHardeningTest`.
- Checkpoint report `docs/checkpoints/APPROVAL-01-REPORT.md`.

#### Changed
- `App\Http\Controllers\Admin\WorkflowController` updated with resolver validation, specific user selection, and hardened fingerprint hashing.
- `App\Http\Controllers\ApprovalController` and `App\Http\Controllers\GovernmentApprovalController` refactored to verify authorization through `ApproverResolverService`.
- `App\Services\Workflow\LeaveWorkflowProvisioner` step mapping updated to reflect dynamic assignee types.
- `WorkflowManagementView.vue` enhanced with resolver type selection, specific user picker, and mobile responsiveness.

### [USER-05] - 2026-08-16
#### Changed
- Removed legacy hardcoded role names in `MyLeavesView.vue`, `DashboardView.vue`, and `UserProfileView.vue` in favor of permission-based getters.
- Cleaned legacy `role_id` queries in `ApprovalController.php` delegation checks.
- Enhanced `LeaveRequestNotificationService` to query approvers by permissions as well as roles.

#### Added
- Automated test suite `Tests\Feature\AuthorizationCleanupAndVerificationTest`.
- Checkpoint report `docs/checkpoints/USER-05-REPORT.md`.

### [USER-04] - 2026-08-16
#### Added
- Model appends and accessors in `App\Models\User`: `personnel_uuid`, `personnel_code`, `personnel_status`, `position`.
- Enhanced payloads in `AuthController` (`/api/login`, `/api/user`) and `Admin\UserController` (`/api/admin/users`, `/api/users/list`) including Personnel Domain data with full backward compatibility.
- Personnel Domain helper getters in Pinia auth store (`useAuthStore`): `personnel`, `currentAssignment`, `personnelCode`, `displayName`, `positionName`, `orgUnitName`.
- Automated test suite `Tests\Feature\PersonnelApiAlignmentTest`.
- Checkpoint report `docs/checkpoints/USER-04-REPORT.md`.

#### Changed
- `UsersManagementView.vue` and `UserProfileView.vue` updated to display staff information from Personnel Domain with fallback to legacy fields.

### [USER-03] - 2026-08-16
#### Changed
- Refactored `App\Services\OrgScopeResolver::homeOrgUnitId` to read org unit from active `PersonnelAssignment` in Personnel Domain before fallback.
- Refactored `App\Services\LeavePolicyService::resolve` to read `personnel_type_id` from Personnel Domain assignment and verify service eligibility with `personnel.effective_from`.
- Refactored `App\Services\Time\TimeService::isWorkingDay` to resolve department/org unit from Personnel Domain.
- Refactored `App\Services\Reporting\ReportingDataService::personnel` to read formatted name, position, organization, and personnel type from Personnel Domain.
- Updated `App\Services\LeaveService::createLeaveRequest` to eager load `personnel` and `currentAssignment`.

#### Added
- Automated test suite `Tests\Feature\PersonnelDomainCoreServicesTest`.
- Checkpoint report `docs/checkpoints/USER-03-REPORT.md`.

### [USER-02] - 2026-08-16
#### Added
- `App\Services\PersonnelSyncService` with recursion guard (`withoutSyncRecursion`), multi-table database transaction wrapping, and effective-dating position manager (`assignPosition`).
- Model Observers: `UserObserver`, `PersonnelObserver`, `UserProfileObserver`, and `PersonnelAssignmentObserver`.
- Observers registered in `AppServiceProvider::boot()`.
- Method `User::currentAssignment()` and `Personnel::currentAssignment()`.
- Automated test suite `Tests\Feature\PersonnelSyncTest`.
- Checkpoint report `docs/checkpoints/USER-02-REPORT.md`.

#### Changed
- Delegated inline model lifecycle hooks in `App\Models\User` to `UserObserver` for unified synchronization.

### [USER-01] - 2026-08-16
#### Added
- Migration `2026_08_16_010000_create_personnel_foundation.php` creating `personnel` table with UUID, unique `user_id` 1:1 foreign key, `personnel_code`, `title`, `first_name`, `last_name`, `status`, `effective_from`, `effective_to`, and composite effective date index.
- Idempotent and transaction-safe data backfill in migration populating `personnel` from `users` and `user_profiles`.
- Model `App\Models\Personnel` using `HasPublicUuid` and defining `user()`, `assignments()`, and `full_name` accessor.
- Relationship `User::personnel()` (1:1 hasOne) in `App\Models\User`.
- Helper relationships `user()`, `orgUnit()`, `personnelType()`, and `personnel()` in `App\Models\PersonnelAssignment`.
- Automated test suite `Tests\Feature\PersonnelEntityFoundationTest`.
- Checkpoint report `docs/checkpoints/USER-01-REPORT.md`.

#### Changed
- Refactored legacy `whereIn('role_id', ...)` in `App\Http\Controllers\ApprovalController` to use Spatie roles relation (`whereHas('roles', ...)`).

### [USER-00] - 2026-08-16
#### Added
- Comprehensive impact analysis report `docs/checkpoints/USER-00-IMPACT-ANALYSIS.md` cataloging 68 foreign keys, authentication, session, token, and authorization dependencies.
