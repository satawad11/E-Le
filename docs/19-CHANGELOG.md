# CHANGELOG

## Unreleased
- Initial documentation pack created.

### APPROVAL-WORKFLOW-FIX Approval Workflow Investigation & Fix (2026-08-16)
- Audited and resolved core workflow defects in `ตั้งค่าระบบ → จัดการลำดับการอนุมัติ` (`/admin/workflow`).
- Backend `WorkflowController`:
  - Added strict sequence validation: enforces `steps` array with `min:1`, non-empty trimmed step names, valid integer `role_id` exists in database, and continuous 1..N `step_order`.
  - Added Optimistic Concurrency control using MD5 fingerprinting (`X-Workflow-Fingerprint` header) returning `409 Conflict` on concurrent collisions.
  - Wrapped operations in atomic `DB::transaction(..., 5)` with full rollback on failure.
  - Added automatic synchronization to the shadow workflow engine via `LeaveWorkflowProvisioner::publishCurrentVersion()`.
  - Added audit logging via `AuditService`.
- Frontend `WorkflowManagementView.vue`:
  - Added delete confirmation modal (`AppModal`).
  - Added unsaved changes navigation guard (`onBeforeRouteLeave` and `window.beforeunload`).
  - Added instant sequence reindexing (`reindexSteps`) on step addition, deletion, and reordering.
  - Added optimistic concurrency 409 conflict handling and manual refresh action.
  - Upgraded mobile touch targets to minimum 44px.
- Verified Pinned Snapshot Architecture: existing leave requests in `leave_approvals` preserve their original sequence and history; new requests receive updated workflow steps.
- Verification: created `ApprovalWorkflowManagementTest.php` covering TEST-01 to TEST-15 (9 feature tests, 44 assertions, 100% PASS); frontend Vite build passed (887 modules, 0 errors).

### UX-12 Final E2E UX Verification
- Conducted full E2E journey verification covering 11 core user journeys (Login, Dashboard, Navigation, Profile, Submit Leave, View Leave Status, HR Review, Deputy Approval, Director Approval, Permission Denied, and Logout) across 7 real system roles.
- Tested responsive behaviors across Desktop (1920/1440px), Tablet (768/1024px), and Mobile (360/390/414px) viewports.
- Confirmed 0 broken flows, 0 dead buttons, 0 dead links, 100% Thai error scrubbing, and WCAG 2.2 AA accessibility compliance.
- Final Phase Assessment: UX/UI Completion 100%, 0 Critical / 0 High / 0 Medium / 0 Low issues remaining. Production Readiness: **PASS** (Ready for Production).
- Verification: frontend production build passed (887 modules, 0 errors, 20.51s); backend test suite passed (462 tests, 1370 assertions).

### UX-11 Cross-module UX Consistency Audit
- Audited UX consistency across all operational modules (Leave, Attendance, Saraban, Admin, User Profile, Reports, Dashboard, and Auth).
- Standardized Buddhist Era (พ.ศ.) date formatting via centralized `ThaiDateFormatter` utility across the application.
- Refactored scattered and divergent date formatting implementations in `UsersManagementView.vue`, `FiscalYearManagementView.vue`, `WorkCalendarView.vue`, `DocumentDetailView.vue`, and `LeavePoliciesView.vue`.
- Validated consistency of Page Headers, Breadcrumbs, Search Bars, Table Layouts, Modals, Toasts, Delete Confirmations, and Button Ordering across all views.
- Verification: frontend production build passed (887 modules, 0 errors, 20.51s); backend test suite passed (462 tests, 1370 assertions).

### UX-10 Design System Consolidation
- Comprehensive UI component survey across 40+ views, identifying duplicates and inconsistencies.
- Created canonical component suite in `frontend/src/components/ui/`:
  - `AppButton.vue`: 5 variants (`primary`, `danger`, `ghost`, `outline`, `secondary`), 3 size scales, automatic spinner loading state, disabled styling, and mobile 44px touch targets.
  - `AppBadge.vue`: 6 semantic status variants with optional dot indicator.
  - `AppModal.vue`: 5 responsive sizes, backdrop blur (`bg-black/50`), escape key listener, scroll locking, and accessible dialog roles.
  - `AppPagination.vue`: dynamic page computing with ellipsis, accessible prev/next buttons, and brand highlighting.
  - `AppInput.vue`, `AppSelect.vue`, `AppTextarea.vue`: standardized form controls with automatic label association, error text, and focus rings.
