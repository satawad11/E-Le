# TASKS

## LEGACY-ASSESSMENT — CURRENT (รอบที่ 1 เสร็จ 2026-08-12 · รออนุมัติ)

- [x] อ่าน START-HERE / AGENTS / CLAUDE / PROJECT_RULES
- [x] Inventory repository และ technology stack
- [x] ตรวจ Database schema/migrations — **จาก migration files เท่านั้น ยังไม่ได้ตรวจ DB จริง**
- [x] ตรวจ Authentication
- [x] ตรวจ RBAC/ABAC/authorization/IDOR
- [x] ตรวจ Organization + Personnel
- [x] ตรวจ Workflow/Approval
- [x] ตรวจ Leave
- [x] ตรวจ Attendance
- [x] ตรวจ Vendor face/fingerprint integration — **ผล: ไม่มี integration ใด ๆ ในระบบเดิม**
- [x] ตรวจ Overtime
- [x] ตรวจ Notification
- [x] ตรวจ Reports
- [x] ตรวจ File storage
- [x] ตรวจ Audit
- [x] ตรวจ Testing — **ไม่ได้รันจริง (ไม่มี php/node ในสภาพแวดล้อมที่ตรวจ)**
- [x] ตรวจ Deployment/Backup
- [x] กรอก docs/LEGACY-SYSTEM-ASSESSMENT.md
- [x] กรอก docs/LEGACY-GAP-ANALYSIS.md
- [x] กรอก docs/LEGACY-MIGRATION-PLAN.md
- [x] สรุป Top 10 gaps และ P0/P1 — P0 = 14, P1 = 16
- [x] **ยืนยัน schema production จริง** — ทำใน LEGACY-00: drift = 0
- [x] **รัน `php artisan test` จริง** — ทำใน LEGACY-00: 86/86 ผ่าน (หักล้างข้อสรุปเดิม)
- [x] ตอบ Open Questions — ตอบได้แล้ว 3 ข้อ (#3, #4, #5) เหลือ 4 ข้อเดิม + 3 ข้อใหม่
- [x] หยุดและรออนุมัติก่อนแก้ Source Code — **ผู้ใช้อนุมัติในหลักการแล้ว 2026-08-12**

## LEGACY-00 — FREEZE & BASELINE ✅ เสร็จ 2026-08-12 (ไม่มี FAIL)

รายงาน: `docs/checkpoints/LEGACY-00-REPORT.md`

- [x] สร้าง baseline commit `6139212` + tag `baseline/legacy-2026-08-12` (258 ไฟล์)
- [x] ตรวจ working tree — clean หลัง commit, ไม่มี secret/vendor/ไฟล์ผู้ใช้หลุดเข้า repo
- [x] ตรวจ migration status — 91/91 `Ran`, 0 `Pending`
- [x] ตรวจ schema จริงจาก MySQL 8.0.46 — 55 ตาราง
- [x] เปรียบเทียบ schema จริงกับ migration — **drift = 0** (ยืนยัน 3 วิธีอิสระ)
- [x] ตรวจ `leaves.status` / `leave_approvals.status` — **พบ K-01: enum ไม่มีค่า `Voided` → ฟีเจอร์ void พัง** (บันทึกแล้ว ไม่แก้)
- [x] Row count baseline — 298 แถว / 55 ตาราง
- [x] Database backup + ตรวจ BOM — `eleave-full-2026-08-12.sql` (143 kB)
- [x] Backup `storage/app` — `storage-app-2026-08-12.tar.gz` (39 kB, 12 ไฟล์)
- [x] Checksum SHA-256 ทั้ง archive และรายไฟล์
- [x] ทดสอบ restore บน instance แยก — **298/298 แถว, checksum ตรงทุกฟิลด์รวมข้อมูลเข้ารหัส**
- [x] รัน `php artisan test` — 86/86 ผ่าน
- [x] รัน `npm run build` — exit 0
- [x] บันทึก test baseline / schema baseline / rowcount baseline
- [x] บันทึก known issues (K-01 ถึง K-09 ใน `docs/KNOWN-LIMITATIONS.md`)
- [x] สร้าง `docs/checkpoints/LEGACY-00-REPORT.md`
- [x] **ได้รับการตัดสินใจ D-1 ถึง D-4 และอนุมัติให้เริ่ม LEGACY-01**

## LEGACY-01 — STABILIZATION ✅ เสร็จสมบูรณ์ 2026-08-13

รายงาน: `docs/checkpoints/LEGACY-01-REPORT.md`

- [x] ลบ public/debug/test artifacts และยืนยันว่า `/fix-leaves` ไม่มี implementation
- [x] Automated backup + restore drill + worker/scheduler
- [x] CI: Pint, backend tests, fresh MySQL 8 migration, frontend build
- [x] Production debug guard, structured JSON log และ `X-Request-ID`
- [x] แก้ LeaveController null role และเปลี่ยนเป็น permission-based authorization
- [x] เปลี่ยน `leaves.status` เป็น checked varchar รองรับ `Voided`
- [x] เติมเมนูที่หายด้วย idempotent seeder
- [x] หมุน MySQL credential จริงและย้าย application ออกจาก root — ยืนยัน `eleave_app@%`, root เฉพาะ localhost, backup ผ่าน
- [x] ปีงบประมาณ 2567–2571 — seed แบบ idempotent; ปีปัจจุบัน 2569
- [x] วันหยุดราชการทั่วประเทศปี 2569 จำนวน 20 วัน และแม่แบบใบลาใช้งานจริง 5 แบบ — seed แบบ idempotent

## AFTER LEGACY ASSESSMENT APPROVAL

- [x] LEGACY-00 Freeze & Baseline
- [x] LEGACY-01 Stabilization — ผ่าน implementation, credential, ปีงบประมาณ, วันหยุด และแม่แบบเอกสาร
- [x] LEGACY-02 Security Hardening — full regression 195 tests / 485 assertions ผ่าน
  - [x] LEGACY-02A File Security — private storage, MIME/size validation, UUID filenames, resource authorization, nginx deny
  - [x] LEGACY-02B Authorization & IDOR — SEC-003, SEC-004, SEC-009 ผ่านจาก commit ก่อนหน้า
  - [x] LEGACY-02C Authentication & Session — lockout, token expiry/rotation, revoke, password reset, secure production session defaults
  - [x] LEGACY-02D E-Signature Integrity — password ทุก mode, assigned signer, sealed content hash, public throttle
  - [x] LEGACY-02E General Hardening — security headers, safe HTML rendering, generic errors, export/download throttles, route audit
- [x] LEGACY-03 Core Data Foundation — full regression 204 tests / 506 assertions ผ่าน
  - [x] LEGACY-03A Organization, Personnel & UUID foundation (03-1 ถึง 03-5)
  - [x] LEGACY-03B Central audit dual-write (03-6 ถึง 03-7)
  - [x] LEGACY-03C Document template version foundation (03-8)
  - [x] LEGACY-03D Leave status checked varchar (03-9) — ทำแล้วใน LEGACY-01
- [x] LEGACY-04 RBAC + ABAC — ปิดงานเมื่อ 2026-08-14 หลังได้รับการตัดสินใจ 4 ข้อ · `docs/checkpoints/LEGACY-04-FINAL-REPORT.md`
  - [x] LEGACY-04A รากฐาน RBAC + ABAC (04-1 ถึง 04-3, 04-5) — alias, explicit deny, org scope
  - [x] LEGACY-04B ถอด `hasRole` (04-4, 04-8) — เหลือ 3 จุด Super Admin bootstrap · หน้าเว็บเหลือ 1 จุด
  - [x] LEGACY-04C สิทธิ์รายบุคคลแหล่งเดียว (04-6, 04-7)
  - [x] LEGACY-04D authorization test matrix (04-9) — `KNOWN_MISSING_PENDING_DECISION` ว่างแล้ว
  - [x] LEGACY-04E เติม permission 7 ตัวตาม Decision 1 — บุคลากรทั่วไปลงเวลาได้แล้ว
  - [x] LEGACY-04F `Document Officer` = เจ้าหน้าที่สารบรรณตาม Decision 2 (permission ไม่ใช่ชื่อ role)
  - [x] LEGACY-04G `org_unit_managers` รองรับหัวหน้าหน่วยงาน/ผู้รักษาการตาม Decision 3 + 4
  - [x] LEGACY-04H ปิดช่องยกระดับสิทธิ์ 3 จุดที่พบระหว่างทาง
- [x] LEGACY-05 Workflow Engine — 320 tests / 832 assertions ผ่าน · `docs/checkpoints/LEGACY-05-REPORT.md`
  - [x] LEGACY-05A schema 8 ตาราง + shadow comparison log (05-1)
  - [x] LEGACY-05B Leave Workflow v1 จาก `approval_steps` พร้อม version pinning (05-2)
  - [x] LEGACY-05C Shadow mode บน endpoint อนุมัติจริง — ตรงกัน 100% (05-3)
  - [ ] LEGACY-05D Cutover (05-4) — **ยังไม่ทำโดยเจตนา** ต้องมี shadow จากการอนุมัติจริงและคำสั่งอนุมัติ
  - [x] LEGACY-05E เตรียม Saraban เข้า engine เดียวกัน (draft · ไม่แตะสิทธิ์) (05-5)
  - [x] LEGACY-05F delegation / acting approver + audit ครบ (05-6 บางส่วน)
  - [x] LEGACY-05G `approval_steps` / `leave_approvals` คงไว้เป็น read-only (05-7)
- [x] CORE CHECKPOINT — ✅ **PASS · อนุมัติแล้ว 2026-08-14** · `CORE_CHECKPOINT_REPORT.md`
  - [x] P0 gaps = 0 (16/16)
  - [x] P1 gaps = 0 (18/18) — รอบแรกเหลือ 3 · แก้ครบแล้ว
    - [x] `DEPLOY-001` — nginx + php-fpm เป็นทางเข้าเดียว เลิกใช้ `artisan serve`
    - [x] `DEPLOY-004` — เหลือ Dockerfile ชุดเดียว · `docker/nginx/default.conf` ถูกใช้งานจริง
    - [x] `CORE-WF-002` — Saraban pin `workflow_version_id` + ผู้บริหารได้สิทธิ์อนุมัติ/ลงนาม
  - [x] Authorization matrix + IDOR ผ่าน
  - [x] Backup + restore drill สำเร็จ (73 ตาราง · mismatch 0)
  - [x] Data reconciliation ผ่าน (55 → 73 ตาราง อธิบายได้ครบ)
  - [x] migrate จากฐานว่างบน MySQL 8 ครบ 103 migration
  - [x] TRACEABILITY-MATRIX อัปเดตครบ
  - [x] ได้รับอนุมัติรายงานจากเจ้าของระบบ
- [x] LEGACY-06 TIME Foundation — **อนุมัติแล้ว 2026-08-15** · 372 tests / 952 assertions · `docs/checkpoints/LEGACY-06-REPORT.md`
  - [x] 06-1 `work_schedules` / `work_schedule_days` / `work_schedule_assignments` แบบ effective-dated
  - [x] 06-2 backfill จาก setting เดิม โดยไม่แตะ `users.work_start_time`
  - [x] 06-3 grace period (ค่าเริ่มต้น 0 เพื่อรักษาพฤติกรรมเดิม)
  - [x] 06-4 เชื่อม `holidays` + `work_calendar_exceptions` เข้ากับ engine
  - [x] `TimeService` เป็นบริการเวลากลาง — โมดูลอื่นเลิกคำนวณวันและเวลาเอง
  - [ ] **รอตัดสินใจ:** กะข้ามวัน — การลงเวลาหลังเที่ยงคืนนับเป็นของวันไหน (เงื่อนไขก่อนเปิดใช้กะดึกจริง)
- [x] LEGACY-07 Leave Modernization — 402 tests / 993 assertions ผ่าน · `docs/checkpoints/LEGACY-07-REPORT.md`
  - [x] 07-1 อ่านเทมเพลตจาก `document_template_versions` + แก้การ pin ให้ตรงแบบฟอร์ม
  - [~] 07-2 แตก `LeaveController` — ทำเฉพาะส่วนเทมเพลตที่ใช้ร่วมกัน (เลี่ยง rewrite ตามข้อกำหนด)
  - [x] 07-3 ยืนยันด้วยเทสต์ว่ารายงานนับครั้งแยกจากวัน
  - [x] 07-4 ผู้อนุมัติเข้าถึงหลักฐานการลาได้ โดยไม่ขยายขอบเขตหน่วยงาน
  - [x] 07-5 ยืนยันฐานปีเลขที่เอกสารสอดคล้องกับสารบรรณ
  - [x] เชื่อม Time Foundation · Workflow Engine · Org Scope · RBAC/ABAC · Audit · Private storage
- [x] LEGACY-08 Attendance Integration — **PASS 2026-08-15** · 428 tests / 1056 assertions · `docs/checkpoints/LEGACY-08-REPORT.md`
  - [x] `attendance_sources`, `devices`, `attendance_identity_maps`, `attendance_raw_events` แบบ additive
  - [x] raw event แยกจาก attendance result · event UUID + source/dedup unique · payload immutable
  - [x] reconciliation service รองรับ duplicate, out-of-order, overnight และ missing checkout
  - [x] web check-in/out เขียน raw event ก่อนสร้างผล และบังคับ `attendance.checkin`
  - [x] correction เข้า Workflow Engine และ audit ตอนขอ/อนุมัติ/ปฏิเสธ/apply
  - [x] report/list/export/summary จำกัด personnel scope ผ่าน `OrgScopeResolver`
  - [x] connector contracts รองรับแหล่งทั่วไปและ biometric โดยไม่ผูก vendor
  - [ ] vendor connector จริง — รอข้อมูลยี่ห้อ/protocol/identifier ตาม Open Question #1/#2
- [x] LEGACY-09 Overtime — **PASS 2026-08-15** · 435 tests / 1096 assertions · `docs/checkpoints/LEGACY-09-REPORT.md`
  - [x] `ot_orders` แยกคำสั่งและเวลาที่อนุมัติ
  - [x] `ot_assignments` แยกผู้ได้รับมอบหมาย
  - [x] `ot_time_records` แยกเวลาปฏิบัติจริงและเวลารับรอง
  - [x] คำสั่งเข้า Workflow Engine ก่อนมอบหมาย/ลงเวลา
  - [x] attendance reconciliation โดยไม่เขียน OT fields เดิม
  - [x] permission + org scope + UUID binding + audit
  - [x] report แสดง approved/actual/certified แยกกัน
  - [x] ไม่สร้าง rate/amount/compensation calculation
- [x] LEGACY-10 Notification Center — **PASS 2026-08-15** · 442 tests / 1140 assertions · `docs/checkpoints/LEGACY-10-REPORT.md`
- [x] LEGACY-11 Evaluation & Reporting — **PASS 2026-08-15** · 448 tests / 1186 assertions · `docs/checkpoints/LEGACY-11-REPORT.md`
- [x] LEGACY-12 Future Module Readiness — **FINAL PASS 2026-08-15** · 456 tests / 1328 assertions · `docs/checkpoints/LEGACY-12-REPORT.md`
- [x] LEGACY Modernization Final Report — **FINAL PASS** · `docs/checkpoints/LEGACY-MODERNIZATION-FINAL-REPORT.md`
  - [x] notification types/templates/recipients/deliveries/preferences แบบ additive + versioned templates
  - [x] In-App และ Email ใช้งานจริง; LINE OA/Telegram ผ่าน adapter และ skip อย่างปลอดภัยเมื่อไม่มี config
  - [x] retry/backoff + terminal failed state + delivery status แยก channel
  - [x] read/unread และ preference API จำกัดเฉพาะเจ้าของ; legacy notifications ยัง dual-read ได้
  - [x] Leave/Attendance/OT/Saraban emit event เข้าศูนย์กลาง; ไม่มี provider logic ใน business flow
  - [x] sensitive payload filtering + generic minimal templates
  - [x] jobs/failed_jobs backlog = 0 จึงไม่ส่งย้อนหลัง
  - [x] MySQL 8 migration, full regression, Pint และ frontend build ผ่าน
- [ ] Evaluation & Reporting

- [x] LEGACY-07-2 follow-up: split the 937-line `LeaveController` into query, request, cancellation, workflow-action, and document controllers; introduced active FormRequests and notification service; retained all legacy endpoint URIs.

## UX / UI STABILIZATION PHASE

- [x] UX-01 Global Layout Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-01-REPORT.md`
  - [x] Standardize desktop AppShell with fixed viewport container and independent scrollable main content
  - [x] Standardize mobile slide-over drawer with backdrop blur, scrollable nav, and route watcher
  - [x] Normalize z-index hierarchy (`z-10`/`z-30` header, `z-40` backdrop, `z-50` drawer/modal)
  - [x] Expand `isBareLayout` for auth guest routes (`forgot-password`, `reset-password`, `verify-leave`)
  - [x] Remove nested `<main>` tags and double padding across 10 views
  - [x] Standardize institutional maroon theme (`#800000`) on guest auth and admin views
- [x] UX-02 Navigation & Menu Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-02-REPORT.md`
  - [x] Register all 9 missing view routes in `router/index.js` with permission gates and titles
  - [x] Add dynamic Breadcrumb navigation in Desktop Header with module hierarchy
  - [x] Synchronize Desktop Sidebar and Mobile Drawer navigation items
  - [x] Gate menu visibility with real system permissions (no hardcoded roles)
  - [x] Auto-expand active collapsible sections and highlight current route
  - [x] Audit navigation visibility across all 7 standard roles with zero unauthorized visibility
- [x] UX-03 Responsive Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-03-REPORT.md`
  - [x] Audit 32 views across 7 breakpoints (360px, 390px, 768px, 1024px, 1366px, 1440px, 1920px)
  - [x] Wrap data tables with `overflow-x-auto` to prevent horizontal layout break
  - [x] Apply fluid typography and adaptive card padding for small mobile devices
  - [x] Enable button and filter wrapping with `flex-wrap` across all report and admin views
  - [x] Add mobile-safe scrolling and padding to modal dialogs
- [x] UX-04 Mobile UX Optimization — **PASS 2026-08-15** · `docs/checkpoints/UX-04-REPORT.md`
  - [x] Enforce minimum 44x44px touch targets on icon buttons, notifications, eye password toggles, and approval CTAs
  - [x] Prevent iOS Safari auto-zoom with 16px input font sizing
  - [x] Convert leave period radios to large segmented touch chips
  - [x] Expose primary leave request CTA on mobile dashboard with thumb-friendly sizing
  - [x] Restrict notification panel width to mobile viewport bounds
- [x] UX-05 Form & Validation Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-05-REPORT.md`
  - [x] Complete label & required field audit with clear visual indicators across all forms
  - [x] Standardize 422 server validation error extraction from `errors` object to Thai toast banners
  - [x] Enforce disabled & loading spinner states to prevent double submission across all submit buttons and action modals
  - [x] Eliminate all remaining native browser `alert()` calls, unifying under Pinia `useAlertStore` and `Swal.fire`
  - [x] Validate date, time, decimal step, and Thai identification number constraints
  - [x] Add destructive action confirmation dialogs for delete, void, and status toggles
- [x] UX-06 UI State Standardization — **PASS 2026-08-15** · `docs/checkpoints/UX-06-REPORT.md`
  - [x] Create reusable UI state components: `LoadingState.vue`, `EmptyState.vue`, `ErrorState.vue`, and `PermissionDenied.vue`
  - [x] Build safe Thai error parser `errorHandler.js` preventing raw SQL, PHP exceptions, and stack traces
  - [x] Standardize 401 Unauthorized redirect and 403 Forbidden route handling
  - [x] Add 404 `NotFoundView.vue` for invalid paths
  - [x] Integrate Skeleton loaders, Empty states, and Retry actions across Dashboard, My Leaves, Approvals, Reports, Attendance, and Admin views
- [x] UX-07 Accessibility Audit (WCAG 2.2 AA) — **PASS 2026-08-15** · `docs/checkpoints/UX-07-REPORT.md`
  - [x] Add Skip to Main Content link for keyboard & screen reader users
  - [x] Modal focus trapping, dialog ARIA roles, and escape key handling across all modals and drawers
  - [x] Enforce label-input associations with `for` and `id` across all form views
  - [x] Make interactive elements (dropzones, profile uploads, notifications) accessible via keyboard (`Enter`/`Space`)
  - [x] Standardize global `:focus-visible` outline rings in `style.css`
  - [x] Add descriptive `aria-label` to icon-only buttons (notifications, drawer toggle, password eye toggle, pagination)
  - [x] Add `alt` text to profile and signature images and `role="img"` with labels on canvases
- [x] UX-08 Permission UX Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-08-REPORT.md`
  - [x] Audit Zero Trust Frontend principle: backend Gate/Policy enforcement is absolute
  - [x] Audit Direct URL access routing unauthorized users to `/forbidden` (403)
  - [x] Audit menu visibility: dynamic permission gating without hardcoded roles
  - [x] Audit action button gating: edit, delete, approve, and sign permissions
  - [x] Verify IDOR protection on resource endpoints (leaves, attendances, saraban)
  - [x] Live permission cache invalidation: `authStore.fetchUser({force:true})` on direct permission updates
- [x] UX-09 Workflow UX Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-09-REPORT.md`
  - [x] Create reusable `WorkflowTimeline.vue` component with multi-tier stepper
  - [x] Current step indicator with active pulsating nodes and clear status badges
  - [x] Prominent Rejection & Cancellation reason alert banner with approver name and timestamp
  - [x] Next step guidance banner clarifying who must act next
  - [x] Expandable audit trail logs in leave and approval cards
  - [x] Full mobile-first responsive layout support
- [x] UX-10 Design System Consolidation — **PASS 2026-08-15** · `docs/checkpoints/UX-10-REPORT.md`
  - [x] UI component codebase survey (80+ buttons, 26+ modals, 50+ inputs across 40+ views)
  - [x] Create canonical `AppButton.vue` (5 variants, 3 sizes, loading/disabled, 44px touch)
  - [x] Create canonical `AppBadge.vue` (6 semantic variants, dot indicator)
  - [x] Create canonical `AppModal.vue` (5 sizes, focus trap, escape key, backdrop blur)
  - [x] Create canonical `AppPagination.vue` (page computation, ellipsis, ARIA navigation)
  - [x] Create canonical `AppInput.vue`, `AppSelect.vue`, `AppTextarea.vue` (form controls, auto ID/label)
  - [x] Perform selective low-regression inconsistency fixes (checkbox styling, tab brand color, modal syntax, skeleton bypasses)
- [x] UX-11 Cross-module UX Consistency Audit — **PASS 2026-08-15** · `docs/checkpoints/UX-11-REPORT.md`
  - [x] Cross-module evaluation of Headers, Breadcrumbs, Search, Filters, Tables, Modals, Toasts, Confirmations, and Button order
  - [x] Standardize Buddhist Era (พ.ศ.) date formatting via centralized `ThaiDateFormatter`
  - [x] Refactor divergent custom date routines in `UsersManagementView`, `FiscalYearManagementView`, `WorkCalendarView`, `DocumentDetailView`, and `LeavePoliciesView`
  - [x] Standardize Thai administrative terminology across all views
- [x] UX-12 Final E2E UX Verification — **PASS 2026-08-15** · `docs/checkpoints/UX-12-FINAL-REPORT.md`
  - [x] E2E verification of 11 critical user journeys across real roles (Login, Dashboard, Navigation, Profile, Leave, Status, HR, Deputy, Director, 403, Logout)
  - [x] Multi-viewport responsiveness audit (Desktop, Tablet, Mobile)
  - [x] Quality gates passed: 0 broken flows, 0 dead buttons, 0 dead links, 100% Thai errors, WCAG 2.2 AA compliant
  - [x] 0 Critical, 0 High, 0 Medium, 0 Low issues remaining
  - [x] APPROVAL-WORKFLOW-FIX Approval Workflow Investigation & Fix — **PASS 2026-08-16** · `docs/checkpoints/APPROVAL-WORKFLOW-FIX-REPORT.md`
  - [x] Investigate root causes: validation gaps, shadow engine desynchronization, concurrency protection, UI reactivity
  - [x] Fix Backend `WorkflowController`: strict 1..N contiguous sequence validation, optimistic concurrency (409 Conflict), DB transaction safety, `LeaveWorkflowProvisioner::publishCurrentVersion()` synchronization, and audit logging
  - [x] Fix Frontend `WorkflowManagementView`: delete confirmation dialog, unsaved changes detection (`onBeforeRouteLeave` / `beforeunload`), instant reindexing, fingerprint tracking, and 44px mobile touch targets
  - [x] Verify Pinned Snapshot architecture: existing leave requests in `leave_approvals` remain untouched; new requests receive the latest approval steps
  - [x] Implement comprehensive Feature Test suite: `ApprovalWorkflowManagementTest.php` (9 tests, 44 assertions, 100% PASS)

## USER MODERNIZATION PHASE

- [x] USER-00 Impact Analysis — **PASS 2026-08-16** · `docs/checkpoints/USER-00-IMPACT-ANALYSIS.md`
  - [x] Deep audit of 68 Foreign Key dependencies across 38 tables referencing `users.id`
  - [x] Analysis of Authentication, Session, Token, RBAC/ABAC, and Domain bindings
  - [x] Migration strategy blueprint (Additive only, backward compatibility)
- [x] USER-01 Personnel Entity Foundation — **PASS 2026-08-16** · `docs/checkpoints/USER-01-REPORT.md`
  - [x] Create additive `personnel` table with UUID, 1:1 user binding, status, and effective dates
  - [x] Idempotent & transaction-safe backfill from `users` + `user_profiles`
  - [x] Establish `User::personnel()`, `Personnel::user()`, and `PersonnelAssignment` domain relations
  - [x] Fix legacy `whereIn('role_id')` in `ApprovalController.php` via Spatie `whereHas('roles')`
  - [x] Maintain 100% backward compatibility for all 68 FKs, Sanctum tokens, and Spatie roles
- [x] USER-02 Dual-Write & Synchronization — **PASS 2026-08-16** · `docs/checkpoints/USER-02-REPORT.md`
  - [x] Build `PersonnelSyncService` with recursion guard (`withoutSyncRecursion`) and DB transaction safety
  - [x] Implement dedicated Observers (`UserObserver`, `PersonnelObserver`, `UserProfileObserver`, `PersonnelAssignmentObserver`)
  - [x] Synchronize Field Ownership Matrix across `users`, `personnel`, `user_profiles`, and `personnel_assignments`
  - [x] Manage effective-dating history on assignment changes without overwriting past records
  - [x] Automated test suite `PersonnelSyncTest.php` (8 tests, 24 assertions, 100% PASS)
- [x] USER-03 Refactor Core Services to Personnel Domain — **PASS 2026-08-16** · `docs/checkpoints/USER-03-REPORT.md`
  - [x] Refactor `OrgScopeResolver::homeOrgUnitId` to prioritize active assignment in Personnel Domain
  - [x] Refactor `LeavePolicyService::resolve` to read `personnel_type_id` from Personnel Domain assignment
  - [x] Refactor `TimeService::isWorkingDay` to resolve org unit from Personnel Domain
  - [x] Refactor `ReportingDataService::personnel` to output domain attributes (name, position, org, type)
  - [x] Automated test suite `PersonnelDomainCoreServicesTest.php` (4 tests, 14 assertions, 100% PASS)
- [x] USER-04 Frontend & API Layer Alignment — **PASS 2026-08-16** · `docs/checkpoints/USER-04-REPORT.md`
  - [x] Extend `/api/login`, `/api/user`, `/api/admin/users`, `/api/users/list` with Personnel Domain payload
  - [x] Add appends & accessors (`personnel_uuid`, `personnel_code`, `personnel_status`, `position`) on User model
  - [x] Add Personnel Domain getters with fallback to `useAuthStore`
  - [x] Align `UsersManagementView.vue` and `UserProfileView.vue` with domain fields
  - [x] Automated test suite `PersonnelApiAlignmentTest.php` (2 tests, 15 assertions, 100% PASS)
- [x] USER-05 Authorization Cleanup & Final Verification — **PASS 2026-08-16** · `docs/checkpoints/USER-05-REPORT.md`
  - [x] Remove hardcoded role checks from `MyLeavesView.vue`, `DashboardView.vue`, `UserProfileView.vue`
  - [x] Clean legacy `role_id` queries in `ApprovalController.php` in favor of Spatie roles relations
  - [x] Enhance `LeaveRequestNotificationService` to query approvers by permissions
  - [x] Verify zero authorization leakage and ABAC scope integrity
  - [x] Automated test suite `AuthorizationCleanupAndVerificationTest.php` (4 tests, 12 assertions, 100% PASS)
- [x] APPROVAL-01 Approval Resolver & Workflow Configuration Hardening — **PASS 2026-08-16** · `docs/checkpoints/APPROVAL-01-REPORT.md`
  - [x] Build `ApproverResolverService` supporting 7 resolver types (`DEPARTMENT_HEAD`, `REQUESTER_SUPERVISOR`, `DIVISION_DIRECTOR`, `DIRECTOR`, `HR_OFFICER`, `ROLE`, `SPECIFIC_USER`)
  - [x] Additive migration for `approval_steps` (`approver_type`, `specific_user_id`, `permission_name`)
  - [x] Immutable workflow snapshotting and optimistic concurrency control (409 Conflict)
  - [x] Upgrade `WorkflowManagementView.vue` with resolver selection and hardened mobile UX
  - [x] Automated test suite `ApprovalResolverAndWorkflowHardeningTest.php` (3 tests, 15 assertions, 100% PASS)
- [x] APPROVAL-02 Approval Chain Snapshot & Worklist Alignment — **PASS 2026-08-19** · `docs/DECISION-LOG.md` ADR-APPROVAL-02
  - [x] Migration เพิ่ม `approver_type`, `specific_user_id`, `permission_name` ใน `leave_approvals` พร้อม backfill ใบลาที่ยังรออนุมัติ
  - [x] `LeaveService` / `GovernmentApprovalController` คัดลอกวิธีหาตัวผู้อนุมัติลง snapshot ให้ครบ
  - [x] `ApproverResolverService` เป็น fail-closed เมื่อไม่มีเกณฑ์คัด (ปิดช่องโหว่ให้ผู้ใช้ทุกคนอนุมัติได้)
  - [x] `Admin\WorkflowController` เลิกเติม role ให้ขั้นตอนอัตโนมัติโดยอัตโนมัติ
  - [x] รายการรออนุมัติใช้ resolver ตัวเดียวกับการอนุมัติจริง ("เห็น = อนุมัติได้")
  - [x] สายอนุมัติคำขอยกเลิกใบลาใช้ snapshot + resolver ชุดเดียวกัน และตรวจสถานะ/ห้ามอนุมัติของตนเอง
  - [x] ลบ `ApprovalController::approve()/reject()` ที่ไม่มี route ชี้ถึงแล้ว
  - [x] Automated test suite `LeaveApprovalChainSnapshotTest.php` (7 tests, 31 assertions, 100% PASS) + regression 514 tests
- [x] APPROVAL-03 Approver Preview สำหรับหน้าตั้งค่าสายอนุมัติ — **PASS 2026-08-19**
  - [x] `POST /api/admin/workflow-steps/preview` ตอบรายชื่อผู้อนุมัติจริงรายขั้นตามผู้ยื่นที่เลือก (รองรับสายที่ยังไม่บันทึก)
  - [x] เตือนเมื่อขั้นตอนใดไม่มีผู้อนุมัติ หรือได้ผู้ยื่นเป็นผู้อนุมัติของตนเอง
  - [x] ปุ่ม "ดูตัวอย่างผู้อนุมัติจริง" ใน `WorkflowManagementView.vue`
  - [x] เทสต์เพิ่ม 3 เคส (รวมชุดเป็น 10 tests, 38 assertions) + regression 517 tests + frontend build
- [x] APPROVAL-04 สายอนุมัติของคำขอยกเลิกใบลา — **PASS 2026-08-19** · `docs/DECISION-LOG.md` ADR-APPROVAL-04
  - [x] Migration `leave_approvals.stage` + `leaves.cancellation_approval_level` พร้อม backfill คำขอที่ค้างอยู่
  - [x] คำขอยกเลิกผนึก snapshot ของตัวเอง ณ วันที่ขอ และเดินระดับแยกจากสายขอลา
  - [x] บันทึกผู้อนุมัติ/ตำแหน่ง/ลายเซ็น/ความเห็น/ผู้มอบอำนาจ รายขั้น + audit log
  - [x] แก้บั๊กอนุมัติยกเลิกแล้ววันลาไม่ถูกคืนเข้าสิทธิ์
  - [x] รายการรออนุมัติตัดสินสิทธิ์ด้วยสายของคำขอยกเลิก และหน้าจอแสดงลำดับการอนุมัติยกเลิก
  - [x] Automated test suite `LeaveCancellationChainTest.php` (9 tests, 86 assertions) + regression 533 tests
- [x] APPROVAL-05 ตั้งค่าสายอนุมัติคำขอยกเลิกแยกได้ — **PASS 2026-08-19** · `docs/DECISION-LOG.md` ADR-APPROVAL-05
  - [x] Migration `approval_steps.chain` (`leave` / `cancellation`)
  - [x] สายยกเลิกที่ยังไม่ตั้งค่า = ใช้สายเดียวกับใบลา (`ApprovalStep::stepsFor()`)
  - [x] Admin API + fingerprint + preview แยกตามสาย และบันทึกสายหนึ่งไม่กระทบอีกสาย
  - [x] แท็บสลับสายในหน้าจัดการลำดับการอนุมัติ พร้อมปุ่มกลับไปใช้สายเดียวกับใบลา
  - [x] ขยายเทสต์เป็น 14 tests, 122 assertions + regression 538 tests






## LEAVE-FIX-01 — Leave lifecycle audit & fix ✅ 2026-10-13

- [x] ทดสอบวงจรการลาจริงผ่าน API ทุกบทบาท (ยื่น → อนุมัติ 3 ขั้น → ขอยกเลิก → อนุมัติยกเลิก)
- [x] คืนวันลาเมื่ออนุมัติยกเลิกใบที่อนุมัติแล้ว
- [x] ปิดช่องข้ามขั้นอนุมัติผ่าน approve-cancellation
- [x] ฝ่ายบุคคลบันทึกวันลาแทน: ตัดยอดเป็น used + ปิดทุกขั้น + บันทึกย้อนหลังได้ + เลือกครึ่งวัน
- [x] ลาครึ่งวันต้องตรงวันทำการ · ครึ่งเช้า+ครึ่งบ่ายวันเดียวกันได้
- [x] แก้วันที่ใบลาที่รออนุมัติคำนวณวันและยอดจองใหม่
- [x] snapshot ชนิดผู้อนุมัติ + รายการรออนุมัติใช้ resolver
- [x] ยอดวันลาปีงบประมาณใหม่แสดงตั้งแต่ 1 ต.ค.
- [x] ทบวันลาพักผ่อน: รวมผู้ไม่มีประเภทบุคลากร + กฎ 10 ปี (20/30 วัน)
- [x] รายงาน/แดชบอร์ดใช้ปีงบประมาณเป็นค่าเริ่มต้น
- [x] จัดการวันหยุดราชการผ่านหน้าเว็บ + วันหยุดปี 2570
- [x] แจ้งเตือนรายขั้น + ขอยกเลิก + ผู้ปฏิบัติงานแทน
- [ ] **รอเจ้าของระบบยืนยัน:** ค่านโยบายตามระเบียบที่ปรับ (สะสมพักผ่อน 20/30 วัน, อายุงาน 6 เดือนก่อนลาพักผ่อน, ลาช่วยภริยาคลอดเฉพาะชาย)
- [ ] **รอประกาศ ครม.:** วันพืชมงคลและวันหยุดพิเศษปี 2570 — เพิ่มที่เมนูปฏิทินวันทำการ

## USER-GOV-01 — User account governance ✅ 2026-10-14

- [x] ปิดการลบบัญชีผู้ใช้จริง (409) — ใช้การระงับการใช้งานแทน
- [x] audit การเปลี่ยนบทบาท/สิทธิ์รายบุคคล/ฟิลด์ทะเบียนประวัติ/การตั้งรหัสผ่านโดยผู้ดูแล (ไม่เก็บค่าอ่อนไหว)
- [x] ห้ามผู้ดูแลระงับบัญชีตนเอง
- [x] เปลี่ยนรหัสผ่านตนเองต้องยืนยันรหัสผ่านเดิม
- [ ] **งานถัดไป:** ย้ายหน่วยงาน/ตำแหน่งผ่าน `personnel_assignments` แบบ effective-dated แทนการเขียนทับ
- [ ] **งานถัดไป:** แยกสิทธิ์แก้ไขฟิลด์อ่อนไหว (เลขบัตรประชาชน/พาสปอร์ต) ออกจาก `edit_users`
- [ ] **รอนโยบายหน่วยงาน:** ระยะเวลาเก็บข้อมูลหลังพ้นสภาพ · รอบเปลี่ยนรหัสผ่าน · 2FA สำหรับผู้ดูแล
## USER-IMPORT-01 — คืนการนำเข้าผู้ใช้จาก CSV ✅ 2026-10-14

- [x] ตรวจประวัติ: `importCsv()` หายไปใน baseline `6139212` (LEGACY-00) โดยไม่ได้ตั้งใจ — route และปุ่มยังอยู่ จึงตอบ 500 ทุกครั้ง
- [x] พอร์ตจาก `5b1497c` (branch `chore/remove-saraban-module` ที่ยังไม่ merge) และแยก `provisionLeaveBalances()` ใช้ร่วมกับ `store()`
- [x] แก้ช่องโหว่ของฉบับเดิม: `phone` ไปลง `users` (เข้ารหัส) แทนที่จะถูกทิ้ง · ปฏิเสธเลขบัตรประชาชนซ้ำ (ระบบเดิมหรือในไฟล์เดียวกัน) และต้องเป็นเลข 13 หลัก · ช่องว่างไม่บันทึกเป็นสตริงว่าง
- [x] ไม่รับรหัสผ่านจากไฟล์ · ทุกคนได้ `personnel_type_id` (ค่าเริ่มต้น civil_servant) และยอดวันลาตามนโยบาย · audit `user.imported`
- [x] ไฟล์แม่แบบเพิ่มคอลัมน์ `personnel_type_id` (ไม่บังคับ)
- [x] `UserImportTest` 10 เคส

## LEAVE-APPROVAL-02 — สายอนุมัติใหม่ + อนุมัติของตนเองรายขั้น ✅ 2026-10-14

ตามมติเจ้าของระบบ — `docs/DECISION-LOG.md` ADR-LEAVE-APPROVAL-02

- [x] สายอนุมัติ งานบุคลากรตรวจสอบ → รองผู้อำนวยการ → ผู้อำนวยการ (ตรงกับ approval_steps ที่มีอยู่)
- [x] สวิตช์ `allow_self_approval` รายขั้น + pin ลงใบลา · เปิดทั้ง 3 ขั้นตามมติ
- [x] ผู้อำนวยการอนุมัติขั้นสุดท้ายของใบลาตนเอง · รองฯ อนุมัติขั้น 2 ของตนเอง · งานบุคลากรตรวจขั้น 1 ของตนเอง
- [x] ปิดช่อง: ผู้รับมอบอำนาจอนุมัติใบลาของผู้มอบ · อนุมัติของตนเองผ่านการรับมอบ · `leave.management.manage` ข้ามขั้นของตนเอง · บันทึกวันลาแทนให้ตนเอง
- [x] บันทึก/แสดง "อนุมัติด้วยตนเอง" · audit `self_approved`
- [x] `LeaveSelfApprovalTest` 14 เคส + เคสสวิตช์ใน `ApprovalWorkflowManagementTest`
- [ ] **ตรวจในระบบจริง:** หลัง migrate ตรวจหน้าจัดการลำดับการอนุมัติว่าสวิตช์ทั้ง 3 ขั้นเปิดอยู่ (migration เปิดให้ทุกขั้นที่มีอยู่)

## TPL-01 — แบบฟอร์มใบลา: ความปลอดภัย ข้อมูล และรูปแบบ ✅ 2026-10-15

- [x] ปิดช่องรันโค้ดจากแม่แบบในฐานข้อมูล (TPL-SEC-01) — แบบฟอร์ม HTML มาจากไฟล์ในโค้ดเท่านั้น
- [x] หน้าแม่แบบเอกสารเป็นแบบอ่านอย่างเดียว + พรีวิว PDF จริง + ลิงก์ไปศูนย์จัดการเอกสาร
- [x] ผู้ลงนามอ่านจาก snapshot ตามลำดับขั้น · ใบขอยกเลิกใช้สายอนุมัติของคำขอยกเลิก
- [x] ยอดวันลาพักผ่อน/ปีงบประมาณบนแบบฟอร์มใช้ปีงบประมาณ · "ลามาแล้ว" นับเฉพาะใบก่อนหน้า
- [x] ทุกแบบฟอร์มพอดี A4 หน้าเดียว · บรรทัดลงชื่อผู้ลาใช้ลายเซ็นจริง
- [x] สร้างแบบฟอร์มฮัจย์ / ติดตามคู่สมรส / ศึกษาฝึกอบรม / บันทึกไปราชการใหม่บนชุดตัวแปรกลาง · ลบชื่อหน่วยงานและชื่อบุคคลที่เขียนตาย
- [x] ถ้อยคำแบบฟอร์มฮัจย์ ติดตามคู่สมรส ศึกษาฝึกอบรม ตรวจเลือก/เตรียมพล องค์การระหว่างประเทศ และฟื้นฟูสมรรถภาพ ตรงตามแบบท้ายระเบียบฯ พ.ศ. 2555 แล้ว (LEAVE-FORMS-04 · 2026-10-16) — แบบองค์การระหว่างประเทศ/ฟื้นฟูสมรรถภาพเป็นแบบหลายหน้าตามต้นฉบับ
- [ ] **งานผู้ดูแล:** รัน `php artisan db:seed --class=LeaveFormExtraFieldSeeder` บน production เพื่อเพิ่มช่องกรอกของ 3 แบบฟอร์มข้างต้น (หรือเพิ่มเองที่หน้าประเภทการลา)
- [x] แบบฟอร์มของลาตรวจเลือก/เตรียมพล · องค์การระหว่างประเทศ · ฟื้นฟูสมรรถภาพ — เพิ่มแล้วใน LEAVE-FORMS-02

## ACCESS-GOV-01 — บทบาท สิทธิ์ บัญชีผู้ใช้ การอนุมัติ ตามระเบียบบริหารสถานศึกษา พ.ศ. 2569 (กำลังดำเนินการ)

แผน: `docs/ACCESS-GOVERNANCE-2569.md` · เจ้าของระบบตอบ Q1–Q9 และอนุมัติข้อยกเว้น freeze 2026-10-16

- [x] อ่านระเบียบ สอศ. ว่าด้วยการบริหารสถานศึกษา พ.ศ. 2569 และวางแผน (หัวข้อ 1–9 ของแผน)
- [x] ขั้นอนุมัติแบบหัวหน้าของหน่วยงานที่ระบุ (`ORG_UNIT_MANAGER`) สำหรับรองฯ ฝ่ายบริหารทรัพยากร (Q3)
- [x] คำขอยกเลิกใบลาที่อนุมัติแล้วผ่านทุกขั้นตอนเดิมของใบลานั้น (Q4)
- [x] สวิตช์ปิดทางถอยตามชื่อบทบาท `WORKFLOW_APPROVER_ROLE_FALLBACK`
- [x] หน้าโครงสร้างหน่วยงานและทะเบียนหัวหน้าหน่วยงานอ้างคำสั่ง (`/admin/org-structure`, สิทธิ์ `system.org_structure.manage`)
- [x] บันทึกบุคลากรพ้นสภาพ: ระงับบัญชี ปิดวาระหัวหน้า เพิกถอนการทำการแทน ลบขอบเขตพิเศษ
- [x] ทบทวนสิทธิ์รายปีการศึกษา (`access:review`, `access:review-signoff`) + บันทึกเวลาเข้าระบบล่าสุด
- [ ] ผู้ดูแลกรอกโครงสร้าง 4 ฝ่ายตามระเบียบ 2569 และหัวหน้าหน่วยงานทุกหน่วยพร้อมเลขคำสั่ง (Q2 — งานผู้ดูแล)
- [ ] ตั้งสายลา: งานบุคลากร → หัวหน้าของฝ่ายบริหารทรัพยากร → ผู้อำนวยการ (หลังกรอกหัวหน้าครบ)
- [ ] ตั้ง `WORKFLOW_APPROVER_ROLE_FALLBACK=false` บน production หลังกรอกหัวหน้าครบและทดลองสายด้วยปุ่มตรวจสอบผู้อนุมัติ
- [ ] เฟสถัดไป: เอาชื่อบทบาทออกจากบล็อกลงนามแบบฟอร์ม PDF และสาย OT (B2) · บทบาทชุดใหม่ (B5) · สมาชิกประจำงานหลายงาน (C1) · ทะเบียนการให้บทบาทพร้อมวันหมดอายุ (C3)

## ORG-ACCESS-01 — ตำแหน่ง/สิทธิ์ตามโครงสร้างสถานศึกษาอาชีวศึกษา (กำลังดำเนินการ)

ตารางจับคู่และคำตอบเจ้าของระบบ — `docs/ORG-POSITION-ACCESS-MAPPING.md` · `docs/DECISION-LOG.md` ADR-ORG-ACCESS-01

- [x] ร่างตารางจับคู่ ตำแหน่ง → ประเภทบุคลากร → บทบาท → หน่วยงาน/ขอบเขต
- [x] เจ้าของระบบตอบ Q1–Q6 (โครงสร้าง 4 ฝ่าย · ครูจ้างสอน/รายชั่วโมงใช้โควตาลูกจ้างชั่วคราว · เจ้าหน้าที่บุคลากรใช้ HR Head · ยังไม่ทำโมดูลพัสดุ/การเงิน/บัญชี · รองฯ เห็นทั้งองค์กร)
- [x] คำสั่ง `access:scope-grant` / `access:scope-revoke` / `access:scope-list` ให้/ถอนขอบเขตรายคนพร้อม audit (ไม่เปลี่ยน schema)
- [x] Q7: ทะเบียนตำแหน่ง + กลุ่มบุคลากร (ORG-ACCESS-02 · ข้อยกเว้น PROD PHASE อนุมัติ 2026-10-16) — หน้า `/admin/positions`, ตำแหน่งในฟอร์มผู้ใช้, ตัวกรองกลุ่มในรายงาน, `personnel:match-positions`
- [x] HR Head จัดการทะเบียนตำแหน่ง/ผูกตำแหน่งได้ด้วยสิทธิ์ใหม่ `system.position.manage` (ไม่ใช่ `system.user.manage` — ดู K-ORG-01)
- [ ] **งานผู้ดูแล:** หลัง deploy รัน `personnel:match-positions --dry-run` แล้วบันทึกจริง และเลือกตำแหน่งให้คนที่จับคู่ไม่ได้
- [ ] ปิดช่อง K-ORG-01: ฟอร์มแก้ไขผู้ใช้มอบบทบาท/สิทธิ์ได้โดยไม่ตรวจ
- [ ] ตรวจตารางเวลาปฏิบัติงานของครูจ้างสอนรายชั่วโมง (ไม่ให้นับสาย/ขาดวันที่ไม่มีสอน)
- [ ] **งานผู้ดูแล:** สร้างฝ่าย/งาน ย้ายหน่วยงาน เพิ่มประเภทบุคลากร + leave_policies ตั้งหัวหน้า และให้ขอบเขต ตามหัวข้อ 8 ของเอกสาร (สำรอง DB ก่อน)

## LEAVE-DELETE-01 — การลบใบลาเป็นสิทธิ์ของ Super Admin เท่านั้น ✅ 2026-10-16

- [x] สิทธิ์ใหม่ `leave.delete` มอบให้ Super Admin เท่านั้น (migration `2026_10_17_120000`) · งานบุคลากรแก้ข้อมูลการลาด้วยการยกเลิกวันลา
- [x] ผู้ยื่นยังลบใบของตนเองที่รออนุมัติได้ภายในจำนวนวันที่ตั้งค่า · บันทึก audit ทุกครั้งที่ลบ · ปุ่ม "ลบ" แสดงเฉพาะผู้มีสิทธิ์

## DELEGATION-01 — รักษาราชการแทน / ปฏิบัติราชการแทน ✅ 2026-10-16

ตามมติเจ้าของระบบ — `docs/DECISION-LOG.md` ADR-DELEGATION-01

- [x] ทะเบียนการปฏิบัติหน้าที่แทน (ตั้งค่าระบบ → การปฏิบัติหน้าที่แทน): บันทึกตามคำสั่ง · รับรองคำขอ · แก้ไข · ยกเลิก (ไม่ลบ) · audit ทุกครั้ง
- [x] ผู้ใช้ส่งคำขอจากหน้าโปรไฟล์ → รอรับรอง · ห้ามมอบให้ตนเอง
- [x] ผู้ทำหน้าที่แทนลงนาม → snapshot ประเภท/ตำแหน่ง/คำสั่ง · แบบฟอร์มพิมพ์ "ปฏิบัติราชการแทน …" ใต้ตำแหน่ง (ทุกแบบฟอร์มยังพอดี A4 หน้าเดียว)
- [x] ผู้ดูแลระบบอนุมัติในนามตนเองไม่ได้ — บันทึกแทนผู้มีอำนาจที่ลงนามในเอกสารจริงเท่านั้น (ทั้งใบลาและคำขอยกเลิก)
- [x] หน้ารออนุมัติแสดงฐานะการลงนาม · ผู้ทำหน้าที่แทนได้รับแจ้งเตือน · ลายเซ็นที่วาดตอนอนุมัติใบลาถูกบันทึกแล้ว
- [ ] **งานผู้ดูแล:** สร้างแบบฟอร์ม Visual Designer ใหม่ (`php artisan document:generate-leave-forms --publish`) เพื่อให้มีช่อง "ปฏิบัติราชการแทน" บนแบบฟอร์มที่ใช้งานอยู่
- [ ] **งานบุคลากร:** ตรวจรายการมอบหมายเดิมในทะเบียน (ระบบตั้งเป็น "ปฏิบัติราชการแทน" ที่รับรองแล้ว) เติมเลขที่คำสั่งหรือยกเลิกรายการที่ไม่มีคำสั่ง
- [x] ใบลาที่ฝ่ายบุคคลบันทึกแทน: เลือกผู้ลงนามจริงทุกขั้น แบบฟอร์มพิมพ์ชื่อผู้มีอำนาจ ไม่ใช่ชื่อเจ้าหน้าที่
- [x] สิทธิ์ "จัดการวันลา (HR)" มีในระบบจริงแล้วและมอบให้บทบาท HR Head (migration `2026_10_16_110000`) และ Super Admin (migration `2026_10_16_120000`)
- [ ] **งานบุคลากร:** ส่งข้อมูลคำสั่งแต่งตั้งรักษาราชการแทน/ปฏิบัติราชการแทนที่มีอยู่จริง เพื่อบันทึกลงทะเบียน

## DOC-DESIGNER-01 — ออกแบบแบบฟอร์มใบลารายประเภทด้วย Visual Designer ✅ 2026-10-15

- [x] แท็บ "แบบฟอร์มใบลา" ในศูนย์จัดการเอกสาร: สร้าง/ออกแบบ/สถานะเผยแพร่/สวิตช์ใช้งานรายประเภทการลา
- [x] สร้างแบบฟอร์มเปล่า + วางฟิลด์ครบอัตโนมัติจากแบบฟอร์มในระบบทั้ง 9 แบบ (ไม่มีชื่อหน่วยงานติดพื้นหลัง)
- [x] Field Dictionary ครบ: วันที่แยกส่วน ผู้ลงนามตามบทบาท สถิติ ยอดวันลาพักผ่อน ช่องเฉพาะแบบฟอร์มของทุกประเภท
- [x] ใบลาใหม่ pin เวอร์ชัน PDF ที่เปิดใช้ · ใบเดิมคงแบบฟอร์มเดิม · สวิตช์ฉุกเฉิน DOCUMENT_ENGINE_ENABLED
- [x] แก้ renderer ล้มเมื่อมีช่องติ๊ก
- [ ] **งานผู้ดูแล (production):** `php artisan document:install-fonts` (ถ้ายังไม่เคย) → สร้างแบบฟอร์มจากหน้า "แบบฟอร์มใบลา" หรือ `php artisan document:generate-leave-forms` → ตรวจในหน้าออกแบบ → เผยแพร่ → เปิดสวิตช์รายประเภท
- [x] แบบฟอร์มลาตรวจเลือก/เตรียมพล · องค์การระหว่างประเทศ · ฟื้นฟูสมรรถภาพ (LEAVE-FORMS-02) — ครบทุกประเภทการลาแล้ว

## PRODUCTION PHASE

- [x] PROD-00 Architecture Freeze
- [ ] PROD-01 Full System Audit
- [ ] PROD-02 Security Audit
- [ ] PROD-03 Database & Data Integrity
- [ ] PROD-04 Performance & Load Test
- [ ] PROD-05 Backup / Restore / DR
- [ ] PROD-06 Observability & Operations
- [ ] PROD-07 UAT Preparation
- [ ] PROD-08 UAT & Bug Fix
- [ ] PROD-09 Production Deployment
- [ ] PROD-10 Post-Deployment Verification


## LEAVE-FORM-01 — พิมพ์ใบลา / ขอยกเลิก / แบบฟอร์มราชการ ✅ เสร็จ 2026-08-19

- [x] แก้พิมพ์ใบลาไม่ได้ (429) — แยกถัง rate limit เป็น named limiter รายกลุ่ม
- [x] แก้ปุ่ม "ขอยกเลิก" ใบลาที่อนุมัติแล้วไม่ขึ้นเมื่อคำขอรอบก่อนถูกปฏิเสธ + กันยื่นซ้ำทับคำขอที่ค้าง
- [x] แสดงสาเหตุจริงเมื่อดาวน์โหลดเอกสารไม่สำเร็จ (อ่าน error body ที่เป็น Blob)
- [x] ผูกประเภทการลา ↔ แบบฟอร์ม เป็นค่าตั้งค่าได้ (`leave_types.document_template_code`)
- [x] ชุดตัวแปรกลางของแบบฟอร์ม (`LeaveDocumentFields`) + รายการตัวแปรและพรีวิว PDF ในหน้าผู้ดูแล
- [x] ช่องกรอกเฉพาะแบบฟอร์มต่อประเภทการลา (`leave_types.extra_fields` → `leaves.meta_data.extra_fields`)
- [x] เขียนแบบฟอร์มราชการใหม่ 5 ฉบับให้ข้อมูลลงตรงช่อง และดึงชื่อสถานศึกษา/ผู้บริหารจากหน้าตั้งค่าระบบ
- [ ] กรอกข้อมูลสถานศึกษาในหน้าตั้งค่าระบบ (ชื่อสถานศึกษา / สังกัด / ตำแหน่งผู้อำนวยการ) — ต้องทำโดยผู้ดูแลระบบ

## DOC-ENGINE — Universal Document & Report Engine

- [x] DOC-ENGINE-00 Assessment ระบบเดิม — **PASS 2026-08-19** · `docs/checkpoints/DOC-ENGINE-00-ASSESSMENT.md`
- [x] DOC-ENGINE-01 Core Database + Models — **PASS 2026-08-20** · migration additive ล้วน + Field Dictionary + Data Source + Binding Rules
- [x] DOC-ENGINE-02 PDF Renderer + Field Mapping — **PASS 2026-08-20** · FPDI overlay + ฟอนต์ไทยฝังในระบบ + print calibration + feature flag
- [x] DOC-ENGINE-03 Snapshot + Final Document + Versioning — **PASS 2026-08-20** · sha256 + revision + IMMUTABLE + เอกสารย้อนหลังอ่านจากไฟล์ที่เก็บไว้
- [x] DOC-ENGINE-04 Admin PDF Designer — **PASS 2026-08-20** · ลาก/ปรับขนาดฟิลด์บนแบบฟอร์มจริง พรีวิว เผยแพร่ จัดการเวอร์ชัน
- [~] DOC-ENGINE-05 ย้าย Leave Type อื่นเข้า Engine — วางชุดฟิลด์ตั้งต้นให้ครบ 5 แบบฟอร์มแล้ว (`document:scaffold-fields`) เหลือจัดตำแหน่งจริงในหน้าออกแบบ (งานผู้ดูแล)
- [x] DOC-ENGINE-06 Report Engine — **PASS 2026-08-20** · grouping/subtotal/grand total/aggregate + PDF/Excel/CSV
- [x] DOC-ENGINE-07 Report Designer — **PASS 2026-08-20** · เลือกแหล่งข้อมูล/คอลัมน์/ผลรวม/จัดกลุ่ม และทดลองประมวลผลได้จากหน้าจอ
- [x] DOC-ENGINE-08 Security / Audit / Test — **PASS 2026-08-20** · สิทธิ์ document.*, audit ครบทุกการกระทำ, 33 tests เฉพาะ engine + regression 583 tests
- [x] DOC-ENGINE-REPORT รายงานปิดเฟส — **PASS 2026-08-20** · `docs/checkpoints/DOC-ENGINE-REPORT.md`
- [ ] วางตำแหน่งฟิลด์บนแบบฟอร์มราชการ 5 ฉบับที่นำเข้าแล้ว แล้วกดเผยแพร่ (ต้องทำโดยผู้ดูแลระบบผ่านหน้า PDF Designer)
- [x] กรอกข้อมูลสถานศึกษาในหน้าตั้งค่าระบบ — **เสร็จ 2026-08-20 (ผู้ดูแลกรอกแล้ว)**
