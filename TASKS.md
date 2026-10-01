# TASKS

## DOCUMENT-CENTER-REVIEW-20260919 — วิเคราะห์ศูนย์จัดการเอกสาร

- [x] ตรวจ frontend/API/model/resolver และบันทึกข้อค้นพบ DC-01 ถึง DC-06 ใน `docs/checkpoints/DOCUMENT-CENTER-REVIEW-2026-09-19.md`
- [x] รัน lint/typecheck/tests/build ตามขอบเขต และบันทึกผลรวมถึงข้อจำกัดในรายงาน; งานนี้วิเคราะห์และเพิ่มเอกสารเท่านั้น
- [ ] DC-01 จำกัด scope รายการ/ดาวน์โหลดเอกสารและไม่คืน snapshot ในรายการ
- [ ] DC-02 ปิดการประมวลผล PHP จากแม่แบบที่ผู้ใช้ส่งผ่าน API
- [ ] DC-03 รักษาประวัติกติกาผูกแม่แบบแบบ effective-dated
- [ ] DC-04 เพิ่ม pagination เอกสาร; DC-05 แก้ตัวกรองหมวด; DC-06 ใช้ page style เดียวกันในพรีวิวและพิมพ์จริง


## SYSTEM-REVIEW-FIX-20260919 — ปิดข้อค้นพบ 6 รายการจากการตรวจระบบ

- [x] คงขอบเขตสิทธิ์โทเคนเมื่อต่ออายุ รวมโทเคนสิทธิ์ว่างและ wildcard
- [x] ตรวจผู้มอบอำนาจกับผู้มีสิทธิ์จริงของเรื่อง/ขั้น ทั้ง legacy และ workflow delegation
- [x] จำกัดสิทธิ์ `leave.management.manage` ให้ใช้ข้ามขั้นได้เฉพาะใบลา
- [x] แก้ข้อมูล transitions ของสายงานสารบรรณให้คืนชื่อและเงื่อนไขจากโมเดลได้
- [x] เปรียบเทียบ multipart จากฟิลด์และเนื้อหาไฟล์ โดยการเปลี่ยน boundary ไม่ทำให้ส่งซ้ำไม่ได้
- [x] รักษาช่วงเวลา OT ที่ผู้อนุญาตปรับไว้ทั้ง API และฟอร์มขั้นอนุมัติ
- [x] เพิ่ม regression tests; โค้ดและเทสต์ถูกรวมในประวัติ repository แล้วก่อนกลับมาปิดงาน
- [x] ตรวจ quality gates บน HEAD ล่าสุด: backend 1,380 passed / 2 skipped (รันสองส่วนที่ข้ามซ้ำพร้อม repository ครบ: 9 passed / 0 skipped); Pint 768 files; PHPStan; frontend lint/typecheck/213 tests/build ผ่าน — `docs/checkpoints/SYSTEM-REVIEW-FIX-2026-09-19.md`
- ไม่มี migration, dependency upgrade หรือ API endpoint ใหม่ในงานนี้

## SARABAN-HUB-CONSOLIDATION-20260916 — Saraban Module Hubs & Redundancy Consolidation (การปรับปรุงโครงสร้างและยุบรวมศูนย์งานสารบรรณ) ✅ เสร็จ 2026-09-16
- [x] **1. Unified Information Architecture & Menu Tree (จัดกลุ่มเมนูจาก 21 รายการเหลือ 7+1 ศูนย์งานหลัก)**:
  - เมนูหลักฝั่งผู้ใช้งาน (`saraban`):
    1. ภาพรวมและสถานะสารบรรณ (`saraban.dashboard` -> `/saraban`)
    2. กล่องงานและหนังสือของฉัน (`saraban.inbox` -> `/saraban/inbox`) รวม 3 มุมมองแท็บ (งานรอพิจารณา, หนังสือเวียน/เพื่อทราบ, เรื่องที่ฉันส่ง)
    3. ทะเบียนหนังสือราชการ (`saraban.registers` -> `/saraban/registers`) รวม 4 ทะเบียน (หนังสือรับ, หนังสือส่ง, หนังสือภายใน, จองเลขหนังสือ)
    4. สร้างและยกร่างหนังสือ (`saraban.documents.create` -> `/saraban/documents/new`)
    5. คลังเอกสารและสำนวน (`saraban.archive` -> `/saraban/archive`) รวม 2 แท็บ (เอกสารจัดเก็บ, แฟ้ม/สำนวนเรื่อง)
    6. บันทึกการเวียนหนังสือ (`saraban.distribution` -> `/saraban/distribution/tracking`)
    7. สถิติและรายงานสารบรรณ (`saraban.reports` -> `/saraban/reports/registers`)
  - เมนูฝั่งผู้ดูแลระบบสารบรรณ (`saraban_admin`):
    8. ศูนย์ตั้งค่าและบริหารงานสารบรรณ (`saraban.admin_hub` -> `/saraban/admin`) รวม 4 แท็บ (ชุดลำดับเลข, เวิร์กโฟลว์และแบบจำลอง, อำนาจและนโยบาย, ความปลอดภัยและตรวจสอบ)
- [x] **2. Frontend Hubs & Sub-Navigation Views**:
  - สร้าง `RegistersHubView.vue`: ศูนย์รวมสมุดทะเบียนหนังสือราชการสลับ 4 แท็บ พร้อมคง URL Search Params (`?tab=incoming`, `?tab=outgoing`, `?tab=internal`, `?tab=reservations`)
  - สร้าง `SarabanAdminHubView.vue`: ศูนย์บริหารและตั้งค่าระบบสารบรรณสลับ 4 แท็บ (`?tab=number_series`, `?tab=workflow`, `?tab=authority_policies`, `?tab=operations_compliance`)
  - สร้าง `ArchiveCasesHubView.vue`: ศูนย์คลังเอกสารและสำนวนเรื่องสลับ 2 แท็บ (`?tab=archive`, `?tab=cases`)
  - ยกระดับ `InboxView.vue`: รองรับแท็บคิวงาน (`?tab=queue`), หนังสือเวียน (`?tab=inbox`), และเรื่องที่ฉันร่าง (`?tab=my_drafts`) พร้อมฟังก์ชันรายงานความคืบหน้า (`/saraban/inbox/{recipient}/progress`)
  - อัปเดต `router/index.js` จัดการเส้นทาง Hub ใหม่ และทำ Backward-Compatible Route Redirects สำหรับ URL เดิมทั้งหมด
- [x] **3. Database Migration & Integrity Fingerprint**:
  - สร้าง Migration `2026_09_27_100000_consolidate_saraban_menus.php` ทำการลบเมนูย่อยที่ถูกยุบและอัปเดตโครงสร้างเมนูใหม่อย่างปลอดภัย
  - อัปเดต `MenuSeeder.php` และอัปเดต SHA-256 `TREE_FINGERPRINT` ใน `MenuDriftTest.php`
- [x] **4. Full Verification & Quality Hard Gates**:
  - Menu Suite: `MenuIntegrityTest` (9 passed), `MenuDriftTest` (5 passed)
  - Full Frontend Vitest: 33 files / 213 unit tests passed 100%
  - Frontend Build: `npm run build` ผ่าน 100% (3.21s)
  - Backend Pint: ผ่าน 100% (767 files)

## SYSTEM-PDF-ENGINE-CONSOLIDATION-20260916 — Consolidated PDF Vector Engine & Overlay Architecture (การยุบรวมและจัดระเบียบสถาปัตยกรรม PDF Vector Engine) ✅ เสร็จ 2026-09-16
- [x] **1. Core Shared Vector Drawing Engine (`PdfVectorDrawer.php`)**:
  - รวมศูนย์ตรรกะการวาดเวกเตอร์ Text Cell, Multiline (Auto Line Height), Checkmark (Vector Green), Digital Pen Strokes (Vector Blue Pen), Stamp Box (Official Border + Metadata), Placeholders และ QR Code Vector Matrix
- [x] **2. Unified Thai Font & Encoding Layer (`OverlayFonts.php`)**:
  - รวมการตรวจสอบและลงทะเบียนฟอนต์ไทย `THSarabunNew` และ `THSarabunNewB` เข้ากับ `\setasign\Fpdi\Fpdi`
  - รวมฟังก์ชัน `OverlayFonts::encode` สำหรับแปลงรหัส UTF-8 เป็น `CP874//TRANSLIT` ปลอดภัยต่อ FPDF
- [x] **3. Refactored PDF Renderers**:
  - ปรับปรุง `SarabanPdfRenderer.php` ให้เรียกใช้ `OverlayFonts`, `OverlayPdf` และ `PdfVectorDrawer` ร่วมกัน 100% พร้อมคงมาตรฐานความปลอดภัย SoT §14 (ไม่รับภาพ Raster แรนเดอร์ฝั่ง Client)
  - ปรับปรุง `PdfOverlayRenderer.php` ของระบบแม่แบบเอกสารให้เรียกใช้ `PdfVectorDrawer` และ `OverlayFonts::encode`
- [x] **4. Full Quality & Verification**:
  - Full PHPUnit Feature & Unit Suite: 485+ tests passed 100% (รวม `SarabanPdfWorkspaceTest`, `VocationalSarabanSigningAndWorkspaceTest`, `DocumentEngineTest`)
  - Full Vitest Suite: 33 files / 213 unit tests passed 100%
  - Production Build: `npm run build` ผ่าน 100% (3.68s)

## SYSTEM-STREAMLINING-20260916 — System Streamlining & Redundancy Reduction (การจัดระเบียบระบบและลดความซ้ำซ้อนการทำงาน ๔ ด้านหลัก) ✅ เสร็จ 2026-09-16
- [x] **1. Executive Unified Task Inbox (ศูนย์รวมภารกิจรอดำเนินการของผู้บริหาร)**:
  - เพิ่ม API สรุปยอดภารกิจรอดำเนินการ 4 ด้านใน `DashboardController.php` (`executive_tasks`: ใบลา, คำสั่ง OT, งานสารบรรณ/เกษียน, หนังสือเวียนเข้า)
  - พัฒนาวิดเจ็ต **Executive Action Center (ภารกิจรอดำเนินการของท่าน)** ใน `DashboardView.vue`
  - เพิ่มแถบนำทางสลับคิวงาน (Quick Inbox Switcher) ใน `ApprovalsView.vue`
- [x] **2. Unified Report & Analytics Hub (ศูนย์รวมรายงานและสถิติแบบเบ็ดเสร็จ)**:
  - ยกระดับ `ReportsView.vue` เป็นศูนย์รวม 4 หมวดหมู่ (4 Tabs Navigation):
    1. ทะเบียนการลาราชการและสถิติ (Leave Registry & Analytics)
    2. เวลาปฏิบัติราชการและ OT (Attendance & OT Summary)
    3. เกณฑ์วันลาประเมิน ก.ค.ศ. ๒๓ วัน และ ว PA (Vocational Evaluation & PA Evidence)
    4. สมุดทะเบียนและรายงานสารบรรณ ๔ ฝ่าย สอศ. (Saraban In/Out Registers)
- [x] **3. Consolidated Personnel Management (จัดระเบียบการสร้างและจัดการข้อมูลบุคลากร)**:
  - ปรับปรุง `UserFormModal.vue` เพิ่มการกำหนดหัวหน้างาน (`is_org_unit_head`) และวิทยฐานะ (`academic_standing`) ในแท็บข้อมูลการจ้าง
  - ปรับปรุง `UserController.php` ทั้งใน `store()` และ `update()` ให้บันทึกและซิงค์การมอบหมายผู้รับผิดชอบหน่วยงาน (`org_unit_managers`) ภายใน Transaction เดียวกัน
- [x] **4. Consolidated Leave Types & Policies (รวมศูนย์การจัดการประเภทการลาและนโยบายสิทธิ)**:
  - ปรับปรุง `LeaveTypesManagementView.vue` รวมการจัดการประเภทการลา แบบฟอร์ม และนโยบายโควตาสิทธิการลาตามประเภทบุคลากร พร้อมตั้งค่าทั่วไปในหน้าจอเดียวแบบ Master-Detail Tabs
- [x] **คุณภาพและการตรวจสอบ (Full Quality & Verification)**:
  - Frontend Vitest Suite: 33 test files / 213 tests passed 100%
  - Frontend Production Build: `npm run build` ผ่าน 100% (4.55s)
  - Backend PHPUnit Tests: ผ่าน 100% (รวม PermissionCatalogTest)

## SARABAN-EDOC-PHASE-08 — Cross-Module Document Automation, Template Center Bridge & Inter-College Electronic Envelope Exchange (ระยะที่ ๘) ✅ เสร็จ 2026-09-16
- [x] **1. Cross-Module Document Automation & Draft Bridge (ระบบยกร่างเอกสารราชการอัตโนมัติข้ามโมดูล)**:
  - พัฒนา `OfficialDocumentTemplateService` เพิ่มฟังก์ชันยกร่างเอกสารคำสั่ง/ประกาศอัตโนมัติจากโมดูลอื่น:
    - `createDraftPayloadFromLeave`: แปลงคำขอลาที่ผ่านการอนุมัติ เป็นร่างคำสั่งวิทยาลัยอนุญาตให้บุคลากรลา
    - `createDraftPayloadFromOvertime`: แปลงคำสั่งปฏิบัติงานนอกเวลา เป็นร่างคำสั่งวิทยาลัยมอบหมายปฏิบัติหน้าที่ล่วงเวลา
    - `createDraftPayloadFromEvaluation`: แปลงสรุปผลการประเมินวันลา ก.ค.ศ. ๒๓ วัน เป็นร่างประกาศผลการประเมินของวิทยาลัย
  - เพิ่ม API Endpoint `GET /api/saraban/templates/cross-module-draft` และเชื่อมโยงกับการสร้างเอกสารใน `DocumentFormView.vue` ผ่าน `route.query.source_type`
- [x] **2. Inter-College & Inter-Agency Electronic Envelope Exchange (ระบบซองเอกสารและแลกเปลี่ยนหนังสือข้ามสถานศึกษา SoT §17, §27)**:
  - สร้าง `InterAgencyDispatchService` รองรับ:
    - `buildEvidencePackage`: จัดทำซองพยานหลักฐานอิเล็กทรอนิกส์ (.zip) รวบรวมไฟล์ PDF หนังสือราชการ, เอกสารแนบ, บันทึกการลงนามดิจิทัล (`signature_evidence.json`), และ `manifest.json` พร้อมคำนวณ Cryptographic SHA-512 Checksum
    - `dispatchToWebhook`: นำส่งหนังสือตรงไปยัง Webhook API ของสถานศึกษา/หน่วยงานปลายทาง พร้อมลงนามความปลอดภัย HMAC-SHA256
    - `importPackage`: จุดรับเข้าหนังสือราชการจากแพ็กเกจหน่วยงานภายนอก บันทึกลงสู่ทะเบียนหนังสือรับและแนบไฟล์อย่างปลอดภัย
  - สร้าง `InterAgencyDispatchController` และลงทะเบียน Routes:
    - `GET /api/saraban/documents/{document}/dispatch-package` (ดาวน์โหลด ZIP)
    - `POST /api/saraban/documents/{document}/dispatch-webhook` (ส่งผ่าน Webhook)
    - `POST /api/saraban/incoming/import-package` (รับเข้า Webhook)
  - สร้างส่วนติดต่อผู้ใช้ `InterAgencyDispatchModal.vue` และเชื่อมต่อกับ `DocumentFormView.vue` สำหรับหนังสือที่ลงนามหรือมีผลสมบูรณ์แล้ว
- [x] **3. Document Center Bridge & Categorization (ศูนย์จัดการแบบฟอร์มและการเชื่อมโยงแม่แบบ)**:
  - ยกระดับ `DocumentCenterView.vue` และ `DocumentEngineController`:
    - เพิ่มตัวกรองหมวดหมู่แม่แบบเอกสาร (Category Filter Tabs: ทั้งหมด, แบบฟอร์มการลา `LEAVE_FORM`, หนังสือราชการสารบรรณ `SARABAN_OFFICIAL`, แบบฟอร์มทั่วไป `ADMIN_CUSTOM`)
    - เพิ่มช่องค้นหาแม่แบบเอกสารแบบเรียลไทม์ (Live Search Query) ค้นหาชื่อและรหัสแม่แบบ
- [x] **4. คุณภาพและการตรวจสอบ (Full Quality Hard Gates & Verification)**:
  - Feature Test: `backend/tests/Feature/VocationalSarabanIntegrationAndExchangeTest.php` (4 passed / 15 assertions)
  - Architecture & Contract Tests: `ApiSurfaceContractTest` (1 passed / 405 assertions), `SarabanCoreContractTest` (5 passed / 15 assertions)
  - Full Backend Test Suite: 1,380 passed / 1 skipped (5,767 assertions), 0 failed
  - Full Frontend Vitest Suite: 33 test files / 213 tests passed 100% (รวม `tests/sarabanIntegrationAndExchange.test.js`)
  - Frontend Production Build: `npm run build` ผ่าน 100% (3.85s)
  - Code Style: Laravel Pint ผ่าน 100% (765 files)
  - UI copy ภาษาไทย 100%, ปลอดการ Hard-code, Non-Destructive 100%

## SYSTEM-UX-UI-ENHANCEMENT-20260916 — Comprehensive UX/UI Modernization (ปรับปรุงประสบการณ์และส่วนติดต่อผู้ใช้ ๖ ด้านหลัก) ✅ เสร็จ 2026-09-16
- [x] **1. Local Draft Auto-Save & Crash Recovery (ระบบบันทึกร่างอัตโนมัติบนเครื่อง)**:
  - สร้าง Composable `frontend/src/composables/useFormAutoSave.js` รองรับ Debounced Auto-Save ไปยัง `localStorage` แยกตามผู้ใช้และประเภทฟอร์ม
  - แถบแจ้งเตือนเมื่อพบฉบับร่างที่ค้างอยู่ (Draft Recovery Banner) พร้อมตัวเลือก "กู้คืนข้อมูล" หรือ "ละทิ้งร่าง"
  - ไฟแสดงสถานะการบันทึกแบบเรียลไทม์ (Auto-Save Status Indicator: บันทึกร่างแล้ว / กำลังบันทึก)
  - ล้างข้อมูลร่างอัตโนมัติเมื่อส่งฟอร์มสำเร็จ (Clear on Submit) พร้อมเชื่อมโยงกับฟอร์มยื่นใบลา (`LeaveRequestView.vue`) และฟอร์มร่างหนังสือราชการ (`DocumentFormView.vue`)
- [x] **2. Split-View / Side Panel Approvals Workflow (ระบบพิจารณาอนุมัติแบบแบ่งหน้าจอ)**:
  - ยกระดับหน้าพิจารณาอนุมัติ (`ApprovalsView.vue`) ด้วยตัวสลับมุมมอง (Dual-View: Cards View vs Split-View)
  - ใน Split-View: แสดงรายการคำขอด้านซ้าย และ แผงตรวจสอบรายละเอียดด้านขวาแบบ Sticky Inspector Panel
  - เพิ่มชุดข้อความหมายเหตุสำเร็จรูป (Quick Preset Remarks) เช่น "อนุมัติ", "อนุมัติตามระเบียบ", "ขอเอกสารเพิ่มเติม" เพื่อความสะดวกรวดเร็ว
- [x] **3. Mobile Card Transformation for Data Tables (ตารางข้อมูลแปลงเป็นการ์ดบนมือถือ)**:
  - ปรับปรุงตารางข้อมูลในหน้าจอหลักให้แสดงผลแบบ Responsive: สลับระหว่างตารางแบบเต็ม (`hidden md:block`) บนจอเดสก์ท็อป และการ์ดข้อมูลแบบเรียงชั้น (`block md:hidden`) บนจอมือถือ
  - ใช้งานใน: การจัดการผู้ใช้ (`UsersManagementView.vue`), ทะเบียนรายงานสารบรรณ (`RegisterReportsView.vue`), กล่องหนังสือเข้า (`InboxView.vue`), และทะเบียนหนังสือ (`DocumentsView.vue`)
- [x] **4. Breadcrumbs & Contextual Navigation (เส้นทางนำทางและปุ่มย้อนกลับอัจฉริยะ)**:
  - สร้างคอมโพเนนต์ `frontend/src/components/ui/AppBreadcrumb.vue` รองรับทั้งเส้นทางแบบกำหนดเอง (Custom Items) และการคำนวณอัตโนมัติจาก Vue Router Meta
  - รองรับปุ่มย้อนกลับอัจฉริยะ (Contextual Back Button) ที่เลือกได้ระหว่าง `router.back()` หรือเส้นทางปลายทางเฉพาะ
  - ติดตั้งในหน้าจอสำคัญทั่วทั้งระบบ เช่น การยื่นใบลา, การร่างหนังสือ, พื้นที่ลงนาม, พื้นที่เกษียนหนังสือ, พื้นที่ประทับตรา, รายงาน, และทะเบียน
- [x] **5. Drag & Drop File Upload Zone with Live Preview (พื้นที่อัปโหลดไฟล์ลากวางพร้อมพรีวิวสด)**:
  - สร้างคอมโพเนนต์ `frontend/src/components/ui/FileUploadZone.vue` รองรับการลากไฟล์มาวาง (Drag & Drop), คลิกเพื่อเลือกไฟล์, ตรวจสอบขนาดและนามสกุลไฟล์
  - พรีวิวภาพถ่ายและเอกสาร PDF ทันทีในตัวคอมโพเนนต์ พร้อมปุ่มลบไฟล์ก่อนอัปโหลด
  - ติดตั้งในฟอร์มยื่นใบลา (`LeaveRequestView.vue`) สำหรับแนบใบรับรองแพทย์และเอกสารหลักฐาน
