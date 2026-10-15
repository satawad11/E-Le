# CHANGELOG

All notable changes to the E-la project will be documented in this file.

## [Unreleased]

### [LEAVE-FORMS-02] - 2026-10-15 — Forms for the three remaining leave types
#### Added
- Forms for every leave type in the regulation that had none (they printed on the sick/personal/maternity form):
  - `leave_military` — แบบรายงานลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล (a report to the supervisor, so it has an acknowledgement block instead of approve/reject)
  - `leave_international` — แบบใบลาไปปฏิบัติงานในองค์การระหว่างประเทศ
  - `leave_rehabilitation` — แบบใบลาไปฟื้นฟูสมรรถภาพด้านอาชีพ
- Leave types are matched to these forms by name until a form is chosen on the leave-type page; default form fields for each in `LeaveFormExtraFieldSeeder`; all three can be generated for the Visual Designer.

### [DOC-DESIGNER-01] - 2026-10-15 — Leave forms designed per leave type in the Visual Designer
#### Added
- **Leave forms tab** in the Document Center: one row per leave type (+ the cancellation form) with *Create form*, *Design*, publish status, and a *Use this form* switch. Admins no longer edit `.env` to turn the engine on for a leave type.
- `document:generate-leave-forms` / `POST /admin/document-engine/leave-type-forms/{type}/generate`: builds a `designer_<code>` draft for each of the 9 system forms — a blank A4 background (no organisation or person names baked in) with every field already placed. Positions are measured from dompdf's own layout of the form (marker values for text, one render per checkbox/signature), not estimated.
- `LeaveFormFieldMap`: one field catalogue for the designer — date parts, signer blocks by role (checker / commander / authorizer: name, position, comment, date, signature, approve/reject boxes), statistics cells, vacation balance, previous leave, cancellation fields, and every leave type's own form fields (`extra.<key>`, plus one checkbox per option of select fields). Values come from the same data as the HTML forms.
- The field dictionary re-syncs whenever the designer loads it, so fields added to a leave type appear immediately; legacy HTML-template variables are hidden.
- Designer preview uses realistic sample values instead of field codes.

#### Changed
- `DOCUMENT_ENGINE_ENABLED` now defaults to `true` and acts as an emergency kill switch; per-leave-type use is controlled from the admin page. `DOCUMENT_ENGINE_LEAVE_TYPES` still works as a forced override.
- New leaves of a type whose designed form is enabled pin that PDF version at submission; earlier leaves keep the form they were pinned to.
- HTML forms: name/position/date lines use fixed-width dotted fill boxes; composite lines use `organization_line` / `affiliation_line`; signer dates go through `App\Support\FormDate`; "เคย/ยังไม่เคย" and expense boxes stay unticked when unknown.

