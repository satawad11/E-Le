# DOC-ENGINE-00 — Assessment ระบบเดิม ก่อนสร้าง Universal Document & Report Engine

- **สถานะ**: Assessment เท่านั้น — **ยังไม่มีการแก้ source code ใด ๆ ในเฟสนี้**
- **วันที่**: 2026-08-19
- **ขอบเขต**: สำรวจระบบเดิมทั้งหมดที่เกี่ยวกับการออกเอกสารและรายงาน เพื่อวางแผน Document & Report Engine กลาง โดยมี E-Leave เป็นโมดูลแรก
- **เอกสารอ้างอิงในโครงการ**: `docs/14-DOCUMENT-PDF-STANDARDS.md`, `docs/07-WORKFLOW-ENGINE.md`, `docs/12-AUDIT-LOGGING.md`, `docs/05-SECURITY-STANDARDS.md`, `PROJECT_RULES.md`, `AGENTS.md`

---

## 1. Existing Architecture

Modular Monolith บน Laravel 13.25 / PHP 8.4 + Vue 3 (Vite) แยก backend/frontend ในโครงเดียว รันด้วย Docker (nginx, php-fpm, mysql 8, worker, scheduler)

```text
frontend (Vue 3 SPA)
        │  REST + Sanctum token
backend (Laravel)
   ├── Http/Controllers      — โมดูล leave / attendance / overtime / saraban / admin
   ├── Services              — business logic ต่อโดเมน
   ├── Services/Workflow     — workflow engine (shadow mode) + resolver ผู้อนุมัติ
   ├── Services/Reporting    — รายงานรวมศูนย์ (dataset + export)
   ├── Policies              — authorization ต่อ resource
   └── Models                — Eloquent
```

จุดสำคัญต่อ Document Engine:

| องค์ประกอบ | ของที่มีอยู่ | หมายเหตุ |
|---|---|---|
| Module Registry | `config/modules.php`, `config/module_registry.php`, `App\Services\Modules\ModuleRegistry` | มี 4 โมดูล: `leave`, `attendance`, `saraban`, `admin` — เปิด/ปิดรายโมดูลได้ |
| Menu | ตาราง `menus` + `menu_permissions` (DB-driven) | เพิ่มเมนูใหม่ได้ด้วย seeder/migration ไม่ต้องแก้โค้ด frontend มาก |
| Workflow | `workflow_definitions/versions/steps/transitions/instances` (shadow) + `approval_steps`/`leave_approvals` (source of truth) | ADR-009, ADR-APPROVAL-02/04/05 |
| Audit | `audit_logs` (กลาง) + `leave_audit_logs` + `saraban_audit_logs` | ตารางกลางมี actor/before/after/ip/correlation_id ครบ |
| Storage | disks `local`, `private_uploads`, `public`, `backup`, `s3` | เอกสาร/หลักฐานอยู่ใน private ทั้งหมด |

**Library ที่เกี่ยวข้องและติดตั้งแล้ว**

```text
barryvdh/laravel-dompdf 3.1.2   → เรนเดอร์ HTML → PDF (ใช้จริงทุกเอกสารตอนนี้)
setasign/fpdf 1.9.0             → เขียนทับ PDF (ใช้ในงานสารบรรณ)
setasign/fpdi 2.6.8             → import หน้า PDF ต้นฉบับ (ใช้ในงานสารบรรณ)
chillerlan/php-qrcode 6.0.1     → QR ตรวจสอบเอกสาร
```

> **ข้อค้นพบสำคัญ**: FPDI/FPDF ที่ Document Engine ต้องใช้สำหรับ "วางฟิลด์ X/Y บน PDF ต้นฉบับ" **มีอยู่ในระบบแล้ว** และมีตัวอย่างการใช้งานจริงที่ `App\Services\Saraban\PdfSignatureService` (import ทุกหน้า → `useTemplate()` → วาดภาพ/ข้อความที่พิกัดที่กำหนด) ไม่ต้องเพิ่ม dependency ใหม่สำหรับ pilot

---

## 2. Database Schema ที่เกี่ยวข้อง

### 2.1 ตารางเทมเพลตที่มีอยู่แล้ว

`document_templates` (migration `2026_08_08_000000`)

```text
id, code (unique), name, module (default 'leave'),
paper_size, orientation, margin_top/bottom/left/right,
content_html (longText), is_active, timestamps
```

`document_template_versions` (migration `2026_08_13_120000`)

```text
id, document_template_id, version, paper_size, orientation, margins,
content_html, effective_from, effective_to, is_active, timestamps
unique(document_template_id, version)
index(document_template_id, effective_from, effective_to)
```