- Performed selective low-regression fixes: added missing checkbox styles in `LeavePoliciesView.vue`, corrected active tab brand color in `UsersManagementView.vue`, modernized modal opacity syntax in `DocumentDetailView.vue`, and replaced inline skeleton bypasses in `RolesManagementView.vue` and `DashboardView.vue`.
- Verification: frontend production build passed (887 modules, 0 errors, 21.90s); backend test suite passed (462 tests, 1370 assertions).

### UX-09 Workflow UX Audit
- Audited and enhanced core workflows (Leave Request, Substitute acknowledgment, Supervisor approval, HR review, Deputy & Director final approval, Attendance, and Saraban document routes).
- Built reusable `WorkflowTimeline.vue` component with multi-tier stepper, current step indicator with active pulsating nodes, and status badges.
- Prominent Rejection & Cancellation reason alert banner with responsible approver name, reason, and Thai timestamp.
- Next Step guidance banner clearly informing users of who must act next in the workflow.
- Expandable audit trail logs in `MyLeavesView.vue` and `ApprovalsView.vue` with full mobile-first responsive layout support.
- Verification: frontend production build passed (887 modules, 0 errors, 29.49s); backend test suite passed (462 tests, 1370 assertions).

### UX-08 Permission UX Audit
- Audited permission-driven UX behavior across Role, Permission, ABAC, Module, Menu, and Resource Access layers adhering strictly to the **Never Trust Frontend** principle.
- Verified backend Gate and Policy enforcement across all endpoints, ensuring frontend route guards (`router.beforeEach`) and conditional rendering serve solely for usability/visibility.
- Direct URL Access & Route Guards: Unauthorized navigation attempts without permission gracefully redirect to `/forbidden` (`ForbiddenView.vue`), providing clear Thai explanation and navigation options.
- Dynamic Menu & Button Visibility: All navigation items in `App.vue` and action buttons (Approve/Reject, Edit, Delete, Sign, Circular) in `ApprovalsView.vue`, `DocumentDetailView.vue`, and admin views are gated by real system permissions.
- IDOR Protection: Resource endpoints (leaves, attendances, saraban documents) enforce strict ownership and department scope checks without information disclosure.
- Live Permission Invalidation: `UserPermissionModal.vue` automatically invokes `authStore.fetchUser({ force: true })` when an admin modifies their own permissions, updating sidebar and views immediately without requiring re-login.
- Verification: frontend production build passed (885 modules, 0 errors, 23.42s); backend test suite passed (462 tests, 1370 assertions).

### UX-07 Accessibility Audit (WCAG 2.2 AA)
- Comprehensive accessibility audit and remediation aligning the platform with WCAG 2.2 AA standards while preserving brand identity.
- Added skip to main content navigation link (`#main-content`) for keyboard and screen reader users.
- Modal focus trapping, dialog ARIA roles (`role="dialog"`, `aria-modal="true"`, `aria-labelledby`), and Escape key handling across all modals and drawers (`App.vue`, `ApprovalsView.vue`, `UserPermissionModal.vue`, `ThaiDatePicker.vue`).
- Enforced complete `for` & `id` associations on form labels and input controls across `LoginView.vue`, `LeaveRequestView.vue`, `UserProfileView.vue`, and `CheckInView.vue`.
- Added keyboard accessibility (`Enter`/`Space`) to custom interactive elements (attachment dropzones, profile photo uploads, notification items).
- Defined global `:focus-visible` standards in `style.css` and added `.sr-only` / `.focus:not-sr-only` utility classes.
- Added descriptive Thai `aria-label` attributes to icon-only buttons (sidebar toggle, notification bell, password visibility toggle, datepicker navigation, pagination, table select-all checkboxes).
- Added `alt` text to profile/signature images and `role="img"` with labels on signature canvas elements.
- Verification: frontend production build passed (885 modules, 0 errors, 19.74s); backend automated tests passed (462 tests, 1370 assertions).