- [x] **6. Full-Screen Mobile Signature & Endorsement Canvas (ผืนผ้าใบลงนาม/เกษียนเต็มจอสำหรับมือถือ)**:
  - ยกระดับ `frontend/src/components/ui/HandwritingCanvas.vue` รองรับโหมดเต็มหน้าจอ (Full-Screen Landscape Canvas Overlay)
  - รองรับปุ่มเลิกทำทีละเส้น (Undo Stroke), ปุ่มล้างทั้งหมด (Clear), สลับสีหมึก (น้ำเงิน/ดำ)
  - ส่งออกพิกัดเส้นเวกเตอร์ (Normalized Vector Stroke Coordinates) ตามข้อกำหนด SoT §14, §15 อย่างถูกต้อง
- [x] **คุณภาพและการตรวจสอบ (Full Quality & Verification)**:
  - Vitest Unit & Component Tests: ผ่านครบ 33 test files / 213 tests passed 100% (รวม `tests/formAutoSave.test.js`)
  - Production Build: `npm run build` ผ่าน 100% (3.69s)
  - Backend Test Suite: ผ่าน 100%
  - UI copy ภาษาไทย 100%, ปลอดการ Hard-code

## ROLE-PERMISSION-ENHANCEMENT-20260916 — Role & Permission Matrix & Live Menu Access (ยกระดับการจัดการบทบาท สิทธิ์ และการเข้าถึงเมนู) ✅ เสร็จ 2026-09-16
- [x] ยกระดับหน้าระบบจัดการบทบาทและสิทธิ์ (`RolesManagementView.vue`):
  - พัฒนาโหมดมุมมองคู่ (Dual-View): มุมมองการ์ด (Cards View) และ ตารางเมทริกซ์สิทธิ์แบบโต้ตอบ (Interactive Permission Matrix Grid View)
  - รองรับการสลับแท็บแยกตามโมดูลหลัก (เช่น Core & Users, Leaves, Attendance, Saraban, System) และช่องค้นหาสิทธิ์แบบทันที (Instant Live Filter)
  - รองรับการติ๊กเปิด/ปิดสิทธิ์ได้แบบเรียลไทม์ พร้อมตรวจจับสถานะการเปลี่ยนแปลง (Dirty State Detection) บันทึกรายบทบาท หรือ บันทึกพร้อมกันทั้งหมดในครั้งเดียว (Batch Save: `saveAllDirtyRoles`)
  - กลไกป้องกันบทบาทระบบ (System Role Safeguards): ป้องกันการลบหรือเปลี่ยนคีย์บทบาทหลัก (`is_system`) พร้อมแจ้งเตือนชัดเจน
- [x] ยกระดับหน้าระบบจัดการเมนูและการเข้าถึง (`MenusManagementView.vue` & `MenuController.php`):
  - เพิ่มระบบแสดงบทบาทที่มีสิทธิ์เข้าถึงเมนูแบบเรียลไทม์ (Live Role Access Badges & Modal Preview)
  - เพิ่มหน้าต่างแก้ไขสิทธิ์ของเมนูแบบกลุ่มตามโมดูล (Grouped Module Permission Editor Modal)
  - เพิ่มปุ่มลัดให้สิทธิ์/เพิกถอนสิทธิ์ระดับโมดูลทันที (Quick Grant/Revoke All per Module)
  - ปรับปรุง Backend `MenuController`: บันทึก Audit Log (`menu.permissions_updated`) และล้างแคชเมนู (`Cache::flush()`) ทันทีที่มีการปรับเปลี่ยนสิทธิ์
- [x] ปรับปรุงระบบจัดการสิทธิ์รายบุคคล (`UserPermissionModal.vue`):
  - เพิ่มช่องค้นหาสิทธิ์และตัวกรองโมดูลแบบเรียลไทม์ และปุ่มลัด "ล้างสิทธิ์เฉพาะบุคคลทั้งหมด" (Clear all direct permissions)
- [x] คุณภาพและความถูกต้อง (Full Hard Gates Verified):
  - ตรวจสอบความปลอดภัย: `SystemRoleProtectionTest` (8 passed / 15 assertions), `AuthorizationMatrixTest` (6 passed / 11 assertions)
  - Full Backend Test Suite: 1,376 passed / 1 skipped (5,740 assertions), 0 failed
  - Full Frontend Vitest Suite: 31 test files / 206 passed (100%), 0 failed
  - Frontend Production Build: `npm run build` สำเร็จ 100% (3.21s)
  - สอดคล้องตามกฎ Hard Gates: Zero Hard-Code, UI ภาษาไทย 100%, Non-Destructive 100%

## SARABAN-EDOC-PHASE-07 — Reporting, Analytics & Legal Compliance Evidence (ระยะที่ ๗) ✅ เสร็จ 2026-09-16
- [x] รายงานสารบรรณและสถิติการไหลเวียนเอกสาร ๔ ฝ่ายหลัก สอศ. (`RegisterReportService` & `RegisterReportsView.vue`):
  - สรุปภาพรวมสถิติหนังสือเข้า-ออก รายเดือน และสถานะการดำเนินการ (Monthly In/Out Bar Chart & Status Doughnut)
  - เพิ่มการวิเคราะห์และแสดงผลสัดส่วนหนังสือแยกตาม ๔ ฝ่ายหลัก สอศ. (`by_division`: ฝ่ายบริหารทรัพยากร, ฝ่ายแผนงานและความร่วมมือ, ฝ่ายพัฒนากิจการนักเรียนนักศึกษา, ฝ่ายวิชาการ)
  - ระบบรายงานทะเบียนหนังสือรับ (แบบที่ ๑๓), ทะเบียนหนังสือส่ง (แบบที่ ๑๔), ทะเบียนหนังสือภายใน (แบบที่ ๒) พร้อมตัวกรองฝ่ายงาน (สอศ.) และทิศทาง
  - รายงานทะเบียนเลขหนังสือ (`numbers`) และรายงานสถิติการส่งเวียนและการรับทราบ (`distribution`)
- [x] ระบบส่งออกรายงานสารบรรณอิเล็กทรอนิกส์ (`RegisterReportExportService`):
  - รองรับการส่งออกรายงานในรูปแบบ Excel (.xlsx) และ CSV (.csv UTF-8 with BOM) ตามระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๒๙/๑
  - รองรับการคัดกรองตามฝ่ายงาน (`division_id`) และทิศทางหนังสือ (`direction`) ในการส่งออกรายงาน
- [x] ศูนย์ตรวจสอบหลักฐานความสอดคล้องตามกฎหมายและระเบียบสารบรรณ (`ComplianceEvidenceController` & `ComplianceEvidenceView.vue`):
  - รวบรวมและประเมินหลักฐานความสอดคล้องตามระเบียบ ๗ ด้านหลัก (Cryptographic Hash Sealing, Immutable Audit, Separation of Duties, Legal Hold, Non-Destructive Storage, Trusted Clock Synchronization, RBAC/ABAC Isolation)
  - แสดงผลสถานะการประเมินแบบเรียลไทม์ (Live Assessment: PASS, PARTIAL, NO_EVIDENCE, N/A) แบบอ่านอย่างเดียว (Read-Only) ป้องกันการเคลมสถานะโดยไม่มีหลักฐาน
- [x] คุณภาพและความถูกต้อง (Full Hard Gates Verified):
  - สร้าง Feature Test: `backend/tests/Feature/VocationalSarabanReportsAndComplianceTest.php` (3 passed / 21 assertions)
  - สร้าง Frontend Vitest Component Test: `frontend/tests/sarabanReportsAndCompliance.test.js` (2 passed)
  - Full Frontend Vitest Suite: 31 test files / 206 passed (100%), 0 failed
  - Frontend Production Build: `npm run build` สำเร็จ 100% (3.27s)
  - Laravel Pint Code Style: 762 files ผ่าน 100% สะอาดสมบูรณ์
  - Full Backend Test Suite: 1,376 passed / 1 skipped (5,740 assertions), 0 failed
- [x] สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

## SARABAN-EDOC-PHASE-06 — Document Lifecycle, Retention, Archiving & Disposal (ระยะที่ ๖) ✅ เสร็จ 2026-09-16
- [x] การกำหนดอายุการเก็บรักษาเอกสารราชการ (Document Retention Scheduling):
  - รองรับตัวเลือกอายุการเก็บรักษามาตรฐานตามระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๖๖-๗๑ (๑ ปี, ๕ ปี, ๑๐ ปี, ๒๐ ปี, ๒๕ ปี และตลอดไป)
  - รองรับการคำนวณและตั้งค่าวันครบกำหนดจัดเก็บ (`retain_until`) อัตโนมัติจากวันที่เอกสารมีผลหรือลงนาม
- [x] ระบบอายัดเอกสารตามกฎหมาย (Legal Hold Management & Audit):
  - รองรับการสั่งอายัดเอกสาร (`LegalHold`) พร้อมชุดเหตุผลมาตรฐาน (คดีความ/การไต่สวน, ตรวจสอบ สตง., ร้องเรียนวินัย, ตรวจสอบภายใน)
  - มีมาตรการคุ้มครองเอกสารที่อยู่ระหว่างอายัด: บล็อกการทำลาย (Disposal) โดยเด็ดขาดจนกว่าจะได้รับการปลดอายัด (Release Hold)
- [x] ระบบการทำลายเอกสารราชการตามหลักการแยกหน้าที่ (Separation of Duties & Non-Destructive Disposal SoT §26):
  - แยกบทบาทระหว่างผู้อนุมัติการทำลาย (`approve_disposal`) และผู้ดำเนินการทำลาย (`dispose_files`)
  - การทำลายไฟล์เอกสารจริงใน Storage เกิดขึ้นหลัง Commit Transaction อย่างปลอดภัย พร้อมบันทึกค่า Cryptographic SHA-512 และเก็บรักษาประวัติทะเบียนเอกสาร (Document Registry) ไว้เป็นหลักฐานตลอดไป ไม่ลบ Record
- [x] การค้นหาและกรองสถานะการเก็บรักษาเอกสารขั้นสูง (Advanced Retention & Lifecycle Search):
  - อัปเกรด `DocumentController::index()` รองรับการคัดกรองตาม `retention_status` (`on_hold`, `due`, `disposed`, `scheduled`) และ Eager loading `retentionSchedule` และ `legalHolds` พร้อมควบคุมเวลาผ่าน `TrustedClock`
  - อัปเกรด `RetentionPanel.vue` และ `DocumentsView.vue` แสดงป้ายสถานะ (Badges), ตัวกรองขั้นสูง, และปุ่มลัดการตั้งค่าตามหลัก UI ภาษาไทย 100%
- [x] คุณภาพและความถูกต้อง (Full Hard Gates Verified):
  - สร้าง Feature Test: `backend/tests/Feature/VocationalSarabanRetentionAndLifecycleTest.php` (2 passed / 24 assertions)
  - สร้าง Frontend Vitest Component Test: `frontend/tests/sarabanRetentionAndLifecycle.test.js` (2 passed)
  - Full Frontend Vitest Suite: 30 test files / 204 passed (100%), 0 failed
  - Frontend Production Build: `npm run build` สำเร็จ 100% (2.84s)
  - Laravel Pint Code Style: 761 files ผ่าน 100% สะอาดสมบูรณ์
  - Full Backend Test Suite: 1,373 passed / 1 skipped (5,719 assertions), 0 failed
- [x] สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

## SARABAN-EDOC-PHASE-05 — Digital Signatures, Endorsement & Stamping Workspace (ระยะที่ ๕) ✅ เสร็จ 2026-09-16
- [x] ยกระดับพื้นที่การลงนามดิจิทัล (Digital Signature Workspace & Step-Up Authentication):
  - ระบบลงนามด้วยลายมือชื่อดิจิทัล พร้อมระบบยืนยันตัวตนซ้ำขั้นสูง (Step-Up Authentication via Password/TOTP)
  - บันทึกพิกัดเส้นลายมือชื่อแบบเวกเตอร์ (Stroke Coordinates) ตามมาตรฐานสากลและ SoT §15 (ไม่บันทึกเป็นไฟล์ภาพบิตแมป)
  - ปิดผนึกแฮชเอกสาร (Cryptographic Document Hash Sealing) และสร้างบันทึกพยานหลักฐานการลงนามที่เปลี่ยนแปลงไม่ได้ (Immutable Signature Evidence)
  - เพิ่มปุ่มลัดระบุข้อมูลผู้ลงนามอัตโนมัติ 1 คลิก (`👤 ใช้ชื่อ-ตำแหน่งตนเอง`) ใน `SignDocumentView.vue`
- [x] ยกระดับระบบเกษียนหนังสือราชการ ๔ ฝ่ายอาชีวศึกษา (Vocational Endorsement Workspace):
  - พื้นที่เกษียนหนังสือพร้อมแคนวาสวาดลายมือและชุดข้อความเกษียนความเห็นด่วน ๔ ฝ่ายหลัก สอศ. (`EndorseDocumentView.vue`)
  - แยกการเกษียนความเห็นออกจากการลงนาม โดยการเกษียนไม่สร้าง Signature Evidence ตามข้อกำหนดระเบียบสารบรรณ
- [x] ยกระดับพื้นที่ประทับตรายางและเรนเดอร์ PDF (Stamping Workspace & Non-Destructive Render):
  - ปรับปรุง `DocumentToolsView.vue` รองรับการประทับตรารับ-ส่ง (Registration Stamp) และกล่องข้อความกำหนดเอง
  - ระบบเรนเดอร์ประทับตรายางลงบนไฟล์ PDF ด้วย `SarabanPdfRenderer` สร้างเป็นเอกสารเวอร์ชันใหม่แบบไม่ทำลายต้นฉบับ (Non-Destructive)
- [x] ระบบยืนยันความถูกต้องของเอกสารฉบับสมบูรณ์ (Finalization & QR Public Verification):
  - ระบบ Finalize เอกสารพร้อมสร้างรหัส QR Code ตรวจสอบความถูกต้อง
  - API สาธารณะสำหรับตรวจสอบความถูกต้องของเอกสารราชการ (`/api/saraban/verify/{hash}`)
- [x] คุณภาพและความถูกต้อง (Full Hard Gates Verified):
  - สร้าง Feature Test: `backend/tests/Feature/VocationalSarabanSigningAndWorkspaceTest.php` (3 passed / 21 assertions)
  - สร้าง Frontend Vitest Component Test: `frontend/tests/sarabanSigningAndWorkspace.test.js` (2 passed)
  - Full Frontend Vitest Suite: 29 test files / 202 passed (100%), 0 failed
  - Frontend Production Build: `npm run build` สำเร็จ 100% (3.06s)
  - Laravel Pint Code Style: 760 files ผ่าน 100% สะอาดสมบูรณ์
  - Full Backend Test Suite: 1,371 passed / 1 skipped (5,695 assertions), 0 failed
- [x] สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%


## SARABAN-EDOC-PHASE-04 — e-Saraban In/Out Registry & Vocational Routing (ระยะที่ ๔) ✅ เสร็จ 2026-09-16
- [x] ยกระดับระบบทะเบียนรับ-ส่งหนังสือราชการและระบบหมวดเลขทะเบียนอัตโนมัติ (`SarabanNumberReservationService` & `NumberSeriesService`):
  - ระบบออกเลขทะเบียนรับ (Incoming Registry) และเลขทะเบียนส่ง (Outgoing Registry) อัตโนมัติ ป้องกันการออกเลขซ้ำซ้อนและการจองเลขข้ามหมวด
  - ระบบบันทึกเลขที่หนังสือภายนอกเดิม พร้อมระบุวันที่รับ-ส่ง และชั้นความเร็ว/ความลับตามระเบียบสารบรรณ
- [x] ยกระดับการไหลเวียนหนังสือราชการและส่งเวียน ๔ ฝ่ายหลักอาชีวศึกษา (`DistributionService` & `DistributeDocumentModal.vue`):
  - เพิ่มปุ่มลัดส่งเวียนหนังสือเข้าสู่โครงสร้างสายงาน ๔ ฝ่ายหลัก สอศ. (`addDivisionSubtreeTarget`): ฝ่ายบริหารทรัพยากร, ฝ่ายวิชาการ, ฝ่ายพัฒนากิจการนักเรียนนักศึกษา, ฝ่ายแผนงานและความร่วมมือ
  - เพิ่มชุดข้อความและเหตุผลประกอบการส่งเวียนด่วน (`NOTE_PRESETS`): "เพื่อโปรดทราบ", "เพื่อโปรดพิจารณา", "เพื่อโปรดดำเนินการในส่วนที่เกี่ยวข้อง", "เพื่อโปรดแจ้งผู้เกี่ยวข้องทราบและถือปฏิบัติ", "เพื่อโปรดพิจารณาลงนาม"
- [x] ตรวจสอบความปลอดภัยและข้อกำหนดทางกฎหมาย (Legal & Security Compliance):
  - ปฏิบัติตามระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๒๙: บล็อกการส่งเวียนหนังสืออิเล็กทรอนิกส์สำหรับเอกสาร "ลับที่สุด" (Top Secret) เพื่อความปลอดภัยของข้อมูลราชการ
  - ตรวจสอบสิทธิ์และการป้องกัน IDOR ผ่าน `OrgScopeResolver` และ Server-Side Scope Authorization
  - บันทึกการลงทะเบียนและการส่งเวียนทุกขั้นตอนลงสู่ Central Audit Log
- [x] คุณภาพและความถูกต้อง (Full Hard Gates Verified):
  - สร้าง Feature Test: `backend/tests/Feature/VocationalSarabanRegistryAndRoutingTest.php` (3 passed / 11 assertions)
  - สร้าง Frontend Vitest Component Test: `frontend/tests/sarabanVocationalRouting.test.js` (3 passed)
  - Full Frontend Vitest Suite: 28 test files / 200 passed (100%), 0 failed
  - Frontend Production Build: `npm run build` สำเร็จ 100% (2.80s)
  - Laravel Pint Code Style: 759 files ผ่าน 100% สะอาดสมบูรณ์
  - Full Backend Test Suite: 1,368 passed / 1 skipped (5,674 assertions), 0 failed
- [x] สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

## SARABAN-EDOC-PHASE-03 — Document Drafting & Live Preview Integration (ระยะที่ ๓) ✅ เสร็จ 2026-09-16
- [x] พัฒนาคอมโพเนนต์จำลองเอกสารราชการแบบเรียลไทม์ (`LiveDocumentPreview.vue`):
  - จำลองหน้ากระดาษ A4 เสมือนจริง จัดวางสัดส่วน ระยะขอบ และตราครุฑราชการมาตรฐานตามระเบียบสำนักนายกรัฐมนตรีฯ
  - วางตำแหน่งตราครุฑอัตโนมัติตามประเภทแม่แบบ (บันทึกข้อความ: ครุฑซ้าย ๑.๕ ซม., หนังสือภายนอก/คำสั่ง/ประกาศ: ครุฑกลาง ๓.๐ ซม.)
  - รองรับการแสดงผลตราประทับชั้นความเร็ว (`ด่วนที่สุด`, `ด่วนมาก`, `ด่วน`) และชั้นความลับ (`ลับที่สุด`, `ลับมาก`, `ลับ`)
  - ฟังก์ชันแปลงตัวเลขอารบิกเป็นเลขไทยอัตโนมัติ (Thai Numerals Converter `๐-๙`) พร้อมปุ่มสลับการแสดงผล
  - ปุ่มคัดลอกข้อความร่างทั้งหมดสำหรับนำไปใช้งานต่อ
- [x] ยกระดับหน้ายกร่างและแก้ไขหนังสือราชการ (`DocumentFormView.vue`):
  - เชื่อมต่อโหลดรายการแม่แบบเอกสารราชการแบบไดนามิกจาก API `/api/saraban/templates`
  - นำเสนอการเลือกแม่แบบ ๔ รูปแบบมาตรฐาน (บันทึกข้อความ, หนังสือภายนอก, คำสั่งสถานศึกษา, ประกาศสถานศึกษา) พร้อมป้ายระบุขนาดตราครุฑ
  - โหมดการร่าง ๒ รูปแบบ: **ร่างแบบแยกส่วน (Structured Drafting)** (ส่วนราชการ, คำขึ้นต้น, เหตุผลความเป็นมา, วัตถุประสงค์, ข้อสรุปพิจารณา) และ **โหมดเนื้อความเต็ม (Freeform Summary)**
  - ระบบซิงก์ข้อมูลสองทิศทางแบบเรียลไทม์ระหว่างฟอร์มยกร่างและ Live Document Preview
  - แถบแทรกข้อความสั่งการ/เกษียนความเห็นด่วน ๔ ฝ่ายอาชีวศึกษา (`endorsement_presets`)
- [x] ตรวจสอบและผ่านเกณฑ์ Hard Gates 100%:
  - สร้าง Frontend Vitest Component Test: `frontend/tests/sarabanDraftingAndLivePreview.test.js` (7 passed)
  - ผ่าน Full Frontend Vitest Suite: 27 test files / 197 passed, 0 failed
  - ผ่าน Frontend Production Build: `npm run build` สำเร็จ 100% (2.96s)
  - ผ่าน Laravel Pint Code Style: 758 files สะอาด 100%
  - ผ่าน Full Backend Test Suite: 1,365 passed / 1 skipped (5,663 assertions), 0 failed