`leaves.document_template_version_id` → FK ไป `document_template_versions` (nullOnDelete) — ใบลาทุกใบ **pin เวอร์ชันเทมเพลตตั้งแต่ตอนสร้าง** (`Leave::booted()`)

### 2.2 ช่องว่างเทียบกับ Target

| สิ่งที่ Prompt ต้องการ | สถานะปัจจุบัน |
|---|---|
| `document_type` (FORM/REPORT/CERTIFICATE/…) | **ไม่มี** — มีแต่ `module` |
| `name_th` แยกจาก `name` | มี `name` เดียว (เป็นภาษาไทยอยู่แล้ว) |
| `status` DRAFT/ACTIVE/RETIRED/ARCHIVED | **ไม่มี** — มีแค่ `is_active` (boolean) + effective dating |
| `pdf_file_path` (PDF ต้นฉบับ) | **ไม่มี** — เก็บ `content_html` เท่านั้น |
| `created_by`, `published_by`, `published_at` | **ไม่มี** |
| Field mapping X/Y/width/height/font | **ไม่มีเลย** |
| Document binding rules (module/entity/priority/conditions) | **ไม่มี** — มีเพียง `leave_types.document_template_code` (string, 1:1) |
| Field dictionary (ตาราง) | **ไม่มีตาราง** — มีเป็น PHP: `LeaveDocumentFields::catalog()` |
| Data source registry | **ไม่มีตาราง** — มี `ReportingDataService::REPORTS` (const array) |
| Final document files + sha256 + revision | **ไม่มี** — ไม่มีการเก็บไฟล์ PDF ที่ออกแล้วเลย (generate ใหม่ทุกครั้ง) |
| Print calibration (offset/scale) | **ไม่มี** |
| Report definition (columns/group/aggregate) | **ไม่มี** — รายงานถูก hard-code ใน PHP |

---

## 3. Leave Type Structure

ตาราง `leave_types`

```text
id, name, code_prefix, document_template_code, extra_fields (json),
description, counts_toward_promotion, max_days, is_prorated,
is_carry_forward, max_carry_forward, requires_doc,
exclude_holidays, notice_period_days, allow_past_dates,
require_attachment_after_days, timestamps
```

- **`document_template_code`** (เพิ่มเมื่อ 2026-08-19, migration `..._160000`) — ผูกประเภทการลากับ code ของแบบฟอร์ม แก้ปัญหา hard-code ชื่อไทยในโค้ด
- **`extra_fields`** — นิยามช่องกรอกเฉพาะแบบฟอร์ม (key/label/required) ค่าที่ผู้ใช้กรอกเก็บใน `leaves.meta_data.extra_fields`
- นโยบายสิทธิ์วันลาแยกอยู่ที่ `leave_policies` (effective-dated ต่อ personnel type)

**ผลต่อ Engine**: การผูก template ปัจจุบันเป็น **1 leave type → 1 template code แบบ string** ยังไม่รองรับ priority / conditions / effective dating ตามที่ Target ต้องการ แต่**หลาย leave type ชี้ code เดียวกันได้แล้ว** (เช่น ป่วย/กิจ/คลอด → `leave_sick_personal`) ซึ่งตรงกับข้อ 9 ของ Prompt บางส่วน

---

## 4. Leave Request Structure

ตาราง `leaves` (41 คอลัมน์) ที่เกี่ยวกับเอกสาร:

```text
id, uuid, user_id, document_number, verification_hash, content_hash,
leave_type_id, leave_policy_id, fiscal_year_id, document_template_version_id,
start_date, end_date, leave_period, total_days, paid_days, unpaid_days,
reason, contact_address, attachment, status, approval_level,
substitute_id/status/reason, hr_verified_by/at,
meta_data (json), signature_path,
cancellation_status/reason/date/approval_level,
submitted_at, voided_by/at/reason, deleted_at (SoftDeletes)
```

จุดที่ Engine ใช้ได้ทันที:

- `document_number` — เลขที่เอกสารออกโดย `LeaveDocumentNumberService` (มี sequence table)
- `verification_hash` + `content_hash` — QR ตรวจสอบ + ลายนิ้วมือเนื้อหา (ผนึกตอนอนุมัติผ่าน `LeaveVerificationService::seal()`)
- `document_template_version_id` — pin เวอร์ชันแล้ว
- `meta_data.extra_fields` — ช่องเฉพาะแบบฟอร์ม

จุดที่**ยังขาด**สำหรับ Snapshot Architecture (ข้อ 16): ไม่มี `personnel_snapshot` / `leave_data_snapshot` — ข้อมูลผู้ลา (ตำแหน่ง สังกัด วิทยฐานะ) ถูกอ่านสด ๆ จาก personnel ตอนพิมพ์ทุกครั้ง