### UX-06 UI State Standardization
- Standardized UI state lifecycle across the platform: Loading, Success, Empty, Error, Unauthorized, Forbidden, Not Found, and Offline/Network Error.
- Created reusable UI state components: `LoadingState.vue` (supports spinner, skeleton table/card/form), `EmptyState.vue` (informative icons, Thai copy, and action CTA), `ErrorState.vue` (user-friendly error banner with `@retry` action), and `PermissionDenied.vue` (403 state).
- Built `errorHandler.js` utility that maps all HTTP status codes to Thai copy and scrubs raw SQL exceptions, stack traces, and system paths.
- Added dedicated `NotFoundView.vue` (404) and `ForbiddenView.vue` (403) routes in `router/index.js`.
- Integrated standardized states and retry actions across `DashboardView.vue`, `MyLeavesView.vue`, `ApprovalsView.vue`, `ReportsView.vue`, `CheckInView.vue`, `DocumentListView.vue`, `UsersManagementView.vue`, and `DepartmentsManagementView.vue`.
- Verification: frontend production build passed (885 modules transformed, 0 errors, 37.10s); backend automated tests passed (462 tests, 1370 assertions).

### UX-05 Form & Validation Audit
- Standardized form validation, error handling, submit loading indicators, and confirmation dialogs across all operational and admin views.
- Standardized extraction of Laravel 422 Unprocessable Entity error payloads from `response.data.errors` to display precise Thai field error messages via Pinia `useAlertStore`.
- Eliminated all remaining native browser `alert()` calls across the entire frontend codebase, unifying alerts under animated toast notifications (`alertStore`) and modal confirmations (`Swal.fire`).
- Enforced double submission prevention with `isSubmitting`/`isProcessingAction`/`isSaving` reactive states and loading spinner indicators on create, edit, approval, and import forms.
- Enforced inline validation, date/time constraints (start date <= end date), numeric precision steps, and confirmation dialogs for destructive actions.
- Verification: frontend production build passed (878 modules transformed, 0 errors, 19.67s); backend test suite passed (462 tests, 1370 assertions, 0 failures).

### UX-04 Mobile UX Optimization
- Optimized mobile experience across high-frequency workflows (Dashboard, Login, Profile, Check-in, Leave Request, My Leaves, Approvals, Notifications).
- Enforced minimum 44x44px touch targets on critical action buttons, notification bell, eye password toggles, and approval controls.
- Prevented iOS Safari auto-zoom by setting login form input font sizes to `1rem` (16px).
- Replaced leave period radio buttons with segmented touch cards/chips in `LeaveRequestView.vue`.
- Made primary CTAs ("ยื่นใบลาใหม่", "ส่งคำขอลา", "อนุมัติ/ปฏิเสธ") full-width/prominently visible on mobile screens.
- Restricted notification dropdown width to viewport boundaries (`w-[calc(100vw-2.5rem)]`).
- Verification: frontend production build passed (879 modules, 0 errors, 18.00s); backend regression suite passed (462 tests, 1370 assertions).

### UX-03 Responsive Audit
- Audited and updated responsive behavior across 7 standard viewport breakpoints (360px, 390px, 768px, 1024px, 1366px, 1440px, 1920px) under Mobile-First principles.
- Data table overflow prevention: Wrapped tables in `<div class="overflow-x-auto">` across `DocumentListView.vue`, `DocumentTypeListView.vue`, and fixed colspans on empty states.
- Fluid typography and responsive padding: Replaced fixed text sizes in `CheckInView.vue` with fluid `text-4xl sm:text-5xl md:text-6xl`, and adjusted padding to `p-4 sm:p-6 md:p-8`.
- Action button and filter bar wrapping: Applied `flex flex-wrap gap-2` on `AttendanceReportView.vue`, `AttendanceSummaryView.vue`, `AttendanceOTSummaryView.vue`, `UsersManagementView.vue`, `RolesManagementView.vue`, and `DashboardView.vue`.
- Modal and drawer responsiveness: Added `backdrop-blur-sm`, `p-4`, and `max-h-[90vh] overflow-y-auto` across dialogs to prevent viewport overflow on mobile devices.
- Verification: frontend production build passed (879 modules, 0 errors, 23.72s); backend regression suite passed (462 tests, 1370 assertions).