- [x] สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

## SARABAN-EDOC-PHASE-02 — Template & PDF Engine Expansion (ระยะที่ ๒) ✅ เสร็จ 2026-09-16
- [x] ยกระดับชุดแม่แบบเอกสารราชการมาตรฐาน ๔ รูปแบบสำหรับสถานศึกษาอาชีวศึกษา (`OfficialDocumentTemplateService`):
  - **บันทึกข้อความ (ภายใน)**: แบบที่ ๒ ครุฑขนาด ๑.๕ ซม. ด้านซ้ายบน
  - **หนังสือภายนอก**: แบบที่ ๑ ครุฑขนาด ๓.๐ ซม. กึ่งกลางหน้ากระดาษ
  - **คำสั่งวิทยาลัย/สถานศึกษา**: แบบที่ ๔ ครุฑขนาด ๓.๐ ซม. กึ่งกลางหน้ากระดาษ
  - **ประกาศวิทยาลัย/สถานศึกษา**: แบบที่ ๕ ครุฑขนาด ๓.๐ ซม. กึ่งกลางหน้ากระดาษ
  - ชุดข้อความสั่งการและเกษียนความเห็นตามโครงสร้าง ๔ ฝ่ายอาชีวศึกษา
- [x] จัดเตรียมและติดตั้งตราครุฑราชการมาตรฐาน (Standard Garuda Emblem Assets):
  - สร้าง Artisan Command `InstallGarudaEmblem` (`php artisan document:install-garuda`) สร้างไฟล์ `garuda.png` และ `garuda.svg` ลงใน `backend/public/images/` และ `frontend/src/assets/`
- [x] ยกระดับ Document Engine & PDF Overlay Renderer:
  - เพิ่มชนิดฟิลด์ `GARUDA` ใน `DocumentFieldMapping::RENDERABLE_TYPES`
  - รองรับการวาดตราครุฑอัตโนมัติใน `PdfOverlayRenderer` ตามพิกัดและขนาดที่กำหนด พร้อมระบบ Fallback ค้นหาไฟล์ภาพอย่างปลอดภัย
  - อัปเดต `TemplatePreflight` ให้ยอมรับฟิลด์ชนิด `GARUDA` โดยไม่ติด Warning เรื่องความสูงฟอนต์
  - เพิ่มชุดข้อมูลตัวอย่างภาษาไทยสมจริงใน `PreviewSampleValues` สำหรับทดสอบพรีวิวฟิลด์บุคลากรและสารบรรณ (`personnel.*`, `saraban.*`, `user.*`, `org_unit.*`, `system.*`)
- [x] ตรวจสอบและผ่านเกณฑ์ Hard Gates 100%:
  - สร้าง Feature Test `backend/tests/Feature/OfficialDocumentTemplateEngineTest.php` (5 passed / 28 assertions)
  - ปรับปรุง `backend/tests/Feature/SarabanMasterSuiteTest.php` ให้ตรวจครบ 4 แม่แบบ (4 passed / 103 assertions)
  - ผ่าน Laravel Pint Code Styling: ตรวจ 758 ไฟล์ สะอาด 100%
  - ผ่าน Full Backend Test Suite: 1,365 passed / 1 skipped (5,663 assertions), 0 failed
  - ผ่าน Full Frontend Vitest Suite: 26 test files / 190 passed, 0 failed
  - ผ่าน Production Build: `npm run build` สมบูรณ์ 100% (3.03s)
- [x] ปฏิบัติตามกฎสำคัญ: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

## SARABAN-EDOC-PHASE-01 — Core Foundation & Data Preparation (ระยะที่ ๑) ✅ เสร็จ 2026-09-16
- [x] ออกแบบและขยาย Data Provider สำหรับ Document Engine ให้รองรับข้อมูลบุคลากร และหนังสือราชการ/สารบรรณ:
  - พัฒนา `PersonnelProfileDataProvider.php` (`PERSONNEL_PROFILE_DETAIL`): แหล่งข้อมูลประวัติบุคลากร สังกัดหน่วยงาน ตำแหน่ง วิทยฐานะ ประเภทบุคลากร และข้อมูลสถานศึกษา
  - พัฒนา `OfficialSarabanDataProvider.php` (`SARABAN_DOCUMENT_DETAIL`): แหล่งข้อมูลหนังสือราชการ เลขที่หนังสือ ชั้นความเร็ว ชั้นความลับ วันที่ไทย และ QR Code ตรวจสอบความถูกต้อง
  - ลงทะเบียนใน `DataProviderRegistry.php`: ลงทะเบียนรหัส provider, กำหนดชื่อภาษาไทยสำหรับหน้าจัดการ และเชื่อมโยง target scope
- [x] ซิงก์พจนานุกรมฟิลด์และแหล่งข้อมูล (`FieldDictionarySynchronizer` & `document:sync-fields`):
  - ซิงก์ฟิลด์จาก catalog ของทั้งสอง provider เข้าสู่ `document_field_definitions` (เพิ่มขึ้นเป็น 203 ฟิลด์ที่พร้อมใช้งาน)
  - รองรับการทำงานแบบ idempotent และไม่กระทบฟิลด์เดิมของระบบใบลา
- [x] ทดสอบและตรวจสอบความถูกต้องตามสัญญาการส่งมอบ (Delivery Contract):
  - พัฒนา Feature Test `backend/tests/Feature/CoreDataDictionaryProviderTest.php` (4 passed / 37 assertions)
  - ผ่าน Laravel Pint Code Style ตรวจ 756 ไฟล์ สะอาด 100%
  - ผ่าน Full Backend Test Suite: 1,360 passed / 1 skipped (5,615 assertions), 0 failed
  - ผ่าน Full Frontend Vitest Suite: 26 test files / 190 passed, 0 failed
  - ผ่าน Production Build: `npm run build` สำเร็จ 100% (3.45s)
- [x] สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

## DUAL-RAIL-NAV — พัฒนาระบบแถบนำทางแบบโมดูลคู่ (Dual-Rail Navigation) ✅ เสร็จ 2026-09-16
- [x] ออกแบบและพัฒนาแถบนำทางแบบโมดูลคู่ (Dual-Rail Navigation) รองรับระบบขนาดใหญ่:
  - **Rail 1 (Primary Module Rail ~72px)**: แถบไอคอนโมดูลหลักแนวตั้ง (หน้าหลัก, ลางาน, ลงเวลา/OT, สารบรรณ, รายงาน, บุคลากร, ตั้งค่า) พร้อมป้ายชื่อไทย, Tooltip, Active Pill และป้ายแจ้งเตือนรวมของแต่ละโมดูล
  - **Rail 2 (Sub-Navigation Panel ~240px)**: แผงเมนูย่อยของโมดูลที่เลือก พร้อมหัวข้อโมดูล, ปุ่มพับเก็บ `[ ◀ ]`, และจัดกลุ่มเมนูย่อยอย่างกระชับ
  - **ระบบตรวจจับโมดูลอัตโนมัติ (Smart Route-to-Module Detection)**: สลับโมดูลใน Rail 1 ให้อัตโนมัติเมื่อผู้ใช้เข้าสู่หน้าใดๆ
  - **ระบบจำสถานะการย่อ/ขยาย (Persistence)**: บันทึกสถานะพับเก็บของ Rail 2 ลง `localStorage`
  - **ระบบนำทางบนมือถือ (Mobile Drawer)**: แถบเลือกโมดูลแนวนอนด้านบน + รายการเมนูย่อยของโมดูลที่เลือก
- [x] อัปเกรด `AppMenuNav.vue` และ `App.vue` ให้รองรับการจัดกลุ่มตามโมดูลอย่างสมบูรณ์
- [x] ผ่านมาตรวัดคุณภาพ (Quality Gates):
  - Vitest Suite: 26 files / 190 tests ผ่าน 100%
  - Production Build: `npm run build` ผ่านสมบูรณ์ (2.76s)
  - Backend MenuDriftTest: ผ่าน 100%

## VOCATIONAL-PERSONA-UI — ปรับปรุง UI และการแสดงผลตรงตามบทบาท สิทธิ์ และการเข้าถึงรายบุคคล ✅ เสร็จ 2026-09-16
- [x] ปรับปรุงหน้ายื่นใบลา (`LeaveRequestView.vue`):
  - เพิ่มเกณฑ์วัดวันลาสะสมสำหรับรอบเลื่อนขั้นเงินเดือน ก.ค.ศ. (23 วัน) พร้อมแสดงรอบปัจจุบัน (รอบที่ ๑: ๑ ต.ค. - ๓๑ มี.ค. หรือรอบที่ ๒: ๑ เม.ย. - ๓๐ ก.ย.) และ Progress Gauge
  - ระบบคัดกรองประเภทการลาอัจฉริยะตามเพศ (Smart Gender Filter: ซ่อนลาคลอดสำหรับเพศชาย, ซ่อนลาอุปสมบท/ช่วยภริยาคลอดสำหรับเพศหญิง)
  - ดึงข้อมูลที่อยู่และเบอร์โทรติดต่ออัตโนมัติจากข้อมูลส่วนตัว (Profile Auto-Fill)
- [x] ปรับปรุงหน้าแดชบอร์ด (`DashboardView.vue`):
  - เพิ่มวิดเจ็ตสรุปสถานะการประเมินวันลา ก.ค.ศ. ๒๓ วัน แสดงแถบความคืบหน้า วันลาสะสม และสถานะความปลอดภัย/ความเสี่ยงต่อการเลื่อนขั้นเงินเดือน
- [x] ปรับปรุงหน้าระบบลงเวลา (`CheckInView.vue`):
  - เพิ่มโหมดการลงเวลา ๒ รูปแบบ: (๑) การลงเวลาปกติในสถานศึกษา และ (๒) การลงเวลานอกสถานที่/นิเทศนักศึกษาฝึกงานอาชีวศึกษา
  - บังคับระบุสถานที่ปฏิบัติงานภายนอก/สถานประกอบการ พร้อมแนบภาพถ่ายหลักฐานและพิกัดดาวเทียม GPS
- [x] ปรับปรุงหน้ารายการรออนุมัติ (`ApprovalsView.vue`):
  - แสดงป้ายระบุ ๔ ฝ่ายหลัก สอศ. (Division Badge) ของผู้ยื่นคำขอ เพื่อให้รอง ผอ. แต่ละฝ่ายตรวจพิจารณาได้ตรงฝ่าย
  - แสดงป้ายแจ้งเตือนสีส้มกรณีผู้ยื่นคำขอมีวันลาสะสมในรอบประเมินเกิน ๒๓ วัน
- [x] ปรับปรุงเมนูระบบและการเชื่อมโยง (`MenuSeeder.php` & Migration `2026_09_26_100000_sync_vocational_evaluation_and_biometric_menus.php`):
  - เพิ่มเมนู `leave.vocational_evaluation` และ `attendance.biometric_hub` เข้าสู่โครงสร้างเมนูระบบ
  - อัปเดต `TREE_FINGERPRINT` และทดสอบ `MenuDriftTest` ผ่าน ๑๐๐%
- [x] คุณภาพและความถูกต้อง (Full Hard Gates):
  - Vitest: 26 files / 190 tests passed (100%)
  - Production Build: `npm run build` สำเร็จ 100%
  - Full Backend Test Suite: 1,356 passed / 1 skipped (5,575 assertions), 0 failed
  - Pint Style: 753 files passed clean 100%