---

## 5. Personnel Source

โดเมนบุคคลถูกแยกตาม ADR-USER-01/02/03 แล้ว:

```text
users                  — identity/auth (id เป็น FK หลักของทั้งระบบ, 68 FK ใน 38 ตาราง)
personnel              — staff identity (uuid, 1:1 กับ users)
user_profiles          — PII: คำนำหน้า ชื่อ-สกุล ตำแหน่ง เลขบัตร ลายเซ็น
personnel_assignments  — สังกัด/ตำแหน่ง แบบ effective-dated
personnel_types        — ประเภทบุคลากร (civil_servant ฯลฯ)
org_units / org_unit_managers — ผังองค์กร + หัวหน้าหน่วยงาน (effective-dated)
departments (legacy)   — ยังมีอยู่ + map ไป org_unit ผ่าน department_org_unit_map
```

ผู้ให้ข้อมูลกลางที่ Engine ควร reuse: `OrgScopeResolver` (หน่วยงานที่มองเห็น/สังกัด), `PersonnelSyncService`, accessor `User::full_name_with_title`

**ความเสี่ยง**: ข้อมูลบุคคลเป็น effective-dated จริง แต่**เอกสารที่พิมพ์ย้อนหลังยังอ่านค่าปัจจุบัน** → ถ้าคนย้ายแผนกหรือเปลี่ยนตำแหน่ง เอกสารเก่าที่พิมพ์ใหม่จะไม่ตรงกับตอนยื่น (ข้อ 19 ของ Prompt)

---

## 6. Approval Workflow

มี 2 ระบบทำงานคู่ขนาน (ADR-009):

1. **Source of truth**: `approval_steps` (การตั้งค่าสาย) → `leave_approvals` (snapshot ต่อใบ) → `leaves.approval_level` / `leaves.cancellation_approval_level`
2. **Shadow**: workflow engine ใหม่ (`workflow_*`) คำนวณคู่ขนานเพื่อเทียบผล — ยังไม่ตัดสินอะไรจริง

`approval_steps`

```text
id, chain (leave|cancellation), role_id, step_order, name,
approver_type (HR_OFFICER|REQUESTER_SUPERVISOR|DEPARTMENT_HEAD|
                DIVISION_DIRECTOR|DIRECTOR|ROLE|SPECIFIC_USER),
specific_user_id, permission_name
```

`leave_approvals` (snapshot — สำคัญมากต่อ Engine)

```text
id, leave_id, stage (request|cancellation), step_order, step_name,
required_role_id, approver_type, specific_user_id, permission_name,
approver_id, approver_name, approver_position, signature_path,
status, comment, approved_at, acted_at, delegated_from_user_id
```

> **ของดีที่ Engine ต้อง reuse**: `leave_approvals` **เก็บ snapshot ชื่อผู้อนุมัติ ตำแหน่ง และ path ลายเซ็น ณ เวลาที่อนุมัติไว้แล้ว** — นี่คือ `approver_snapshot` + `signature_snapshot` ตามข้อ 16 ที่มีอยู่จริงแล้วสำหรับสายอนุมัติ ขาดเพียง snapshot ของผู้ยื่นและตัวข้อมูลใบลา

---

## 7. Signature Storage

| ลายเซ็น | ที่เก็บ | หมายเหตุ |
|---|---|---|
| ลายเซ็นประจำตัว | `user_profiles.signature_path` | ไฟล์อยู่ disk `private_uploads` |
| ลายเซ็นผู้ยื่นบนใบลา | `leaves.signature_path` | วาดสดตอนยื่นหรือใช้ของโปรไฟล์ |
| ลายเซ็นผู้อนุมัติรายขั้น | `leave_approvals.signature_path` | **snapshot แล้ว** — ไม่เปลี่ยนตามโปรไฟล์ภายหลัง |
| ลายเซ็นงานสารบรรณ | `saraban_document_signatures` (+ mode, document_hash) | ใช้ FPDI stamp ลงไฟล์ PDF จริง |

รูปแบบ path ที่ใช้: `/storage/signatures/<uniqid>.<ext>` เขียนผ่าน `Storage::disk('private_uploads')`

---

## 8. Current PDF / Print System

เส้นทางการพิมพ์ใบลาปัจจุบัน (`LeaveDocumentController::exportPdf`)