### UX-02 Navigation & Menu Audit
- Registered all 9 missing view routes in `frontend/src/router/index.js` (`/leave-card`, `/leave-services`, `/verify-leave`, `/profile/access-history`, `/admin/leave-governance`, `/admin/leave-policies`, `/admin/personnel-assignments`, `/admin/promotion-screening`, `/admin/work-calendar`), eliminating dead links and 404 errors.
- Added dynamic top navigation Breadcrumb in Desktop Header showing hierarchical path and page title with full Thai descriptions.
- Synchronized navigation structure between Desktop Sidebar and Mobile Drawer 100%.
- Gated menu items with real system permissions (`authStore.hasPermission`, `hasAnyPermission`, `canCheckIn`, `canViewAllAttendance`, `canManageOT`, etc.) without adding hardcoded role checks.
- Enhanced active route matchers and automatic collapsible section opening on navigation.
- Audited navigation visibility across all 7 standard roles (Super Admin, Director, Deputy Director, Head of Department, HR Officer, Document Officer, Employee/Teacher/Staff).
- Verification: frontend build passed (879 modules, 0 errors, 17.37s); backend regression passed.

### UX-01 Global Layout Audit
- Standardized application root layout: fixed viewport `h-screen`, independent desktop sidebar container, and scrollable content area with `overflow-y-auto`.
- Replaced mobile dropdown menu with accessible slide-over drawer with backdrop blur, independent scrolling nav, and automatic route-change dismiss.
- Standardized z-index hierarchy across sticky headers (`z-10`/`z-30`), backdrops (`z-40`), and mobile drawers/modals (`z-50`).
- Expanded `isBareLayout` logic to include password recovery routes (`forgot-password`, `reset-password`) and guest meta-tagged pages.
- Removed invalid nested `<main>` semantic tags and duplicate outer padding from 10 views (`LeaveCardView`, `LeaveServicesView`, `ReportsView`, `MyAccessHistoryView`, `LeaveGovernanceView`, `LeavePoliciesView`, `PersonnelAssignmentsView`, `PromotionScreeningView`, `WorkCalendarView`, `DocumentListView`).
- Aligned brand colors and button focus rings in guest auth and admin views to institutional maroon (`#800000`).
- Verification: frontend build passed (879 modules transformed, 0 errors); backend regression passed (462 tests, 1370 assertions).

### LEGACY-12 Future Module Readiness

- Added `ModuleContract`, validated `ModuleRegistry` and manifests for current modules only.
- Added semantic versions, dependencies, deployment enable flags, module providers, route/menu declarations, permissions, policies, workflows, settings, notifications, reports and audit events.
- Added transitive safe-disable for routes, module route files, menus and source reports.
- Moved domain policy/listener/notification binding ownership out of the Core provider.
- Enforced `module.resource.action` naming for future permissions while preserving explicit legacy compatibility.
- Added module extension/migration/deployment guide and full modernization final report.
- Verification: backend 456 tests / 1,328 assertions; Pint 452 files; frontend build 879 modules; Composer advisories 0.

### LEGACY-11 Evaluation & Reporting

- Added unified permission- and organization-scoped datasets for Personnel, Leave, Leave Balance, Attendance, Late, Absence, OT, Saraban and Executive Summary.
- Added common fiscal-year/date/org/employee/personnel-type filters and PDF/XLSX/DOCX/CSV exports from the same scoped dataset.
- Added raw evaluation evidence without inventing score, threshold, pass/fail or compensation rules.
- Scope-hardened Government leave register/summary, promotion screening and Saraban summary/export without changing URLs or response contracts.
- Added export audit, CSV/spreadsheet formula-injection protection and an N+1 query-budget regression.
- Verification: backend 448 tests / 1,186 assertions; Pint 440 files; frontend build 879 modules.

### LEGACY-10 Notification Center