## VOCATIONAL-PHASE-04 — Verification, Full-Suite Hard Gates & Rollout Runbook ✅ เสร็จ 2026-09-16
- [x] จัดทำคู่มือปฏิบัติการและแผนเปิดใช้งานจริงสำหรับสถานศึกษาอาชีวศึกษา: [`docs/onboarding/VOCATIONAL-COLLEGE-ROLLOUT-RUNBOOK.md`](file:///d:/E-Le/docs/onboarding/VOCATIONAL-COLLEGE-ROLLOUT-RUNBOOK.md) ครอบคลุม:
  - ภาพรวมผังองค์กร ๔ ฝ่ายหลัก สอศ. และบุคลากร ๕ กลุ่ม
  - คู่มือย้ายและตั้งค่าข้อมูลผัง ๔ ฝ่าย (`VocationalOrgStructureSeeder`) และการแต่งตั้งผู้บริหาร ๕ ระดับ
  - คู่มือศูนย์นำเข้าไฟล์สแกนเวลา (Smart Biometric Hub) ทั้งแบบ Drag & Drop และประมวลผล Reconciliation
  - คู่มือรายงานสรุปการประเมิน ก.ค.ศ. เกณฑ์วันลา ๒๓ วัน และการสร้างแฟ้มหลักฐานผลการปฏิบัติงานรายบุคคลสำหรับ ว PA
  - คู่มือความปลอดภัย การสำรอง และการซ้อมกู้คืนข้อมูล (Backup & Disaster Recovery Drill)
  - รายการตรวจสอบความพร้อม ๗ ด้านหลังการอัปเกรด (Post-Upgrade 7-Pillar Checklist)
  - แผ่นพับสรุปการใช้งานระบบ ๑ หน้า (One-Page Cheat Sheet) สำหรับแอดมิน, ผู้บริหาร, และครู/บุคลากร
- [x] ตรวจสอบคำสั่งสุขภาพระบบ: `php artisan app:preflight-check` ผ่านเกณฑ์หลัก ๑๐๐% (FAIL = 0)
- [x] รันตรวจสอบมาตรฐานโค้ด Backend: Laravel Pint ผ่าน ๑๐๐% สะอาดครบทุกไฟล์ (752 files)
- [x] ผ่านชุดทดสอบ Backend ทั้งหมด (Full Backend Suite): **1,356 passed / 1 skipped (5,575 assertions), 0 failed**
- [x] ผ่านชุดทดสอบ Frontend ทั้งหมด (Vitest Suite): **26 test files / 189 passed (100%), 0 failed**
- [x] ผ่านการคอมไพล์ Frontend Production Build: `npm run build` สมบูรณ์ ๑๐๐% (2.78s)
- [x] ปฏิบัติตามกฎทุกข้อ: Non-Destructive, ห้าม Hard-Code, UI ภาษาไทย 100%, และรักษาความสมบูรณ์ของระบบเดิม 100%

## VOCATIONAL-PHASE-03 — Frontend Modern UI & Integration ✅ เสร็จ 2026-09-16
- [x] พัฒนา `VocationalEvaluationReportView.vue`: หน้ารายงานสรุปการประเมินวันลาและเวลาปฏิบัติราชการ ก.ค.ศ. พร้อม Interactive KPI cards, ปุ่มเลือกช่วงเวลารอบที่ ๑ (๑ ต.ค. – ๓๑ มี.ค.), รอบที่ ๒ (๑ เม.ย. – ๓๐ ก.ย.) และปีงบประมาณ, ตัวกรอง ๔ ฝ่ายหลัก สอศ. และประเภทบุคลากร ๕ กลุ่ม, ตารางแสดงผลพร้อมป้ายแจ้งเตือนเกณฑ์วันลาสะสมเกิน ๒๓ วัน และโมดอลตรวจสอบแฟ้มหลักฐานผลการปฏิบัติงานรายบุคคล (Teacher PA & Workload Evidence Pack)
- [x] พัฒนา `BiometricHubView.vue`: ศูนย์เชื่อมต่อเครื่องสแกนนิ้ว/ใบหน้าอัจฉริยะ (Biometric Ingestion Zone) รองรับการลากวาง (Drag & Drop) หรือเลือกไฟล์ `.dat`, `.txt`, `.csv`, `.xlsx`, ตัวเลือก Timezone/เขตเวลา, แถบแสดงความคืบหน้า และการ์ดสรุปผลการนำเข้าพร้อมสถานะจับคู่ผู้ใช้และบันทึกเวลาอัตโนมัติ (Reconciled)
- [x] ลงทะเบียนเส้นทางและผูกปุ่มนำทาง:
  - ลงทะเบียน `/reports/vocational-evaluation` และ `/admin/attendance/biometric-hub` ใน `frontend/src/router/index.js`
  - เพิ่มปุ่ม Shortcut "รายงานประเมิน ก.ค.ศ. (ว PA)" ใน `frontend/src/views/ReportsView.vue`
  - เพิ่มปุ่ม Shortcut "นำเข้าไฟล์สแกน (Biometric Hub)" ใน `frontend/src/views/admin/AttendanceSummaryView.vue`
  - อัปเดต `ROUTES_WITHOUT_MENU` ใน `backend/tests/Feature/MenuIntegrityTest.php`
- [x] สร้างชุดทดสอบ Unit/Component Tests:
  - `frontend/tests/vocationalEvaluationReportView.test.js` (3 tests passed)
  - `frontend/tests/biometricHubView.test.js` (2 tests passed)
- [x] ผ่านมาตรวัดคุณภาพ (Quality Gates):
  - Frontend Vitest: 26 test files / 189 tests ผ่าน 100% (0 failure)
  - Frontend Production Build: `npm run build` ผ่านสมบูรณ์ (2.78s)
  - Backend Full Suite: `MenuIntegrityTest` (9 passed), `VocationalReportApiTest` (6 passed), `VocationalDivisionScopeTest` (2 passed), `VocationalOrgStructureSeederTest` (4 passed)
- [x] ปฏิบัติตามกฎ Non-Destructive, UI ภาษาไทย 100%, และรักษาสัญญา Hard Gate (หยุดรอคำสั่งก่อนเริ่มเฟส 4)
- [x] พัฒนา `BiometricFileParserService`: รองรับการวิเคราะห์และนำเข้าไฟล์จากเครื่องสแกนลายนิ้วมือ/ใบหน้า (ZKTeco, HIP, Hikvision) ทั้ง `.dat`, `.txt`, `.csv`, `.xlsx` บันทึก raw event พร้อม map ผู้ใช้และประมวลผลการเข้า-ออกงานอัตโนมัติ (Reconciliation)
- [x] พัฒนา `VocationalLeaveEvaluationService`: ระบบประมวลผลวันลาและเวลาปฏิบัติราชการตามรอบเลื่อนขั้นเงินเดือน ก.ค.ศ. (รอบที่ 1: 1 ต.ค. – 31 มี.ค., รอบที่ 2: 1 เม.ย. – 30 ก.ย., หรือรอบปีงบประมาณ) คำนวณเกณฑ์วันลาสะสม 23 วันสำหรับข้าราชการครู และ 13 วันสำหรับพนักงานราชการ/ลูกจ้าง พร้อมระบบจัดเตรียมแฟ้มหลักฐานผลการปฏิบัติงานรายบุคคล (Teacher PA & Workload Evidence Pack)
- [x] พัฒนา `VocationalReportController` & RESTful API Endpoints พร้อมระบบความปลอดภัยและการป้องกัน IDOR:
  - `GET /api/v1/reports/vocational/evaluation-summary` (รายงานสรุปการประเมินวันลา ก.ค.ศ.)
  - `GET /api/v1/reports/vocational/teacher-pa-evidence` (แฟ้มหลักฐาน ว PA รายบุคคล)
  - `POST /api/v1/attendance/biometric/import-file` (นำเข้าไฟล์บันทึกเวลาสแกนนิ้ว/ใบหน้า)
- [x] สร้าง Feature Tests เฉพาะทาง: `BiometricFileParserTest` และ `VocationalReportApiTest`
- [x] ผ่านการทดสอบ Full Backend Suite: 1,356 passed / 5,575 assertions (0 failed, 1 skipped) และจัดระเบียบโค้ดด้วย Laravel Pint สะอาด 100% (752 files)
- [x] ปฏิบัติตามกฎ Non-Destructive, ห้าม Hard-code, และรักษาสัญญา Hard Gate (หยุดรอคำสั่งก่อนเริ่มเฟส 3)

## VOCATIONAL-PHASE-01 — ฐานราก Backend & Data Migration (Core Foundation Engines) ✅ เสร็จ 2026-09-16
- [x] ต่อยอด `OrgScopeResolver`: เพิ่มฟังก์ชันค้นหาฝ่ายหลัก `divisionOrgUnitId()`, ตรวจสอบฝ่ายเดียวกัน `isSameDivision()`, และขยายขอบเขตการมองเห็นระดับ ๔ ฝ่ายหลัก สอศ.
- [x] ปรับปรุง `ApproverResolverService::resolveDivisionDirectorIds()` ให้ไต่ระดับค้นหาผู้บริหารฝ่าย (รอง ผอ. ๔ ฝ่าย) ตามลำดับชั้นผังองค์กรจริง
- [x] เพิ่มชุดทดสอบ `VocationalDivisionScopeTest` ครอบคลุมการคำนวณและสโคปข้อมูล ๔ ฝ่าย และการจำแนกบุคลากร
- [x] ผ่านการทดสอบ Backend ทั้งหมด: 1,348 passed / 5,533 assertions (0 failure) และจัดระเบียบโค้ดด้วย Pint 100%
- [x] คงความเข้ากันได้ย้อนหลัง 100% (Non-Destructive) ไม่มี Hard-code และไม่กระทบโครงสร้างระบบเดิม

## VOCATIONAL-ARCH-01 — พิมพ์เขียวสถาปัตยกรรมการบริหารงานบุคคลและการปฏิบัติราชการสถานศึกษาอาชีวศึกษา (21 หมวด) ✅ เสร็จ 2026-09-16
- [x] จัดทำและบันทึกเอกสารแม่บท `docs/VOCATIONAL-COLLEGE-PERSONNEL-ARCHITECTURE.md` ครอบคลุม 21 หมวดหมู่:
  1. บทนำและวัตถุประสงค์ (สอศ. / วิทยาลัยเทคนิคดอนเมือง)
  2. โครงสร้างองค์กร ๔ ฝ่ายหลัก (บริหารทรัพยากร, แผนงานฯ, พัฒนากิจการฯ, วิชาการ)
  3. โมเดลตำแหน่งคู่ขนาน (Dual Role & Multi-Duty Model)
  4. การจำแนกประเภทบุคลากร ๕ กลุ่ม (ข้าราชการครู, พนักงานราชการ, ครูอัตราจ้าง, ลูกจ้างประจำ, ลูกจ้างชั่วคราว)
  5. ลำดับบทบาทและสายอำนาจการอนุมัติ ๕ ระดับ + ผู้รักษาการแทน
  6. ขอบเขตสิทธิ์การเข้าถึงข้อมูล (Division & Institutional Scope)
  7. แผนที่การพัฒนาระยะยาว (Phase 1–4)
  8. การตั้งค่าระบบแบบ Zero-Code Admin Self-Service & การปรับลด UX
  9. สถาปัตยกรรมเทมเพลตฟอร์มการลาไดนามิก & ความไม่เปลี่ยนแปลงของข้อมูลประวัติ (Version Pinning)
  10. สถาปัตยกรรมสายการอนุมัติและยกเลิกใบลา พร้อมระบบผูกตำแหน่งช่องลงนาม 1:1 กับกระดาษ (Visual Slot Binding)
  11. เครื่องยนต์โครงสร้างพื้นฐานร่วมกัน ๖ ระบบ (Scope, Approval, PDF Visual Stamp, Notification, Setting, Audit)
  12. ฟังก์ชันเสริมการลาเฉพาะทางสถานศึกษา (นโยบายใบรับรองแพทย์ยืดหยุ่น, เกณฑ์ประเมิน 23 วัน, ตัวกรองเพศอัจฉริยะ)
  13. การตั้งค่าสิทธิการลา รายงาน และแบบฟอร์มราชการ (ตัวช่วยยกยอดสิ้นปี, ตารางโควตาสะสม, Leave Hub)
  14. สถาปัตยกรรมระบบลงเวลาและการปฏิบัติงานนอกเวลา OT (เช็กอินนอกสถานที่ GPS/รูปถ่าย, แผนที่ Geofencing, บัญชีลงเวลา, ขอ OT ผูกคำสั่ง)
  15. สถาปัตยกรรมสารบรรณและการลงนาม/เกษียนบนไฟล์ PDF โดยตรง (Permanent Visual Flattening, ตรารับอัตโนมัติ, ทะเบียนคำสั่ง)
  16. การเดินหนังสือราชการและการลงนามหลายบุคคล/คณะกรรมการ (Parallel Committee Signing, ร่วมลงนาม, สารบบจัดเก็บ 3 ระดับ)
  17. การปรับปรุงโครงสร้างเมนูตั้งค่าระบบให้กระชับและเป็นหมวดหมู่ (Consolidated 6 Tabbed Settings)
  18. สถาปัตยกรรมศูนย์รายงานกลางสถานศึกษาและเอกสารประเมินราชการ (Unified College Report Hub 5 หมวด, ตัวกรองรอบ 1 เม.ย./1 ต.ค., แดชบอร์ด 4 ฝ่าย)
  19. มาตรการความปลอดภัย การย้ายข้อมูล และคู่มือหลังการปรับปรุง (Operational Safety, Data Migration & Post-Upgrade Runbook)
  20. กลยุทธ์และลำดับขั้นตอนการพัฒนา: ลำดับ Backend-First (Backend Foundation -> API & Tests -> Frontend UI -> Verification)
  21. สถาปัตยกรรมการเชื่อมต่อเครื่องสแกนนิ้ว/ใบหน้าอัจฉริยะ (Smart Biometric Hub: ลากวางไฟล์ + Direct IP/LAN Sync)

## SCHOOL-FIT-01 — ตรวจข้อมูลและหน้าจอให้ตรงกับการใช้งานจริงของสถานศึกษา (2026-09-15)

คำสั่งเจ้าของระบบ: (1) ทบทวน/จัดระเบียบข้อมูลให้สอดคล้องกับการใช้งานจริงของสถานศึกษา (2) หาจุดที่ใช้งานติดขัดหรือต้องปรับ UI/UX

### แก้แล้ว
- [x] **ช่องเลือกชื่อบุคลากรเห็นแค่ 25 คนแรก** — `/admin/users` แบ่งหน้าละ 25 คน แต่ 4 หน้าจอที่ใช้รายชื่อเป็นช่องเลือก (ไม่มีปุ่มเลื่อนหน้า) ดึงหน้าเดียว · เพิ่ม `utils/personnelDirectory.js` ไล่ดึงจนครบ แล้วใช้ที่ `LeaveManagementView` `OrgUnitManagersView` `PersonnelAssignmentsView` `AdminSettingsView`
- [x] **หน้า "กำหนดประเภทบุคลากร" ไม่แสดงใครเลย** — รับทั้งซองแบ่งหน้ามาใส่ `users` ทำให้ `.filter()` พังทุกครั้ง · แก้แล้ว พร้อมเพิ่ม loading / empty / error ที่เดิมไม่มีเลย
- [x] **เปลี่ยนปีในปฏิทินวันทำการแล้วรายการไม่โหลดใหม่** — ช่องปีไม่มี `@change` ต่างจากช่องหน่วยงาน
- [x] **ปีเป็น ค.ศ. ขัดกับทั้งระบบที่ใช้ พ.ศ.** — `LeaveManagementView` และ `WorkCalendarView` เปลี่ยนเป็นรายการเลือก พ.ศ. ตามแบบ `AttendanceSummaryView`
- [x] **ชื่อบทบาทเดียวกันแสดงคนละชื่อในแต่ละหน้า** — `WorkflowManagementView` มีตารางแปลชื่อของตัวเอง (`HR Head` = "หัวหน้าบุคลากร", `Employee` = "พนักงานทั่วไป") · ถอดทิ้ง ใช้ `utils/roleLabel.js` ชุดเดียว
- [x] **คำเรียกบุคลากรไม่ตรงกับคำที่ราชการใช้** — แทน "พนักงาน" 11 จุดด้วย "บุคลากร" และตำแหน่งตัวอย่างใน CSV ต้นแบบเป็น "ครู"
- [x] เก็บกวาด lint ที่ค้างอยู่ใน `WorkQueueView` และ `PolicyCenterView`

### ข้อ 1 — ผังหน่วยงานตามโครง ๔ ฝ่าย ✅ เสร็จ 2026-09-15
- [x] `VocationalOrgStructureSeeder` — ๔ ฝ่าย + ๒๕ งาน ตามระเบียบ สอศ. · อ้างอิงด้วย `code` คงที่ จึงรันซ้ำได้และไม่ทับชื่อ/สังกัดที่ผู้ดูแลแก้เอง
- [x] `DatabaseSeeder` — เรียกผังตั้งแต่ต้น แล้วให้บัญชีระบบสังกัด "งานศูนย์ข้อมูลสารสนเทศ" / "งานบุคลากร" แทนหน่วยงานสมมติ `ฝ่ายสารสนเทศ` / `ฝ่ายทรัพยากรบุคคล`
- [x] `MockDataSeeder` — แผนกวิชาสังกัดฝ่ายวิชาการ แทนการแขวนใต้ยอดผัง
- [x] หน้า "โครงสร้างหน่วยงาน" — เรียงแบบต้นไม้ ย่อหน้าตามชั้น เพิ่มคอลัมน์ "ระดับ" และย่อหน้าในช่องเลือกหน่วยงานแม่
- [x] เพิ่มช่อง "ระดับ" ในฟอร์ม — เดิมทุกหน่วยที่สร้างจากหน้าเว็บถูกบันทึกเป็น `department` หมด
- [ ] **ค้างที่เจ้าของระบบ**: รัน `php artisan db:seed --class=VocationalOrgStructureSeeder` บนเครื่องที่ติดตั้งไปแล้ว แล้วย้ายหน่วยงานเดิมเข้าฝ่ายที่ถูกต้องผ่านหน้าจอ (seeder ไม่ย้ายของเดิมให้เอง โดยตั้งใจ)

### ข้อ 2 — ชื่อผู้ลงนามยึดผู้มีอำนาจจริง ✅ เสร็จ 2026-09-16
- [x] `pendingApproverName()` — ถามผู้มีอำนาจจริงจาก `ApproverResolverService` ก่อน แล้วค่อยตกไปที่ค่าในหน้าตั้งค่า แล้วจึงเป็นเส้นประ
- [x] ไม่เดาเมื่อผู้มีอำนาจมีหลายคน — พิมพ์ไปคนหนึ่งผิดยิ่งกว่าเว้นว่าง
- [x] การพิมพ์ต้องไม่ล้มเมื่อหาผู้มีอำนาจไม่ได้ — จับ `Throwable` แล้วใช้ค่าสำรอง
- [x] ถอดบล็อกสร้างช่องลงนามที่ซ้ำกัน 60 บรรทัดระหว่าง `exportPdf()` / `exportWord()`
- [x] หน้า "ผู้บริหารและผู้ลงนาม" ประกาศชัดว่าเป็นค่าสำรองสำหรับพิมพ์บนกระดาษ พร้อมลิงก์ไปแหล่งความจริง

### ข้อ 3 — กล่องยืนยันมาตรฐานของระบบ ✅ เสร็จ 2026-09-16
- [x] `stores/confirm.js` + `composables/useConfirm.js` + `components/ConfirmDialog.vue` วางไว้ครั้งเดียวที่ `App.vue` เหมือน `AlertList`
- [x] แปลงครบทั้ง 27 จุดใน 15 หน้าจอ รวม navigation guard ที่กันงานหายเมื่อยังไม่ได้บันทึก
- [x] `promptText()` บังคับกรอกได้จริง — สองจุดที่เคยเป็น `window.prompt` เป็นเหตุผลที่ผู้ถูกตรวจจะได้อ่าน แต่เดิมกด OK ทั้งช่องว่างได้
- [x] ข้อความบนปุ่มบอกการกระทำจริง ("ลบหน่วยงาน" แทน "ตกลง") และมีบรรทัดอธิบายผลกระทบ

### ข้อ 4 — จัดรูปแบบ `LeaveGovernanceView.vue` ✅ เสร็จ 2026-09-16
- [x] แตกบล็อก `<script setup>` ที่ถูกบีบเหลือบรรทัดเดียว (ยาวกว่า 2,000 อักขระ) ออกเป็นโค้ดที่อ่านและ diff ได้
- [x] เพิ่ม `try/catch` ที่ขาดไปใน `verify` / `decide` / `voidLeave` — เดิมถ้า API ล้ม ผู้ใช้ไม่เห็นอะไรเลย
- [x] ใช้ `getErrorMessage` เหมือนหน้าอื่นแทนการอ่าน `e.response?.data?.message` ตรง ๆ

### ข้อ 5 — จัดระเบียบลำดับและโครงสร้างเมนูระบบใหม่แบบ Zero-Impact ✅ เสร็จ 2026-09-16
- [x] จัดเรียงลำดับเมนูใน `MenuSeeder::TREE` ตามลำดับความสำคัญและการใช้งานจริงของสถานศึกษา (งานตนเอง/ประจำวัน -> งานอนุมัติ -> งานกำกับดูแลและรายงาน)
- [x] จัดเรียงงานสารบรรณตามกระบวนการเอกสารราชการ และจัดกลุ่มหน้าตั้งค่าระบบตามประเภทข้อมูล
- [x] คง `code`, `route_name`, `route_path`, และสิทธิ์การเข้าถึงเดิม 100% (Zero-Impact ต่อ URL, bookmarks, authorization)
- [x] เพิ่ม migration `2026_09_25_100000_reorganize_menus_structure` เพื่อซิงก์ลำดับและจัดระเบียบให้กับระบบที่ติดตั้งแล้ว
- [x] อัปเดต `TREE_FINGERPRINT` และ `MenuDriftTest` พร้อมผ่านการทดสอบ `MenuIntegrityTest` (9/9), `MenuDriftTest` (5/5), `SarabanModuleRegistrationTest` (13/13) และ Vitest 184/184 tests 100%

## SARABAN COMPLETE VOCATIONAL UPGRADE (8 MASTER PILLARS) — 2026-09-15

- [x] **Pillar 1: Role-Tailored Workspace & Navigation Experience**
  - แยกมุมมองการใช้งานสารบรรณตามบทบาทจริง 3 กลุ่ม: ครู/บุคลากรทั่วไป (กล่องหนังสือของฉัน, ร่างบันทึกข้อความ, ติดตามเรื่องเสนอ), ผู้บริหาร ผอ./รอง ผอ. 4 ฝ่าย (คิวงานรอดำเนินการ, รอลงนาม, ประวัติการสั่งการ), เจ้าหน้าที่สารบรรณกลาง (ลงทะเบียนรับ, ออกเลขส่ง, กระจายเอกสาร, สมุดทะเบียนราชการ)
- [x] **Pillar 2: Executive Fast-Signing & Quick Endorsement Snippets (สำหรับ ๔ ฝ่ายอาชีวศึกษา)**
  - Frontend: เพิ่มแถบข้อความเกษียนด่วน 8 รูปแบบสำหรับ ผอ. และ รอง ผอ. 4 ฝ่าย ใน `EndorseDocumentView.vue` (ทราบ/ดำเนินการตามระเบียบ, อนุมัติ ตามเสนอ, มอบ รอง ผอ. ฝ่ายบริหารทรัพยากร/วิชาการ/พัฒนากิจการนักเรียนฯ/แผนงานฯ, แจ้งเวียนบุคลากร, ขอข้อมูลเพิ่มเติม) พร้อมปุ่มคลิกเดียวแทรกข้อความและพร้อมประทับลงหน้าเอกสารทันที
- [x] **Pillar 3: Pre-configured Vocational Workflows (สายการเดินหนังสือ ๔ ฝ่ายอาชีวะ)**
  - รองรับเส้นทางเดินหนังสือของสถานศึกษาอาชีวศึกษา (บันทึกข้อความภายใน, ขออนุมัติโครงการ/งบประมาณ, หนังสือรับภายนอก, หนังสือส่งออกภายนอก)
- [x] **Pillar 4: Urgent Priority Engine & Real-time Highlighting**
  - Frontend: พัฒนาการแสดงผลชั้นความเร็วด่วนพิเศษ (ด่วนที่สุด สีแดงกระพริบ `animate-pulse`, ด่วนมาก สีส้ม, ด่วน สีเหลือง) บน `WorkQueueView.vue` และ `InboxView.vue`
  - Frontend: เพิ่ม Quick Filter ปุ่มคัดกรอง "เฉพาะด่วนที่สุด / ด่วนมาก" ในหน้า `WorkQueueView.vue`
- [x] **Pillar 5: Official Thai Government Document Templates (ระเบียบสำนักนายกฯ ๒๕๖๔)**
  - Backend: พัฒนา `OfficialDocumentTemplateService` ให้บริการโครงสร้างแม่แบบ "บันทึกข้อความ" (แบบที่ ๒ - ครุฑซ้าย ๑.๕ ซม.) และ "หนังสือภายนอก" (แบบที่ ๑ - ครุฑกลาง ๓ ซม.)
  - Backend: พัฒนา `DocumentTemplateController` (`GET /api/saraban/templates`)
  - Frontend: เพิ่มแผงเลือกแม่แบบหนังสือราชการใน `DocumentFormView.vue` โหลดโครงสร้าง 3 ภาค (เหตุ - ความประสงค์ - สรุป) อัตโนมัติ
- [x] **Pillar 6: QR Code Verification & Authenticity Check**
  - ตรวจสอบความถูกต้องเอกสารและลายมือชื่อดิจิทัลผ่าน `VerifyDocumentView.vue` ยืนยันความสมบูรณ์และตรวจจับการแก้ไขดัดแปลงไฟล์หลังลงนาม
- [x] **Pillar 7: Official Email Integration & Auto-reply with Bilingual Disclaimer (ภาคผนวก ๗)**
  - Backend: พัฒนา `OfficialEmailTemplateService` ให้บริการข้อความปฏิเสธความรับผิดชอบ (Legal Disclaimer) ๒ ภาษา (ไทย-อังกฤษ), ข้อความตอบรับอัตโนมัติ (Auto-reply), และการจัดรูปแบบหัวเรื่องตามชั้นความเร็ว
  - Backend: เพิ่ม endpoint `GET /api/saraban/templates/disclaimer`
- [x] **Pillar 8: Executive SLA & Analytics Dashboard**
  - Backend: ยกระดับ `DashboardService` เพิ่มสถิติการจำแนกตามทิศทาง (ภายใน/ส่งออก/รับเข้า) และชั้นความเร็ว (ด่วนที่สุด/ด่วนมาก/ด่วน/ปกติ)
  - Frontend: เพิ่มการ์ดสถิติแสดงสัดส่วนหนังสือและการจำแนกตามชั้นความเร็วใน `SarabanDashboardView.vue`
- [x] **Verification & Quality Gate**
  - Backend: เพิ่มชุดทดสอบ `SarabanMasterSuiteTest.php` (4 passed / 83 assertions)
  - Full Backend Suite: 331 passed (1,622 assertions), Laravel Pint 100% ผ่าน 741 ไฟล์
  - Frontend: Vitest 170 passed (21 test files), `npm run build` ผ่าน 100% ปราศจากข้อผิดพลาด

## SARABAN & SYSTEM READINESS UPGRADE (4 MAJOR TOPICS & REGULATION COMPLIANCE) — 2026-09-15

- [x] **Topic 1: Pre-flight Readiness Dashboard & Quick Setup for Saraban**
  - Backend: พัฒนา `PreflightReadinessChecker::checkSarabanReadiness()` ตรวจความพร้อม 4 ด้าน (ชุดเลขหนังสือ, ผู้ลงนาม, ClamAV, WebPush VAPID)
  - Backend: พัฒนา `POST /api/saraban/number-series/quick-setup` (`NumberSeriesController::quickSetup`) สร้างชุดเลขเริ่มต้น 5 หมวดมาตรฐาน (คำสั่ง, ประกาศ, ภายใน, ส่ง, รับ)
  - Frontend: เพิ่มปุ่ม Quick Setup ใน `AdminSettingsView.vue` และ `NumberSeriesView.vue` ช่วยให้ผู้ดูแลระบบกดสร้างชุดเลข 5 หมวดได้ในคลิกเดียว พร้อมอัปเดตสถานะทันที
- [x] **Topic 2: ระบบส่งออกรายงานทะเบียนหนังสือเป็น Excel/CSV (RISK-69 & ระเบียบฯ ข้อ ๒๙/๑)**
  - Backend: พัฒนา `RegisterReportExportService` ส่งออกไฟล์ CSV (พร้อม UTF-8 BOM) และ XLSX (OpenXML มาตรฐาน ไม่พึ่งพาแพ็กเกจภายนอก) สำหรับทะเบียนหนังสือรับ (แบบที่ ๑๓/๑), ทะเบียนส่ง (แบบที่ ๑๔/๒), ทะเบียนเลขหนังสือ, และการเวียนรับทราบ
  - Backend: เพิ่ม endpoint `GET /api/saraban/reports/export` ภายใต้สิทธิ์ `saraban.report.export` พร้อม Org Scope Check
  - Frontend: เพิ่มปุ่มส่งออกรายงานและ Modal ดาวน์โหลดไฟล์ใน `RegisterReportsView.vue` เลือกระบุช่วงวันที่ ทิศทาง และฟอร์แมตไฟล์ได้
- [x] **Topic 3: Step-up Authentication & TOTP / MFA (RISK-29)**
  - Backend: พัฒนา `TotpService` (RFC 6238 TOTP) Zero-Schema โดย derive secret key จาก APP_KEY + user uuid
  - Backend: พัฒนา `StepUpAuthentication` รองรับการยืนยันตัวตนด้วย TOTP Code 6 หลัก ควบคู่กับรหัสผ่านเดิม
  - Backend: เพิ่ม `GET /api/auth/totp/setup` และปรับ `POST /api/auth/confirm-password` รองรับทั้ง `password` และ `totp_code`
  - Frontend: ปรับปรุง `ConfirmOperation.vue` และ Modal ใน `PolicyCenterView.vue` ให้สลับแท็บยืนยันตัวตนด้วยรหัสผ่าน หรือรหัส Authenticator OTP 6 หลักได้
- [x] **Topic 4: การมอบอำนาจรับทราบหนังสือเวียนแทน (RISK-35) และ Form Wizard สำหรับ Policy Center (RISK-45)**
  - Backend: ปรับ `DistributionService::acknowledge()` และ `InboxController` รองรับการมอบอำนาจ (Delegation) ปฏิบัติราชการแทนผ่านตาราง `user_delegations` โดยบันทึกผู้กดจริงและผู้มอบอำนาจใน Audit Trail อย่างเคร่งครัด
  - Frontend: เพิ่มแถบแจ้งเตือนการมอบอำนาจ ตัวกรองเลือกดูหนังสือแทนผู้มอบอำนาจ และป้ายแสดง "แทน [ชื่อผู้มอบอำนาจ]" ใน `InboxView.vue`
  - Frontend: เพิ่ม Form Wizard ใน `PolicyCenterView.vue` ช่วยให้ผู้ดูแลระบบแก้ไขนโยบายยอดนิยม (`distribution`, `document_layout`, `numbering`) ด้วยฟอร์ม UI ที่เข้าใจง่าย สลับกับโหมด Raw JSON แบบสองทาง
- [x] **Regulation Compliance Additions (ระเบียบสำนักนายกรัฐมนตรีฯ)**
  - Frontend: เพิ่มข้อแนะนำการตั้งชื่อไฟล์แนบอิเล็กทรอนิกส์ตามมาตรฐาน ภาคผนวก ๗ ข้อ ๔.๓ ใน `DocumentFormView.vue`
- [x] **Quality Gates & Full Regression Testing**
  - Backend: ผ่าน 333 tests (1,621 assertions) รวม `SarabanCoreContractTest`, `SarabanOfficialComplianceTest`, `AdminPreflightCheckApiTest`, `SarabanSignatureAndStepUpTest`, `SarabanDistributionAndInboxTest`
  - Backend: Laravel Pint ผ่าน 100% (740 files)
  - Frontend: Vitest ผ่าน 170 tests (21 test files), Build ผ่าน 100% (4.45s)

## SARABAN REGULATION COMPLIANCE (ระเบียบสำนักนายกรัฐมนตรีฯ ฉบับที่ ๔ พ.ศ. ๒๕๖๔) — 2026-09-15

- [x] **Official Saraban Filename Service (ภาคผนวก ๗ ข้อ ๔.๓)** — สร้าง `OfficialSarabanFilenameService` รองรับชื่อไฟล์ส่งออกตามรหัสตัวอักษรโรมันประจำกระทรวงศึกษาธิการ (`MOE`) และรหัสสถานศึกษาอาชีวศึกษา (`YYYY_MOE{unit}_{running}.pdf`), รองรับไฟล์แนบ `_1.pdf`, `_2.pdf` และทำความสะอาดอักขระต้องห้าม
- [x] **Document Download Endpoint** — เพิ่ม `GET /api/saraban/documents/{document}/download` กำหนดส่วนหัว `Content-Disposition` ด้วยชื่อไฟล์ทางการตามระเบียบ
- [x] **Top Secret Safeguard (ข้อ ๒๙ วรรคหนึ่ง, ภาคผนวก ๗ ข้อ ๓.๖)** — บล็อกการเวียนหนังสือชั้น "ลับที่สุด" ทางอิเล็กทรอนิกส์ใน `DistributionService` ด้วย `DistributionException`, พร้อมแสดงแถบเตือนสีแดงและล็อกปุ่มส่งใน `DistributeDocumentModal.vue`
- [x] **Official Registry Reports (แบบที่ ๑ และ แบบที่ ๒)** — ปรับปรุง `RegisterReportService` และ `RegisterReportExportService` ให้มีคอลัมน์มาตรฐานสำหรับทะเบียนหนังสือรับ (แบบที่ ๑) และทะเบียนหนังสือส่ง (แบบที่ ๒)
- [x] **Regression & Official Compliance Suite** — เพิ่ม `SarabanOfficialComplianceTest` (5 passed / 24 assertions), ผ่านชุดทดสอบ Saraban ทั้งหมด 80 tests (358 assertions) และ Frontend Vitest 170 tests

## DEVOPS & DATA ONBOARDING (OPERATIONS & GO-LIVE READINESS) — 2026-09-14

- [x] **DevOps & Operations: สคริปต์สำรองและกู้คืนข้อมูลอัตโนมัติ** — สร้าง `docker/scripts/backup.sh`, `docker/scripts/backup.ps1`, `docker/scripts/restore.sh`, `docker/scripts/restore.ps1` รองรับการสำรองฐานข้อมูล MySQL และ private_uploads พร้อมระบบลบไฟล์เก่าตาม Retention
- [x] **DevOps & Operations: การจัดการ Log Rotation และตรวจสุขภาพคอนเทนเนอร์** — ตั้งค่า `x-logging` (`max-size: 20m`, `max-file: 5`) ใน `docker-compose.yml` ให้กับคอนเทนเนอร์หลักทั้งหมด; สร้างสคริปต์ `docker/scripts/healthcheck.sh` และ `docker/scripts/healthcheck.ps1` ตรวจสอบสถานะบริการทั้ง 6 ตัวและ API Ping
- [x] **Data Onboarding: คำสั่งตรวจความพร้อมข้อมูลตั้งต้นก่อนเปิดระบบจริง** — พัฒนา `php artisan app:preflight-check` (`CheckPreflightReadinessCommand`) ตรวจสอบ 7 ด้านสำคัญ (OrgUnits, OrgUnitManagers, Users/Personnel, FiscalYear & LeavePolicies, WorkSchedules & Holidays, DocumentTemplates, Notification Channels) รายงานผลลัพธ์เป็นตารางภาษาไทย 100%
- [x] **Data Onboarding: หน้าจอแดชบอร์ดตรวจความพร้อมระบบ (Pre-flight Dashboard)** — สกัด Service `PreflightReadinessChecker`, สร้าง API `GET /api/admin/preflight-check` (ภายใต้สิทธิ์ `system.setting.manage`), เพิ่มแท็บและ UI ตรวจความพร้อมใน `AdminSettingsView.vue` พร้อมสรุปสถานะ (เขียว/เหลือง/แดง) และปุ่ม Shortcut จัดการข้อมูลที่เกี่ยวข้อง
- [x] **Data Onboarding: เทมเพลตและคู่มือเปิดใช้งานจริง** — สร้าง `docs/onboarding/GO-LIVE-CHECKLIST.md` (Checklist 8 ขั้นตอนสำหรับผู้ดูแลระบบและฝ่ายบุคคล) และ `docs/onboarding/users_import_template.csv` (เทมเพลตนำเข้าบุคลากร)
- [x] **Quality Gate & Regression Tests** — เพิ่ม `AdminPreflightCheckApiTest` (4 passed / 67 assertions) และ `CheckPreflightReadinessCommandTest` (2 passed / 4 assertions); `ApiSurfaceContractTest` และ `MenuDriftTest` ผ่าน 100%; Pint ผ่าน 100% (733 ไฟล์สะอาด); Frontend build และ Vitest 170 ผ่านครบถ้วน

## สายอนุมัติใบลาเดินด้วย WORKFLOW ENGINE — 2026-09-12

- [x] เปิด `WORKFLOW_ENGINE_SOURCE_OF_TRUTH` เป็นค่าเริ่มต้น ครอบทั้งสายขอลาและสายขอยกเลิก
- [x] แก้ `LeaveAssigneeResolver` ให้อ่าน snapshot ตาม stage ของ instance — เดิมอ่านสายขอลาตายตัว ผู้อนุมัติขั้นแรกของใบลาจึงอนุมัติคำขอยกเลิกได้ ทั้งที่ผู้ดูแลตั้งสายยกเลิกไว้เป็นคนละคน
- [x] `authoritativeInstance()` หารอบที่ยังเดินอยู่ก่อนเสมอ แล้วค่อยออกเวอร์ชันปัจจุบัน — ผู้ดูแลแก้สายอนุมัติแล้วเรื่องที่ค้างอยู่ต้องไม่เปลี่ยนสายกลางคัน
- [x] `run_no` ให้เรื่องหนึ่งเดินสายเดียวกันได้หลายรอบ — คำขอยกเลิกที่ถูกปฏิเสธแล้วยื่นใหม่เคยติดที่ unique index
- [x] เปิด workflow instance ตั้งแต่ตอนยื่นใบลา ให้เวอร์ชันที่ pin ตรงกับ snapshot ใน `leave_approvals` ที่บันทึกในจังหวะเดียวกัน
- [x] ถอด shadow mode ทั้งชุด — `LeaveWorkflowShadow`, `WorkflowShadowComparison`, `workflow:shadow-report` / `shadow-replay`, `WORKFLOW_SHADOW_MODE` และตาราง `workflow_shadow_comparisons`
- [x] backend 1,305 tests ผ่าน · Pint 726 ไฟล์สะอาด

### ปิดงานสองรายการที่เหลือ — 2026-09-12

- [x] **ถอดคอลัมน์ `workflow_instances.is_shadow` ทิ้ง** — migration `2026_09_24_130000_drop_the_shadow_flag_from_workflow_instances` · ถอดพารามิเตอร์ `$shadow` ออกจาก `WorkflowEngine::start()` / `startOnVersion()` และ `LeaveWorkflowProvisioner::backfill()` ด้วย · คอลัมน์ที่ไม่มีผู้เขียนแต่ยังมีผู้กรอง (`WorkQueueService`) เป็นกับดัก — เผลอเขียน true กลับเข้ามาเมื่อไร เรื่องนั้นหายจากคิวงานเงียบ ๆ
- [x] **หน้าจอออกแบบสายอนุมัติแบบอิงเวอร์ชัน — ตัดสินใจว่า "ไม่ทำ" ไม่ใช่ "เลื่อน"** — `approval_steps` เป็นหน้าจอตั้งสายอนุมัติถาวรของระบบ ไม่ใช่ของเก่าที่รอถูกแทน · ดู ADR-APPROVAL-CHAIN-EDITOR-20260912

## MENU NAMING — 2026-09-10

- [x] สำรวจชื่อเมนูทั้ง 64 รายการใน `MenuSeeder::TREE` และจัดกลุ่มปัญหา 4 แบบ (ชื่อซ้ำ · ไม่บอกขอบเขต · ศัพท์เทคนิค · ยาวเกินแถบเมนู)
- [x] MENU-NAME-01: แก้ `title_th`/`title_en` 14 รายการที่ `MenuSeeder::TREE` โดยไม่แตะ `code`/`route_path`/permissions
- [x] MENU-NAME-02: migration `2026_09_24_120000_rename_menus_for_readability` พาชื่อใหม่ไปยังระบบที่ติดตั้งแล้ว + ล้าง cache `user_menu_tree_*` · เขียนเฉพาะ `title_th`/`title_en` ของ 14 รายการที่ระบุไว้ ไม่แตะ `code`/เส้นทาง/สิทธิ์ และอ่านค่าจาก `MenuSeeder::TREE` ไม่พิมพ์ชื่อซ้ำไว้ในตัว migration
- [x] MENU-NAME-03: แก้ `meta.title` ใน `frontend/src/router/index.js` ให้ breadcrumb ตรงกับแถบเมนู
- [x] MENU-NAME-04: แก้หัวข้อบนหน้าจอ 13 หน้าให้ตรงกับชื่อเมนูใหม่
- [x] MENU-NAME-05: อัปเดต `MenuDriftTest::TREE_FINGERPRINT` และ `LATEST_MENU_SYNC_MIGRATION`
- [x] บันทึกเกณฑ์การตั้งชื่อเมนูไว้เป็น ADR-MENU-NAMING-20260910 เพื่อให้เมนูที่เพิ่มในอนาคตตั้งชื่อตามแนวเดียวกัน
- [x] MENU-NAME-06: เก็บกวาดข้อความที่อ้างชื่อเมนูเดิม — `DocumentTools.vue` ชี้ไปเมนู "จอง/ออกเลข" และคำอธิบายสิทธิ์ใน `PermissionCatalogSeeder` ยังใช้ชื่อเก่า
- [x] MENU-NAME-07: เทสต์ใหม่ใน `MenuDriftTest` พิสูจน์ว่า migration เปลี่ยนชื่อเมนูของระบบที่ติดตั้งแล้วได้จริง — ของเดิมตรวจแค่ว่าไฟล์มีอยู่ ซึ่ง migration เปล่า ๆ ก็ผ่าน
- [x] รัน quality gates: backend 1,307 tests ผ่าน · Pint 727 ไฟล์ · frontend eslint / vitest 168 / vite build ผ่าน

## LEAVE MODULE AUDIT — 2026-09-05

- [x] ตรวจโมดูลลาและเพิ่ม regression tests ยืนยัน 4 ปัญหา — `docs/checkpoints/LEAVE-MODULE-AUDIT-2026-09-05.md`
- [x] LEAVE-AUDIT-01 (P1): ปิดอ่านร่างผู้อื่นทั้ง API รายฉบับ/PDF/Word/evidence รวม Super Admin
- [x] LEAVE-AUDIT-02 (P1): ปิดแก้ทับใบที่ยื่นแล้ว; ร่างของตนใช้การคำนวณใหม่และ lock
- [x] LEAVE-AUDIT-03 (P1): บันทึกแทนตัดยอด/seal/sync attendance/audit ใน transaction และตรวจ scope/deny
- [x] LEAVE-AUDIT-04 (P1): ปฏิเสธใบลาข้ามรอบปีที่ด่านนโยบายร่วมทุกทางเข้า
- [x] ผลการแก้: LeaveModuleAuditTest 11 passed / 41 assertions ไม่ข้าม regression เดิม — `docs/checkpoints/LEAVE-MODULE-FIX-2026-09-05.md`
- [x] รันชุดเต็มหลังแก้พร้อมไฟล์ repository ครบ: 1,067 passed / 2 failed / 0 skipped; Pint 662 files และ frontend lint/typecheck/81 tests/build ผ่าน
- [x] แก้ Quality gate ทั้ง 2 ตามคำสั่งผู้ใช้: เชื่อม UI กับ API ที่ขาด, ตรวจ alias/action/middleware และ URL จาก composable; pin minishlink/web-push เป็น 11.0.0 — ตรวจซ้ำ 2026-09-07: backend 1,141 passed / 0 failed / 0 skipped (4,623 assertions); frontend 127 tests, lint/typecheck/build และ Pint 679 files ผ่าน — `docs/checkpoints/API-SURFACE-FIX-2026-09-07.md`
- [x] ป้องกันปกปิดผิดฉบับเมื่อสลับ preview หรือไฟล์เก่าโหลดช้า; เพิ่ม regression 3 กรณี รวม apiSurfaceFlows 17 passed
- [x] รันครบ: backend 1,042 passed / 4 failed / 8 skipped (3,842 assertions); 4 failed เป็น regression ใหม่ตรงข้อค้นพบ; Pint 660 files และ frontend lint/typecheck/81 tests/build ผ่าน ยังไม่แก้ application code

## SARABAN VISIBILITY AUDIT — 2026-09-05

- [x] ตรวจ registry/menu/routes/permission/ABAC/flags/guards/UI/API และ role ปัจจุบันครบ 10 ประเด็น — `docs/checkpoints/SARABAN-VISIBILITY-AUDIT-2026-09-05.md`
- [x] ยืนยัน runtime: Super Admin ผ่าน SARABAN 32/32; module enabled; DB มีเมนูลูก 6/13; ขาด 7 เมนูที่มี UI แล้ว
- [x] รอบตรวจนี้ backend 1,039 passed / 8 skipped / 3,825 assertions; Pint 657 files; frontend lint/typecheck/81 tests/build ผ่าน (ผลใหม่ ไม่แก้ผลประวัติ TEST-REVIEW)
- [ ] SARABAN-VIS-01: sync เมนูที่ขาดแบบจำกัดขอบเขตและเพิ่มตรวจ installed-DB drift
- [ ] SARABAN-VIS-02: ทำ parent menu / options / policy read / authority / exception permission mapping ให้ตรง API
- [ ] SARABAN-VIS-03: ผูก UI สำหรับ finalize, endorse, tracking, comparison, redaction, retention และ saved views ตามรายงาน
- [ ] SARABAN-VIS-04: ทบทวน case read scope, confidentiality และ explicit deny; ตรวจ role + scope ตามหน้าที่ก่อนมอบสิทธิ์
- [ ] SARABAN-VIS-05: เติม Module Registry metadata ให้ตรง implementation

## TEST-REVIEW — 2026-09-05

- [x] รัน backend suite, Pint, frontend lint/typecheck/tests/build และรีวิว working tree — `docs/checkpoints/TEST-REVIEW-2026-09-05.md`
- [x] เพิ่ม regression tests ยืนยัน IDOR การปิดสารบบและทำลายเอกสารข้ามหน่วยงาน (2 failed; ยังไม่แก้ application code)
- [ ] REVIEW-20260905-01 (P1): ตรวจ resource scope ก่อนอนุมัติ/ทำลายเอกสารและแสดงคิวครบกำหนด
- [ ] REVIEW-20260905-02 (P1): เพิ่ม case policy/scope สำหรับการอ่านและแก้สารบบเรื่อง
- [ ] REVIEW-20260905-03 (P1): ป้องกัน race ระหว่าง legal hold กับ disposal ด้วยล็อกร่วมและ concurrency test
- [ ] REVIEW-20260905-04 (P1): bind MalwareScanner กับ ClamAvScanner ที่ Core และพิสูจน์การ resolve จริง
- [ ] Quality gates รอบสุดท้ายยังไม่ผ่าน: backend 119 failed / 914 passed / 8 skipped; Pint 1 style issue ใน OperationalPermissionSeeder

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

## LEAVE-SETTINGS — ปิดข้อค้างจากการตรวจการตั้งค่า/แบบฟอร์ม/รายงาน (2026-09-07)

- [x] LS-01 นโยบายที่ใบลาอ้างอยู่ต้องไม่ถูกเขียนทับ — **PASS 2026-09-09** · ออกฉบับใหม่พร้อมช่วงมีผล (ADR-POLICY-VERSION-20260909)
- [x] LS-02 นโยบายที่ยังไม่มีผล/หมดอายุ/ปิดใช้งาน ต้องไม่แตะยอดปัจจุบัน — **PASS 2026-09-09**
- [x] LS-03 วันที่มีผลต้องไม่เลื่อนเมื่อเปิดแก้ไข — **PASS 2026-09-09** · date-only ทั้ง API และหน้าจอ
- [x] LS-04 ใบลาเก่าต้องพิมพ์ด้วยแบบฟอร์มที่ pin ไว้ — **PASS 2026-09-09**
- [x] LS-05 ปีงบประมาณที่ถูกใช้แล้วลบไม่ได้ — **PASS 2026-09-09** · กันปีปัจจุบันด้วย
- [x] LS-06 รายงานยอดคงเหลือต้องเจอข้อมูลเมื่อเลือกปี พ.ศ. — **PASS 2026-09-09**
- [x] LS-07 ไฟล์ Word ต้องตรงกับชนิดที่ประกาศ — **PASS 2026-09-09** (ADR-WORD-20260909)
- [x] LS-08 แม่แบบทุกฉบับต้องมีเวอร์ชัน — **PASS 2026-09-09**
- [x] ช่องว่างของหน้าตั้งค่า: ช่องนโยบายครบ · ระยะขอบ/ฟอนต์มีผลจริง · สิทธิ์หน้าตรงกับ API · ตรวจช่องเฉพาะตามชนิด · สรุปรายงานใช้ตัวกรองเดียวกับตาราง · audit ครบทุก CRUD — **PASS 2026-09-09** · `docs/checkpoints/LEAVE-SETTINGS-FIX-2026-09-09.md`

## DESIGNER — ยกระดับเป็น Visual PDF Template Designer (แผน: `docs/VISUAL-PDF-TEMPLATE-DESIGNER-PLAN.md`)

- [x] DESIGNER-01 ระยะ 1 · ความถูกต้องของพิกัด — **PASS 2026-09-08** · pdf.js + ขนาดหน้าจริงรายหน้า, geometry endpoint, ตรวจขอบกระดาษฝั่งเซิร์ฟเวอร์ทั้งตอนบันทึกและเผยแพร่, snap/ไม้บรรทัด/ลูกศร/เลือกหลายฟิลด์/จัดแนว/กระจายระยะ/คัดลอก–วาง/undo-redo/เตือนก่อนออก · `docs/checkpoints/DESIGNER-PHASE-1-2-2026-09-08.md`
- [x] DESIGNER-02 ระยะ 2 · เปิดคุณสมบัติที่หลังบ้านรองรับแล้ว — **PASS 2026-09-08** · font/overflow/format/static_text/underline/letter_spacing/rotate/checkbox options, `ValueFormatter` ทำให้ `format` มีผลจริง, QR_CODE วาดได้, ถอด BARCODE ออกจากตัวเลือก, แก้บั๊กช่องติ๊กที่ทำให้พิมพ์ไม่ออก
- [x] DESIGNER-03 ระยะ 3 · ครบวงจรกับการตั้งค่าประเภทการลา — **PASS 2026-09-08** · `extra.<คีย์>` / `approval.step{N}.*` / `stats.leave_type_<id>.*` เข้าพจนานุกรมอัตโนมัติ, แก้ป้ายชื่อ–ปิดการใช้งานตัวแปรจากหน้าจอ, เงื่อนไขแสดง/ซ่อนฟิลด์, เปิดใช้รายประเภทการลาจากหน้าจอพร้อม audit, หน้าประเภทการลาแสดงสถานะแบบฟอร์ม · `docs/checkpoints/DESIGNER-PHASE-3-2026-09-08.md`
  - บล็อกตารางซ้ำแบบทั่วไปไม่ได้ทำในระยะนี้โดยตั้งใจ — ใช้ฟิลด์สถิติรายช่องแทน ดูเหตุผลใน ADR-DESIGNER-20260908
- [x] DESIGNER-04 ระยะ 4 · **PASS 2026-09-09** · ตรวจก่อนเผยแพร่ (error/warning), พรีวิวสามโหมดรวมใบลาจริงที่ผ่าน policy + audit, ใบสอบเทียบการพิมพ์พร้อมคำนวณค่าชดเชย, เทียบเวอร์ชัน, นำเข้า/ส่งออกชุดแบบฟอร์ม (.zip) · `docs/checkpoints/DESIGNER-PHASE-4-2026-09-09.md`
- [~] DESIGNER-05 ระยะ 5 · เปิดใช้จริงทีละประเภท — **หยุดรอคำสั่ง** · ปิด LS-04 และ LS-08 แล้ว (2026-09-09 · แม่แบบทั้ง 10 มีเวอร์ชันครบ ไม่มีการอ้างผลย้อนหลัง ไม่มีใบลาถูกแตะ) เหลือการอัปโหลด/วางฟิลด์บนแบบฟอร์มราชการจริงและการเปิดสวิตช์ ซึ่งเป็นงานและการตัดสินใจของผู้ดูแล · ดู `docs/checkpoints/DESIGNER-PHASE-5-STATUS-2026-09-09.md`
- [x] ซ้อมเปิดใช้งานครบ 3 ขั้นบนเครื่องพัฒนา — **PASS 2026-09-09** · เผยแพร่ 5 แบบฟอร์ม เปิดใช้ 6 ประเภทการลา ยื่น–อนุมัติ–พิมพ์ใบลาจริงออกจาก engine ได้ และพบ/แก้บั๊กที่จะพังบนของจริง 3 ข้อ · `docs/checkpoints/DEV-REHEARSAL-GO-LIVE-2026-09-09.md`
- [ ] รัน `php artisan document:backfill-versions` และ `php artisan document:import-forms` บนเครื่องจริงของหน่วยงาน (ตรวจ `--dry-run` ก่อนทั้งสองคำสั่ง)
- [ ] วางตำแหน่งฟิลด์ให้ตรงช่องจริงบนแบบฟอร์ม 5 ฉบับ → ตรวจ → พรีวิวจากใบลาจริง → สอบเทียบเครื่องพิมพ์ → เผยแพร่ (งานผู้ดูแลระบบ · ชุดที่ scaffold ให้เป็นตำแหน่งโดยประมาณเท่านั้น)
- [ ] เปิด `DOCUMENT_ENGINE_ENABLED` บนเครื่องจริงและเปิดใช้ทีละประเภทพร้อมเกณฑ์รับ (การตัดสินใจของเจ้าของระบบ)
- [ ] DESIGNER-06 (5.1) · โครงรองรับแบบฟอร์มโมดูลอื่น — `document_entity_types`, gateway กลาง, provider contract, ธงรายโมดูล · **ต้องแก้ schema จึงต้องขออนุมัติเปิด phase ก่อน**
- [x] กรอกข้อมูลสถานศึกษาในหน้าตั้งค่าระบบ — **เสร็จ 2026-08-20 (ผู้ดูแลกรอกแล้ว)**

## SEC-HOTFIX-01 — Security hotfix (Saraban / runtime / dependency) ✅ เสร็จ 2026-08-22

- [x] SEC-020 อัปเดต dependency ที่มี advisory โดย pin เวอร์ชัน — axios 1.19.0, overrides nanoid 3.3.18 / postcss 8.5.26 (`npm audit` = 0, `composer audit` สะอาด) + เพิ่ม audit ทั้งสองฝั่งเข้า CI
- [x] SEC-021 ปิด `filesystems.disks.local.serve` — ตัด route ที่เสิร์ฟไฟล์ใต้ `storage/app/private` โดยไม่ผ่าน authentication
- [x] SEC-022 Production runtime guard — `php artisan app:verify-environment` เป็นคำสั่งแรกของ backend/worker/scheduler container หยุดก่อน migrate เมื่อ `APP_ENV`/`APP_DEBUG`/`APP_KEY`/`APP_URL`/`local.serve` ไม่ปลอดภัย
- [x] DEPLOY-006 เลิก expose Vite dev server ใน production compose — ย้ายไป `docker-compose.dev.yml` (ผูก 127.0.0.1), compose หลักเหลือ job `frontend_build`
- [x] SEC-025 authorize `read()` และ `updateState()` ด้วย `DocumentPolicy::view` + route model binding + `saraban.classified` (403/404 สม่ำเสมอ)
- [x] AUDIT-007 audit สำหรับ read ครั้งแรก และการซ่อนหนังสือ (`document.read`, `document.state.hidden`)
- [x] SEC-024 แก้ download ให้อ่านจาก `private_uploads` (เดิมอ่าน disk `public` จึง 404 ทุกไฟล์) + `no-store` / `nosniff`
- [x] SEC-023 MIME allowlist จากเนื้อไฟล์ + allowlist นามสกุล + ชื่อไฟล์ UUID (`config/saraban.php`)
- [x] SEC-026 ตรวจ actions Saraban ทั้งสาย upload → view → download → read → pin/hide → circulate — พบและปิดช่องเวียนหนังสือให้ผู้ไม่มีสิทธิ์ และแก้ให้ผู้รับหนังสือเวียนเปิดอ่านได้จริง
- [x] REPORT-005 รายงานเลิก match ชื่อไทยของประเภทการลา — ยึด `leave_types.code_prefix` ผ่าน `config/leave_reporting.php`
- [x] UI-THAI-02 แปลข้อความ API 20 รายการ และป้าย/หัวข้อ UI ที่เหลือเป็นภาษาไทย (รวมป้ายบทบาทบนแถบเมนู)
- [x] Regression tests เพิ่ม 5 ชุด — IDOR read/state, upload/download round-trip + ไฟล์อันตราย, circulate, dependency/runtime assertions, report correctness เมื่อเปลี่ยนชื่อประเภทการลา
- [x] QUALITY-002/003/004 ESLint + `vue-tsc` typecheck + Vitest ฝั่งหน้าเว็บ ผูกเข้า CI (เดิมมีแต่ `npm run build`)
- [x] แก้บั๊กที่ linter/typecheck เพิ่งเปิดโปง — `AppButton.vue` syntax error, หน้าทะเบียนหนังสือและหน้ารายละเอียดหนังสือเปิดไม่ได้เลยจาก import ตกหล่น, ลงลายเซ็นแบบวาดใช้ไม่ได้
- [x] Verification — backend 629 tests ผ่าน (5 skipped เป็นเทสต์ระดับ repo ที่รันจริงบน CI) · Pint ผ่าน · frontend lint/typecheck/test(14)/build ผ่าน · E2E ตาม role ผ่าน nginx จริง และ Desktop 1440 / Tablet 768 / Mobile 375 ไม่มี horizontal overflow
- [ ] ทบทวนป้ายกำกับที่เป็น "ไทย (English)" ในหน้าผู้ดูแลราว 30 จุด (เช่น `รวม (SUM)`, `พิกัด GPS ละติจูด (Latitude)`) ว่าจะคงไว้เป็นคำอธิบายทางเทคนิคหรือถอดออกให้เป็นไทยล้วนตาม AGENTS.md — เป็นแบบแผนเดิมทั้งระบบ จึงควรตัดสินเป็นนโยบายครั้งเดียว
- [x] SEC-027 แผง "บัญชีทดสอบ" บนหน้าเข้าสู่ระบบกรอกทั้งอีเมลและรหัสผ่านของบัญชี seed รวมถึง Super Admin และถูก build ติดไปกับหน้าเว็บ production — ปิดไว้หลัง `import.meta.env.DEV` แล้ว bundle จริงไม่มีค่าเหล่านี้อีก และ CI ตรวจซ้ำทุกครั้ง

## MODULE-REMOVE-01 — ถอดโมดูลสารบรรณอิเล็กทรอนิกส์ (2026-08-27)
- [x] ลบ controller / model / service / policy / request / notification / provider / middleware ของโมดูล
- [x] ลบเส้นทาง `/api/saraban/*` ทั้งหมด รวมเส้นทางสาธารณะ verify ของสารบรรณ
- [x] ลบหน้าเว็บ เมนู เส้นทาง router store และ helper สถานะของโมดูล
- [x] ถอดโมดูลออกจาก `config/modules.php` / `config/module_registry.php` / `config/workflow.php` / `config/system_settings.php`
- [x] ถอดสิทธิ์ `saraban.*` ออกจาก seeder ทุกตัว โดย**คง**บทบาท "เจ้าหน้าที่สารบรรณกลาง" ไว้
- [x] ถอดรายงาน `saraban` ออกจากศูนย์รายงานกลาง
- [x] migration ทางเดียว drop ตาราง `saraban_*` 14 ตาราง + ล้างสิทธิ์/เมนู/ค่าตั้งค่า/สายงาน/ประเภทแจ้งเตือนของโมดูล
- [x] ปรับเทสต์ที่อ้างโมดูล และลบชุดเทสต์เฉพาะของโมดูล
- [x] Verification — backend 548 tests ผ่าน (5 skipped) · frontend `eslint` สะอาด · `vitest` 14 tests ผ่าน · `vite build` ผ่าน
- [x] SEC-023R allowlist กลางของไฟล์อัปโหลดที่ `config/uploads.php` + `App\Support\UploadRules` แทน SEC-023 ที่หายไปพร้อมโมดูล — ทุกจุดที่รับไฟล์ตรวจสองชั้น (เนื้อไฟล์ + นามสกุล) และปิดช่อง `svg`/`gif` ที่เปิดอยู่
- [x] Verification (SEC-023R) — backend 557 tests ผ่าน (5 skipped) · Pint ผ่าน 463 ไฟล์

## CLEANUP-01 — เก็บกวาดความซ้ำซ้อนตามรายงานสำรวจเมนู (2026-08-28)

อ้างอิง `docs/audits/MENU-FEATURE-INVENTORY-2026-08-27.md` · เจ้าของระบบตัดสินใจ 2026-08-28:
รายงานใช้ชุดล่าสุด · เมนูไดนามิกทำให้ใช้งานจริง · ของที่ไม่ใช้ถอดทิ้ง

### รอบที่ 1 — เก็บกวาดความเสี่ยงต่ำ
- [x] D9 ลบโค้ดตาย 4 controller (`LeaveController`, `LeaveAttachmentController`, `SettingController`, `Admin\ExportSettingController`) + 3 model (`LeaveRequest`, `ApprovalLog`, `Attachment`)
- [x] `DeadCodeGuardTest` — ด่านกันโค้ดตายกลับเข้ามา: controller ต้องมี route, model ต้องมีคนอ้าง, และ **route ต้องชี้ไปยังเมธอดที่มีอยู่จริง**
- [x] แก้ผลพลอยได้จากด่านใหม่ — `AttendanceIdentityMap` ถูกเรียกผ่าน `DB::table()` เปลี่ยนมาใช้ model จริง
- [x] D8 รวมช่องทางแจ้งเตือน — ลบ `TelegramNotificationService` (proxy ที่ไม่มีใครเรียก), ย้าย `TelegramService`/`LineNotificationService` เป็น `NotificationCenter\Providers\{TelegramGateway,LineGateway}`, เพิ่ม `ChannelAdapterRegistry` + `NotificationChannelTester` และให้ปุ่มทดสอบในหน้าตั้งค่าเดินผ่าน adapter ชุดเดียวกับการส่งจริง
- [x] D11 `DocumentTemplatesView.vue` เลิกใช้ `axios` ดิบ มาใช้ api client กลาง (interceptor / refresh token / error handling กลับมาทำงาน)
- [x] D3 ถอดชั้นรายงานเดิม — ลบ `ReportController`, `LeaveExcelService`, `pdf/report.blade.php` และ 3 route (`/reports/summary`, `/reports/export-excel`, `/reports/export-pdf`) ที่ไม่มีหน้าใดเรียกเลย
- [x] D3 ยกชั้นรายงานกลางให้ใช้งานได้จริง — แปลชื่อรายงาน/คอลัมน์/ยอดรวมเป็นไทยทั้งชุด, PDF ใช้ฟอนต์ TH Sarabun, เพิ่มสารบัญ `GET /api/reporting` และรายชื่อหน่วยงาน `GET /api/reporting-org-units`
- [x] หน้าใหม่ `ศูนย์รายงานกลาง` (`/report-center`) — เลือกรายงาน 8 ชนิด กรองตามช่วงวันที่/หน่วยงาน/ประเภทบุคลากร และส่งออก PDF/Excel/Word/CSV
- [x] แก้บั๊กปีงบประมาณใน `ReportFilter` — ทางถอยเอาเลข พ.ศ. ไปประกอบเป็นวันที่ตรง ๆ ช่วงข้อมูลจึงเพี้ยน 543 ปีเมื่อยังไม่มีข้อมูลปีงบประมาณ
- [x] แก้ 3 route ที่ชี้ไปยังเมธอดที่ไม่มีอยู่จริง — `POST /admin/users/import` (นำเข้า CSV ใช้ไม่ได้มาตลอด), `GET /admin/departments-list` และ `GET /admin/roles/{role}` (ไม่มีใครใช้ ถอดทิ้ง)
- [x] รวมช่องตัวกรองหน่วยงานเหลือเส้นทางเดียว `GET /api/departments` (เดิมหน้ารายงานการลาเรียก endpoint ที่ไม่มีอยู่ ส่วนหน้ารายงานลงเวลาเรียกเมธอดที่ไม่มีอยู่)
- [x] `UserImportTest` 6 เคส — นำเข้าสำเร็จพร้อมบทบาท/ประวัติ/สิทธิ์วันลา, ไม่รับรหัสผ่านจากไฟล์, ข้ามแถวเสียพร้อมเลขบรรทัด, ปฏิเสธไฟล์ที่คอลัมน์ไม่ครบ, บังคับสิทธิ์

### รอบที่ 2 — เมนูมีแหล่งความจริงเดียว (D1)
- [x] เขียน `MenuSeeder` ใหม่เป็นแหล่งความจริงเดียว (`MenuSeeder::TREE`) 6 กลุ่ม 33 รายการ ตรงกับ `router/index.js` ทุกเส้นทาง และลบเมนูที่ชี้ไปยังหน้าที่ไม่มีอยู่
- [x] migration `2026_08_28_090000_resync_menus_with_application_routes` ซิงค์เมนูของระบบที่ติดตั้งไปแล้ว
- [x] `AppMenuNav.vue` — แถบเมนูตัวเดียวที่วาดจาก `/api/user/menus` ใช้ร่วมกันทั้งจอใหญ่และมือถือ แทนโค้ดที่เขียนตายตัวซ้ำ 2 ก๊อป (`App.vue` 1,097 → 529 บรรทัด)
- [x] `MenusManagementView` จัดการเมนูได้จริง — เปิด/ปิดเมนู และแก้สิทธิ์ที่ใช้มองเห็นเมนู มีผลกับแถบเมนูทันที (เดิมหน้านี้ไม่แตะเมนูเลยและถูกซ่อนไว้)
- [x] `MenuIntegrityTest` 8 เคส — เมนูตรงกับ TREE, ลบเมนูที่ตายแล้ว, ชื่อสิทธิ์ต้องมีจริง, ซ่อนตามสิทธิ์, ซ่อนตามโมดูลที่ปิด และ (บน CI) ทุก path ต้องมีใน router + ทุกหน้าที่ต้องล็อกอินต้องมีเมนูชี้ถึง
- [x] Verification — backend 573 tests ผ่าน (7 skipped) · Pint 460 ไฟล์ · frontend `eslint` สะอาด · `vite build` ผ่าน · ตรวจจริงบนเบราว์เซอร์: เมนูไดนามิกแสดงครบ ปิดเมนูแล้วหายจากแถบเมนูทันที และศูนย์รายงานกลางแสดงข้อมูลจริง

### รอบที่ 3 — รวมระบบเอกสารและหน้าที่ซ้อนทับ (D2, D10)
- [x] D2 ย้ายการจัดการแม่แบบชนิด HTML เข้าศูนย์จัดการเอกสาร — เพิ่ม 5 endpoint บน `document-engine` (แก้เนื้อหา, สารบัญตัวแปร, พรีวิว, ชุดตั้งต้น, ลบแม่แบบ) แล้วลบ `Admin\DocumentTemplateController` กับ 7 route เดิม
- [x] D2 หน้า `/admin/document-templates` ถูกยุบเข้า `/admin/document-center` (คงเส้นทางเดิมเป็น redirect) — ศูนย์จัดการเอกสารมีปุ่ม "แก้เนื้อหา / จัดการเวอร์ชัน / ลบ" และปุ่มชุดแม่แบบตั้งต้นครบในหน้าเดียว
- [x] D2 `LeaveTypesManagementView` ชี้ไปยัง endpoint และหน้าใหม่
- [x] D10 ยุบ `/leave-services` เข้า `/leaves` — หลักฐานประกอบการลา ประวัติการดำเนินการ และการขอยกเลิกบางช่วง ย้ายมาอยู่ในแถบขยายของแต่ละใบลา (คงเส้นทางเดิมเป็น redirect)
- [x] **พบระหว่างทาง: แหล่งประกาศ route ที่สาม** — `main.js` เรียก `router.addRoute()` อีก 8 เส้นทางโดยใช้ชื่อ route ซ้ำกับ `router/index.js` ซึ่ง Vue Router ถือว่า "แทนที่" ผลคือเงื่อนไขสิทธิ์และชื่อหน้าที่เขียนไว้ถูกทิ้งเงียบ ๆ และ `/profile/access-history` ถูกย้ายไป `/my-access-history` โดยไม่มีใครรู้ — ถอดออกทั้งหมด เหลือ `router/index.js` ที่เดียว พร้อม redirect ของ path เดิม
- [x] แก้ผลพลอยได้จากข้อบน — `/verify-leave` ในไฟล์ router ประกาศไว้แบบไม่มี `:hash` ทั้งที่ QR บนเอกสารชี้มาที่ `/verify-leave/{hash}` เสมอ และปรับสิทธิ์ของ `admin-leave-governance` / `admin-leave-policies` ให้ตรงกับที่ฝั่ง server บังคับจริง
- [x] `DocumentEngineTest` เพิ่ม 4 เคส — แก้เนื้อหาแล้วออกเวอร์ชันใหม่, endpoint เดิมหายไปแล้ว, พรีวิวรายงานข้อผิดพลาดแทนที่จะพัง, บังคับสิทธิ์

### รอบที่ 4 — Workflow cutover และสารบัญสิทธิ์ (D4, D5)
- [x] D4 `LeaveApprovalCoordinator` — จุดตัดสินเดียวของสายอนุมัติใบลา อ่านสวิตช์ `workflow.engine_is_source_of_truth` ที่เตรียมไว้ตั้งแต่ LEGACY-05 แต่ **ไม่เคยมีโค้ดส่วนใดอ่านเลย** การ cutover จึงทำได้จริงเป็นครั้งแรก
- [x] D4 การเขียนข้อมูลเป็นชุดเดียวกันทั้งสองเส้นทาง — `leave_approvals`, `leaves.approval_level`, `leaves.status`, สิทธิ์วันลา และ audit log (dual-write ตามข้อกำหนดข้อ 3 ของ 05-4)
- [x] D4 รายการ "ใบลาที่ต้องอนุมัติ" ถามผู้ตัดสินคนเดียวกับตอนกดอนุมัติจริง (เดิม `ApprovalController` เรียก resolver เอง จะเพี้ยนทันทีที่ cutover)
- [x] D4 `LeaveApprovalCutoverTest` 14 เคส — รัน 6 สถานการณ์เดียวกันทั้งปิดและเปิดสวิตช์ แล้วยืนยันว่าผลตรงกันทุกจุด
- [x] D5 `PermissionCatalogSeeder` — สารบัญชื่อสิทธิ์ที่เดียว (มาตรฐาน 39 + ชุดเดิม 10) สร้าง**ชื่อ**อย่างเดียว ไม่แจกสิทธิ์ให้ใคร + migration สำหรับระบบที่ติดตั้งแล้ว
- [x] D5 แก้บั๊กที่พบ — `leave.management.manage` และ `system.role.manage` ถูกโค้ดบังคับใช้แต่ไม่เคยถูกสร้าง endpoint และเมนูที่อ้างถึงจึงใช้ได้เฉพาะ Super Admin
- [x] D5 ลบ `StandardRolesAndPermissionsSeeder` — สร้างบทบาทชื่อไทยอีก 7 ตัวที่ระบบไม่ได้ใช้ และไม่เคยถูกเรียกตอนติดตั้ง
- [x] `PermissionCatalogTest` 5 เคส — ทุกชื่อที่ route, โค้ด และเมนูอ้างถึงต้องอยู่ในสารบัญ และการ seed ต้องไม่ผูกสิทธิ์กับบทบาทใด
- [x] Verification — backend 596 tests ผ่าน (7 skipped) · Pint 463 ไฟล์ · frontend `eslint` / `vitest` 14 / `vite build` ผ่าน · ตรวจจริงบนเบราว์เซอร์ทั้งเมนูไดนามิก ศูนย์รายงานกลาง ศูนย์จัดการเอกสาร และหน้ารายการใบลาที่รวมบริการแล้ว

### ยังไม่ได้ทำ — ต้องมีคำสั่ง/หลักฐานเพิ่ม
- [x] **เปิดสวิตช์ `WORKFLOW_ENGINE_SOURCE_OF_TRUTH=true`** — ทำแล้ว 2026-09-12 · หลักฐานที่ใช้ตัดสินใจคือเทสต์ parity ที่รันสถานการณ์เดียวกันทั้งเปิดและปิดสวิตช์ ไม่ใช่ตัวเลขผลเทียบของ shadow ดู ADR-WORKFLOW-CUTOVER-20260912
- [x] **นำสาย "ขอยกเลิกใบลา" เข้า workflow engine** — ทำแล้วใน CLEANUP-02 · ทั้งสองสายเดินบนโครงเดียวกัน
- [ ] **ถอดชื่อสิทธิ์ชุดเดิม (D5 ระยะสอง)** — ต้องย้ายการแจกสิทธิ์ของทุกบทบาทไปยังชื่อมาตรฐานก่อน มิฉะนั้นผู้ใช้ปัจจุบันที่ถือชื่อชุดเดิมจะหลุดสิทธิ์ทันที
- [ ] **รวม audit log 3 ระบบ (D6)** — `AuditLog` / `LeaveAuditLog` / `LeaveActionLog` ต้องใช้ migration ย้ายประวัติทั้งหมด เข้าข่าย stop condition เรื่อง schema conflict และห้ามลบ record ประวัติ
- [ ] **รวมสองหน้าสรุปการลงเวลา (D10 ส่วนที่เหลือ)** — `AttendanceSummaryView` กับ `AttendanceOTSummaryView` โครงเหมือนกันแต่คนละ endpoint และคนละสิทธิ์
- [ ] **รายงานเฉพาะกิจที่ยังไม่ผ่านชั้นรายงานกลาง** — บัตรลา คัดกรองเลื่อนเงินเดือน ทะเบียนคุมการลา และสรุปลงเวลา/OT เป็นเอกสารราชการที่มีรูปแบบตายตัว การบังคับให้ผ่านชั้นตารางกลางจะทำให้รูปแบบเสีย จึงเสนอให้คงไว้และทบทวนเป็นรายฉบับ
- [ ] **`/admin/modules` ยังเปิด-ปิดโมดูลด้วยกลไกที่สาม** (`enable_attendance_menu` / `enable_leave_menu` ใน settings) ซ้อนกับ `config/modules.*.enabled` ของ ModuleRegistry ที่เมนูไดนามิกใช้จริง — ควรรวมให้เหลือกลไกเดียว

## CLEANUP-02 — ปิดงานที่เหลือให้พร้อมขึ้น production (2026-08-28)

คำสั่งเจ้าของระบบ: "ดำเนินการแก้ที่เหลือให้ครบ หรือหากไม่จำเป็นลบทิ้งได้เลย
ต้องการให้พร้อมสำหรับขึ้น production ทุกฟีเจอร์ ทุกฟังก์ชัน ทุก Actions"

### สแกนหา "ปุ่มที่กดแล้วพัง" ทั้งระบบ
- [x] เทียบทุกการเรียก API ของหน้าเว็บกับ route ที่มีจริง — **ครบทุกเส้นทาง ไม่มีการเรียกที่ตกหล่น**
- [x] เทียบกลับด้าน พบ endpoint 11 เส้นที่ไม่มีหน้าจอใดเรียกเลย แยกเป็นสองกลุ่มและจัดการครบ
- [x] `ApiSurfaceContractTest` — ด่านผูกสองฝั่งเข้าด้วยกัน endpoint ที่ไม่มีใครเรียกต้องมีเหตุผลกำกับเสมอ

### ฟีเจอร์ที่สร้างเสร็จแล้วแต่ไม่มีหน้าจอ — สร้างให้ใช้งานได้
- [x] **การขอแก้ไขเวลาปฏิบัติงาน** — มีสายอนุมัติ audit และการแจ้งเตือนครบตั้งแต่ LEGACY-08 แต่ไม่มีหน้าจอ และไม่มี endpoint สำหรับ "ดูรายการ" เลย · เพิ่ม `GET /attendance-corrections` (ของตนเอง) กับ `GET /admin/attendance-corrections` (ของผู้ตรวจสอบ ตามขอบเขตหน่วยงาน) + ปุ่ม "ขอแก้ไข" ในหน้าประวัติการลงเวลา และรายการอนุมัติในหน้ารายงานการลงเวลา
- [x] **การตั้งค่าการแจ้งเตือนรายบุคคล** — Notification Center อ่าน preference ตอนส่งจริงมาตั้งแต่ LEGACY-10 แต่ไม่มีหน้าจอให้ตั้ง · เพิ่มตารางเลือกช่องทางรายเหตุการณ์ในหน้าโปรไฟล์ (ส่ง `default_channels` มาด้วยเพื่อไม่ให้แสดงว่าปิดทั้งที่เปิดอยู่)
- [x] **จัดการประเภทบุคลากร** — endpoint สร้าง/แก้ไขมีมาตลอดแต่ไม่มีหน้าจอ ผู้ดูแลจึงเพิ่มประเภทเองไม่ได้ ทั้งที่ `AGENTS.md` ห้าม hard-code ประเภทบุคลากร
- [x] **สถานะคำสั่ง OT** — `GET /overtime/my-active` เดิมคืนแค่ record ล่าสุด บอกไม่ได้ว่าลงเวลา OT ได้หรือยัง · เปลี่ยนให้ตอบตามเงื่อนไขเดียวกับ `checkIn()` และแสดงบนหน้าลงเวลา ผู้ใช้จึงรู้ล่วงหน้าแทนที่จะกดแล้วเจอข้อความปฏิเสธ
- [x] **เลิกใช้เวอร์ชันแบบฟอร์ม** — endpoint `retire` มีมาตั้งแต่ DOC-ENGINE แต่ไม่มีปุ่มเรียก

### ของที่ไม่ใช้ — ถอดทิ้ง
- [x] `SecureExportSettingController` + 3 route `/admin/export-settings` — หน้าตั้งค่าบันทึกผ่าน `/admin/settings` ทั้งหมดแล้ว
- [x] `GET /admin/permissions/user-counts` — ค้างจากหน้าสรุปสิทธิ์เดิมที่ถูกแทนที่
- [x] `GET /fiscal-year/current` — ไม่มีผู้เรียก
- [x] `GET /leave-services` + `LeaveGovernanceWorklistController::myLeaves` — ค้างจากหน้าที่ถูกยุบไปแล้ว
- [x] `GET /admin/roles/{role}` — ไม่มีเมธอดและไม่มีผู้เรียก
- [x] getter ของ auth store 10 ตัวที่เหลือไว้ช่วยแถบเมนูที่เขียนตายตัว + เงื่อนไข `hasPermission('approvals')` ที่อ้างสิทธิ์ที่ไม่มีอยู่จริง

### รวมกลไกที่ซ้ำซ้อน
- [x] **D5 ระยะสอง — ด่านสิทธิ์เหลือทางเดียว** · `permission:` ของ Spatie ไม่รู้จักทั้งชื่อที่เทียบเท่ากันและ explicit deny ทุก route จึงต้องเขียนชื่อชุดเดิมคู่กับชุดมาตรฐาน · เปลี่ยนเป็น `can:` และ `ability:` (middleware ใหม่ที่เดินผ่าน Gate) ชื่อชุดเดิมหายจาก route ทั้งหมด และ **explicit deny มีผลกับ route แล้ว** (เดิมหลบได้)
- [x] `/api/user` ส่ง `permission_names` ที่รวมชื่อเทียบเท่าแล้ว หน้าเว็บจึงเช็คชื่อมาตรฐานชื่อเดียว — router meta และ `MenuSeeder` ถอดชื่อชุดเดิมออกหมด
- [x] `MenuService` ตัดสินด้วย Gate แทนการเทียบชื่อดิบ เมนูจึงรู้จักชื่อเทียบเท่าและ explicit deny เช่นกัน
- [x] **D10 ที่เหลือ** — `useAttendanceSummary` composable รวมตัวกรอง รายชื่ออ้างอิง และปุ่มดาวน์โหลดของสองหน้าสรุปการลงเวลา (ตารางยังแยกกันตามรูปแบบเอกสารราชการ) · แก้ไปด้วย: ทั้งสองหน้าเรียก `/admin/departments` ที่ต้องมีสิทธิ์ `manage_departments` ช่องตัวกรองจึงว่างเปล่าสำหรับผู้ดูรายงาน และหน้า OT ไม่เคยแสดงข้อความผิดพลาดเลย
- [x] **กลไกเปิด-ปิดโมดูลเหลือชุดเดียว** — `ModuleStateResolver` เอาค่าตั้งค่า `module_{code}_enabled` มาเติมทับ `config('modules.*.enabled')` ตอน boot · หน้า "โมดูลระบบ" เปลี่ยนมาตั้งค่ากลไกจริง (เดิมเขียน `enable_leave_menu` ที่ไม่มีอะไรอ่านอีกแล้ว จึงกดแล้วไม่มีอะไรเกิดขึ้น) พร้อมแจ้งโมดูลที่จะถูกปิดตามไปด้วย
- [x] **D6 — ประวัติการดำเนินการอ่านจากทะเบียนกลางที่เดียว** · `leave_audit_logs` และ `leave_action_logs` ส่งต่อเข้า `audit_logs` ให้เองอยู่แล้วผ่าน model hook แต่หน้าประวัติอ่านเฉพาะตารางแรก ผู้ใช้จึง **ไม่เห็นการยื่นใบลาและการขอยกเลิกเลย** · เปลี่ยนไปอ่าน `audit_logs` และแสดงชื่อผู้กระทำ

### D4 — สาย "ขอยกเลิกใบลา" เข้า Workflow Engine (เงื่อนไขก่อน cutover)
- [x] `LeaveCancellationWorkflowProvisioner` — สายที่สองบนโครงเดียวกับสายขอลา ต่างกันแค่ชุดขั้นตอน แถวสรุป และคอลัมน์ระดับ
- [x] `WorkflowEngine::instanceFor()` รับ definition code แล้ว — ใบลาหนึ่งใบมีสอง instance จึงต้องระบุสายเสมอ มิฉะนั้นหยิบผิดสาย
- [x] `LeaveCancellationController` ตัดสินผ่าน `LeaveApprovalCoordinator` เหมือนสายขอลา (เดิมเป็นตรรกะเดินสายชุดที่สองที่ไม่เคยถูกครอบด้วย shadow)
- [x] ระบบออกเวอร์ชันของสายอนุมัติให้เองถ้ายังไม่มี — เปิดสวิตช์ cutover บนระบบที่ยังไม่ได้ seed แล้วไม่พัง
- [x] `LeaveCancellationCutoverTest` 9 เคส — สถานการณ์เดียวกันทั้งเปิดและปิดสวิตช์ + ยืนยันว่าสองสายแยก instance กันจริง

### Verification
- [x] backend **626 tests ผ่าน** (5 skipped เป็นเทสต์ระดับ repo ที่ CI บังคับ) · Pint 470 ไฟล์
- [x] frontend `eslint` สะอาด · `vitest` 14 ผ่าน · `vite build` ผ่าน
- [x] ตรวจจริงบนเบราว์เซอร์ — ขอแก้ไขเวลา, รายการอนุมัติคำขอแก้ไขเวลา, ตั้งค่าการแจ้งเตือนรายบุคคล, จัดการประเภทบุคลากร, และหน้าโมดูลระบบ (ปิดโมดูลลงเวลาแล้วเมนูหายทั้งกลุ่มทันที เปิดกลับแล้วคืนสภาพ)

### คงไว้โดยตั้งใจ พร้อมเหตุผล
- รายงานเฉพาะกิจ (บัตรลา · คัดกรองเลื่อนเงินเดือน · ทะเบียนคุมการลา · สรุปลงเวลา/OT) **ไม่ผ่านชั้นรายงานกลาง** เพราะเป็นเอกสารราชการที่มีรูปแบบตายตัว การบังคับให้ผ่านชั้นตารางกลางจะทำให้รูปแบบเสีย
- `leave_action_logs` **ไม่ถูกยุบ** เพราะฝั่งเอกสารใช้เป็น "ข้อมูล" (หาผู้ขอยกเลิกและวันที่มาพิมพ์ลงแบบฟอร์ม) ไม่ใช่แค่ประวัติ
- ชื่อสิทธิ์ชุดเดิมยัง **ไม่ถูกลบออกจากฐานข้อมูล** เพราะผู้ใช้ปัจจุบันถืออยู่ — route และหน้าเว็บเลิกอ้างแล้ว การลบชื่อทำได้หลังย้ายการแจกสิทธิ์ของทุกบทบาทไปยังชื่อมาตรฐานครบ

### เหลือรายการเดียว — ต้องมีหลักฐานจากการใช้งานจริง
- [x] **เปิด `WORKFLOW_ENGINE_SOURCE_OF_TRUTH=true`** — ทำแล้ว 2026-09-12 (ดูหัวข้อบนสุดของไฟล์นี้)

### ปิดท้าย — ด่านความพร้อม production ที่ยังไม่เคยรัน (2026-08-28)
- [x] **SEC-028 บัญชีที่ติดตั้งมาพร้อมรหัสผ่านตายตัว** — `DatabaseSeeder` สร้างผู้ใช้ 6 คนรวม Super Admin ด้วยรหัส `1111111111111` ถึง `6666666666666` · SEC-027 เอาค่าเหล่านี้ออกจากหน้าเข้าสู่ระบบไปแล้ว แต่ **ตัวบัญชียังถูกสร้างทุกครั้งที่ `db:seed --force`** ระบบที่เพิ่งขึ้นจึงเปิดให้ใครก็เข้าเป็นผู้ดูแลระบบสูงสุดได้ · ตอนนี้นอก local/testing สร้างผู้ดูแลระบบบัญชีเดียว รหัสผ่านมาจาก `ADMIN_PASSWORD` ไม่ตั้งก็สุ่มให้แล้วพิมพ์ครั้งเดียวตอนติดตั้ง ชุดบัญชีทดลองต้องสั่ง `SEED_DEMO_ACCOUNTS=true` เท่านั้น
- [x] **ติดตั้งใหม่แล้วได้กลุ่มเมนูเกินมาหนึ่งกลุ่ม** — `DocumentEngineSeeder::syncMenu()` เพิ่ม `admin.document_center` ซ้ำกับที่มีอยู่แล้วในโครงหลัก และกิ่งแม่ที่มันหาไม่มีอยู่จริง เมนูซ้ำจึงลอยขึ้นเป็นกลุ่มบนสุด · ถอดออก + `MenuIntegrityTest` รัน seeder ครบชุดเป็นด่านกันซ้ำ
- [x] **ลบ seeder ที่รันแล้วพัง/ทำลายข้อมูล** — `PurgeAndMigrateTo7StandardRolesSeeder` (เรียก seeder ที่ถูกลบไปแล้ว และงานของมันคือลบ role ทิ้งทั้งชุด) · `UpdateUserNamesSeeder` (เขียนทับชื่อบุคลากรจริงด้วยชื่อสมมติ)
- [x] `composer audit` ไม่พบช่องโหว่ · `npm audit --audit-level=high` 0 vulnerabilities
- [x] ติดตั้งใหม่จริงด้วย `APP_ENV=production` — migrate + seed ผ่าน ได้ menus 38 · users 1 (`superadmin@example.com` รหัสสุ่ม) · permissions 48 · roles 6
- [x] backend **631 tests ผ่าน** (5 skipped) · Pint 469 ไฟล์สะอาด · frontend `eslint` / `vue-tsc` / `vitest` 14 / `vite build` ผ่านทั้งหมด

### เหลือให้เจ้าของระบบตัดสินใจ
- [x] เปิด `WORKFLOW_ENGINE_SOURCE_OF_TRUTH=true` — ทำแล้ว 2026-09-12 (ดูหัวข้อบนสุดของไฟล์นี้)
- [ ] ตั้ง `ADMIN_EMAIL` / `ADMIN_PASSWORD` ใน `.env` ของ production ก่อน `db:seed --force` (ถ้าไม่ตั้ง ระบบจะสุ่มให้และพิมพ์บนหน้าจอครั้งเดียว — ต้องบันทึกไว้ทันที)

### แก้ก่อน commit — วันลาคงเหลือ การตัดวัน และการคืนวันเมื่อยกเลิก (2026-08-29)
คำสั่งเจ้าของระบบ: "ไปดูการแสดงผลวันลาคงเหลือ การคำนวณให้ถูกต้อง การตัดวันลา การยกเลิกแล้วดูเหมือนระบบจะไม่คืนวันลา"

- [x] ตรวจวงจรชีวิตทั้งเส้นด้วย `LeaveBalanceLifecycleTest` (8 เคส ผ่าน API เส้นเดียวกับที่หน้าเว็บเรียก) — **ตรรกะฝั่ง server ถูกต้องอยู่แล้ว** ยื่น→จอง อนุมัติ→ตัด ยกเลิก/ปฏิเสธ→คืนครบ ปฏิเสธคำขอยกเลิก→ยังตัดอยู่
- [x] **หน้าจอหาแถวโควตาผิดปี** — `balances()` หาด้วยเลขปีงบประมาณตัวเดียวทุกประเภท ทั้งที่แถวถูกคีย์ด้วย `periodYear()` ของนโยบายแต่ละตัว ประเภทที่นับตามปีปฏิทินจึงอ่านคนละแถวกับที่ระบบตัดจริงตลอด 1 ต.ค. – 31 ธ.ค.
- [x] **หน้าเว็บไม่ดึงโควตาใหม่หลังทำรายการ** — มีแต่ `cancelLeave` ที่ดึง อีก 5 คำสั่งรวมถึง "อนุมัติคำขอยกเลิก" ดึงเฉพาะรายการใบลา ตัวเลขคงเหลือจึงค้าง = อาการ "ยกเลิกแล้วไม่คืนวันลา" ที่เจ้าของระบบเห็น
- [x] **guard กัน fetch ซ้ำคืนค่าชุดเก่า** — เดิมเป็นธง `true/false` แล้ว `return` เปล่า ผู้เรียกที่ชนกันจึง `await` ผ่านไปพร้อมข้อมูลเดิม · เปลี่ยนเป็นคืน promise ที่กำลังวิ่งอยู่
- [x] **ประเภทการลาที่ไม่จำกัดวันแสดง "เหลือ 0 วัน"** — แถวโควตาที่ seeder สร้างไม่ผูก `leave_policy_id` หน้าจอจึงไม่รู้ว่าไม่จำกัด · ตรวจจริงบนเบราว์เซอร์: 5 ประเภทเปลี่ยนเป็น "ไม่จำกัดโควตา" แล้ว
- [x] การยกเลิกบางช่วง (`partial-cancellation`) คืนวันลาบางส่วน — หน้าจอดึงโควตาใหม่ด้วยแล้ว

## ผลตรวจโมดูลลาและการลงเวลา — แก้ครบทั้ง 8 ข้อ (2026-08-29)
คำสั่งเจ้าของระบบ: "อนุมัติตามที่เสนอ 8 อย่าง" (รายงาน artifact ผลตรวจ 16 ประเด็น)

### ใช้งานไม่ได้จริง
- [x] **ข้อ 01 ประเภทการลาที่ไม่จำกัดวัน อนุมัติไม่ผ่านเลย** — `deductBalance()` คิด available = 0 เสมอเมื่อ `annual_quota` เป็น null แล้วเทียบกับจำนวนวันที่ขอ จึงคืน false ทุกครั้ง ผู้อนุมัติได้ 422 ใบลาค้าง Pending ตลอดไป · กระทบ 5 ประเภท · ตอนนี้ข้ามการตรวจโควตาเหมือนตอนยื่น แต่ยังบันทึก `used` ให้รายงานและทะเบียนคุมการลา
- [x] **ข้อ 02 + 03 ปีงบประมาณไม่เลื่อนเอง และขึ้นรอบใหม่แล้วหน้าวันลาคงเหลือว่างเปล่า** — คำสั่งใหม่ `leave:open-fiscal-year` ตั้งเวลารัน 1 ต.ค. 00:05 **ก่อน** คำสั่งทบวันลา (00:10) เพราะการทบเขียนทับ `carried_forward` ของแถวที่คำสั่งนี้สร้าง · เลื่อน `is_current` และสร้างแถวโควตาปีใหม่ให้บุคลากรที่ยังปฏิบัติงานทุกคนจากนโยบายที่ใช้อยู่ · รันซ้ำได้ ไม่ล้าง used/carried_forward · ทดสอบกับข้อมูลจริง: จะสร้าง 72 รายการสำหรับปีงบ 2570

### ตัวเลขในเอกสารราชการ
- [x] **ข้อ 04 ยกเลิกใบลาแล้ววันลาค้างในใบลงเวลา** — `LeaveAttendanceSync` ใหม่ทำทั้งขาไปและขากลับ · เรียก `clear()` จากทุกทางที่ปิดใบลา (ยกเลิกตอนรออนุมัติ · อนุมัติคำขอยกเลิก · ปฏิเสธ · เพิกถอน · ลบ) และเรียก `apply()` ตอนอนุมัติครบสาย ไม่ต้องรอกดปุ่มซิงค์อีกต่อไป · วันที่มีการลงเวลาจริงถูกเก็บไว้ ตัดเฉพาะหมายเหตุวันลา
- [x] **ข้อ 05 ใบลงเวลาแยกประเภทการลาด้วยการค้นคำในชื่อไทย** — เพิ่มคอลัมน์ `leave_types.attendance_bucket` ตั้งค่าเริ่มต้นจาก `code_prefix` และแก้ได้ที่หน้าจัดการประเภทการลา · เพิ่มช่อง "พักผ่อน" ในใบลงเวลา (เดิมลาพักผ่อนถูกนับเป็นลากิจ) และ "ลาไปช่วยเหลือภริยาที่คลอดบุตร" เลิกถูกนับเป็นลาคลอด
- [x] **ข้อ 06 + 07 วันหยุดคร่อมช่วงลาและลาครึ่งวัน** — `LeaveDayClassifier::dayWeight()` นับด้วยเกณฑ์เดียวกับตอนตัดโควตา เคารพทั้ง `exclude_holidays` ของนโยบายและ `leave_period` · ยอดท้ายใบลงเวลาตรงกับโควตาที่ถูกตัดจริง
- [x] **ข้อ 08 + 09 + 10 การยกเลิกลาบางช่วง** — กันคำขอที่ซ้อนทับกัน (เดิมอนุมัติสองคำขอครอบวันเดียวกันได้ คืนวันซ้ำ) · เทียบกับวันที่เหลือจริง ไม่ใช่จำนวนวันทั้งใบ · นับวันด้วยตารางเวลาของเจ้าตัว · ปรับ `paid_days`/`unpaid_days` ให้รวมกันเท่า `total_days` เสมอ (คืนวันไม่ได้รับเงินเดือนก่อน) · ล้างเฉพาะช่วงที่ยกเลิกออกจากใบลงเวลา **ไม่ขยับ start/end ของใบลา** เพราะเอกสารที่อนุมัติแล้วต้องคงช่วงวันเดิม

### เก็บกวาด
- [x] **ข้อ 11** ลงเวลาออกโดยไม่เคยลงเวลาเข้า → 422 พร้อมแนะให้ใช้คำขอแก้ไขเวลาปฏิบัติงาน · แก้ไปด้วย: `where('date')` เปลี่ยนเป็น `whereDate()` ทั้งสามจุด (คอลัมน์อาจมีส่วนเวลาติดมาแล้วหาไม่เจอเงียบ ๆ)
- [x] **ข้อ 12** ลงเวลาในวันที่ลาอนุมัติแล้ว — **ตัดสินใจ: ให้บันทึกได้แต่แจ้งผู้ตรวจสอบ** (แจ้งเตือนชนิดใหม่ `attendance.leave_conflict`) เพราะห้ามขาดจะทำให้ผู้ที่กลับมาปฏิบัติงานกลางคันแล้วยกเลิกลาทีหลังบันทึกอะไรไม่ได้เลย · สลับเป็นห้ามขาดได้ด้วยการแก้บล็อกเดียวใน `checkIn()`
- [x] **ข้อ 13** `today()` ใช้บริการเวลากลางแทน `Carbon::now()` ของเซิร์ฟเวอร์
- [x] **ข้อ 14** การทบวันลาเคารพ `period_basis` ของนโยบายแต่ละตัว
- [x] **ข้อ 15** ตั้งเวลารัน `leave:reconcile-balances` ทุกสัปดาห์
- [x] **ข้อ 16** ลบ `LeaveService::hasEnoughBalance()` — ไม่มีผู้เรียก และตรรกะข้างในผิดแบบเดียวกับข้อ 01

### Verification
- [x] backend **657 ผ่าน** (5 skipped) · เทสต์ใหม่ 18 ข้อใน `LeaveAttendanceIntegrityTest` (8) `FiscalYearRolloverTest` (4) `LeavePartialCancellationTest` (6)
- [x] Pint สะอาด · frontend `eslint` / `vue-tsc` / `vitest` / `vite build` ผ่านทั้งหมด
- [x] ตรวจจริงบนเบราว์เซอร์ — ช่อง "ช่องในใบลงเวลารายเดือน" ในหน้าจัดการประเภทการลาแสดงค่าถูกต้อง (ลาพักผ่อน → ลาพักผ่อน)

### ข้อสังเกตจากการใช้งานจริง (2026-08-29 รอบสอง)
- [x] **แบบฟอร์มใบลาพิมพ์ได้ทุกสถานะ** — ผมตีความข้อนี้สลับในรอบแรกแล้วไปจำกัดให้พิมพ์ได้เฉพาะใบที่อนุมัติแล้ว เจ้าของระบบยืนยันว่าต้องพิมพ์ใบที่ยังรออนุมัติได้ **เพื่อเอาไปเสนอผู้บังคับบัญชาลงนาม** ซึ่งเป็นขั้นตอนราชการจริง · แก้กลับแล้ว พร้อมเพิ่มคำอธิบายใต้ปุ่มว่าช่องลงนามจะยังว่างจนกว่าจะอนุมัติในระบบ
- [x] **แบบฟอร์มใบยกเลิกต้องเคยมีคำขอยกเลิกจริง** — ใบที่ถอนเองตั้งแต่ยังรออนุมัติไม่เคยมีเอกสารที่อนุมัติแล้วให้ยกเลิก เดิมมีปุ่ม "PDF ยกเลิก" ขึ้นให้ด้วย · บังคับที่ฝั่ง server
- [x] **หน้าวันลาคงเหลือแยกสองตัวเลข** — ตัวใหญ่ = คงเหลือตามสิทธิ์ (หักเฉพาะที่อนุมัติแล้ว) + แถบ "กันไว้รออนุมัติ X วัน — ยื่นเพิ่มได้อีก Y วัน" · เลือกแสดงทั้งคู่เพราะแสดงตัวแรกอย่างเดียวจะทำให้ผู้ใช้ยื่นแล้วโดนปฏิเสธโดยไม่เข้าใจ
- [x] เจอระหว่างทำ: `cancellation_status` มีค่าเริ่มต้นเป็นสตริง `'None'` ไม่ใช่ null — โค้ดที่เทียบกับ null เป็นจริงกับทุกใบ

### ร่างใบลา และปิดรูการลบ (2026-08-29 รอบสาม)
คำสั่งเจ้าของระบบ: "ใบลาที่ผ่านการอนุมัติหรือไม่อนุมัติ หรือได้รับอนุมัติยกเลิกแล้วไม่ควรลบได้ เพราะจะไม่สามารถตรวจสอบได้ · การยื่นใบลาควรมีการร่างก่อน แล้วถึงส่งอนุมัติได้ ร่างสามารถลบได้"

#### ก. ปิดรูการลบ
- [x] **ลบได้เฉพาะร่าง** — ทุกสถานะอื่นตอบ 422 พร้อมชี้ทางที่ถูก (ยกเลิกใบลา / เพิกถอนเอกสาร) · ตรวจจริงแล้ว: ผู้ดูแลระบบสูงสุดลบใบที่อนุมัติแล้วไม่ได้
- [x] **การลบเขียน audit log แล้ว** — เดิม `destroy()` ไม่เขียนอะไรเลยสักบรรทัด ลบแล้วไม่มีร่องรอยว่าใครลบ เมื่อไร
- [x] **ตัดสิทธิ์เจ้าของลบใบ Pending ของตนเองภายใน 5 วัน** — ให้ใช้ "ยกเลิกใบลา" แทน ผลต่อผู้ใช้เหมือนกันแต่ตรวจสอบย้อนหลังได้
- [x] ตรวจสิทธิ์ก่อนตรวจสถานะ ผู้ไม่เกี่ยวข้องยังได้ 403 ไม่ใช่ 422 (ไม่รั่วว่าใบลานั้นอยู่สถานะใด)
- [x] ถอดปุ่ม "ลบ" ของผู้ดูแลออกจากหน้ารายการใบลา

#### ข. สถานะร่าง
- [x] **สถานะ `Draft` มีอยู่ใน enum ของ migration แรกตั้งแต่ต้น แต่ไม่เคยมีโค้ดใช้เลย** — คนออกแบบตั้งใจไว้แต่ไม่ได้ทำ · คอลัมน์เป็น varchar แล้วจึงไม่ต้องแก้ schema
- [x] ร่าง **ไม่ออกเลขที่หนังสือ · ไม่กันโควตา · ไม่ผนึกสายอนุมัติ · ไม่แจ้งเตือน · ไม่เปิด workflow instance** — ทั้งหมดเกิดตอนกด "ส่งอนุมัติ" ใน `LeaveService::submitDraft()`
- [x] **เลขที่หนังสือไม่ขาดช่วงอีกต่อไป** — เดิมยื่นผิดแล้วลบ เลขนั้นหายตลอดกาล ทะเบียนหนังสือราชการมีช่องว่างที่อธิบายไม่ได้ · มีเทสต์ยืนยัน
- [x] ร่างเป็นของส่วนตัวจนกว่าจะส่ง — ผู้มีสิทธิ์ดูทั้งองค์กรก็ไม่เห็น และไม่โผล่ในทะเบียนคุมการลา
- [x] ร่างไม่กันช่วงเวลาของใบที่ยื่นจริง
- [x] UI: ปุ่ม "บันทึกร่าง" คู่กับ "ส่งคำขอลา" · แท็บ "ร่าง" · ปุ่ม "ส่งอนุมัติ / ลบร่าง" บนการ์ดร่าง
- [x] เจอระหว่างทำ: การตรวจใบลาซ้ำช่วงเวลามีสองที่ที่ใช้เงื่อนไขคนละชุด — ตัวใน controller ไม่ยกเว้น `Voided` และไม่ล็อกแถว · ยุบเหลือที่เดียวใน `LeaveService`

#### Verification
- [x] backend **670 ผ่าน** (8 skipped) · เทสต์ใหม่ `LeaveDraftAndDeletionTest` 10 ข้อ
- [x] Pint สะอาด · frontend eslint/vue-tsc/build ผ่าน
- [x] ตรวจกับ API จริง: บันทึกร่างได้ (เลขที่=NULL reserved=0) · ลบร่างได้ 200 · ผู้ดูแลระบบสูงสุดลบใบที่อนุมัติแล้วได้ 422 และใบยังอยู่ครบ

### สิทธิ์ปุ่ม "ตรวจสอบสถิติการลา" (2026-08-29 รอบสี่)
เจ้าของระบบแจ้งว่าบุคลากรกดปุ่มไม่ได้ แต่แอดมินกับรองกดได้ และถามว่าปุ่มนี้ใครมีหน้าที่ใช้

- [x] **ต้นเหตุ: ไม่มีบทบาทใดได้สิทธิ์ `leave.approval.hr` เลยนอกจาก Super Admin** — บทบาท `HR Head` (หัวหน้าบุคลากร) ซึ่งเป็นผู้ทำหน้าที่นี้จริงและเป็น role ที่ผูกกับ `ApprovalStep` ขั้นแรก "ตรวจสอบโดยหัวหน้าบุคลากร" กลับไม่มีสิทธิ์กดปุ่มของตัวเอง
- [x] **ที่ "รอง" กดได้ ไม่ใช่เพราะเป็นรองผู้อำนวยการ** — บัญชี `deputy@example.com` ถูกมอบบทบาท **Super Admin** ไว้ จึงผ่าน `Gate::before` ทุกกรณี · เป็นข้อมูลที่ปิดบังบั๊กนี้ไว้ตลอด
- [x] แจกสิทธิ์ให้ `HR Head` ใน `OperationalPermissionSeeder` (ติดตั้งใหม่) + migration `2026_08_29_120000` (ระบบที่ติดตั้งแล้ว)
- [x] เจอระหว่างทำ: `OperationalPermissionSeeder` มอบได้เฉพาะสิทธิ์ที่ตัวเองสร้าง อ้างชื่อจากสารบัญกลางแล้ว seeder ล้ม — แก้ให้หา id แบบ lazy
- [x] เทสต์ใหม่ 2 ข้อใน `OperationalPermissionMatrixTest` · ตรวจกับ API จริง: หัวหน้าบุคลากร 200 · พนักงานทั่วไป 403
- [ ] **ให้เจ้าของระบบตรวจ:** บัญชี `deputy@example.com` ถือบทบาท Super Admin อยู่ ถ้าบนระบบจริงรองผู้อำนวยการก็ถือบทบาทนี้ ควรลดเหลือ `Deputy Director` เพื่อให้สิทธิ์ตรงกับหน้าที่

### ทำร่างใบลาให้สมบูรณ์ (2026-09-01)
- [x] **แก้ไขร่างได้** — เดิมร่างแก้ไม่ได้ ต้องลบแล้วกรอกใหม่ทั้งใบ ซึ่งทำให้กฎ "ลบได้เฉพาะร่าง" กลายเป็นภาระ · `POST /leaves/{leave}/draft` คำนวณวัน/นโยบาย/การแบ่งวันได้รับเงินเดือนใหม่ทุกครั้ง
- [x] **`GET /leaves/{leave}`** เปิดใบลาใบเดียวเพื่อโหลดร่างกลับเข้าฟอร์ม
- [x] **ปุ่ม "แก้ไขร่าง"** → `/request?draft=<id>` โหลดค่าเดิมครบ · กดส่งจากหน้าแก้ร่างจะบันทึกทับแล้วส่งร่างเดิม ไม่สร้างใบใหม่
- [x] **ยุบตรรกะตรวจ+คำนวณคำขอลาเหลือที่เดียว** (`resolveRequest()`) ใช้ร่วมกันทั้งตอนสร้างและตอนแก้ร่าง
- [x] แนบไฟล์ใหม่ตอนแก้ร่าง = แทนที่ของเดิม ไม่สะสมไฟล์
- [x] เทสต์ใหม่ 6 ข้อ (รวมเป็น 16 ข้อใน `LeaveDraftAndDeletionTest`) · backend 678 ผ่าน
- [x] ตรวจกับ API จริงครบวงจร: บันทึกร่าง → เปิดอ่าน → แก้เป็น 3 วัน (เลขที่ยังเป็น NULL) → ส่งอนุมัติ (ได้เลข ลก.-2569-00001) → แก้หลังส่งถูกปฏิเสธ 422

#### เจอระหว่างทำ — ต้องแก้ก่อนขึ้น production
- [x] **DEPLOY-007 stack ที่ขึ้นจากศูนย์ backend วนรีสตาร์ตตลอดไป** — compose ส่ง `DB_APP_USERNAME`/`DB_APP_PASSWORD` ให้ backend แต่ไม่มีอะไรสร้างบัญชีนั้นใน MySQL เลย มีแต่ root · ที่ผ่านมาใช้ได้เพราะมีคนสร้างด้วยมือไว้ครั้งหนึ่ง · เพิ่ม `MYSQL_USER`/`MYSQL_PASSWORD` ใน service `db`
- [x] เทสต์ `LeaveBalanceLifecycleTest` แดงเองเมื่อวันที่เปลี่ยน (นับวันตามปฏิทิน) — ตรึงวันเป็นวันจันทร์เสมอ

### ตรวจความปลอดภัยและแก้ไข (2026-09-02)

คำสั่งเจ้าของระบบ: แก้ช่องโหว่และข้อผิดพลาดที่พบจากการ audit โดยไม่ลดมาตรฐานความปลอดภัย
และไม่ deploy ขึ้น Production

#### ระยะที่ 1 — ประเด็นเร่งด่วน (P1)
- [x] **SEC-024 การเปลี่ยนรหัสผ่าน** — เดิมช่อง `password` ปนอยู่ในคำขออัปเดตโปรไฟล์ทั่วไป ไม่ต้องรู้รหัสเดิม ไม่มีช่องยืนยัน · แยกเป็น `POST /profile/password` บังคับ `current_password` + `confirmed` + ความยาว 12 · เพิกถอนโทเคนอื่นตามนโยบายเดิมแล้วออกใบใหม่ให้อุปกรณ์ที่ยืนยันสำเร็จ · audit ไม่บันทึกรหัสผ่านหรือ hash · เทสต์ 10 ข้อ
- [x] **SEC-025 ถูกเตะออกจากระบบเมื่อเจอ 429/5xx** — ต้นเหตุอยู่ที่ `authStore.fetchUser()` เรียก `logout()` ใน `catch` ที่ครอบทุกความล้มเหลว ไม่ใช่ที่ rate limit · ล้างเซสชันเฉพาะ 401 · 429 ลองใหม่แบบ backoff เฉพาะคำขออ่าน (สูงสุด 2 ครั้ง เคารพ `Retry-After`) · รวมคำขอซ้ำของ `/user` `/user/menus` `/settings` `/notifications` · **ไม่ได้ผ่อนเพดาน rate limit ใด ๆ** · เทสต์ frontend 21 ข้อ
- [x] **SEC-026 การป้องกันบทบาทระบบ** — ด่านเดิมเทียบชื่อ `SuperAdmin` แต่บทบาทจริงชื่อ `Super Admin` จึงไม่เคยทำงานเลย · เพิ่ม `roles.system_key` + `is_system` (migration additive, ADR-ROLE-01) · กัน self-lockout และการลบ/ระงับผู้ดูแลคนสุดท้าย · เทสต์ 8 ข้อ
- [x] **SEC-027 race condition ในการยกเลิกใบลา** — ครอบทั้งการคืนโควตา ล้างตารางลงเวลา เปลี่ยนสถานะ และเขียน log/audit ไว้ใน transaction เดียวพร้อม `lockForUpdate()` และตรวจสถานะซ้ำหลังได้ล็อก · กดซ้ำ idempotent · ตัด `system.user.view` ออกจากสิทธิ์ยกเลิกใบลาผู้อื่น · **ทดสอบพร้อมกันจริงบน MySQL 5 คำขอขนาน → คืนโควตาครั้งเดียว**

#### ระยะที่ 2 — ความปลอดภัยที่ไม่เปลี่ยน public contract
- [x] **SEC-028 upload และลายเซ็น** — `profile_photo` เดิมไม่มีกฎ validate เลยแม้แต่ข้อเดียว · `signature_base64` ทุกจุดเชื่อชนิดไฟล์จากส่วนหัว data URI ที่ client เขียนเอง ไม่จำกัดขนาด decode ไม่ strict และตั้งชื่อไฟล์จาก `uniqid()` · รวมเป็นด่านกลาง `ImageUploadService` + `Base64Image` + allowlist ที่ `config/uploads.php` · เทสต์ 10 ข้อ
- [x] **SEC-029 file authorization** — `SecureFileAccessService` ใช้ `PermissionEvaluator` + `OrgScopeResolver` แทน `hasAnyPermission()` · รองรับ canonical/alias/explicit deny/organization scope/owner · เทสต์ 8 ข้อ
- [x] **SEC-030 ข้อมูลส่วนบุคคล** — รายการผู้ใช้ปิดบังเลขบัตรประชาชนเหลือ 4 หลักท้าย ตัดแฮชและเลขหนังสือเดินทางออก · เปิด `GET /admin/users/{user}` ที่มีอยู่แต่ไม่เคยมี route พร้อม scope check และ personal-data audit · ค้นด้วยเลขเต็ม 13 หลักผ่านแฮชจึงไม่กระทบ workflow เดิม · กันค่าที่ปิดบังเขียนทับข้อมูลจริง · เทสต์ 7 ข้อ

#### ระยะที่ 3 — UI ภาษาไทยและ Accessibility
- [x] แปล `Real-time` · `Personnel & Org Structure` · `Close` (sr-only) · `404 Not Found` · `Field Dictionary` · `Document Engine` (คงตัวระบุอังกฤษในวงเล็บพร้อมคำอธิบายไทย) · ขนาดกระดาษ Letter/Legal
- [x] เติม `aria-label` ภาษาไทย 65 จุดใน 9 หน้าจอหลัก
- [x] ข้อความอังกฤษของเฟรมเวิร์กใน 429/5xx ถูกแทนด้วยข้อความไทยที่ชั้น HTTP client
- [x] ตรวจ keyboard navigation (70 จุดโฟกัสได้ · skip link ภาษาไทย) และ responsive 390px / 768px / desktop — ไม่มี horizontal overflow

#### Verification
- [x] backend **728 ผ่าน** (8 skipped · เท่าเดิม) · 2,513 assertions · Pint สะอาด 492 ไฟล์
- [x] frontend eslint / vue-tsc / vitest **59 ผ่าน** / vite build ผ่านทั้งหมด
- [x] `composer audit` = 0 · `npm audit --omit=dev` = 0 · `migrate:status` ไม่มี Pending · ไม่มี failed queue job
- [x] ตรวจจริงบนเบราว์เซอร์: เข้าสู่ระบบ · นำทางต่อเนื่อง 12 หน้าจนชน 429 แล้วยังอยู่ในระบบ · เปลี่ยนรหัสผ่านผิดได้ข้อความไทย · เลขบัตรประชาชนถูกปิดบังในตาราง · ค้นด้วยเลขเต็มยังเจอ · ฟอร์มแก้ไขโหลดเลขเต็มถูกต้อง

#### ที่ยังไม่ทำ — ต้องขออนุมัติก่อน
- [ ] **explicit deny ยังไม่มีผลทั้งระบบ** — `$user->can()` ข้าม deny เมื่อผู้ใช้ถือสิทธิ์นั้นอยู่ เพราะ `Gate::before` ของ Spatie ทำงานก่อนของเรา · แก้ได้ด้วยการปิด `permission.register_permission_check_method` ซึ่งเปลี่ยนพฤติกรรมการตรวจสิทธิ์ของทุก endpoint พร้อมกัน (ดู ADR-AUTHZ-02)
- [ ] เปลี่ยน public API จาก numeric ID เป็น UUID — ต้องทำ impact analysis ก่อน
- [ ] ย้าย bearer token จาก localStorage ไป HttpOnly cookie — ต้องทำ impact analysis ก่อน
- [ ] เปิด Workflow Engine เป็น source of truth · เปิด Document Engine — **ไม่ได้แตะในรอบนี้**