```text
GET /api/leaves/{leave}/pdf   (auth:sanctum, throttle:documents)
  → LeavePolicy::view (ownership / substitute / approver / permission)
  → เลือก view: viewFor() → LeaveDocumentTemplateResolver::templateCodeForLeave()
        (ยึด leave_types.document_template_code → fallback เดาจากชื่อไทย)
  → ประกอบข้อมูล: user, profile, type, approvals, balance, settings,
        approverBlocks, สถิติ 2 ภาคเรียน, QR (data URI)
  → LeaveDocumentTemplateResolver::render()
        - merge ตัวแปรกลางจาก LeaveDocumentFields::build($leave)
        - ถ้ามี template version ที่ pin ไว้ → Blade::render(content_html)
        - ถ้าไม่มี → เรนเดอร์ blade ไฟล์จริงใน resources/views/pdf/*
  → Pdf::loadHTML()->setPaper(...)->download()
```

Blade แบบฟอร์มที่มีอยู่: `pdf.leave`, `pdf.leave_vacation`, `pdf.leave_cancel`, `pdf.leave_ordination`, `pdf.leave_paternity`, `pdf.official_travel`, `pdf.leave_hajj`, `pdf.leave_spouse`, `pdf.leave_study` (map ↔ code ที่ `LeaveDocumentTemplateResolver::VIEW_TO_TEMPLATE`)

**ฟอนต์ไทย**: TH SarabunNew ถูก register ไว้ใน `storage/fonts` แล้ว (มี `.ttf` + `.ufm` + `installed-fonts.json`) และ `config/dompdf.php` ชี้ `font_dir`/`font_cache` ไปที่นั่น → **ไม่พึ่งฟอนต์ของ Host OS** ตรงตามข้อ 13 แล้ว

**ข้อจำกัดของสถาปัตยกรรมปัจจุบัน**: เป็น HTML→PDF ล้วน ไม่มีการวางฟิลด์บนพิกัด ไม่มีการใช้ PDF ต้นฉบับของราชการ การจัดตำแหน่งให้ตรงแบบฟอร์มจริงทำด้วย CSS absolute ใน blade ซึ่งเปราะและผู้ดูแลแก้เองไม่ได้

---

## 9. Existing Reports

| รายงาน | Endpoint | Output |
|---|---|---|
| Reporting layer กลาง (9 รายงาน: personnel, leave, leave_balance, attendance, late, absence, ot, saraban, executive) | `GET /api/reporting/{report}` และ `/export/{format}` | pdf, xlsx, docx, csv |
| สรุปการลา | `GET /api/reports/summary`, `/export-excel`, `/export-pdf` | xlsx/pdf |
| ทะเบียนการลาราชการ | `GET /api/reports/government-leave-register(.csv)`, `/government-leave-summary` | json/csv |
| บัตรลา | `GET /api/reports/leave-card(.pdf)` | json/pdf |
| คัดกรองเลื่อนเงินเดือน | `GET /api/reports/promotion-screening(.csv)` | json/csv |

โครงสร้างที่ reuse ได้ทันที:

```php
ReportDataset { report, title, columns[], rows[], totals[], meta[] }   // readonly DTO
ReportFilter                                                          // ตัวกรองมาตรฐาน
ReportingScopeService::authorize($viewer, $report)                    // ABAC ต่อรายงาน
ReportExportService::{csv, xlsx, pdf, docx}                           // xlsx เขียน OOXML เองไม่พึ่ง library
ReportingDataService::REPORTS                                         // whitelist ชื่อรายงาน
```

> **ของดีที่ Engine ต้อง reuse**: `ReportDataset` + `ReportingScopeService` คือ Data Provider + Authorization ที่ Prompt ข้อ 10/28 ต้องการ **ในรูปแบบที่ถูกต้องอยู่แล้ว** (ไม่รับ raw SQL, whitelist ชื่อรายงาน, ตรวจสิทธิ์ฝั่ง server) ขาดเพียงการทำให้ columns/group/aggregate กำหนดได้จาก DB แทนการ hard-code

**ข้อจำกัด**: ยังไม่มี grouping / subtotal / grand total เชิงโครงสร้าง (`totals` เป็น key-value แบน) และไม่มี report definition ในฐานข้อมูล

---

## 10. File Storage

```text
storage/app/private            (disk local)          — leave-evidences
storage/app/private/uploads    (disk private_uploads) — signatures/, settings/, saraban PDFs
storage/app/public             (disk public)         — ไฟล์สาธารณะ
storage/backups                (disk backup)         — spatie/laravel-backup
storage/fonts                  — ฟอนต์ dompdf (TH SarabunNew)
```

การเข้าถึงไฟล์: `SecureFileController::show()` + `SecureFileAccessService` + `StoredFile` model (มี `DISK` const) — **ไม่มี public URL ตรงไปยังเอกสาร** ตรงตาม `docs/14`

**ช่องว่าง**: ไม่มีที่เก็บ "ไฟล์ PDF ฉบับสมบูรณ์" — ทุกครั้งที่กดพิมพ์คือ generate ใหม่จากข้อมูลปัจจุบัน