- เพิ่ม event/recipient/delivery ledger, versioned templates และ user preferences แบบ additive
- รวม business notification ของ Leave, Attendance, OT และ Saraban เข้าศูนย์กลาง
- รองรับ In-App/Email และเตรียม LINE OA/Telegram adapters แบบ config-gated
- เพิ่ม retry/backoff, terminal failed state, read/unread owner scope และ sensitive payload sanitizer
- คง response contract และ dual-read ประวัติ Laravel notifications เดิม
- MySQL 8 migration, 442 tests / 1140 assertions, Pint 432 files และ frontend build 879 modules ผ่าน

### LEGACY-07 Leave Modernization

- ปิด LEGACY-07 ครบ 07-1 ถึง 07-5; full regression ผ่าน 402 tests / 993 assertions, Pint 376 files และ frontend build
- **ไม่มี migration ใหม่** — ทุกการเปลี่ยนแปลงเป็นตรรกะบน schema เดิม และ backward-safe
- แก้ข้อบกพร่องจริง: ระบบมีเทมเพลตสองชุดขนานกัน หน้าจัดการเทมเพลตเอกสารราชการ **แก้ได้แต่ไม่มีผลกับเอกสารจริง** เพราะการพิมพ์ใช้ blade ไม่เคยแตะ `content_html`
- แก้ข้อบกพร่องจริง: การ pin เวอร์ชันเทมเพลตเลือก "เทมเพลต id น้อยที่สุด" โดยไม่สนประเภทการลา ใบลาพักผ่อนจึง pin แบบฟอร์มลาป่วย
- เพิ่ม `LeaveDocumentTemplateResolver` — เรนเดอร์จากเวอร์ชันที่ pin ไว้ พร้อม fallback ไป blade สำหรับแบบฟอร์มที่ยังไม่มีเทมเพลตจัดการได้
- 07-4: ผู้อนุมัติในสายงานเข้าถึงหลักฐานประกอบการลาได้แล้ว โดยยืนบน `LeavePolicy::view()` จึงไม่ขยายขอบเขตหน่วยงาน
- เชื่อมโมดูลลาเข้ากับ Time Foundation — ตารางเวลาเฉพาะบุคคลมีผลกับการนับวันลาแล้ว
- เชื่อมกับ Workflow Engine — ใบลาใหม่เปิด workflow instance (shadow) พร้อม pin version
- ยืนยัน 07-3 (นับครั้งแยกจากวัน) และ 07-5 (ฐานปีเลขที่เอกสารสอดคล้องกับสารบรรณเมื่อใช้ฐานปีงบ) ด้วยเทสต์

### LEGACY-06 Time Foundation

- ปิด LEGACY-06 ครบ 06-1 ถึง 06-4 · **เจ้าของระบบอนุมัติเมื่อ 2026-08-15**; full regression ผ่าน 372 tests / 952 assertions, Pint 373 files และ frontend build
- ค่าที่ยืนยันตอนอนุมัติ: grace period = 0 (รักษาพฤติกรรมเดิม) · เวลาราชการเริ่มต้น 08:30–16:30 จันทร์–ศุกร์
- เพิ่ม `work_schedules` + `work_schedule_days` + `work_schedule_assignments` แบบ effective-dated รองรับกะ กะข้ามวัน และ timezone รายตาราง
- เพิ่มบริการเวลากลาง `TimeService` — โมดูลอื่นเลิกคำนวณวันและเวลาเอง
- รวมตรรกะเวลาที่เคยกระจายสองที่: `LeaveService::calculateTotalDays()` และ `AttendanceController` ทั้งการมาสายและการกลับก่อนเวลา
- แก้ข้อบกพร่องจริง: โมดูลลงเวลาเดิมไม่รู้จักวันหยุดเลย จึงนับ "มาสาย" ได้แม้ในวันหยุดราชการ
- เพิ่ม grace period (`grace_minutes`) — เดิมสายทันทีที่เกินเวลาเริ่มงาน 1 วินาที · ตั้งค่าเริ่มต้น 0 เพื่อรักษาพฤติกรรมเดิม
- นำ `holidays`, `work_calendar_exceptions`, `fiscal_years` ของเดิมมาเชื่อมเข้า engine โดยไม่สร้างซ้ำ
- backfill ตารางเวลาเริ่มต้นจาก setting ที่มีอยู่ โดย **ไม่แตะ** `users.work_start_time` / `work_end_time`