#### Fixed
- `PdfOverlayRenderer` crashed on any ticked checkbox (it switched to a Helvetica font file that the engine's font folder does not contain).
- Leaves always pinned the HTML form version, so a published PDF form was never used for new leaves.

#### Removed
- Old "จัดการแม่แบบเอกสาร" page and menu entry — `/admin/document-templates` now redirects to the Document Center ("แบบฟอร์มใบลา" tab). The read-only template API stays for the leave-type settings page.
- `document:import-forms`, `document:scaffold-fields` and the five PDFs in `resources/document-templates` (another college's Word forms with its name baked in and placeholder field positions) — superseded by `document:generate-leave-forms`. Tests now generate their own sample PDF.

#### Tests
- `LeaveFormDesignerTest` (11). `DocumentEngineTest` default-state test updated for the new kill-switch default.

### [TPL-01] - 2026-10-15 — Leave form templates: security, data and layout
#### Security
- **Remote code execution via document templates (TPL-SEC-01).** `LeaveDocumentTemplateResolver::render()` passed admin-editable `document_templates.content_html` to `Blade::render()`, and `POST /api/admin/document-templates/preview` rendered HTML sent in the request body the same way — anyone with the settings permission could run PHP on the server (`@php … @endphp`). HTML leave forms now always render from the code-owned blade files. The HTML template create/update/delete and HTML-preview endpoints were removed; preview is `GET /api/admin/document-templates/{template}/preview` rendering the code view with sample data. Admin form design goes through the Document Engine (field placement on the official PDF).

#### Fixed
- Signature blocks matched approvals by `approver->role_id === step->role_id`, so approvers found by a resolver (department head, direct supervisor, specific user) printed as blank. Blocks now come from the `leave_approvals` snapshot by `step_order` (`App\Services\LeaveApproverBlocks`); pending steps show no date/comment. The cancellation form uses the cancellation chain (falls back to the old action log for cancellations decided before that chain existed).
- Vacation balance on the form read `leave_balances` by calendar year; balances are keyed by fiscal year, so leaves from October–December printed the closed year's balance. The printed fiscal year was the calendar year too.
- "ลามาแล้ว" counted approved leaves taken *after* the leave being printed.
- Sick/personal/maternity form overflowed to a second page (dompdf cannot split a table row); the bottom panel now uses floated columns. All 9 forms fit on one A4 page.
- Requester signature line printed the typed name; it now shows the signature attached at submission, or a blank line to sign by hand (`pdf.partials.requester_signature`).
- Form title "แบบลาป่วย…" → official "แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว".
- Hajj, follow-spouse and study forms rebuilt on the shared field set: position was empty, a statistics table not on the official forms was shown, and the Hajj form had a stray "ถึงวันที่ - มีกำหนด -" line and no birth / service-start dates.
- Official-travel memo layout was scrambled, showed a broken garuda image, hard-coded the college name and four real administrators' names as fallbacks, and hard-coded division names. Rewritten on the shared fields; the opinion section is generated from the configured approval chain.
- Word export no longer falls back to a hard-coded college name.
- Template admin page used plain `axios` without the auth token, so it never loaded; it is now a read-only list (forms, bound leave types, PDF preview, link to the Document Center).

#### Added
- Fields `requester_signature`, `previous_same_type_count`, `duration_years|months|days` in `LeaveDocumentFields`.
- Default form fields for the Hajj, follow-spouse and study leave types in `LeaveFormExtraFieldSeeder` (only for types with none yet).
- Tests `DocumentTemplateSecurityTest` (5), `LeaveFormDataTest` (8).

### [USER-GOV-01] - 2026-10-14 — User account governance
#### Fixed
- `DELETE /api/admin/users/{user}` hard-deleted the account. FK cascades wiped the user's leaves, leave balances, attendances, personnel record and PDPA access logs (and tables with `restrictOnDelete` made it fail with 500). The endpoint now returns 409 and admins suspend accounts instead. The delete button was removed from the users page.
- Changes to roles, direct permissions and personnel profile fields made through the user admin page were not audited. They now write `user.access_changed`, `user.profile_updated` (field names only, never values) and `user.password_reset_by_admin`. Column changes on `users` (incl. `is_active`) were already audited by the model hook.
- Admins could suspend their own account (now 422; the button is hidden for self).
- Users page set `user.is_active` from a field the toggle endpoint never returned.
- Changing one's own password (`POST /api/profile`) did not require the current password. It now does; the profile page asks for it and signs the user out after the change (all tokens are revoked server-side).

#### Added
- Tests `Tests\Feature\UserAccountGovernanceTest` (9 tests).

### [MERGE-PR2] - 2026-10-14 — Merge Document & Report Engine / cancellation chain (PR #2) into main
#### Changed
- PR #2 (document engine, leave-type form binding, separate cancellation approval chain) merged on top of LEAVE-FIX-01, USER-IMPORT-01 and LEAVE-APPROVAL-02. Conflicts resolved with LEAVE-APPROVAL-02 rules taking precedence.
- `LeaveCancellationController` uses PR #2's cancellation chain (`stage = cancellation`, `cancellation_approval_level`, `ApprovalStep::stepsFor`) and keeps main's row lock and status re-check, step notifications, `restoreBalance($leave, true)` and audit; self-approval follows the per-step switch, admin bypass does not apply to one's own request, and rejecting one's own request stays forbidden (comment required to reject).
- `LeaveApprovalFlow` is chain-aware: the current step of a leave awaiting cancellation is read from the cancellation snapshots, so the pending list and notifications follow the cancellation chain.
- Approver preview in `WorkflowController` counts the requester as an approver on steps that allow self-approval.
#### Added
- Migration `2026_10_15_100000` backfills `allow_self_approval` on cancellation snapshots created by PR #2's upgrade migration on databases that already ran `2026_10_14_100000`.

### [LEAVE-APPROVAL-02] - 2026-10-14 — Self-approval per step & approval bypass fixes
#### Security
- A delegate of the director could approve the director's own leave at the director step, in the director's name. Delegation can no longer act for the requester, and a requester can never approve their own leave through a delegation they hold.
- `POST /admin/leaves` (record leave on someone's behalf — fully approved on creation) accepted the caller's own `user_id`, bypassing the whole chain. Now 403.
- Holders of `leave.management.manage` / Super Admin no longer bypass step checks on their own leave or their own cancellation request.
#### Changed
- Owner decision (ADR-LEAVE-APPROVAL-02): chain HR check → deputy director → director, and each step may let its own approver approve their own leave. The director approves the final step of their own leave; the deputy approves step 2 of theirs; HR verifies step 1 of theirs. Only direct approvers of that step qualify.
- Own leaves now appear in the approver's pending list at the steps they may approve; cancellation approval follows the same rule. Rejecting one's own leave stays forbidden.
#### Added
- Migration `2026_10_14_100000`: `approval_steps.allow_self_approval`, `leave_approvals.allow_self_approval` (pinned at submission) and `leave_approvals.is_self_approval`; switches all existing steps on and backfills open leaves.
- `ApproverResolverService::canSelfApprove()`; per-step switch in `WorkflowManagementView.vue`; "อนุมัติด้วยตนเอง" badge in `WorkflowTimeline.vue`; `self_approved` in leave audit entries.
- Tests `Tests\Feature\LeaveSelfApprovalTest` (14 tests) and a per-step switch test in `ApprovalWorkflowManagementTest`.

### [USER-IMPORT-01] - 2026-10-14 — Restore user CSV import
#### Fixed
- `POST /api/admin/users/import` returned 500 on every call: the route and the "นำเข้า CSV" button existed but `UserController::importCsv()` was lost in the LEGACY-00 baseline snapshot (`6139212`). Restored from the unmerged commit `5b1497c`, with these fixes:
  - `phone` was written to `user_profiles` and silently dropped; it now goes to `users.phone` (encrypted).
  - Duplicate national ID numbers (against existing profiles or earlier rows in the same file) are rejected, because ID-card login looks up `id_card_number_hash` and takes the first match. IDs must be 13 digits.
  - Empty CSV cells are treated as "not provided" instead of empty strings (e.g. `personnel_type_id`).
#### Changed
- Import rules: passwords are never read from the file (a random unknown password is set; the user resets it through "ลืมรหัสผ่าน"). Every imported user gets a `personnel_type_id` (default `civil_servant`) and current-fiscal-year leave balances from policy. Bad rows are skipped and reported by line number. One `user.imported` audit entry per import records the counts and created user IDs.
- Leave balance provisioning moved into `UserController::provisionLeaveBalances()`, which `store()` and `importCsv()` now share.
- The CSV template in `UsersManagementView.vue` includes an optional `personnel_type_id` column.
#### Added
- Tests `Tests\Feature\UserImportTest` (10 tests).

### [LEAVE-FIX-01] - 2026-10-13 — Leave lifecycle audit & fix
#### Fixed
- Cancelling an approved leave never returned the used days (status was set to `Cancelled` before `restoreBalance()` ran).
- `approve-cancellation` / `reject-cancellation` accepted leaves with no pending cancellation request — approvers could advance `approval_level` of a pending leave and skip their own approval step. Now requires `Approved` + `cancellation_status = Pending`, forbids self-decision, and authorizes through `ApproverResolverService`.
- HR-recorded leave (`POST /admin/leaves`) left its days in `reserved` forever and every approval step `Pending`; past dates were blocked. Now finalized via `LeaveService::finalizeRecordedLeave()` and timing rules are skipped for HR backfill.
- Half-day leave on a weekend/holiday counted 0.5 day; morning + afternoon on the same day were treated as overlapping.
- Editing a leave (`PUT /leaves/{id}`) changed dates without recalculating `total_days` / reservation. Pending leaves are now rescheduled through `LeaveService::reschedule()`; approved leaves must use cancellation.
- `leave_approvals` snapshot did not pin `approver_type` / `specific_user_id` / `permission_name`, so resolver-based steps (department head, director, specific user) could not be approved; the pending list used `approval_steps.role_id` and never showed them.
- Leave balances of a new fiscal year did not exist until the first request; balances endpoint now initializes them from policy. Seeder used calendar year and hard-coded quotas.
- Carry-forward skipped users with no `personnel_type_id`, and ignored the 10-year rule (accumulate 30 instead of 20).
- Reports defaulted to the calendar year while filtering by fiscal year (showed the closed fiscal year from Oct–Dec). Dashboard months now follow fiscal order.
- Partial cancellation allowed overlapping/duplicate requests and did not adjust paid/unpaid days.
- Substitute could respond after a leave was closed; requester was never told.
- Controller-level leave-type checks contradicted `leave_policies` (policy is now the single source).
- Removed unreachable `ApprovalController::approve/reject` (routes point to `GovernmentApprovalController`).

#### Added
- `App\Services\LeaveApprovalFlow` — one place for current step / next step / who may act.
- National holiday management API `GET|POST|PUT|DELETE /api/admin/holidays` (`leave.calendar.manage`) and a section in ปฏิทินวันทำการ.
- `ThaiGovernmentHoliday2570Seeder` (2027 holidays; ploughing day and special cabinet holidays to be added by admins when announced).
- Notification types `leave.cancellation_requested|approved|rejected`, `leave.substitute_requested|responded`; approvers are notified per step instead of all executives at once.
- Migrations: `2026_10_13_100000` (snapshot resolver fields), `2026_10_13_100100` (notification types), `2026_10_13_100200` (policy defaults per regulation: vacation 20/30-day accumulation, 6-month service for vacation, paternity leave for men — only rows still at seed defaults).
- Tests `Tests\Feature\LeaveLifecycleFixesTest` (15 tests); fixed 7 pre-existing tests that broke after the 1 Oct fiscal-year rollover.

### [DOC-ENGINE-10] - 2026-08-20
#### Fixed
- Document Engine เคยเรนเดอร์ด้วย "เวอร์ชันแบบฟอร์มที่ใช้อยู่ปัจจุบัน" เสมอ ทำให้ใบลาที่ยื่นไว้ก่อนเผยแพร่แบบฟอร์ม PDF จะเปลี่ยนไปพิมพ์บนแบบฟอร์มใหม่ ขัดกับข้อกำหนดเอกสารย้อนหลัง — ปัจจุบันยึดเวอร์ชันที่ใบลา pin ไว้ตอนยื่น (`leaves.document_template_version_id`): ใบที่ยื่นตอนแบบฟอร์มยังเป็น HTML จะพิมพ์ด้วยเส้นทางเดิมตลอดไป ส่วนใบที่ยื่นหลังเผยแพร่จะได้แบบฟอร์ม PDF ตามที่ตั้งค่า

### [DOC-ENGINE-09] - 2026-08-20
#### Fixed
- แม่แบบเอกสารแสดงชื่อซ้ำกันสองรายการต่อหนึ่งแบบฟอร์ม — แบบฟอร์ม PDF ที่นำเข้าถูกสร้างเป็นแม่แบบชุดใหม่ (`form_leave_*`) แยกจากแม่แบบ HTML เดิม คำสั่ง `document:consolidate-forms` ย้ายเวอร์ชัน PDF เข้าเป็นเวอร์ชันถัดไปของแม่แบบเดิม เหลือหนึ่งรายการต่อแบบฟอร์มที่มีประวัติเดียวไล่จาก HTML → PDF (ใบลาเก่าที่ pin เวอร์ชันเดิมยังพิมพ์ได้ทุกใบ)
- การอัปโหลดไฟล์ PDF ลงฉบับร่างไปแก้ค่าของแม่แบบ ทำให้ hook ออกเวอร์ชันเปล่าเพิ่มทุกครั้ง (พบ `leave_cancel` มีเวอร์ชันส่วนเกิน) — บันทึกแบบ `saveQuietly()` แล้ว
- ประเภทการลาหนึ่งประเภทถูกผูกกับหลายแบบฟอร์มด้วยลำดับที่ทำให้แบบฟอร์มรวมชนะแบบฟอร์มเฉพาะ (เช่น "ลาไปช่วยเหลือภริยาที่คลอดบุตร" ไปเข้าแบบฟอร์มลาป่วย/กิจ/คลอด) — จัดลำดับให้แบบฟอร์มเฉพาะเหนือกว่าเสมอ และเหลือการผูกใบเดียวต่อประเภท
- เวอร์ชันที่ประกาศเป็นแบบ PDF แต่ไม่มีไฟล์ต้นฉบับถูกปลดเป็น RETIRED (ไม่ลบ) และคืนเวอร์ชันที่ใช้งานได้จริงกลับมา

#### Added
- การเผยแพร่เวอร์ชันอัปเดตวิธีเรนเดอร์ของแม่แบบให้ตรงกับเวอร์ชันที่ใช้งานอยู่
- หน้า "การผูกแบบฟอร์ม" แสดงรายการประเภทการลาที่ยังไม่มีแบบฟอร์มเฉพาะ เพื่อให้ผู้ดูแลรู้ว่าต้องอัปโหลดของประเภทใดบ้าง

### [DOC-ENGINE-05, 07] - 2026-08-20
#### Added
- หน้าออกแบบรายงาน (`ReportDesignerView.vue`) — เลือกแหล่งข้อมูล เลือก/เรียงคอลัมน์ ตั้งชื่อหัวคอลัมน์ กำหนดผลรวมรายคอลัมน์ (COUNT/SUM/AVG/MIN/MAX) จัดกลุ่ม เพิ่มช่องลงนาม และทดลองประมวลผลได้ในหน้าเดียว
- คำสั่ง `document:scaffold-fields` วางชุดฟิลด์ตั้งต้น 23–26 ช่องให้แบบฟอร์มราชการที่นำเข้า (ตำแหน่งโดยประมาณ) เพื่อให้ผู้ดูแลลากจัดตำแหน่งต่อแทนการเพิ่มทีละช่อง

#### Fixed
- หน้าศูนย์จัดการเอกสารโหลดรายการแม่แบบไม่ได้ (SQLSTATE[23000] `Column 'document_template_id' in field list is ambiguous`) — ความสัมพันธ์ `currentVersion` ใช้ `latestOfMany()` ซึ่ง join ตารางเดียวกันซ้อนกัน การ eager load แบบระบุรายชื่อคอลัมน์โดยไม่มีชื่อตารางนำหน้าจึงกำกวมบน MySQL (SQLite ไม่ error จึงหลุดจากเทสต์ชุดแรก — เพิ่มเทสต์ที่เรียก endpoint จริงแล้ว)
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