---

## 11. API ที่เกี่ยวข้อง

```text
เอกสารใบลา
  GET  /api/leaves/{leave}/pdf                       throttle:documents
  GET  /api/leaves/{leave}/word                      throttle:documents
  GET  /api/leaves/verify/{hash}                     public + throttle:verify
  GET  /api/leaves/verify/{hash}/qrcode              public + throttle:verify

แม่แบบเอกสาร (ผู้ดูแล)  [permission:manage_settings|system.setting.manage]
  GET    /api/admin/document-templates
  POST   /api/admin/document-templates
  POST   /api/admin/document-templates/seed-defaults
  GET    /api/admin/document-templates/fields        ← รายการตัวแปร (Field Dictionary ฉบับ PHP)
  POST   /api/admin/document-templates/preview       ← พรีวิว PDF ด้วยข้อมูลตัวอย่าง
  GET|PUT|DELETE /api/admin/document-templates/{template}
  (+ endpoints ของ version: ดู DocumentTemplateController)

สายอนุมัติ  [can:manage_workflow_steps]
  GET|POST /api/admin/workflow-steps        (?chain=leave|cancellation)
  POST     /api/admin/workflow-steps/preview

รายงาน
  GET /api/reporting/{report}                        whereIn ReportingDataService::REPORTS
  GET /api/reporting/{report}/export/{format}        whereIn [pdf,xlsx,docx,csv] + throttle:exports
  GET /api/reports/*                                 (สรุป/ราชการ/บัตรลา/คัดกรอง)

ไฟล์
  GET /api/files/{path}                              SecureFileController
```

**API Contract ที่ห้ามพัง**: `/api/leaves/{leave}/pdf` และ `/api/leaves/{leave}/word` ถูกเรียกจาก `MyLeavesView.vue`, `ApprovalsView.vue`, `LeaveGovernance*` — ต้องคง URL, method, และ response type (binary download) เดิมไว้

---

## 12. Permission

ระบบสิทธิ์ = Spatie Permission + ABAC scope (`permission_scopes`, `OrgScopeResolver`, `PermissionEvaluator`) + `Gate::before` สำหรับ Super Admin

สิทธิ์ที่เกี่ยวกับเอกสาร/รายงาน **ในปัจจุบัน**:

```text
manage_settings | system.setting.manage   → จัดการแม่แบบเอกสาร (กว้างเกินไป)
manage_workflow_steps                     → จัดการสายอนุมัติ
leave.report.view                         → ดูรายงานการลา
leave.export                              → ส่งออกรายงานการลา
saraban.document.report                   → รายงานสารบรรณ
attendance.export / overtime.*            → ส่งออกรายงานโมดูลอื่น
```

**ช่องว่างเทียบข้อ 29**: ยังไม่มี `document.template.view/manage`, `document.report.view/manage`, `document.generate`, `document.final.view/download`, `document.audit.view` — และการจัดการแม่แบบปัจจุบันผูกกับสิทธิ์ "ตั้งค่าระบบ" ซึ่งกว้างกว่าที่ควร (ผู้ดูแลเอกสารจะได้เห็นค่า SMTP/Telegram ไปด้วย)

---

## 13. Existing Tests

61 ไฟล์ใน `tests/Feature` + `tests/Unit` — รวม **543 tests / 1802 assertions ผ่านทั้งหมด** (baseline ณ วันประเมิน)

ชุดที่ Engine ต้องไม่ทำให้แตก:

```text
DocumentTemplateVersionTest          — pin เวอร์ชัน + เอกสารย้อนหลังไม่เปลี่ยน
LeaveFormBindingTest                 — เลือกแบบฟอร์มตาม document_template_code + extra fields
LeaveExportTest                      — export ใบลา
LeaveCardPdfTest                     — บัตรลา PDF
LeaveRegisterReportTest              — ทะเบียนการลา
ReportingLayerTest                   — reporting layer กลาง
BaselineReferenceDataSeederTest      — จำนวนแม่แบบตั้งต้น (ปัจจุบัน 10)
LeaveVerificationTest                — QR/hash ตรวจสอบเอกสาร
LeaveAuthorizationTest / SecureFileAuthorizationTest / RouteAuthorizationAuditTest — สิทธิ์และ IDOR
LeaveApprovalChainSnapshotTest / LeaveCancellationChainTest — snapshot สายอนุมัติ
```

---

## 14. สิ่งที่ Reuse ได้