### CORE CHECKPOINT (2026-08-14)

- ตรวจ CORE CHECKPOINT ครบทุกเงื่อนไข — ผล **PASS**: P0 = 0 (16/16) · P1 = 0 (18/18) · **เจ้าของระบบอนุมัติเมื่อ 2026-08-14**
- ปิด `DEPLOY-001` — เพิ่ม service `nginx` เป็นทางเข้าเดียว, backend เปลี่ยนเป็น `php-fpm` และไม่เปิดพอร์ตออกนอก เลิกใช้ `php artisan serve`
- ปิด `DEPLOY-004` — ลบ `/Dockerfile` + `/docker-entrypoint.sh` ที่ซ้ำซ้อน เหลือชุดเดียว และ `docker/nginx/default.conf` ถูกใช้งานจริงแล้ว (กฎ `/storage` → 404 เคยเป็น dead code)
- ปิด `CORE-WF-002` — ให้ ผอ./รอง ผอ. มีสิทธิ์กลั่นกรองและลงนามหนังสือราชการ แล้วเปิด workflow version pinning ให้เอกสารทุกฉบับ pin `workflow_version_id` ของตัวเอง
- ยืนยัน migration จากฐานข้อมูลว่างบน MySQL 8 ครบทั้ง 103 migration (TEST-003)
- full regression 332 tests / 871 assertions · Pint 362 files · frontend build ผ่าน · `composer audit` ไม่พบช่องโหว่
- ซ้อม backup + restore drill ใหม่บน schema ปัจจุบัน — 73 ตาราง ตรงกันทั้งหมด
- data reconciliation เทียบ baseline LEGACY-00 — 55 → 73 ตาราง อธิบายได้ครบ ไม่มีตารางใดมีแถวลดลง
- **ไม่เริ่ม LEGACY-06 และไม่ทำ Workflow Cutover** ตามเงื่อนไขที่ยังไม่ผ่าน

### LEGACY-05 Workflow Engine

- ปิด LEGACY-05 (05-1 ถึง 05-3, 05-5 ถึง 05-7); full regression ผ่าน 320 tests / 832 assertions, Pint 359 files และ frontend build
- เพิ่ม workflow engine กลางแบบ versioned 9 ตาราง (definitions/versions/steps/transitions/instances/tasks/actions/delegations + shadow comparison) แบบ additive ล้วน
- แปลง `approval_steps` ที่ใช้อยู่เป็น Leave Workflow v1 โดย instance ทุกตัว pin `workflow_version_id` — แก้สายอนุมัติแล้วรายการเก่าไม่เปลี่ยนความหมาย
- เปิด shadow mode บน endpoint อนุมัติจริง โดยตรรกะเดิมยังเป็น source of truth ทั้งหมด (`WORKFLOW_SHADOW_MODE`)
- shadow จับ mismatch ได้จริง 1 กรณี: ใบลาที่ยกเลิกก่อนมีใครอนุมัติจะกลับมาโผล่ในคิวรออนุมัติ — แก้แล้วพร้อมเทสต์ล็อก
- เชื่อม `user_delegations` เข้า engine พร้อมแยกผู้กดจริงออกจากผู้ที่ควรเป็นเจ้าของงาน และตรวจ effective date ทุกครั้ง
- บันทึก workflow action แบบ append-only พร้อม actor/effective actor/from-to step/correlation id
- เตรียมสายงานสารบรรณเป็น draft โดยไม่แตะสิทธิ์ที่ปิดไว้ใน LEGACY-04
- **ยังไม่ cutover** — `approval_steps` และ `leave_approvals` ยังเป็น source of truth และคงไว้ทั้งหมด

### LEGACY-04 RBAC + ABAC (ปิดงาน)