| Target ที่ Prompt ต้องการ | ของเดิมที่ใช้ต่อได้ | ต้องทำเพิ่ม |
|---|---|---|
| Template + Version + effective dating | `document_templates`, `document_template_versions`, การ pin ที่ `leaves.document_template_version_id` | เพิ่ม `document_type`, `status`, `pdf_file_path`, `created_by/published_by/published_at` |
| Data Provider Layer | `ReportingDataService` + `ReportDataset` + `ReportFilter` + `ReportingScopeService` | ยกระดับเป็น registry ของ provider ต่อโมดูล + สัญญา output มาตรฐาน |
| Field Dictionary | `LeaveDocumentFields::catalog()` (มี group/label ไทยครบ) + endpoint `/fields` | ย้าย/เสริมเป็นตาราง พร้อม `data_type`, `format`, `source` |
| PDF overlay X/Y | `setasign/fpdi` + `PdfSignatureService` (ตัวอย่างการ stamp บนพิกัด) | field mapping table + renderer + coordinate utility |
| ฟอนต์ไทยฝังในระบบ | `storage/fonts` (TH SarabunNew) + dompdf config | ลงทะเบียนฟอนต์กับ FPDF สำหรับ renderer ใหม่ (`AddFont`) |
| Snapshot ผู้อนุมัติ + ลายเซ็น | `leave_approvals.approver_name/position/signature_path` | snapshot ผู้ยื่น + ข้อมูลใบลา |
| ตรวจสอบเอกสาร (QR/hash) | `LeaveVerificationService` (`verification_hash`, `content_hash`, seal ตอนอนุมัติ) | ผูกกับ final file + sha256 |
| Audit | ตาราง `audit_logs` กลาง (actor/before/after/ip/correlation) + `AuditService` | เพิ่ม action ของ document engine |
| เมนูผู้ดูแล | ตาราง `menus` + `menu_permissions` | seeder เมนู "ศูนย์จัดการเอกสารและรายงาน" |
| Export | `ReportExportService` (csv/xlsx/pdf/docx) — xlsx เขียน OOXML เองอยู่แล้ว | เพิ่ม layout/group/aggregate |
| Feature flag | `config/modules.php` + `ModuleRegistry` + ตาราง settings | เพิ่มธง `DOCUMENT_ENGINE_ENABLED` รายโมดูล/ราย leave type |
| Rate limit เอกสาร | named limiter `documents`, `exports`, `files` | ใช้ต่อกับ endpoint ใหม่ |

---

## 15. Breaking Change Risks

| # | ความเสี่ยง | ผลกระทบ | ระดับ | การป้องกัน |
|---|---|---|---|---|
| R1 | เปลี่ยนความหมาย `document_templates.content_html` เป็น PDF-based | ใบลาทุกใบที่ pin เวอร์ชันไว้พิมพ์ไม่ออก | **สูง** | เพิ่ม `render_mode` (`html`\|`pdf_overlay`) ค่าเริ่มต้น `html` — ของเดิมไม่ถูกแตะ |
| R2 | เปลี่ยน `is_active` เป็น enum `status` | โค้ด/เทสต์ที่อ่าน `is_active` พัง (`DocumentTemplateVersionTest`) | **สูง** | เพิ่มคอลัมน์ `status` ควบคู่ + derive จาก `is_active` ตอน backfill และคง `is_active` ไว้ |
| R3 | เปลี่ยน route `/api/leaves/{leave}/pdf` ให้เข้า engine ทันที | หน้าเว็บพิมพ์ใบลาไม่ได้ทั้งระบบ | **สูง** | Feature flag ราย leave type + fallback legacy renderer เสมอ |
| R4 | ผูก `leave_types.document_template_code` ทิ้งแล้วใช้ binding rules อย่างเดียว | ประเภทการลาที่ตั้งค่าไว้แล้วเลือกแบบฟอร์มผิด | กลาง | binding rules อ่าน `document_template_code` เป็น rule ที่ priority ต่ำสุด |
| R5 | เปลี่ยน/ลบ blade ใน `resources/views/pdf/*` | ใบลาเก่าที่ไม่มีเวอร์ชันเทมเพลตพิมพ์ไม่ออก (LEGACY MODE) | กลาง | ห้ามลบ blade จนกว่า migration จะครบ 100% |
| R6 | Field Dictionary ย้ายเป็นตารางแล้วเลิกใช้ `LeaveDocumentFields` | แม่แบบ HTML ที่ผู้ดูแลเขียนไว้ (`{{ $school_name }}`) ตัวแปรหาย | กลาง | คง `LeaveDocumentFields::build()` เป็น provider ตัวหนึ่งของ engine |
| R7 | เพิ่มสิทธิ์ `document.*` แล้วย้าย route ออกจาก `manage_settings` | ผู้ดูแลเดิมเข้าหน้าแม่แบบไม่ได้ | กลาง | ให้ route ยอมรับสิทธิ์เดิม **หรือ** สิทธิ์ใหม่ (`permission:a|b`) แล้วค่อยถอดของเดิมในเฟสหลัง |
| R8 | เก็บ Final PDF แล้วบังคับใช้กับเอกสารเก่า | ใบลาเก่าไม่มีไฟล์ → เปิดไม่ได้ | กลาง | ถ้าไม่มี snapshot ให้ fallback generate จาก pinned version (พฤติกรรมเดิม) |
| R9 | เขียน migration ที่ DROP/RENAME คอลัมน์เดิม | ผิด PROJECT_RULES + ข้อมูลหาย | **สูง** | Additive only — ห้าม DROP/RENAME/TRUNCATE |
| R10 | Engine query personnel/approval เอง | ตรรกะสิทธิ์/ผังองค์กรแตกเป็นสองชุด | กลาง | บังคับผ่าน Data Provider ที่เรียก service เดิม (`OrgScopeResolver`, `ApproverResolverService`) |
| R11 | ใช้ FPDI กับ PDF ที่มี encryption/เวอร์ชันสูง | render ล้มเฉพาะบางไฟล์ | ต่ำ | ตรวจไฟล์ตอน upload + แจ้ง error ที่อ่านรู้เรื่อง |
| R12 | Report designer เปิดให้ใส่ SQL | ช่องโหว่ร้ายแรง | **สูง** | Whitelisted provider เท่านั้น (ข้อ 28) — บังคับด้วยเทสต์ |

---

## 16. Migration Plan

หลักการ: **Additive Migration + Compatibility Layer + Feature Flag** — ของเดิมยังเป็นเส้นทางหลักจนกว่าเฟสจะปิด

### DOC-ENGINE-01 — Core schema + models (ไม่แตะการพิมพ์เดิม)

Migration (เพิ่มอย่างเดียว):

```text
ALTER document_templates
  + document_type (default 'FORM')       + render_mode (default 'html')
  + name_th (nullable)                   + description (nullable)
ALTER document_template_versions
  + status (default derive จาก is_active) + pdf_file_path (nullable)
  + created_by / published_by / published_at (nullable)
NEW  document_field_definitions      — field dictionary (code, label_th, data_type, source, format)
NEW  document_data_sources           — whitelist provider (code, module, provider_class, description)
NEW  document_binding_rules          — module, entity_type, entity_id, template_id, priority, effective_from/to, conditions
```

- `status` backfill: `is_active = true → ACTIVE`, `false → RETIRED` และ**คง `is_active` ไว้ทั้งคอลัมน์และการอ่าน**
- `document_binding_rules` seed จาก `leave_types.document_template_code` ที่มีอยู่ (priority ต่ำสุด = fallback)

### DOC-ENGINE-02 — PDF renderer + field mapping (pilot: ลาพักผ่อน)

```text
NEW document_field_mappings — template_version_id, field_code, page, x, y, width, height,
                              font, font_size, align, format, field_type
```

- Renderer ใหม่ (FPDI overlay) อยู่คู่กับ dompdf renderer เดิม เลือกด้วย `render_mode`
- เปิดใช้เฉพาะ leave type ที่ธง feature flag เปิด (`VACATION` ก่อน)

### DOC-ENGINE-03 — Snapshot + Final document

```text
NEW document_generated_files — document_definition_id, source_type, source_id,
                               template_version_id, revision, file_path, mime_type,
                               sha256, generated_at, finalized_at, status
ALTER leaves + personnel_snapshot (json, nullable) + leave_data_snapshot (json, nullable)
```

- เขียน snapshot ตอน submit/approve (เขียนเพิ่ม ไม่แก้ของเดิม)
- เปิดเอกสารย้อนหลัง → ใช้ final file ก่อนเสมอ ไม่มีจึง fallback pinned version

### DOC-ENGINE-04 → 08

ตามลำดับใน Prompt (Designer → ย้าย leave type อื่น → Report Engine → Report Designer → Security/Audit/Perf/Test) โดยแต่ละเฟสต้อง: รัน `php artisan test` ครบ, Pint ผ่าน, frontend build ผ่าน, อัปเดต `TASKS.md` / `docs/CHANGELOG.md` / `docs/TRACEABILITY-MATRIX.md` / `docs/DECISION-LOG.md`

### ลำดับ rollout ราย Leave Type (ข้อ 32)

```text
VACATION  → engine ก่อน (แบบฟอร์มนิ่งที่สุด มีเทสต์ครอบ)
ORDINATION / PATERNITY → ตามมา
SICK / PERSONAL / MATERNITY → กลุ่มสุดท้าย (ใช้แบบฟอร์มร่วมกัน กระทบมากที่สุด)
```

---

## 17. Rollback Plan