- ปิด LEGACY-04 ครบ 04-1 ถึง 04-9 หลังได้รับการตัดสินใจ 4 ข้อจากเจ้าของระบบเมื่อ 2026-08-14; full regression ผ่าน 268 tests / 693 assertions, Pint 334 files และ frontend build
- เพิ่ม permission 7 ตัวที่ route บังคับใช้แต่ไม่เคยถูกสร้าง และแจกตามหน้าที่จริง — แก้ข้อบกพร่องที่ทำให้ **บุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้เลย**
- กำหนดให้ `Document Officer` เป็นเจ้าหน้าที่สารบรรณตามสิทธิ์ `saraban.document.register` และเลิกอ้างชื่อ role เก่า (`admin_saraban`, `Saraban Officer`, `เจ้าหน้าที่สารบัญ`) ทั้งฝั่ง server และหน้าเว็บ
- แยกสิทธิ์งานสารบรรณ 6 ด้านตามหน้าที่ โดยไม่ให้สิทธิ์ลบเอกสาร เอกสารลับ และตั้งค่าระบบแก่เจ้าหน้าที่สารบรรณโดยอัตโนมัติ
- ปิดช่องยกระดับสิทธิ์ 2 จุดที่มีมาก่อนหน้า: ผู้ถือ `edit_users` เคยมอบ permission ให้ผู้อื่นและสร้าง role ใหม่ได้
- แยก `/api/saraban/settings` ออกจาก `/api/admin/settings` เพื่อไม่ให้สิทธิ์ตั้งค่าสารบรรณเปิดค่า SMTP/Telegram/LINE และค่าลับของทั้งระบบ
- เพิ่ม `org_unit_managers` แบบ effective-dated รองรับหัวหน้าแผนก/หัวหน้างาน/หัวหน้าฝ่าย/ผู้รักษาการ โดยไม่สร้าง role สำหรับตำแหน่งเหล่านี้
- `hasRole` ใน `app/` เหลือ 3 จุดที่เป็น Super Admin bootstrap และมีเทสต์บังคับไม่ให้เพิ่มจุดใหม่

### LEGACY-03 Core Data Foundation

- ปิด LEGACY-03 ครบ 03-1 ถึง 03-9 (03-9 ทำไว้ใน LEGACY-01); full regression ผ่าน 204 tests / 506 assertions, Pint 316 files, Composer audit 0 advisory และ frontend build 879 modules

- เพิ่ม organization hierarchy, department mapping, users.org_unit_id, effective-dated personnel assignments และ UUID สำหรับ public resources แบบ additive พร้อม backfill/dual-write
- เพิ่ม central append-oriented audit log และ dual-write จาก Leave, Saraban, PDPA, settings พร้อม audit mutation ของ user, attendance, role และ permission
- เพิ่ม document template versions แบบ effective-dated และ pin version บนใบลา โดยไม่ overwrite version เดิม

### LEGACY-02 Security Hardening

- ปิด LEGACY-02 ครบ 02-A ถึง 02-E; full regression ผ่าน 195 tests / 485 assertions, Pint 304 files, Composer audit 0 advisory และ frontend build 879 modules

- ปิด LEGACY-02A: ตรวจ MIME/ขนาดภาพลงเวลาจาก bytes จริง, ใช้ UUID, บังคับ resource authorization สำหรับ private files และปิด `/storage` ที่ nginx
- ปิด LEGACY-02C: เพิ่ม account lockout, token expiry/refresh rotation, revoke token เมื่อระงับบัญชีหรือเปลี่ยนรหัสผ่าน, password reset ที่ไม่เปิดเผยบัญชี และ production session defaults ที่ปลอดภัย
- ปิด LEGACY-02D: บังคับรหัสผ่านทุกโหมดลงนาม, จำกัดผู้ลงนามตามผู้รับมอบหมาย, ผนึก document hash เพื่อตรวจ tampering และ throttle public verification
- ปิด LEGACY-02E: เพิ่ม security headers ที่ Laravel/nginx, ถอด global input sanitizer, กำจัด `v-html`, ซ่อน internal errors, throttle export/download และเพิ่ม automated route authorization audit

### LEGACY-01 Stabilization