| ระดับ | วิธี | เวลาโดยประมาณ |
|---|---|---|
| **L1 — ปิดธง** | ตั้ง `DOCUMENT_ENGINE_ENABLED=false` (หรือปิดราย leave type) → ทุกอย่างกลับไปใช้ dompdf + blade เดิมทันที | ทันที ไม่ต้อง deploy |
| **L2 — ปิดต่อโมดูล/ต่อ template** | ตั้ง `render_mode = 'html'` ที่ template version นั้น | ทันที ผ่านหน้าผู้ดูแล |
| **L3 — Revert code** | `git revert` commit ของเฟสนั้น — schema ที่เพิ่มไว้เป็น additive ทั้งหมด จึงอยู่ต่อได้โดยไม่กระทบระบบเดิม | ~นาที |
| **L4 — Rollback migration** | `php artisan migrate:rollback --step=N` — ทุก migration ต้องมี `down()` ที่ปลอดภัย และ**ห้ามลบข้อมูลของระบบเดิม** (down ลบเฉพาะตาราง/คอลัมน์ที่เฟสนั้นสร้างเอง) | ~นาที |
| **L5 — Restore** | spatie/laravel-backup (disk `backup`) — มี restore drill บันทึกไว้แล้วใน LEGACY-01 | ตามขนาดฐานข้อมูล |

**เงื่อนไขบังคับของทุก migration ในโครงการนี้**: ห้าม DROP/TRUNCATE ตารางเดิม, ห้ามแก้ PK/FK เดิม, ห้ามแตะข้อมูลใบลาย้อนหลัง, `down()` ต้องไม่ลบข้อมูลที่ระบบเดิมสร้าง

---

## 18. ข้อสังเกตที่ต้องตัดสินใจก่อนเริ่ม DOC-ENGINE-01

รายการนี้คือจุดที่ผมยัง **ไม่ตัดสินใจแทน** เพราะกระทบสถาปัตยกรรม:

1. **HTML template ของเดิม (10 แม่แบบ) จะอยู่ต่อถาวรหรือทยอยเลิก?**
   ถ้าอยู่ต่อ = engine ต้องรองรับ 2 render mode ตลอดไป (ผมเสนอแบบนี้เพื่อความปลอดภัย)
   ถ้าเลิก = ต้องมีแผนแปลง 10 แม่แบบเป็น PDF + mapping ซึ่งเป็นงานเนื้อหา ไม่ใช่งานโค้ด

2. **Workflow engine (shadow) ยังค้างอยู่** — ADR-APPROVAL-02 ถึง 05 ระบุไว้ว่ายังไม่ตัดสินใจ ถ้า Document Engine ไปดึงข้อมูลผู้อนุมัติ ควรดึงจาก `leave_approvals` (source of truth ปัจจุบัน) เท่านั้น ไม่ใช่จาก workflow engine

3. **สิทธิ์ `document.*` ชุดใหม่** จะ map เข้ากับ 7 บทบาทมาตรฐานเดิมอย่างไร (`StandardRolesAndPermissionsSeeder`) — ต้องกำหนดก่อนสร้าง เพื่อไม่ให้ผู้ดูแลที่ใช้งานอยู่เข้าหน้าเดิมไม่ได้

4. **Print calibration** ต้องการ printer profile ต่อเครื่อง — ยังไม่มีแนวคิด "เครื่องพิมพ์" ในระบบเลย ต้องตัดสินใจว่าเก็บระดับ user, ระดับหน่วยงาน หรือระดับเครื่อง (ผมเสนอ: global → template → user override)

5. **ข้อมูลสถานศึกษาในหน้าตั้งค่าระบบยังว่างอยู่** (ค้างจาก LEAVE-FORM-01) — เอกสารที่ออกจาก engine จะได้ช่องว่างเช่นเดียวกับตอนนี้จนกว่าจะกรอก

---

## 19. Definition of Done ของเฟสนี้ (DOC-ENGINE-00)

- [x] สำรวจสถาปัตยกรรม ฐานข้อมูล เอกสาร รายงาน สิทธิ์ ไฟล์ และเทสต์ของระบบเดิม
- [x] ระบุสิ่งที่ reuse ได้ และช่องว่างเทียบกับ Target Architecture
- [x] ระบุความเสี่ยง Breaking Change 12 รายการพร้อมวิธีป้องกัน
- [x] วาง Migration Plan แบบ additive ต่อเฟส และ Rollback Plan 5 ระดับ
- [x] **ไม่มีการแก้ source code ในเฟสนี้**
- [ ] ผู้ดูแลโครงการอนุมัติข้อ 18 (5 ประเด็นที่ต้องตัดสินใจ) ก่อนเริ่ม DOC-ENGINE-01