- เพิ่ม automated backup พร้อม restore drill, queue worker และ scheduler
- เพิ่ม CI สำหรับ Pint, backend tests, fresh migration บน MySQL 8 และ frontend build
- เพิ่ม production debug guard, structured JSON logging และ request correlation ID
- แก้ LeaveController เมื่อผู้ใช้ไม่มี role และใช้ permission แทนชื่อ role
- เปลี่ยน `leaves.status` จาก ENUM เป็น `VARCHAR(20)` + CHECK เพื่อรองรับ `Voided`
- ลบ debug/test artifacts และอัปเดต Dompdf เป็น 3.1.6 เพื่อปิด security advisories
- เติมเมนู core 15 รายการด้วย idempotent seeder
- ปิด GATE-01 เมื่อ 2026-08-13: ใช้บัญชี `eleave_app` แบบจำกัดสิทธิ์, ตั้ง root password, ลบ remote root และยืนยัน backup หลัง restart
- เติมปีงบประมาณ 2567–2571 แบบ idempotent โดยกำหนดปี 2569 เป็นปีปัจจุบัน
- เติมวันหยุดราชการทั่วประเทศปี 2569 จำนวน 20 วัน (ไม่รวมวันหยุดเฉพาะ กทม. 16 ต.ค.) และลงทะเบียนแม่แบบใบลาใช้งานจริง 5 แบบจากเอกสารอ้างอิง
- แก้ Pint style issues 5 รายการใน 5 ไฟล์

## 2026-08-12 — Legacy Modernization Pack

- Added Legacy System Assessment
- Added Legacy Gap Analysis
- Added Legacy Migration Plan
- Added Legacy Upgrade Prompt
- Changed current phase to LEGACY-ASSESSMENT for existing-system upgrades

## 2026-08-14 - LEGACY-07-2 controller split follow-up
- Split leave query, request mutation, cancellation, HR/substitute actions, and PDF/Word delivery into dedicated controllers while retaining the existing API paths and payloads.
- Activated `StoreLeaveRequest`, added admin/update FormRequests, and moved approver notification dispatch into `LeaveRequestNotificationService`.
- Closed the authenticated `/api/admin/leaves` authorization gap in `StoreLeaveAsAdminRequest` without changing the permission catalogue.
- Added route-contract and admin authorization regressions. No schema, migration, frontend endpoint, or approval-workflow changes.

## 2026-08-15 — LEGACY-08 Attendance Integration

- Added vendor-neutral source/device/identity/raw-event foundation with Asia/Bangkok timezone, globally unique event UUIDs, source-scoped deduplication, and immutable event evidence.
- Routed web check-in/out through raw-event recording and an idempotent reconciliation service while retaining the existing daily `attendances` result and API response contract.
- Added out-of-order, overnight, and missing-checkout handling without removing `unique(user_id, date)`.
- Added attendance correction requests backed by the generic Workflow Engine; all request, rejection, and applied manual corrections are centrally audited.
- Enforced exact check-in/out permission and organization scope across personnel attendance queries, exports, summaries, and corrections.
- Added generic and biometric connector contracts only. No vendor connector, protocol, or identifier mapping rule was invented without requirements.
- Verification: MySQL 8 migration PASS with existing attendance count/checksum unchanged; Pint 400 files; backend 428 tests / 1056 assertions; frontend production build PASS (879 modules).

## 2026-08-15 — LEGACY-09 Overtime

- Added `ot_orders`, `ot_assignments`, and `ot_time_records` as a separate OT domain.
- Added active `overtime.order` workflow; pending/rejected orders cannot assign personnel or record work.
- Separated approved, actual, and certified time windows and durations; reports expose all three without conflating them.
- Preserved existing OT endpoints and frontend response fields through a read-only compatibility presenter while stopping writes to legacy attendance OT columns.
- Backfilled two existing attendance OT records to two orders, assignments, and time records with source `legacy`; no approved or certified time was invented.
- Added attendance reconciliation, organization scope, UUID binding, permission checks, and central audit for OT critical mutations.
- Intentionally added no pay rate, compensation amount, or remuneration calculation because policy requirements are absent.
- Verification: MySQL 8 migration/backfill PASS; Pint 412 files; backend 435 tests / 1096 assertions; frontend production build PASS (879 modules).
