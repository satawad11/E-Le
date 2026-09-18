# CHANGELOG

All notable changes to the E-la project will be documented in this file.

### การปรับปรุงโครงสร้างและยุบรวมศูนย์งานสารบรรณ (Saraban Module Hubs & Redundancy Consolidation) — 2026-09-16

- **1. การจัดกลุ่มและยุบรวมเมนูสารบรรณ (Unified Information Architecture)**:
  - ปรับโครงสร้างเมนูจากเดิม 21 รายการย่อยที่กระจัดกระจาย ยุบรวมเป็น 7 ศูนย์งานหลักสำหรับผู้ใช้ทั่วไป และ 1 ศูนย์บริหารงานสารบรรณสำหรับผู้ดูแล:
    1. ภาพรวมและสถานะสารบรรณ (`/saraban`)
    2. กล่องงานและหนังสือของฉัน (`/saraban/inbox` รองรับแท็บงานรอพิจารณา, หนังสือเวียน/เพื่อทราบ, เรื่องที่ฉันส่ง)
    3. ทะเบียนหนังสือราชการ (`/saraban/registers` รวมทะเบียนรับ, ส่ง, ภายใน, และจองเลข)
    4. สร้างและยกร่างหนังสือ (`/saraban/documents/new`)
    5. คลังเอกสารและสำนวน (`/saraban/archive` รวมเอกสารจัดเก็บและแฟ้มสำนวน)
    6. บันทึกการเวียนหนังสือ (`/saraban/distribution/tracking`)
    7. สถิติและรายงานสารบรรณ (`/saraban/reports/registers`)
    8. ศูนย์ตั้งค่าและบริหารงานสารบรรณ (`/saraban/admin` รวมชุดลำดับเลข, เวิร์กโฟลว์, นโยบาย/อำนาจ, และความปลอดภัย/ตรวจสอบ)
- **2. การพัฒนาหน้าจอศูนย์งาน (Frontend Hub Views)**:
  - สร้าง `RegistersHubView.vue`, `SarabanAdminHubView.vue`, และ `ArchiveCasesHubView.vue` ด้วย Tabbed Sub-Navigation และ Search Parameters
  - ยกระดับ `InboxView.vue` ให้มีคิวงานรอพิจารณา (Sign/Endorse), หนังสือเวียนเข้า และฉบับร่างของฉันในที่เดียว พร้อมรองรับการรายงานความคืบหน้าการดำเนินงาน (`/saraban/inbox/{recipient}/progress`)
  - กำหนด Router Redirects ใน `router/index.js` เพื่อให้ URL เดิมทุกเส้นทางสามารถใช้งานต่อได้ 100% โดยไม่เกิด 404
- **3. ฐานข้อมูลและการตรวจสอบความถูกต้อง (Database Migration & Integrity Fingerprint)**:
  - รัน Migration `2026_09_27_100000_consolidate_saraban_menus.php` ลบเมนูที่ซ้ำซ้อนและจัดระเบียบโครงสร้างต้นไม้เมนูใหม่
  - อัปเดต `TREE_FINGERPRINT` ใน `MenuDriftTest.php` และตรวจสอบ `MenuIntegrityTest`
- **4. การตรวจสอบคุณภาพ (Verification Gates)**:
  - Full PHPUnit Suite: ผ่าน 100% (1,380 passed, 0 failed, 5,767 assertions)
  - Full Vitest Suite: 33 files / 213 unit tests passed 100%
  - Production Build: `npm run build` ผ่าน 100% (3.21s)
  - Laravel Pint: ผ่าน 100% (767 files)

### การยุบรวมและจัดระเบียบสถาปัตยกรรม PDF Vector Engine (Consolidated PDF Vector Engine & Overlay Architecture) — 2026-09-16

- **1. สถาปัตยกรรมแกนกลางเวกเตอร์ร่วม (`PdfVectorDrawer.php`)**:
  - รวมศูนย์ตรรกะการวาดเวกเตอร์ Text Cell, Multiline (Auto Line Height), Checkmark (Vector Green), Digital Pen Strokes (Vector Blue Pen), Stamp Box (Official Border + Metadata), Placeholders และ QR Code Vector Matrix
- **2. เลเยอร์ฟอนต์และการเข้ารหัสอักขระภาษาไทย (`OverlayFonts.php`)**:
  - รวมการตรวจสอบและลงทะเบียนฟอนต์ไทย `THSarabunNew` และ `THSarabunNewB` เข้ากับ `\setasign\Fpdi\Fpdi`
  - รวมฟังก์ชัน `OverlayFonts::encode` สำหรับแปลงรหัส UTF-8 เป็น `CP874//TRANSLIT` ปลอดภัยต่อ FPDF
- **3. ปรับปรุงตัวเรนเดอร์เอกสารและสารบรรณ (Refactored PDF Renderers)**:
  - ปรับปรุง `SarabanPdfRenderer.php` ให้เรียกใช้ `OverlayFonts`, `OverlayPdf` และ `PdfVectorDrawer` ร่วมกัน 100% พร้อมคงมาตรฐานความปลอดภัย SoT §14 (ไม่รับภาพ Raster แรนเดอร์ฝั่ง Client)
  - ปรับปรุง `PdfOverlayRenderer.php` ของระบบแม่แบบเอกสารให้เรียกใช้ `PdfVectorDrawer` และ `OverlayFonts::encode`
- **4. การตรวจสอบคุณภาพ (Verification Gates)**:
  - Full PHPUnit Tests: 485+ tests passed 100%
  - Full Vitest Suite: 33 files / 213 unit tests passed 100%
  - Production Build: `npm run build` ผ่าน 100% (3.68s)

### การจัดระเบียบระบบและลดความซ้ำซ้อนการทำงาน (System Streamlining & Redundancy Reduction) — 2026-09-16

- **1. ศูนย์รวมภารกิจรอดำเนินการของผู้บริหาร (Executive Unified Task Inbox)**:
  - ยกระดับ `DashboardController.php` และ `DashboardView.vue` เพิ่มวิดเจ็ต **Executive Action Center (ภารกิจรอดำเนินการของท่าน)** แสดงภาพรวมงานรอการพิจารณา 4 ด้าน: ใบลาคำขออนุมัติ, คำสั่ง OT รออนุมัติ, งานเกษียน/ลงนามสารบรรณ, และหนังสือเวียนราชการเข้าที่ยังไม่อ่าน
  - ปรับปรุง `ApprovalsView.vue` เพิ่มแถบนำทางสลับคิวงาน (Quick Inbox Switcher) ระหว่างการอนุมัติใบลา, คำขอยกเลิก, คำสั่ง OT, และคิวงานสารบรรณ
- **2. ศูนย์รวมรายงานและสถิติแบบเบ็ดเสร็จ (Unified Report & Analytics Hub)**:
  - ยกระดับ `ReportsView.vue` เป็นศูนย์รวมรายงาน 4 หมวดหมู่ (4 Tabs Navigation):
    1. ทะเบียนการลาราชการและสถิติ (Leave Registry & Analytics)
    2. เวลาปฏิบัติราชการและ OT (Attendance & OT Summary)
    3. เกณฑ์วันลาประเมินผลการปฏิบัติราชการ ก.ค.ศ. ๒๓ วัน และ ว PA (Vocational 23-Day Evaluation & PA Evidence)
    4. สมุดทะเบียนและรายงานสารบรรณ ๔ ฝ่าย สอศ. (Saraban In/Out Registers)
- **3. จัดระเบียบการสร้างและจัดการข้อมูลบุคลากร (Consolidated Personnel Management)**:
  - ปรับปรุง `UserFormModal.vue` เพิ่มการกำหนดหัวหน้างาน/หัวหน้าแผนก (`is_org_unit_head`) และระดับวิทยฐานะ (`academic_standing`) ในแท็บข้อมูลการจ้าง
  - ปรับปรุง `UserController.php` ทั้งใน `store()` และ `update()` ให้บันทึกและซิงค์การมอบหมายผู้รับผิดชอบหน่วยงาน (`org_unit_managers`) ภายใน Transaction เดียวกัน
- **4. รวมศูนย์การจัดการประเภทการลาและนโยบายสิทธิ (Consolidated Leave Types & Policies)**:
  - ปรับปรุง `LeaveTypesManagementView.vue` รวมการจัดการประเภทการลา แบบฟอร์มพิมพ์ และนโยบายโควตาสิทธิการลาตามประเภทบุคลากร พร้อมตั้งค่าทั่วไปของระบบลาไว้ในหน้าจอเดียวแบบ Master-Detail Tabs
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Frontend Vitest Suite: 33 test files / 213 tests passed 100%
  - Frontend Production Build: `npm run build` ผ่าน 100% (4.55s)
  - Backend PHPUnit Tests: ผ่าน 100% (รวม PermissionCatalogTest)


### ระบบเชื่อมโยงเอกสารข้ามโมดูล ศูนย์จัดการแม่แบบ และการแลกเปลี่ยนซองหนังสือข้ามสถานศึกษา (Cross-Module Document Automation, Template Center Bridge & Inter-College Electronic Envelope Exchange) (Phase 8) — 2026-09-16

- **1. ระบบยกร่างเอกสารราชการอัตโนมัติข้ามโมดูล (Cross-Module Document Automation & Draft Bridge)**:
  - พัฒนา `OfficialDocumentTemplateService` สำหรับแปลงข้อมูลการอนุมัติข้ามโมดูลเป็นโครงสร้างเอกสารคำสั่ง/ประกาศมาตรฐาน:
    - `createDraftPayloadFromLeave`: แปลงคำขอลาที่ได้รับอนุมัติ เป็นร่างคำสั่งวิทยาลัยอนุญาตให้บุคลากรลา
    - `createDraftPayloadFromOvertime`: แปลงคำสั่งปฏิบัติงานนอกเวลาราชการ เป็นร่างคำสั่งวิทยาลัยมอบหมายการปฏิบัติหน้าที่ล่วงเวลา
    - `createDraftPayloadFromEvaluation`: แปลงข้อมูลสรุปผลการประเมินวันลาสะสม ก.ค.ศ. ๒๓ วัน เป็นร่างประกาศผลการประเมินของวิทยาลัย
  - เพิ่ม API Endpoint `GET /api/saraban/templates/cross-module-draft` และเชื่อมโยงกับการสร้างหนังสือใน `DocumentFormView.vue` ผ่าน Query Parameter `source_type` และ `source_id`
- **2. ระบบซองเอกสารและแลกเปลี่ยนหนังสือข้ามสถานศึกษา (Inter-College & Inter-Agency Electronic Envelope Exchange SoT §17, §27)**:
  - พัฒนา `InterAgencyDispatchService` รองรับ:
    - `buildEvidencePackage`: จัดทำซองพยานหลักฐานอิเล็กทรอนิกส์ (.zip) รวบรวมไฟล์ PDF หนังสือราชการ, เอกสารแนบ, บันทึกการลงนามดิจิทัล (`signature_evidence.json`), และ `manifest.json` พร้อมคำนวณ Cryptographic SHA-512 Checksum ปิดผนึก
    - `dispatchToWebhook`: นำส่งหนังสือตรงไปยัง Webhook API ปลายทาง พร้อมลงนามความปลอดภัย HMAC-SHA256
    - `importPackage`: จุดรับเข้าหนังสือราชการจากแพ็กเกจหน่วยงานภายนอก บันทึกลงสู่ทะเบียนหนังสือรับและแนบไฟล์อย่างปลอดภัย
  - สร้าง `InterAgencyDispatchController` และลงทะเบียน Routes สำหรับดาวน์โหลดซอง ZIP, ส่งผ่าน Webhook, และรับเข้า Webhook
  - พัฒนาส่วนติดต่อผู้ใช้ `InterAgencyDispatchModal.vue` พร้อมเชื่อมต่อกับ `DocumentFormView.vue` สำหรับหนังสือที่ลงนามหรือมีผลสมบูรณ์แล้ว
- **3. ศูนย์จัดการแบบฟอร์มและการจัดหมวดหมู่แม่แบบ (Document Center Bridge & Categorization)**:
  - ยกระดับ `DocumentCenterView.vue` และ `DocumentEngineController`:
    - เพิ่มแท็บกรองหมวดหมู่แม่แบบเอกสาร (ทั้งหมด, แบบฟอร์มการลา `LEAVE_FORM`, หนังสือราชการสารบรรณ `SARABAN_OFFICIAL`, แบบฟอร์มทั่วไป `ADMIN_CUSTOM`)
    - เพิ่มกล่องค้นหาแม่แบบเอกสารแบบเรียลไทม์ (Live Search Query) ค้นหาชื่อและรหัสแม่แบบ
- **4. ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Tests: `VocationalSarabanIntegrationAndExchangeTest` (4 passed / 15 assertions)
  - Contract & Architecture Tests: `ApiSurfaceContractTest` (1 passed / 405 assertions), `SarabanCoreContractTest` (5 passed / 15 assertions)
  - Full Backend Test Suite: 1,380 passed / 1 skipped (5,767 assertions), 0 failed
  - Full Frontend Vitest Suite: 33 test files / 213 tests passed 100% (รวม `sarabanIntegrationAndExchange.test.js`)
  - Frontend Production Build: `npm run build` สำเร็จ 100% (3.85s)
  - Code Style: Laravel Pint ผ่าน 100% (765 files)
  - สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

### การยกระดับประสบการณ์และส่วนติดต่อผู้ใช้ทั้งระบบ (Comprehensive UX/UI Modernization) — 2026-09-16

- **1. ระบบบันทึกร่างอัตโนมัติบนเครื่องและการกู้คืนข้อมูล (Local Draft Auto-Save & Crash Recovery)**:
  - พัฒนา Composable `useFormAutoSave.js` รองรับ Debounced Auto-Save ไปยัง `localStorage` แยกตามผู้ใช้และประเภทฟอร์ม
  - แถบแจ้งเตือนเมื่อพบฉบับร่างที่ค้างอยู่ (Draft Recovery Banner) พร้อมปุ่ม "กู้คืนข้อมูล" หรือ "ละทิ้งร่าง"
  - ไฟแสดงสถานะการบันทึกแบบเรียลไทม์ (Auto-Save Status Indicator)
  - ล้างข้อมูลร่างอัตโนมัติเมื่อส่งฟอร์มสำเร็จ (Clear on Submit) ในฟอร์มยื่นใบลา (`LeaveRequestView.vue`) และฟอร์มร่างหนังสือราชการ (`DocumentFormView.vue`)
- **2. ระบบพิจารณาอนุมัติแบบแบ่งหน้าจอ (Split-View / Side Panel Approvals Workflow)**:
  - ยกระดับ `ApprovalsView.vue` ด้วยตัวสลับมุมมอง (Dual-View: Cards View vs Split-View)
  - Split-View แสดงรายการคำขอฝั่งซ้าย และ แผงตรวจสอบรายละเอียดฝั่งขวา (Sticky Inspector Panel) พร้อมข้อมูลสรุปและการประเมินสิทธิประโยชน์
  - เพิ่มชุดข้อความหมายเหตุสำเร็จรูป (Quick Preset Remarks) เช่น "อนุมัติ", "อนุมัติตามระเบียบ", "ขอเอกสารเพิ่มเติม"
- **3. ตารางข้อมูลแปลงเป็นการ์ดบนอุปกรณ์มือถือ (Mobile Card Transformation for Data Tables)**:
  - ปรับปรุงตารางข้อมูลในหน้าจอหลักให้แสดงผลแบบ Responsive: สลับระหว่างตารางแบบเต็ม (`hidden md:block`) บนจอเดสก์ท็อป และการ์ดข้อมูลแบบเรียงชั้น (`block md:hidden`) บนจอมือถือ
  - ใช้งานใน: `UsersManagementView.vue`, `RegisterReportsView.vue`, `InboxView.vue`, และ `DocumentsView.vue`
- **4. เส้นทางนำทางและปุ่มย้อนกลับอัจฉริยะ (Breadcrumbs & Contextual Navigation)**:
  - สร้างคอมโพเนนต์ `AppBreadcrumb.vue` รองรับทั้งเส้นทางแบบกำหนดเองและอัตโนมัติจาก Vue Router Meta
  - รองรับปุ่มย้อนกลับอัจฉริยะ (Contextual Back Button) ที่เชื่อมโยงกับประวัติหน้าจอก่อนหน้า
  - ติดตั้งในหน้าจอสำคัญทั่วทั้งระบบ เช่น ยื่นใบลา, ร่างหนังสือ, พื้นที่ลงนาม, เกษียนหนังสือ, ประทับตรา, รายงาน, และทะเบียน
- **5. พื้นที่อัปโหลดไฟล์ลากวางพร้อมพรีวิวสด (Drag & Drop File Upload Zone with Live Preview)**:
  - สร้างคอมโพเนนต์ `FileUploadZone.vue` รองรับการลากไฟล์มาวาง (Drag & Drop), คลิกเพื่อเลือกไฟล์, ตรวจสอบขนาดและนามสกุลไฟล์
  - พรีวิวภาพถ่ายและเอกสาร PDF ทันทีในตัวคอมโพเนนต์ พร้อมปุ่มลบไฟล์ก่อนอัปโหลด ในฟอร์มยื่นใบลา (`LeaveRequestView.vue`)
- **6. ผืนผ้าใบลงนามและเกษียนหนังสือเต็มจอสำหรับมือถือ (Full-Screen Mobile Signature & Endorsement Canvas)**:
  - ยกระดับ `HandwritingCanvas.vue` รองรับโหมดเต็มหน้าจอ (Full-Screen Landscape Canvas Overlay)
  - รองรับปุ่มเลิกทำทีละเส้น (Undo Stroke), ปุ่มล้างทั้งหมด (Clear), สลับสีหมึก (น้ำเงิน/ดำ)
  - ส่งออกพิกัดเส้นเวกเตอร์ (Normalized Vector Stroke Coordinates) ตามข้อกำหนด SoT §14, §15 อย่างถูกต้อง
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Frontend Unit & Component Tests: 33 test files / 213 passed (100%), 0 failed
  - Frontend Production Build: `npm run build` สำเร็จ 100% (3.69s)
  - Backend Test Suite: ผ่าน 100%
  - สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%

### การยกระดับระบบจัดการบทบาท สิทธิ์ และการเข้าถึงเมนู (Role & Permission Matrix & Live Menu Access) — 2026-09-16

- **ระบบจัดการบทบาทและกำหนดชุดสิทธิ์การใช้งาน (Role & Permission Management)**:
  - เพิ่มมุมมองตารางเมทริกซ์สิทธิ์แบบโต้ตอบ (Interactive Permission Matrix Grid View) ควบคู่กับมุมมองการ์ด (Cards View) ใน `RolesManagementView.vue`
  - รองรับการคัดกรองสิทธิ์แยกตามโมดูลหลัก (Core/Users, Leaves, Attendance, Saraban, System) พร้อมกล่องค้นหาแบบเรียลไทม์
  - ตรวจจับการเปลี่ยนแปลงสิทธิ์ (Dirty State Tracker) และรองรับการบันทึกทีละบทบาท หรือ บันทึกพร้อมกันทั้งหมดในคลิกเดียว (Batch Save)
  - มีระบบป้องกันบทบาทระบบ (System Role Safeguards) ป้องกันการลบหรือแก้คีย์ของบทบาทหลัก
- **ระบบจัดการเมนูและการเข้าถึง (Live Menu Role Access & Grouped Permissions)**:
  - เพิ่มการแสดงบทบาทที่มีสิทธิ์เข้าถึงแต่ละเมนูแบบเรียลไทม์ (Live Role Access Badges & Modal Preview) ใน `MenusManagementView.vue`
  - หน้าต่างจัดการสิทธิ์ของเมนูแบบจัดกลุ่มตามโมดูล (Grouped Module Permission Editor) พร้อมปุ่มลัดให้สิทธิ์/เพิกถอนสิทธิ์รายโมดูล
  - Backend `MenuController`: บันทึก Audit Log (`menu.permissions_updated`) และล้างแคชเมนูอัตโนมัติเพื่อให้ผลการเปลี่ยนสิทธิ์มีผลทันที
- **ระบบสิทธิ์เฉพาะบุคคล (User Direct Permissions)**:
  - เพิ่มตัวกรองสิทธิ์และปุ่มลัดล้างสิทธิ์เฉพาะบุคคลทั้งหมดใน `UserPermissionModal.vue`
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - ตรวจสอบความปลอดภัย: `SystemRoleProtectionTest` (8 passed), `AuthorizationMatrixTest` (6 passed)
  - Full Backend Test Suite: 1,376 passed / 1 skipped (5,740 assertions), 0 failed
  - Full Frontend Vitest Suite: 31 test files / 206 passed (100%), 0 failed
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (3.21s)
  - สอดคล้องตามกฎ Hard Gates: ปลอดการ Hard-Code 100%, UI ภาษาไทย 100%, Non-Destructive 100%


### การพัฒนาระบบรายงานสารบรรณ สถิติการไหลเวียนเอกสาร และศูนย์ตรวจสอบหลักฐานความสอดคล้องตามกฎหมาย (Saraban Reporting, Analytics & Legal Compliance Evidence) (Phase 7) — 2026-09-16

- **รายงานสารบรรณและสถิติการไหลเวียนเอกสาร ๔ ฝ่ายหลัก สอศ. (Vocational Analytics)**:
  - สรุปภาพรวมสถิติหนังสือเข้า-ออก รายเดือน และสถานะการดำเนินการ
  - เพิ่มการวิเคราะห์และแสดงผลสัดส่วนหนังสือแยกตาม ๔ ฝ่ายหลัก สอศ. (`by_division`: ฝ่ายบริหารทรัพยากร, ฝ่ายแผนงานและความร่วมมือ, ฝ่ายพัฒนากิจการนักเรียนนักศึกษา, ฝ่ายวิชาการ)
  - ปรับปรุงรายงานทะเบียนหนังสือรับ (แบบที่ ๑๓), ทะเบียนหนังสือส่ง (แบบที่ ๑๔), ทะเบียนหนังสือภายใน (แบบที่ ๒) พร้อมตัวกรองฝ่ายงาน (สอศ.)
  - รายงานทะเบียนเลขหนังสือ (`numbers`) และรายงานสถิติการส่งเวียนและการรับทราบ (`distribution`)
- **ระบบส่งออกรายงานสารบรรณอิเล็กทรอนิกส์ (Secure Report Export)**:
  - รองรับการส่งออกรายงานในรูปแบบ Excel (.xlsx) และ CSV (.csv UTF-8 with BOM) ตามระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๒๙/๑
  - รองรับการคัดกรองตามฝ่ายงาน (`division_id`) และทิศทางหนังสือ (`direction`) ในการส่งออกรายงาน
- **ศูนย์ตรวจสอบหลักฐานความสอดคล้องตามกฎหมายและระเบียบสารบรรณ (Compliance Evidence Center)**:
  - รวบรวมและประเมินหลักฐานความสอดคล้องตามระเบียบ ๗ ด้านหลัก (Cryptographic Hash Sealing, Immutable Audit, Separation of Duties, Legal Hold, Non-Destructive Storage, Trusted Clock Synchronization, RBAC/ABAC Isolation)
  - แสดงผลสถานะการประเมินแบบเรียลไทม์ (Live Assessment: PASS, PARTIAL, NO_EVIDENCE, N/A) แบบอ่านอย่างเดียว (Read-Only)
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Test: `VocationalSarabanReportsAndComplianceTest.php` (3 passed / 21 assertions)
  - Frontend Vitest: `sarabanReportsAndCompliance.test.js` (2 passed)
  - Full Frontend Vitest Suite: 31 test files / 206 passed (100%), 0 failed
  - Full Backend Test Suite: 1,376 passed / 1 skipped (5,740 assertions), 0 failed
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (3.27s)
  - Laravel Pint Code Style: 762 files ผ่าน ๑๐๐% สะอาดสมบูรณ์

### การพัฒนาระบบจัดเก็บ ค้นหา และทำลายหนังสือราชการตามอายุการเก็บรักษา (Document Lifecycle, Retention, Archiving & Disposal) (Phase 6) — 2026-09-16

- **การกำหนดอายุการเก็บรักษาเอกสารราชการ (Document Retention Scheduling)**:
  - เพิ่มตัวเลือกกำหนดอายุการเก็บรักษามาตรฐานตามระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๖๖-๗๑ (๑ ปี, ๕ ปี, ๑๐ ปี, ๒๐ ปี, ๒๕ ปี และตลอดไป)
  - คำนวณและตั้งค่าวันครบกำหนดจัดเก็บ (`retain_until`) อัตโนมัติ พร้อมอัปเดตสถานะการเก็บรักษา (`active`, `due_for_disposal`, `disposed`)
- **ระบบอายัดเอกสารตามกฎหมาย (Legal Hold Management & Audit)**:
  - รองรับการสั่งอายัดเอกสาร (`LegalHold`) พร้อมชุดเหตุผลมาตรฐาน (คดีความ/การไต่สวน, ตรวจสอบ สตง., ร้องเรียนวินัย, ตรวจสอบภายใน)
  - มีมาตรการคุ้มครองเอกสารที่อยู่ระหว่างอายัด: บล็อกการทำลาย (Disposal) โดยเด็ดขาดจนกว่าจะได้รับการปลดอายัด (Release Hold)
- **ระบบการทำลายเอกสารราชการตามหลักการแยกหน้าที่ (Separation of Duties & Non-Destructive Disposal SoT §26)**:
  - แยกบทบาทระหว่างผู้อนุมัติการทำลาย (`approve_disposal`) และผู้ดำเนินการทำลาย (`dispose_files`)
  - การทำลายไฟล์เอกสารจริงใน Storage เกิดขึ้นหลัง Commit Transaction อย่างปลอดภัย พร้อมบันทึกค่า Cryptographic SHA-512 และเก็บรักษาประวัติทะเบียนเอกสาร (Document Registry) ไว้เป็นหลักฐานตลอดไป ไม่ลบ Record
- **การค้นหาและกรองสถานะการเก็บรักษาเอกสารขั้นสูง (Advanced Retention & Lifecycle Search)**:
  - อัปเกรด `DocumentController::index()` รองรับการคัดกรองตาม `retention_status` (`on_hold`, `due`, `disposed`, `scheduled`) และ Eager loading `retentionSchedule` และ `legalHolds` พร้อมควบคุมเวลาผ่าน `TrustedClock`
  - อัปเกรด `RetentionPanel.vue` และ `DocumentsView.vue` แสดงป้ายสถานะ (Badges), ตัวกรองขั้นสูง, และปุ่มลัดการตั้งค่าตามหลัก UI ภาษาไทย 100%
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Test: `VocationalSarabanRetentionAndLifecycleTest.php` (2 passed / 24 assertions)
  - Frontend Vitest: `sarabanRetentionAndLifecycle.test.js` (2 passed)
  - Full Frontend Vitest Suite: 30 test files / 204 passed (100%), 0 failed
  - Full Backend Test Suite: 1,373 passed / 1 skipped (5,719 assertions), 0 failed
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (2.84s)
  - Laravel Pint Code Style: 761 files ผ่าน ๑๐๐% สะอาดสมบูรณ์

### การพัฒนาระบบลงนามดิจิทัล การเกษียนหนังสือ และการประทับตรารับ-ส่ง (Digital Signatures, Endorsement & Stamping Workspace) (Phase 5) — 2026-09-16

- **การพัฒนาระบบลงนามดิจิทัลและการยืนยันตัวตนขั้นสูง (Digital Signatures & Step-Up Auth)**:
  - รองรับการลงนามด้วยลายมือชื่อดิจิทัล พร้อมระบบยืนยันตัวตนซ้ำขั้นสูง (Step-Up Authentication via Password/TOTP)
  - บันทึกพิกัดเส้นลายมือชื่อแบบเวกเตอร์ (Stroke Coordinates) ตามมาตรฐาน SoT §15 เพื่อป้องกันการคัดลอกรูปภาพลายเซ็น
  - ปิดผนึกแฮชเอกสาร (Cryptographic Document Hash Sealing) และสร้างบันทึกพยานหลักฐานการลงนามที่เปลี่ยนแปลงไม่ได้ (Immutable Signature Evidence)
  - พัฒนาปุ่มลัดระบุข้อมูลผู้ลงนามอัตโนมัติ 1 คลิก (`👤 ใช้ชื่อ-ตำแหน่งตนเอง`) ในหน้า `SignDocumentView.vue`
- **การพัฒนาระบบเกษียนหนังสือราชการ ๔ ฝ่ายอาชีวศึกษา (Vocational Endorsement Workspace)**:
  - พื้นที่เกษียนหนังสือพร้อมแคนวาสวาดลายมือและชุดข้อความเกษียนความเห็นด่วน ๔ ฝ่ายหลัก สอศ. (`EndorseDocumentView.vue`)
  - แยกการเกษียนความเห็นออกจากการลงนาม โดยการเกษียนไม่สร้าง Signature Evidence ตามข้อกำหนดระเบียบสารบรรณ
- **การพัฒนาระบบประทับตรายางและเรนเดอร์ PDF (Stamping Workspace & Non-Destructive Render)**:
  - ปรับปรุง `DocumentToolsView.vue` รองรับการประทับตรารับ-ส่ง (Registration Stamp) และกล่องข้อความกำหนดเอง
  - เรนเดอร์ประทับตรายางลงบนไฟล์ PDF ด้วย `SarabanPdfRenderer` สร้างเป็นเอกสารเวอร์ชันใหม่แบบไม่ทำลายต้นฉบับ (Non-Destructive)
- **ระบบยืนยันความถูกต้องของเอกสารฉบับสมบูรณ์ (Finalization & QR Public Verification)**:
  - ระบบ Finalize เอกสารพร้อมสร้างรหัส QR Code ตรวจสอบความถูกต้อง
  - API สาธารณะสำหรับตรวจสอบความถูกต้องของเอกสารราชการ (`/api/saraban/verify/{hash}`)
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Test: `VocationalSarabanSigningAndWorkspaceTest.php` (3 passed / 21 assertions)
  - Frontend Vitest: `sarabanSigningAndWorkspace.test.js` (2 passed)
  - Full Frontend Vitest Suite: 29 test files / 202 passed (100%), 0 failed
  - Full Backend Test Suite: 1,371 passed / 1 skipped (5,695 assertions), 0 failed
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (3.06s)
  - Laravel Pint Code Style: 760 files ผ่าน ๑๐๐% สะอาดสมบูรณ์


### การพัฒนาระบบสารบรรณรับ-ส่งและการไหลเวียนหนังสืออิเล็กทรอนิกส์ (e-Saraban In/Out Registry & Vocational Routing) (Phase 4) — 2026-09-16

- **การยกระดับระบบทะเบียนรับ-ส่งหนังสือราชการและออกเลขทะเบียนอัตโนมัติ**:
  - ระบบทะเบียนรับ (Incoming Registry) และทะเบียนส่ง (Outgoing Registry) ป้องกันการออกเลขซ้ำซ้อนและการจองเลขข้ามหมวด
  - ปรับปรุงการบันทึกข้อมูลเลขที่หนังสือภายนอกเดิม วันที่รับ-ส่ง และชั้นความเร็ว/ความลับตามระเบียบสารบรรณ
- **การยกระดับการไหลเวียนหนังสือราชการและส่งเวียน ๔ ฝ่ายหลักอาชีวศึกษา**:
  - ปรับปรุงโมดอลส่งเวียนหนังสือ (`DistributeDocumentModal.vue`) เพิ่มปุ่มลัดส่งเวียนหนังสือตรงเข้าสู่โครงสร้างสายงาน ๔ ฝ่ายหลัก สอศ. (`addDivisionSubtreeTarget`): ฝ่ายบริหารทรัพยากร, ฝ่ายวิชาการ, ฝ่ายพัฒนากิจการนักเรียนนักศึกษา, ฝ่ายแผนงานและความร่วมมือ
  - เพิ่มชุดข้อความและเหตุผลประกอบการส่งเวียนด่วน (`NOTE_PRESETS`): "เพื่อโปรดทราบ", "เพื่อโปรดพิจารณา", "เพื่อโปรดดำเนินการในส่วนที่เกี่ยวข้อง", "เพื่อโปรดแจ้งผู้เกี่ยวข้องทราบและถือปฏิบัติ", "เพื่อโปรดพิจารณาลงนาม"
- **การรักษาความปลอดภัยและการปฏิบัติตามกฎหมาย (Legal & Security Compliance)**:
  - บล็อกการส่งเวียนหนังสืออิเล็กทรอนิกส์สำหรับเอกสาร "ลับที่สุด" (Top Secret) ตามระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๒๙
  - บังคับใช้ Server-side Authorization + Scope Check ป้องกัน IDOR ผ่าน `OrgScopeResolver`
  - บันทึก Audit Log ทุกขั้นตอนการรับ-ส่งและไหลเวียนหนังสือ
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Test: `VocationalSarabanRegistryAndRoutingTest.php` (3 passed / 11 assertions)
  - Frontend Vitest: `sarabanVocationalRouting.test.js` (3 passed)
  - Full Frontend Vitest Suite: 28 test files / 200 passed (100%), 0 failed
  - Full Backend Test Suite: 1,368 passed / 1 skipped (5,674 assertions), 0 failed
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (2.80s)
  - Laravel Pint Code Style: 759 files ผ่าน ๑๐๐% สะอาดสมบูรณ์

### การพัฒนาระบบร่างและปรับปรุงเอกสารหนังสือราชการ (Document Drafting & Live Preview Integration) (Phase 3) — 2026-09-16

- **การพัฒนาคอมโพเนนต์จำลองเอกสารราชการแบบเรียลไทม์ (`LiveDocumentPreview.vue`)**:
  - สร้างคอมโพเนนต์จำลองหน้ากระดาษ A4 สัดส่วนและระยะขอบตามมาตรฐานระเบียบสำนักนายกรัฐมนตรีฯ
  - วางตำแหน่งตราครุฑอัตโนมัติตามประเภทเอกสาร: ซ้ายบน ๑.๕ ซม. สำหรับบันทึกข้อความ และกึ่งกลาง ๓.๐ ซม. สำหรับหนังสือภายนอก คำสั่ง และประกาศ
  - จัดรูปแบบหัวเอกสารและเส้นบรรทัดตามแบบฟอร์มทางราชการ
  - มีปุ่มสลับการแสดงผลตัวเลขไทย (Thai Numerals `๐-๙`) และปุ่มคัดลอกข้อความร่าง
- **การยกระดับหน้ายกร่างเอกสาร (`DocumentFormView.vue`)**:
  - เชื่อมโยงและโหลดแม่แบบเอกสารราชการ ๔ รูปแบบมาตรฐานแบบไดนามิกจาก `/api/saraban/templates`
  - รองรับโหมดการร่างแบบแยกส่วน (Structured Drafting) และโหมดเนื้อความเต็มอิสระ (Freeform Summary) พร้อมซิงก์ข้อมูลสองทิศทาง
  - เพิ่มแถบแทรกข้อความสั่งการ/เกษียนความเห็นด่วน ๔ ฝ่ายอาชีวศึกษา (`endorsement_presets`)
  - เพิ่มฟังก์ชัน `toThaiDigits()` ใน `ThaiDateFormatter.js`
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Frontend Vitest: สร้าง `sarabanDraftingAndLivePreview.test.js` (7 passed)
  - Full Frontend Suite: 27 test files / 197 passed (100%), 0 failed
  - Full Backend Suite: 1,365 passed / 1 skipped (5,663 assertions), 0 failed
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (2.96s)
  - Laravel Pint Code Style: 758 files ผ่าน ๑๐๐%


### การพัฒนาระบบแม่แบบเอกสารและการเรนเดอร์ PDF สำหรับ e-Document & e-Saraban (Phase 2) — 2026-09-16

- **การขยายแม่แบบเอกสารราชการมาตรฐาน ๔ รูปแบบสำหรับสถานศึกษาอาชีวศึกษา**:
  - พัฒนา `OfficialDocumentTemplateService.php` ให้รองรับ ๔ แม่แบบมาตรฐานตามระเบียบสำนักนายกรัฐมนตรีฯ:
    1. บันทึกข้อความ (ภายใน): แบบที่ ๒ ครุฑขนาด ๑.๕ ซม. ด้านซ้ายบน
    2. หนังสือภายนอก: แบบที่ ๑ ครุฑขนาด ๓.๐ ซม. กึ่งกลางหน้ากระดาษ
    3. คำสั่งวิทยาลัย/สถานศึกษา: แบบที่ ๔ ครุฑขนาด ๓.๐ ซม. กึ่งกลางหน้ากระดาษ
    4. ประกาศวิทยาลัย/สถานศึกษา: แบบที่ ๕ ครุฑขนาด ๓.๐ ซม. กึ่งกลางหน้ากระดาษ
  - คงชุดข้อความสั่งการและเกษียนความเห็นตามโครงสร้าง ๔ ฝ่ายอาชีวศึกษา (บริหารทรัพยากร, วิชาการ, พัฒนากิจการนักเรียนนักศึกษา, แผนงานและความร่วมมือ)
- **การติดตั้งตราสัญลักษณ์ครุฑราชการมาตรฐาน (Garuda Emblem Assets)**:
  - สร้าง Artisan Command `InstallGarudaEmblem` (`php artisan document:install-garuda`): สร้างไฟล์ `garuda.png` และ `garuda.svg` ทั้งฝั่ง Backend (`backend/public/images/`) และ Frontend (`frontend/src/assets/`)
- **การยกระดับ Document Engine และ PDF Overlay Renderer**:
  - เพิ่มชนิดฟิลด์ `GARUDA` ใน `DocumentFieldMapping::RENDERABLE_TYPES` สำหรับการวางตราครุฑบนแบบฟอร์มเอกสาร
  - ปรับปรุง `PdfOverlayRenderer.php` ให้วาดตราครุฑอัตโนมัติตามพิกัดและขนาด พร้อมการค้นหาไฟล์ภาพตราครุฑอย่างปลอดภัย
  - ปรับปรุง `TemplatePreflight.php` ยกเว้นการเตือนขนาดความสูงขั้นต่ำสำหรับฟิลด์รูปภาพและตราครุฑ (`GARUDA`)
  - ขยายชุดข้อมูลตัวอย่างภาษาไทยใน `PreviewSampleValues.php` สำหรับฟิลด์บุคลากร สารบรรณ และหน่วยงาน
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Test: `backend/tests/Feature/OfficialDocumentTemplateEngineTest.php` (5 passed / 28 assertions)
  - Regression Test: `backend/tests/Feature/SarabanMasterSuiteTest.php` (4 passed / 103 assertions)
  - Full Backend Test Suite: 1,365 passed / 1 skipped (5,663 assertions), 0 failed
  - Full Frontend Vitest: 26 files / 190 passed, 0 failed
  - Production Build: `npm run build` ผ่านสมบูรณ์ (3.03s)
  - Laravel Pint Code Style: 758 files ผ่าน ๑๐๐%


### การพัฒนาระบบแกนกลางข้อมูลและ Data Providers สำหรับ e-Document & e-Saraban (Phase 1) — 2026-09-16

- **การขยายระบบ Data Provider บน Document Engine**:
  - สร้าง `PersonnelProfileDataProvider.php` (`PERSONNEL_PROFILE_DETAIL`): เชื่อมต่อและดึงข้อมูลบุคลากร ตำแหน่ง สังกัด ๔ ฝ่าย/งาน วิทยฐานะ ประเภทบุคลากร ลายเซ็นดิจิทัล และข้อมูลสถานศึกษา
  - สร้าง `OfficialSarabanDataProvider.php` (`SARABAN_DOCUMENT_DETAIL`): เชื่อมต่อข้อมูลหนังสือราชการ เลขที่หนังสือ ชั้นความเร็ว ชั้นความลับ วันที่ไทย และ URL ตรวจสอบความถูกต้อง QR Code
  - อัปเดต `DataProviderRegistry.php`: ลงทะเบียน Data Providers ทั้งสองตัวเข้าสู่ Registry พร้อมกำหนดชื่อภาษาไทยและ Target Scopes สำหรับการเลือกใช้งานใน Visual Designer
  - เชื่อมโยงและทดสอบ `FieldDictionarySynchronizer`: เพิ่มจำนวนฟิลด์ตัวแปรที่สามารถวางบนแม่แบบเอกสาร PDF และรายงานเป็น ๒๐๓ ฟิลด์
- **ความปลอดภัยและการปฏิบัติตามมาตรฐาน (Security & Zero Hard-Coding)**:
  - ดึงข้อมูลสังกัดและตำแหน่งจาก Single Source of Truth ของระบบ โดยไม่มีการ Hard-Code
  - รองรับการแปลงวันที่ไทยผ่าน `ThaiDateService` และปี พ.ศ. อย่างถูกต้อง
- **ผลการทดสอบและการตรวจสอบคุณภาพ (Hard Gates Verified)**:
  - Feature Test: `backend/tests/Feature/CoreDataDictionaryProviderTest.php` (4 passed / 37 assertions)
  - Full Backend Test Suite: 1,360 passed / 1 skipped (5,615 assertions) ผ่าน ๑๐๐%
  - Full Frontend Vitest: 26 files / 190 passed ผ่าน ๑๐๐%
  - Production Build: `npm run build` สำเร็จ ๑๐๐% (3.45s)
  - Laravel Pint Code Style: 756 files ผ่าน ๑๐๐%

### การปรับปรุงการแสดงผลหน้าต่าง Modal Popup ให้รองรับขนาดหน้าจออย่างสมบูรณ์ (Responsive Modal Viewport Constraints) — 2026-09-16

- **การแก้ไขปัญหา Modal ล้นจอ (Viewport Height Clipping Fix)**:
  - แก้ไขโครงสร้าง Container และ Dialog Modal ทุกจุดทั่วทั้งระบบ ให้ถูกจำกัดความสูงไม่เกิน `max-h-[90vh]` และจัดวางแบบ `flex flex-col overflow-hidden`
  - ส่วนหัว (Header) และส่วนท้าย/ปุ่มดำเนินการ (Footer Actions) ล็อกตำแหน่งคงที่ด้วย `flex-shrink-0` ไม่ถูกเลื่อนหลุดออกจากหน้าจอ
  - เนื้อหาและฟอร์มภายใน (Modal Body) มีแถบเลื่อนแนวตั้งเฉพาะส่วน (`overflow-y-auto flex-1 min-h-0`) ทำให้สามารถกรอกข้อมูลได้อย่างราบรื่นบนทุกขนาดหน้าจอทั้งโน้ตบุ๊ก จอแนวตั้ง และอุปกรณ์พกพา
  - ปรับปรุง Backdrop Wrapper ให้มี `overflow-y-auto`, `p-4`, และการแตะปิด `@click.self` อย่างสอดคล้องกัน
- **ไฟล์ที่ได้รับการปรับปรุง**:
  - `frontend/src/views/admin/LeaveTypesManagementView.vue` (สร้าง/แก้ไข และยืนยันการลบประเภทการลา)
  - `frontend/src/views/admin/FiscalYearManagementView.vue` (สร้าง/แก้ไข และยืนยันการลบปีงบประมาณ)
  - `frontend/src/views/admin/OrgUnitsManagementView.vue` (สร้าง/แก้ไขโครงสร้างหน่วยงาน)
  - `frontend/src/views/admin/RolesManagementView.vue` (สร้าง/แก้ไขบทบาทและชุดสิทธิ์ Matrix)
  - `frontend/src/views/admin/UsersManagementView.vue` & `UserFormModal.vue` (ฟอร์มข้อมูลผู้ใช้งาน ๖ แท็บ)
  - `frontend/src/views/admin/OvertimeOrdersView.vue` (ออกคำสั่ง OT, อนุมัติ, ไม่อนุมัติ, มอบหมายผู้ปฏิบัติงาน, รับรองเวลา)
  - `frontend/src/views/admin/PersonnelAssignmentsView.vue` (ประเภทบุคลากร)
  - `frontend/src/views/admin/WorkCalendarView.vue` (ปฏิทินวันพิเศษ/วันหยุด)
  - `frontend/src/views/admin/OrgUnitManagersView.vue` (มอบหมายผู้รับผิดชอบหน่วยงาน)
  - `frontend/src/views/admin/DocumentCenterView.vue` (สร้างแม่แบบเอกสาร)
  - `frontend/src/views/ApprovalsView.vue` (พิจารณาอนุมัติ/ไม่อนุมัติใบลา)
  - `frontend/src/views/MyLeavesView.vue` (ยกเลิกใบลา, ยกเลิกบางช่วง, ประวัติการดำเนินการ)
  - `frontend/src/views/TeamCalendarView.vue` (รายละเอียดการลาในปฏิทินทีม)
  - `frontend/src/views/Attendance/MyHistoryView.vue` (ขอแก้ไขเวลาปฏิบัติงาน)
- **ผลการทดสอบ (Quality Hard Gates)**:
  - Frontend Vitest: 26 files / 190 tests ผ่าน ๑๐๐%
  - Production Build: `npm run build` ผ่านสมบูรณ์ (3.74s)
  - Full Backend Test Suite: 1,356 passed / 1 skipped (5,578 assertions) ผ่าน ๑๐๐%

### การแก้ไขข้อผิดพลาดระบบรายงานประเมิน ก.ค.ศ. และการปรับแต่ง Dual-Rail Navigation — 2026-09-16

- **การแก้ไขข้อผิดพลาด HTTP 500 ในระบบรายงานประเมินวันลา ก.ค.ศ.**:
  - แก้ไข `VocationalLeaveEvaluationService.php` ในการเรียกความสัมพันธ์ `LeaveType` ให้ใช้คอลัมน์ `code_prefix` และ `attendance_bucket` ตามโครงสร้างฐานข้อมูลจริง แทนการเรียกคอลัมน์ `code` ที่ไม่มีอยู่
  - เพิ่มความแม่นยำในการจัดกลุ่มวันลา (Sick / Personal / Vacation / Other) รองรับทั้งรหัสคำนำหน้าไทย (`ลป.`, `ลก.`, `ลช.`, `ลพ.`), `attendance_bucket` และชื่อประเภทการลา
  - เพิ่มค่า Alias ในสรุปสถิติ (`passed_count`, `exceeded_count`) รองรับทั้งสองฝั่ง API และ UI
  - ปรับปรุงการเชื่อมโยงข้อมูลใน `VocationalEvaluationReportView.vue` ให้แสดงช่วงวันที่, ตัวเลขสถิติ, และสังกัดฝ่ายอย่างถูกต้องและยืดหยุ่น
- **การปรับแต่งแถบนำทาง Dual-Rail Navigation (UI Polish)**:
  - ปรับปรุง Rail 1 Header: เปลี่ยนปุ่มโลโก้ด้านบนเป็นตราสัญลักษณ์ประจำระบบ E-la สไตล์ Monogram หรูหรา แยกชัดเจนจากปุ่มเลือกโมดูล "หน้าหลัก"
  - ปรับปรุงการตรวจจับเส้นทาง (Route-to-Module Auto Detection) ให้จับคู่โมดูลได้อย่างแม่นยำทุกหน้า
- **ผลการทดสอบและการตรวจสอบ (Hard Gates Verified)**:
  - Frontend Vitest: 26 files / 190 tests ผ่าน ๑๐๐%
  - Production Build: `npm run build` ผ่านสมบูรณ์ (5.91s)
  - Full Backend Test Suite: 1,356 passed / 1 skipped (5,578 assertions)
  - Laravel Pint Code Style: 753 files ผ่าน ๑๐๐%


### การพัฒนาระบบแถบนำทางแบบโมดูลคู่ (Dual-Rail Navigation System) — 2026-09-16

- **การยกระดับแถบนำทางสู่สถาปัตยกรรม Dual-Rail ระดับ Enterprise**:
  - **Rail 1: Primary Module Rail (๗๒ พิกเซล)**: แถบแนวตั้งไอคอนโมดูลหลัก (หน้าหลัก 🏠, ระบบลางาน 🌿, ระบบลงเวลา/OT ⏰, งานสารบรรณ 📬, ศูนย์รายงาน 📊, ผู้ใช้งาน/บุคลากร 👥, การตั้งค่าระบบ ⚙️) พร้อมชื่อไทยสั้น, Tooltip คำอธิบายเต็ม, Active Indicator Pill และป้ายแสดงยอดแจ้งเตือนรวมของแต่ละโมดูล
  - **Rail 2: Sub-Navigation Panel (๒๔๐ พิกเซล)**: แผงแสดงรายการเมนูย่อยเฉพาะของโมดูลที่เลือก พร้อมหัวข้อโมดูลและปุ่มพับเก็บ `[ ◀ ]` เพื่อขยายพื้นที่ทำงานหลักให้กว้างเต็มตา
  - **Smart Route-to-Module Detection**: สลับโมดูลใน Rail 1 ให้อัตโนมัติตามเส้นทาง URL ที่กำลังเปิดอยู่
  - **Responsive Mobile Drawer**: รองรับอุปกรณ์มือถือด้วยแถบสลับโมดูลแนวนอนด้านบน พร้อมรายการเมนูย่อยของโมดูลที่เลือก
  - **State Persistence**: บันทึกสถานะการย่อ/ขยายของแผงเมนูย่อยลงใน `localStorage`
- **มาตรวัดคุณภาพ (Quality Gates)**:
  - Frontend Vitest: 26 files / 190 tests ผ่าน ๑๐๐%
  - Production Build: `npm run build` ผ่านสมบูรณ์ (2.76s)
  - Backend MenuDriftTest: ผ่าน ๑๐๐%


### การปรับปรุง UI ตามบทบาท สิทธิ์ และบริบทสถานศึกษาอาชีวศึกษา (Persona & Role-Based UI Integration) — 2026-09-16

- **การยกระดับหน้าจอและประสบการณ์ผู้ใช้ตามบทบาท (Role & Persona Experience)**:
  - `LeaveRequestView.vue`:
    - เพิ่มวิดเจ็ตเกณฑ์วัดวันลาสะสมรอบการประเมินเลื่อนขั้นเงินเดือน ก.ค.ศ. (๒๓ วัน) แสดงรอบ ๖ เดือนปัจจุบัน (๑ ต.ค. – ๓๑ มี.ค. หรือ ๑ เม.ย. – ๓๐ ก.ย.) และ Progress Gauge เพื่อให้ผู้ยื่นคำขอตรวจสอบสถานะความปลอดภัยได้ทันที
    - ระบบคัดกรองประเภทการลาอัจฉริยะตามเพศ (Smart Gender Filter): ซ่อนการลาคลอดบุตรสำหรับบุคลากรชาย และซ่อนการลาอุปสมบท/ลาช่วยภริยาคลอดบุตรสำหรับบุคลากรหญิง
    - ระบบดึงข้อมูลอัตโนมัติ (Profile Auto-Fill): กรอกที่อยู่และหมายเลขโทรศัพท์ติดต่อระหว่างลาจากข้อมูลส่วนตัวอัตโนมัติ
  - `DashboardView.vue`:
    - เพิ่มการ์ดสรุปสถานะการประเมิน ก.ค.ศ. (Vocational Evaluation Card) บนหน้าแรก แสดงเกณฑ์ ๒๓ วัน แถบความคืบหน้า และสถานะ
  - `CheckInView.vue`:
    - เพิ่มตัวเลือกโหมดลงเวลา ๒ รูปแบบ: การลงเวลาปกติในสถานศึกษา และการลงเวลานิเทศนักศึกษาฝึกงาน/ปฏิบัติราชการนอกสถานที่ (Off-Site Vocational Duty) โดยบังคับระบุชื่อสถานที่/สถานประกอบการ แนบภาพถ่ายหลักฐาน และพิกัดดาวเทียม GPS
  - `ApprovalsView.vue`:
    - เพิ่มป้ายระบุ ๔ ฝ่ายหลัก สอศ. (Division Badge) ของผู้ยื่นคำขอ เพื่อให้รองผู้อำนวยการแต่ละฝ่ายตรวจสอบพิจารณาได้ตรงฝ่าย
    - เพิ่มป้ายแจ้งเตือนสีส้มกรณีผู้ยื่นคำขอมีวันลาสะสมเกินเกณฑ์ประเมิน ๒๓ วัน
- **การจัดการเมนูระบบและการ Sync สำหรับฐานข้อมูลจริง**:
  - เพิ่มเมนู `leave.vocational_evaluation` (รายงานประเมิน ก.ค.ศ. เกณฑ์ ๒๓ วัน) และ `attendance.biometric_hub` (ศูนย์นำเข้าไฟล์สแกนนิ้ว/ใบหน้า) ใน `MenuSeeder::TREE`
  - สร้าง Migration `2026_09_26_100000_sync_vocational_evaluation_and_biometric_menus.php` สำหรับซิงค์เมนูให้อินสแตนซ์ที่ติดตั้งแล้ว
  - อัปเดต `TREE_FINGERPRINT` และผ่านการทดสอบ `MenuDriftTest` ๑๐๐%
- **มาตรวัดคุณภาพ (Full Hard Gates)**:
  - Vitest: 26 files / 190 tests ผ่าน ๑๐๐%
  - Production Build: `npm run build` ผ่านสมบูรณ์ (3.14s)
  - Full Backend Test Suite: 1,356 passed / 1 skipped (5,575 assertions), 0 failed
  - Pint Code Style: 753 files ผ่าน ๑๐๐%


### เฟสที่ ๔: Verification, Full-Suite Hard Gates & Rollout Runbook — 2026-09-16

- **เอกสารคู่มือปฏิบัติการและแผนเปิดใช้งานจริง (Operational Runbook)**:
  - จัดทำ [`docs/onboarding/VOCATIONAL-COLLEGE-ROLLOUT-RUNBOOK.md`](file:///d:/E-Le/docs/onboarding/VOCATIONAL-COLLEGE-ROLLOUT-RUNBOOK.md) ครอบคลุม:
    1. ภาพรวมสถาปัตยกรรมสถานศึกษาอาชีวศึกษา ๔ ฝ่ายหลัก สอศ. และ ๕ กลุ่มบุคลากร
    2. ขั้นตอนการย้ายและตั้งค่าข้อมูลผัง ๔ ฝ่าย (`VocationalOrgStructureSeeder`) และการแต่งตั้งผู้บริหาร ๕ ระดับ
    3. คู่มือการใช้งานศูนย์นำเข้าไฟล์สแกนเวลา (Smart Biometric Hub) ทั้งแบบ Drag & Drop และประมวลผล Auto-Reconciliation
    4. คู่มือการใช้งานระบบรายงานประเมิน ก.ค.ศ. เกณฑ์วันลา ๒๓ วัน และการสร้างแฟ้มหลักฐานผลการปฏิบัติงานรายบุคคลสำหรับ ว PA
    5. คู่มือความปลอดภัย การสำรอง และการซ้อมกู้คืนข้อมูล (Backup & Disaster Recovery Drill)
    6. รายการตรวจสอบความพร้อม ๗ ด้านหลังการอัปเกรด (Post-Upgrade 7-Pillar Checklist)
    7. แผ่นพับสรุปการใช้งานระบบ ๑ หน้า (One-Page Cheat Sheet) สำหรับแอดมิน, ผู้บริหาร, และครู/บุคลากร
- **ผลการทดสอบและการตรวจสอบ Hard Gates ครบวงจร**:
  - `php artisan app:preflight-check` ผ่านเกณฑ์หลัก ๑๐๐% (FAIL = 0)
  - Laravel Pint สะอาด ๑๐๐% (752 files)
  - Full Backend Test Suite: **1,356 passed / 1 skipped (5,575 assertions), 0 failed**
  - Frontend Vitest Suite: **26 test files / 189 passed (100%), 0 failed**
  - Frontend Production Build: `npm run build` ผ่านสมบูรณ์ ๑๐๐% (2.78s)
- **สรุปภาพรวมโครงการ**:
  - พัฒนาครบทั้ง ๔ เฟสตามพิมพ์เขียว ๒๑ หมวดหมู่ใน [`docs/VOCATIONAL-COLLEGE-PERSONNEL-ARCHITECTURE.md`](file:///d:/E-Le/docs/VOCATIONAL-COLLEGE-PERSONNEL-ARCHITECTURE.md) อย่างสมบูรณ์แบบ รักษาความเข้ากันได้ย้อนหลัง ๑๐๐% และ UI ภาษาไทย ๑๐๐%

### เฟสที่ ๓: Frontend Modern UI & Integration (ระบบรายงานประเมิน ก.ค.ศ. & Biometric Hub) — 2026-09-16

- **การพัฒนาคอมโพเนนต์และหน้าจอใหม่**:
  - `VocationalEvaluationReportView.vue`: หน้ารายงานสรุปการประเมินวันลาและเวลาปฏิบัติราชการ ก.ค.ศ. เชื่อมต่อ API `GET /api/v1/reports/vocational/evaluation-summary` และ `GET /api/v1/reports/vocational/teacher-pa-evidence`
    - แผงตั้งค่าช่วงเวลารอบประเมิน ก.ค.ศ. (รอบที่ ๑: ๑ ต.ค. – ๓๑ มี.ค., รอบที่ ๒: ๑ เม.ย. – ๓๐ ก.ย., หรือรอบปีงบประมาณ)
    - ตัวกรอง ๔ ฝ่ายหลัก สอศ. (บริหารทรัพยากร, แผนงานฯ, พัฒนากิจการฯ, วิชาการ) และตัวกรองประเภทบุคลากร ๕ กลุ่ม
    - การ์ดสรุปสถิติ KPI (จำนวนบุคลากรทั้งหมด, ผู้ที่ผ่านเกณฑ์, ผู้ที่ลาเกินเกณฑ์ ๒๓ วัน)
    - ตารางแสดงผลพร้อม Badge สีเตือนเมื่อเกินเกณฑ์ ๒๓ วัน และปุ่มเปิดดูแฟ้มหลักฐาน ว PA รายบุคคล (Teacher PA & Workload Evidence Modal)
  - `BiometricHubView.vue`: ศูนย์นำเข้าไฟล์สแกนนิ้วและใบหน้า เชื่อมต่อ API `POST /api/v1/attendance/biometric/import-file`
    - พื้นที่ลากและวางไฟล์ (Drag & Drop Zone) รองรับไฟล์ `.dat`, `.txt`, `.csv`, `.xlsx`
    - ตัวเลือก Timezone (Asia/Bangkok, UTC ฯลฯ)
    - แถบแสดงความคืบหน้าการอัปโหลด (Upload Progress Bar) และการ์ดสรุปผลการนำเข้า (จำนวนเหตุการณ์ทั้งหมด, บันทึกสำเร็จ, ข้าม)
- **การเชื่อมโยงระบบและการนำทาง (Navigation Integration)**:
  - เพิ่มเส้นทาง `/reports/vocational-evaluation` และ `/admin/attendance/biometric-hub` ใน `frontend/src/router/index.js`
  - เพิ่มปุ่ม Shortcut ไปยังหน้ารายงานประเมิน ก.ค.ศ. ใน `frontend/src/views/ReportsView.vue`
  - เพิ่มปุ่ม Shortcut ไปยังหน้า Biometric Hub ใน `frontend/src/views/admin/AttendanceSummaryView.vue`
  - อัปเดต `ROUTES_WITHOUT_MENU` ใน `backend/tests/Feature/MenuIntegrityTest.php`
- **การทดสอบและมาตรวัดคุณภาพ (Quality Gates)**:
  - `vocationalEvaluationReportView.test.js` (3 passed)
  - `biometricHubView.test.js` (2 passed)
  - Frontend Vitest: 26 test files, 189 passed (100%)
  - Frontend Build: `npm run build` ผ่านสมบูรณ์ 100% (2.78s)
  - Backend Suites: `MenuIntegrityTest` (9 passed / 27 assertions), `VocationalReportApiTest` (6 passed), `VocationalDivisionScopeTest` (2 passed), `VocationalOrgStructureSeederTest` (4 passed)

### เฟสที่ ๒: Backend Module APIs & Automated Hard Gate Tests (ระบบประเมิน ก.ค.ศ. & Biometric Hub) — 2026-09-16

- **การพัฒนาและปรับปรุง**:
  - `BiometricFileParserService`: บริการประมวลผลและนำเข้าไฟล์ข้อมูลบันทึกเวลาจากเครื่องสแกนลายนิ้วมือและใบหน้า (ZKTeco, HIP, Hikvision) รองรับไฟล์ `.dat`, `.txt`, `.csv`, `.xlsx` บันทึก raw event และทำการ map บุคลากรผ่าน `AttendanceIdentityMap` พร้อม reconcile เวลาเข้า-ออกงานอัตโนมัติ
  - `VocationalLeaveEvaluationService`: เครื่องคำนวณและประมวลผลวันลาและเวลาปฏิบัติราชการตามรอบเลื่อนขั้นเงินเดือน ก.ค.ศ. (รอบที่ ๑: ๑ ต.ค. – ๓๑ มี.ค., รอบที่ ๒: ๑ เม.ย. – ๓๐ ก.ย., หรือรอบปีงบประมาณ) คำนวณเกณฑ์วันลาสะสม ๒๓ วันสำหรับข้าราชการครู และ ๑๓ วันสำหรับพนักงานราชการ/ลูกจ้าง พร้อมบริการจัดเตรียมแฟ้มหลักฐาน ว PA (Teacher PA & Workload Evidence Pack)
  - `VocationalReportController` & RESTful API Endpoints:
    - `GET /api/v1/reports/vocational/evaluation-summary` (รายงานสรุปการประเมินวันลา ก.ค.ศ.)
    - `GET /api/v1/reports/vocational/teacher-pa-evidence` (แฟ้มหลักฐาน ว PA รายบุคคล)
    - `POST /api/v1/attendance/biometric/import-file` (นำเข้าไฟล์บันทึกเวลาสแกนนิ้ว/ใบหน้า)
- **ความปลอดภัยและการป้องกัน IDOR**:
  - ทุก endpoint ผ่านด่านตรวจสอบสิทธิ์ระดับ Server-Side (`leave.report.view`, `attendance.view_all`) และกรณีเรียกดูข้อมูล ว PA รายบุคคลจะอนุญาตเฉพาะตนเอง หรือผู้มีสิทธิ์กำกับดูแลเท่านั้น
- **มาตรวัดคุณภาพและการทดสอบ**:
  - `BiometricFileParserTest` (2 passed / 20 assertions)
  - `VocationalReportApiTest` (6 passed / 22 assertions)
  - Full Backend Suite: 1,356 passed, 1 skipped (5,575 assertions), 0 failure
  - Laravel Pint สะอาด 100% (752 files)

### เฟสที่ ๑: ฐานราก Backend & ขอบเขตข้อมูล ๔ ฝ่ายสถานศึกษาอาชีวศึกษา (Core Foundation Engines) — 2026-09-16

- **การพัฒนาและปรับปรุง**:
  - `OrgScopeResolver`: เพิ่มฟังก์ชัน `divisionOrgUnitId()` เพื่อค้นหาฝ่ายหลัก (๑ ใน ๔ ฝ่ายหลัก สอศ.) ตามลำดับชั้นผังองค์กรจริง และ `isSameDivision()` เพื่อตรวจขอบเขตสังกัดฝ่ายเดียวกัน ป้องกันการเข้าถึงข้อมูลข้ามฝ่ายโดยไม่ได้รับอนุญาต (Anti-IDOR)
  - `ApproverResolverService`: ปรับปรุง `resolveDivisionDirectorIds()` ให้สามารถไต่ระดับค้นหาผู้บริหารฝ่าย (รอง ผอ. ๔ ฝ่าย) ได้แม่นยำแม้แผนกวิชาจะซ้อนอยู่ใต้หน่วยงานย่อย
  - เพิ่มชุดทดสอบ `VocationalDivisionScopeTest` ครอบคลุมการคำนวณและสโคปข้อมูล ๔ ฝ่าย
  - **Zero-Regression & Non-Destructive**: คงความเข้ากันได้ย้อนหลัง 100% ไม่มี Hard-code และไม่กระทบโครงสร้างระบบเดิม
- **มาตรวัดคุณภาพ**:
  - `VocationalDivisionScopeTest` (2 passed / 11 assertions)
  - Full Backend Test Suite: 1,348 passed, 1 skipped (5,533 assertions) 0 failure
  - Pint ผ่าน 747 files

### พิมพ์เขียวสถาปัตยกรรมการบริหารงานบุคคลและการปฏิบัติราชการสถานศึกษาอาชีวศึกษา (๒๑ หมวด) — 2026-09-16

- **เอกสารแม่บท**: จัดทำ [`docs/VOCATIONAL-COLLEGE-PERSONNEL-ARCHITECTURE.md`](file:///d:/E-Le/docs/VOCATIONAL-COLLEGE-PERSONNEL-ARCHITECTURE.md) ครอบคลุม 21 หมวดสถาปัตยกรรมสำหรับวิทยาลัยอาชีวศึกษา (สอศ. / ก.ค.ศ. / วิทยาลัยเทคนิคดอนเมือง)
- **สรุปเนื้อหาสำคัญ**:
  1. โครงสร้างองค์กร ๔ ฝ่ายหลัก และโมเดลตำแหน่งคู่ขนาน (Dual Role & Multi-Duty)
  2. การจำแนกประเภทบุคลากร ๕ กลุ่ม และลำดับสายอำนาจการอนุมัติ ๕ ระดับ + ผู้รักษาการแทน
  3. ระบบแบบฟอร์มการลาไดนามิก และความไม่เปลี่ยนแปลงของข้อมูลประวัติ (Version Pinning)
  4. สายการอนุมัติและยกเลิกใบลา ผูกตำแหน่งช่องลงนาม 1:1 กับกระดาษ (Visual Slot Binding)
  5. เครื่องยนต์โครงสร้างพื้นฐานร่วมกัน ๖ ระบบ (Scope, Approval, PDF Visual Stamp, Notification, Setting, Audit)
  6. ฟังก์ชันเสริมการลาเฉพาะทางสถานศึกษา (เกณฑ์ประเมิน 23 วัน, ตารางโควตาวันลาสะสม, ตัวช่วยยกยอดสิ้นปี)
  7. สถาปัตยกรรมระบบลงเวลาและการปฏิบัติงานนอกเวลา OT (เช็กอินนอกสถานที่ GPS/รูปถ่าย, แผนที่ Geofencing, บัญชีลงเวลา, ขอ OT ผูกคำสั่ง)
  8. สถาปัตยกรรมสารบรรณและการลงนาม/เกษียนบนไฟล์ PDF โดยตรง (Permanent Visual Flattening, ตรารับอัตโนมัติ, ทะเบียนคำสั่ง, ลงนามร่วม/คณะกรรมการ)
  9. โครงสร้างเมนูตั้งค่ารวมศูนย์ ๖ หมวดหมู่ (Zero-Code Tabbed Settings)
  10. ศูนย์รายงานกลางสถานศึกษา (Unified College Report Hub ๕ หมวด, ตัวกรองรอบ ๑ เม.ย. / ๑ ต.ค., แดชบอร์ด ๔ ฝ่าย)
  11. มาตรการความปลอดภัย การย้ายข้อมูล และคู่มือหลังปรับปรุงระบบ (Operational Safety & Post-Upgrade Runbook)
  12. กลยุทธ์และลำดับการพัฒนา: Backend-First (Backend Foundation -> API & Tests -> Frontend UI -> Verification)
  13. สถาปัตยกรรมการเชื่อมต่อเครื่องสแกนนิ้ว/ใบหน้าอัจฉริยะ (Biometric Hub: ลากวางไฟล์ + Direct Network Sync)

### จัดระเบียบลำดับและโครงสร้างเมนูระบบ (Menu Information Architecture) — 2026-09-16

- **ปัญหา**: เมนูภายในแต่ละกลุ่ม (เช่น ระบบลางาน, งานสารบรรณ, ตั้งค่าระบบ) เรียงตามลำดับเวลาที่พัฒนา มีการปะปนกันระหว่างงานประจำวันของบุคลากรทั่วไป งานอนุมัติของหัวหน้างาน และงานกำกับดูแลของเจ้าหน้าที่
- **การปรับปรุง**:
  - จัดระเบียบลำดับเมนูใน `MenuSeeder::TREE` ตาม Flow การทำงานจริงของสถานศึกษา: (1) งานบริการตนเองของบุคลากร/ครู (2) งานพิจารณาอนุมัติของหัวหน้างาน (3) งานกำกับดูแลและรายงานสำหรับเจ้าหน้าที่/ผู้บริหาร
  - กลุ่มสารบรรณเรียงตามเส้นทางเอกสารราชการ: แดชบอร์ด -> กล่องของฉัน -> รอพิจารณา/ลงนาม -> ทะเบียนรับ/ส่ง/ภายใน -> กระจายเอกสาร -> คลังหนังสือ -> สารบบเรื่อง -> รายงาน
  - กลุ่มตั้งค่าระบบจัดเป็นหมวด: ข้อมูลองค์กร/ผังหน่วยงาน -> ปีงบประมาณ/ปฏิทิน -> นโยบายการลา/เวลา -> ลำดับอนุมัติ/แจ้งเตือน -> เอกสาร/โมดูลระบบ/การเชื่อมต่อ
  - **Zero-Impact**: คง `code`, `route_name`, `route_path`, และสิทธิ์การเข้าถึง (Permissions) เดิม 100% บุ๊กมาร์กและระบบความปลอดภัยไม่ได้รับผลกระทบใด ๆ
  - เพิ่ม migration `2026_09_25_100000_reorganize_menus_structure` ซิงก์ลำดับและกลุ่มไปยังระบบที่ติดตั้งแล้วทันที พร้อมล้าง cache อัตโนมัติ
- **มาตรวัดคุณภาพ**: `MenuIntegrityTest` (9 passed / 21 assertions) · `MenuDriftTest` (5 passed / 11 assertions) · `PermissionCatalogTest` (6 passed / 95 assertions) · `SarabanModuleRegistrationTest` (13 passed / 192 assertions) · Pint 745 files · Frontend Vitest 184 passed (24 files) · Frontend build ผ่าน 100%

### กล่องยืนยันมาตรฐานของระบบ และจัดรูปแบบหน้าตรวจหลักฐานการลาใหม่ — 2026-09-16

- **ปัญหา**: หน้าจอ 15 หน้าถามยืนยันด้วย `window.confirm()` / `window.prompt()` ของเบราว์เซอร์ รวม 27 จุด · ปุ่มเป็นภาษาของเบราว์เซอร์ไม่ใช่ภาษาไทย (ขัด PROJECT_RULES ข้อ 13) จัดรูปแบบข้อความไม่ได้ บังคับกรอกไม่ได้ และหน้าตาไม่เหมือนกล่องยืนยันของโมดูลสารบรรณที่ใช้ `AppModal` อยู่แล้ว
- **`stores/confirm.js` + `composables/useConfirm.js` + `components/ConfirmDialog.vue`**: กล่องยืนยันกลาง วางไว้ครั้งเดียวที่ `App.vue` เหมือน `AlertList` · ใช้รูปแบบ promise จึงเขียนแทน `confirm()` เดิมได้ตรง ๆ เพียงเติม `await`
  - `confirmAction()` ถามยืนยันธรรมดา · `confirmDanger()` สำหรับการกระทำที่ย้อนกลับไม่ได้ (ปุ่มยืนยันสีแดง) · `promptText()` ขอข้อความแทน `window.prompt`
  - รองรับหัวเรื่อง ข้อความอธิบายผลกระทบ ข้อความบนปุ่มที่บอกการกระทำจริง (เช่น "ลบหน่วยงาน" แทน "ตกลง") และ**บังคับกรอก**ก่อนยืนยันได้ — สิ่งที่ `window.prompt` ทำไม่ได้
  - เปิดกล่องใหม่ทับกล่องเดิมจะปิดของเดิมด้วย "ยกเลิก" เพื่อไม่ให้ promise เส้นนั้นค้างตลอดไป
- **แปลงครบทั้ง 27 จุดใน 15 หน้าจอ** รวมถึง navigation guard ที่กันงานหายเมื่อยังไม่ได้บันทึก (`onBeforeRouteLeave` รับ promise ได้จึงรอผลได้ตรง ๆ)
- **สองจุดที่เคยเป็น `window.prompt` ล้วนเป็นเหตุผลที่ผู้ถูกตรวจจะได้อ่าน** — "เหตุผลที่ไม่ผ่าน" ในหน้าตรวจหลักฐานการลา และ "เหตุผลที่ไม่อนุมัติ" คำขอแก้ไขเวลา · เดิมกด OK ทั้งช่องว่างได้ ตอนนี้บังคับกรอกจริง
- **`LeaveGovernanceView.vue` ถูกจัดรูปแบบใหม่**: ทั้งบล็อก `<script setup>` เคยถูกบีบเหลือบรรทัดเดียว (ยาวกว่า 2,000 อักขระ) แก้ต่อ ทบทวนโค้ด และอ่าน diff แทบไม่ได้ · จัดรูปแบบใหม่โดยไม่เปลี่ยนพฤติกรรม พร้อมเพิ่ม `try/catch` ที่ขาดไปใน `verify` / `decide` / `voidLeave` และเปลี่ยนมาใช้ `getErrorMessage` เหมือนหน้าอื่น
- **มาตรวัดคุณภาพ**: `confirmDialog.test.js` (6 passed) · Frontend Vitest 184 passed (24 files) · eslint 0 error · build ผ่าน

### ชื่อผู้ลงนามบนเอกสารยึดผู้มีอำนาจจริง ไม่ใช่ข้อความที่พิมพ์ไว้ในหน้าตั้งค่า — 2026-09-16

- **ปัญหา**: ชื่อที่พิมพ์ในช่องลงนามของใบลาที่ยังไม่มีใครเซ็น มาจากช่องข้อความในหน้าตั้งค่า (`head_hr_name`, `deputy_admin_name`, `director_name`) ซึ่งเป็นข้อมูลคนละชุดกับทะเบียนผู้มีอำนาจจริงของระบบ · ค่าที่บันทึกเป็น "ข้อความชื่อ" ณ วันที่เลือก ไม่ได้ผูกกับบัญชีผู้ใช้ · เมื่อเปลี่ยนตัวผู้รับผิดชอบแต่ไม่มีใครกลับไปแก้ ใบลาเปล่าที่พิมพ์ไปให้เสนอเซ็นจะขึ้นชื่อคนที่ไม่ใช่ผู้มีอำนาจลงนามแล้วบนเอกสารราชการ
- **`LeaveDocumentController::pendingApproverName()`**: ถามระบบก่อนว่าขั้นนั้นใครเป็นผู้มีอำนาจจริงสำหรับผู้ยื่นรายนี้ ผ่าน `ApproverResolverService` ตัวเดียวกับที่สายอนุมัติใช้จริง · ลำดับความสำคัญคือ **ผู้มีอำนาจจริง → ค่าที่ตั้งไว้ในหน้าตั้งค่า → เส้นประ**
- **ไม่เดาเมื่อมีหลายคน**: ใช้ชื่อจากระบบเฉพาะเมื่อผู้มีอำนาจของขั้นนั้นมีคนเดียว เพราะถ้ามีหลายคน การพิมพ์ไปคนหนึ่งผิดยิ่งกว่าการเว้นว่าง
- **การพิมพ์ต้องไม่ล้ม**: การหาผู้มีอำนาจแตะทั้งผังหน่วยงาน ทะเบียนผู้รับผิดชอบ และการมอบอำนาจ · ถ้าข้อมูลชุดใดไม่พร้อมจะตกไปใช้ค่าสำรองแทนการโยน error หลักเดียวกับ Document Engine
- **ถอดโค้ดซ้ำ**: บล็อกสร้างช่องลงนามเคยถูกคัดลอกไว้ทั้งใน `exportPdf()` และ `exportWord()` แบบตัวต่อตัว (60 บรรทัด) เอกสารสองแบบของใบเดียวกันจึงเสี่ยงเพี้ยนจากกันทุกครั้งที่แก้ที่เดียว · รวมเป็น `approverBlocksFor()` ตัวเดียว
- **หน้า "ผู้บริหารและผู้ลงนาม"**: เพิ่มคำอธิบายว่าชื่อในหน้านี้เป็น**ค่าสำรองสำหรับพิมพ์บนกระดาษเท่านั้น** พร้อมลิงก์ไปยังแหล่งความจริงจริง (จัดการลำดับการอนุมัติ / ผู้รับผิดชอบหน่วยงาน) — เดิมผู้ดูแลเข้าใจว่าแก้ที่นี่แล้วผู้อนุมัติเปลี่ยนตาม
- **มาตรวัดคุณภาพ**: `LeavePendingApproverNameTest` (4 passed / 11 assertions) · Pint ผ่าน 744 ไฟล์ · Frontend Vitest 178 passed · eslint 0 error · build ผ่าน

### ผังหน่วยงานตามโครง ๔ ฝ่ายของสถานศึกษาอาชีวศึกษา — 2026-09-15

- **ปัญหา**: การติดตั้งใหม่ได้ผังแบนราบชั้นเดียว — ยอดผังกับหน่วยงานชื่อกลาง ๆ สองหน่วย (`ฝ่ายสารสนเทศ`, `ฝ่ายทรัพยากรบุคคล`) ซึ่งไม่ใช่โครงของวิทยาลัยจริง ส่วนแผนกวิชาที่ seeder ข้อมูลจำลองสร้างก็แขวนใต้ยอดผังตรง ๆ · สิ่งที่ผูกกับผัง (สายอนุมัติที่ไต่หาหัวหน้าตามลำดับชั้น ขอบเขตการมองเห็นของรายงาน ปฏิทินวันทำการรายหน่วยงาน) จึงไม่มีชั้น "ฝ่าย" ให้ไต่หรือให้กรอง — ผู้ดูแลกำหนดได้แค่ "ทั้งสถานศึกษา" หรือ "รายแผนก" ไม่มีตรงกลาง
- **`VocationalOrgStructureSeeder`**: สร้างผังตั้งต้นตามระเบียบสำนักงานคณะกรรมการการอาชีวศึกษาว่าด้วยการบริหารสถานศึกษา — ๔ ฝ่าย (บริหารทรัพยากร / แผนงานและความร่วมมือ / พัฒนากิจการนักเรียนนักศึกษา / วิชาการ) และ ๒๕ งานในสังกัด · อ้างอิงด้วย `code` คงที่ จึง idempotent และ **ไม่ทับ**ชื่อหรือสังกัดที่ผู้ดูแลแก้ไว้เอง
- **`DatabaseSeeder`**: เรียก `OrgUnitBackfillSeeder` + `VocationalOrgStructureSeeder` ตั้งแต่ต้น แล้วให้บัญชีระบบสังกัด "งานศูนย์ข้อมูลสารสนเทศ" และ "งานบุคลากร" ที่มีอยู่จริงในผัง แทนหน่วยงานสมมติสองหน่วยเดิม (ถอด `seedOrgUnit()` ที่ไม่มีใครเรียกแล้วออก)
- **`MockDataSeeder`**: แผนกวิชา 10 แผนกสังกัด "ฝ่ายวิชาการ" แทนการแขวนใต้ยอดผัง และถ้าไม่พบฝ่ายวิชาการจะตกไปแขวนใต้ยอดผังแทนที่จะล้มทั้ง seeder
- **หน้า "โครงสร้างหน่วยงาน"**: เดิมเป็นตารางแบนเรียงตาม `parent_id` ซึ่งอ่านไม่ออกเมื่อผังมีเกือบสามสิบหน่วยสามชั้น · เปลี่ยนเป็นเรียงแบบต้นไม้ ย่อหน้าตามชั้น เพิ่มคอลัมน์ "ระดับ" (สถานศึกษา / ฝ่าย / งาน / แผนกวิชา) และย่อหน้าในช่องเลือกหน่วยงานแม่ด้วย
- **เพิ่มช่อง "ระดับ" ในฟอร์มหน่วยงาน**: เดิมหน้าจอไม่มีช่องนี้ ทุกหน่วยที่สร้างจากหน้าเว็บจึงถูกบันทึกเป็น `department` หมด แม้จะเป็นฝ่ายหรืองาน (endpoint รับ `type` อยู่แล้ว)
- **มาตรวัดคุณภาพ**: `VocationalOrgStructureSeederTest` (4 passed / 13 assertions) · `orgUnitsTreeView.test.js` (4 passed) · Frontend Vitest 178 passed (23 files) · eslint 0 error · build ผ่าน · Pint ผ่าน 743 ไฟล์

### ตรวจและแก้ข้อมูล/หน้าจอให้ตรงกับการใช้งานจริงของสถานศึกษา — 2026-09-15

- **ช่องเลือกชื่อบุคลากรเห็นแค่ 25 คนแรก (ใช้งานติดขัดจริง)**: `/admin/users` แบ่งหน้าละ 25 คนเสมอ (ถูกต้องสำหรับตารางที่มีปุ่มเลื่อนหน้า) แต่หน้าจอที่ใช้รายชื่อเป็น "ช่องเลือก" ไม่มีปุ่มเลื่อนหน้า บุคลากรที่ชื่อเรียงหลังคนที่ 25 จึงเลือกไม่ได้เลย
  - เพิ่ม `frontend/src/utils/personnelDirectory.js` (`fetchAllPersonnel`) ไล่ดึงทีละ 100 คนจนครบ โดยขอบเขตการมองเห็นและการบันทึก audit ยังเป็นของฝั่ง server เหมือนเดิม
  - แก้ 4 หน้าจอให้ใช้ helper นี้: `LeaveManagementView.vue` (บันทึกวันลาแทนบุคลากร), `OrgUnitManagersView.vue` (ผู้รับผิดชอบหน่วยงาน), `PersonnelAssignmentsView.vue` (กำหนดประเภทบุคลากร), `AdminSettingsView.vue` (เลือกผู้ลงนาม)
- **หน้า "กำหนดประเภทบุคลากร" ไม่แสดงใครเลย**: `PersonnelAssignmentsView.vue` รับ **ทั้งซอง** ของ endpoint แบ่งหน้ามาใส่ตัวแปร `users` ทำให้เป็น object ไม่ใช่ array แล้ว `filtered` ที่เรียก `.filter()` โยน error ทุกครั้ง — แก้แล้ว พร้อมเพิ่มสถานะกำลังโหลด ข้อความเมื่อไม่พบข้อมูล และการแจ้งเตือนเมื่อโหลดไม่สำเร็จ (เดิมไม่มี try/catch เลย)
- **เปลี่ยนปีในปฏิทินวันทำการแล้วรายการไม่โหลดใหม่**: ช่องปีใน `WorkCalendarView.vue` ไม่มี `@change` ต่างจากช่องหน่วยงานที่มี — เพิ่มแล้ว
- **ปีในหน้าจอเป็น ค.ศ. ขัดกับทั้งระบบที่ใช้ พ.ศ.**: `LeaveManagementView.vue` และ `WorkCalendarView.vue` เปลี่ยนช่องพิมพ์ตัวเลข ค.ศ. เป็นรายการเลือก พ.ศ. ตามแบบเดียวกับ `AttendanceSummaryView.vue` (ค่าที่ส่งให้ API ยังเป็น ค.ศ. เหมือนเดิม)
- **ชื่อบทบาทเดียวกันแสดงคนละชื่อในแต่ละหน้า**: `WorkflowManagementView.vue` มีตารางแปลชื่อบทบาทของตัวเองที่ไม่ตรงกับชุดกลาง (`HR Head` = "หัวหน้าบุคลากร", `Employee` = "พนักงานทั่วไป" ขณะที่หน้าอื่นใช้ "เจ้าหน้าที่บุคลากร (HR)" และ "บุคลากร / ครู / เจ้าหน้าที่") — ถอดตารางซ้ำทิ้ง ใช้ `utils/roleLabel.js` ชุดเดียว และย้ายคีย์ `Deputy` / `HRHead` ที่มีเฉพาะในตารางซ้ำเข้าไปไว้ที่ชุดกลาง
- **คำเรียกบุคลากรไม่ตรงกับคำที่สถานศึกษาใช้**: แทนคำว่า "พนักงาน" ที่เหลืออยู่ 11 จุดด้วย "บุคลากร" (`LeaveManagementView.vue`, `TeamCalendarView.vue`) และเปลี่ยนตำแหน่งตัวอย่างในไฟล์ CSV ต้นแบบนำเข้าผู้ใช้จาก "พนักงานทั่วไป" เป็น "ครู"
- **เก็บกวาด lint ที่ค้างอยู่**: ถอด `urgencyLabel` ที่ไม่ถูกใช้แล้วใน `WorkQueueView.vue` (ป้ายชั้นความเร็วถูกวาดเป็น badge แยกสีในตารางแทน) และแก้การกำหนดค่าซ้ำซ้อนใน `PolicyCenterView.vue`
- **มาตรวัดคุณภาพ**: `personnelDirectory.test.js` (4 passed) · Frontend Vitest 174 passed (22 test files) · eslint ผ่าน 0 error · build ผ่าน

### ยกระดับและปิดงานโมดูลสารบรรณอิเล็กทรอนิกส์สมบูรณ์แบบ (8 มิติหลักสำหรับสถานศึกษาอาชีวศึกษา) — 2026-09-15

- **ระบบข้อความเกษียนด่วน ๔ ฝ่ายอาชีวะ (Executive Quick-Endorsements & Snippets)**:
  - เพิ่มชุดข้อความเกษียนความเห็นและสั่งการสำเร็จรูป 8 รายการสำหรับผู้อำนวยการและรองผู้อำนวยการ 4 ฝ่ายใน `EndorseDocumentView.vue` (ทราบ/ดำเนินการตามระเบียบ, อนุมัติ ตามเสนอ, มอบ รอง ผอ. ฝ่ายบริหารทรัพยากร/วิชาการ/พัฒนากิจการนักเรียนฯ/แผนงานฯ, แจ้งเวียนบุคลากร, ขอข้อมูลเพิ่มเติม)
  - รองรับการกดแทรกข้อความลงในช่องเกษียนทันทีใน 1 คลิก ช่วยลดภาระการพิมพ์ของผู้บริหาร
- **ระบบคัดกรองและแจ้งเตือนหนังสือด่วนพิเศษ (Urgent Priority Engine)**:
  - พัฒนาการแสดงผลสถานะชั้นความเร็วด้วย Visual Badges ที่ชัดเจน: "ด่วนที่สุด" (ป้ายสีแดงกระพริบ `animate-pulse` พร้อมกรอบสีแดงเน้นแถว), "ด่วนมาก" (ป้ายสีส้ม), และ "ด่วน" (ป้ายสีเหลือง) บน `WorkQueueView.vue` และ `InboxView.vue`
  - เพิ่มปุ่มกรองด่วน "เฉพาะด่วนที่สุด / ด่วนมาก" ในหน้า `WorkQueueView.vue` ให้ผู้บริหารและผู้ปฏิบัติงานจัดการงานด่วนได้ทันที
- **แม่แบบหนังสือราชการมาตรฐานไทย (Official Document Templates ตามระเบียบสำนักนายกฯ ๒๕๖๔)**:
  - พัฒนา `OfficialDocumentTemplateService` และ Controller `DocumentTemplateController` (`GET /api/saraban/templates`) ให้บริการแม่แบบ "บันทึกข้อความ" (แบบที่ ๒ - ครุฑซ้าย ๑.๕ ซม.) และ "หนังสือภายนอก" (แบบที่ ๑ - ครุฑกลาง ๓ ซม.)
  - เพิ่มแผงเลือกแม่แบบหนังสือราชการในหน้าสร้างหนังสือ `DocumentFormView.vue` โหลดโครงสร้าง 3 ย่อหน้า (เหตุ - ความประสงค์ - สรุป) และค่าเริ่มต้นอัตโนมัติ
- **ระบบอีเมลสารบรรณและข้อความปฏิเสธความรับผิดชอบ ๒ ภาษา (Official Email & Auto-Reply ภาคผนวก ๗)**:
  - พัฒนา `OfficialEmailTemplateService` ให้บริการข้อความ Legal Disclaimer ภาษาไทยและอังกฤษ, ข้อความตอบรับอัตโนมัติ (Auto-reply), และการจัดรูปแบบหัวข้ออีเมลตามชั้นความเร็วและเลขที่หนังสือ
  - เพิ่ม endpoint `GET /api/saraban/templates/disclaimer`
- **แดชบอร์ดสถิติสารบรรณสำหรับผู้บริหาร (Executive SLA Dashboard)**:
  - ยกระดับ `DashboardService` เพิ่มข้อมูลสรุปสัดส่วนหนังสือในเดือนนี้ (ภายใน/ส่งออก/รับเข้า) และการจำแนกตามชั้นความเร็ว
  - เพิ่มการ์ดสถิติใน `SarabanDashboardView.vue` แสดงภาพรวมงานสารบรรณทั้งหมดของวิทยาลัย
- **การปรับปรุงประสบการณ์ผู้ใช้งานในขั้นตอนการเดินเรื่อง เกษียน และลงนาม (Workflow & Endorsement UX)**:
  - เพิ่มปุ่ม "เกษียนหนังสือ" ในแถบ Action bar ด้านบนขวาของหน้ารายละเอียดหนังสือ (`DocumentFormView.vue`) สำหรับผู้มีสิทธิ์เกษียน
  - ยกแถบการเดินเรื่องและอนุมัติด่วน (Workflow Quick Actions) ขึ้นมาไว้ด้านบนสุด พร้อมไฟกะพริบแจ้งเตือนขั้นตอนที่รอผู้ใช้ดำเนินการ เพื่อให้ผู้ตรวจทาน/ผู้บริหารเห็นปุ่ม "เห็นชอบ / ส่งกลับ / ไม่เห็นชอบ" และช่องระบุความเห็นได้ทันทีโดยไม่ต้องเลื่อนลงล่าง
  - ปรับสถานะปุ่ม "ลงนาม" เป็นปุ่มหลัก (Primary highlight) พร้อมข้อความระบุชัดเจนเมื่อเอกสารดำเนินมาถึงขั้นตอนลงนาม
  - ปรับเงื่อนไขการแสดงปุ่ม "ประทับตรา" ให้แสดงเฉพาะหนังสือรับเข้าภายนอก เพื่อไม่ให้เกิดความสับสนในบันทึกข้อความภายใน
- **การทดสอบและมาตรวัดคุณภาพ (Quality Gate)**:
  - สร้างชุดทดสอบ `SarabanMasterSuiteTest.php` (4 passed / 83 assertions)
  - ผ่านชุดทดสอบ Saraban Backend ทั้งหมด 331 tests (1,622 assertions)
  - Laravel Pint ผ่าน 100% (741 ไฟล์)
  - Frontend Vitest ผ่าน 170 tests (21 test files) และ build ผ่านสมบูรณ์

### ยกระดับระบบสารบรรณและตรวจความพร้อมระบบ (4 หัวข้อหลักและมาตรฐานระเบียบฯ) — 2026-09-15

- **ระบบตรวจความพร้อมระบบและตั้งค่าชุดเลขด่วน (Pre-flight Dashboard & Quick Setup)**:
  - พัฒนา `PreflightReadinessChecker::checkSarabanReadiness()` ตรวจความพร้อม 4 ด้าน (ชุดเลขหนังสือ, บัญชีผู้มีอำนาจลงนาม, แอนติไวรัส ClamAV, และ WebPush VAPID)
  - พัฒนา API `POST /api/saraban/number-series/quick-setup` สร้างชุดเลขเริ่มต้น 5 หมวดตามระเบียบงานสารบรรณ (คำสั่ง, ประกาศ, หนังสือภายใน, หนังสือส่ง, หนังสือรับ) อัตโนมัติ
  - เพิ่มปุ่ม Quick Setup ใน `AdminSettingsView.vue` และ `NumberSeriesView.vue` ช่วยให้ผู้ดูแลระบบกดสร้างชุดเลขได้ในคลิกเดียว
- **ระบบส่งออกรายงานทะเบียนหนังสือเป็น Excel/CSV (RISK-69 & ระเบียบฯ ข้อ ๒๙/๑)**:
  - สร้าง `RegisterReportExportService` สำหรับส่งออกไฟล์ CSV (UTF-8 BOM ป้องกันภาษาไทยเพี้ยน) และไฟล์ XLSX (OpenXML ตามมาตรฐาน ISO/IEC 29500 ไม่พึ่งพาแพ็กเกจภายนอก)
  - ให้บริการรายงาน 3 หมวด: ทะเบียนหนังสือ (รับ แบบ ๑๓/๑, ส่ง แบบ ๑๔/๒, ภายใน แบบ ๒), ทะเบียนเลขหนังสือ, และการเวียนรับทราบ
  - เพิ่ม endpoint `GET /api/saraban/reports/export` (`ability:saraban.report.export`) พร้อม Org Scope Guard
  - เพิ่มปุ่มส่งออกรายงานและ Modal เลือกระบุช่วงวันที่ ทิศทางหนังสือ และรูปแบบไฟล์ใน `RegisterReportsView.vue`
- **การยกระดับความปลอดภัยด้วย Step-up Authentication / TOTP MFA (RISK-29)**:
  - สร้าง `TotpService` พัฒนาตามมาตรฐาน RFC 6238 TOTP แบบ Zero-Schema โดย derive Base32 secret key จาก APP_KEY และ User UUID
  - ปรับปรุง `StepUpAuthentication` ให้รองรับการยืนยันตัวตนด้วยรหัส OTP 6 หลัก หรือรหัสผ่านเดิม
  - เพิ่ม endpoint `GET /api/auth/totp/setup` และปรับปรุง `POST /api/auth/confirm-password` รองรับทั้ง `password` และ `totp_code`
  - ปรับปรุงคอมโพเนนต์ `ConfirmOperation.vue` และ Modal ใน `PolicyCenterView.vue` ให้สลับแท็บยืนยันตัวตนระหว่างรหัสผ่านและรหัส OTP จากแอปพลิเคชัน Authenticator ได้อย่างราบรื่น
- **การมอบอำนาจรับทราบหนังสือเวียนแทน (RISK-35) และ Form Wizard สำหรับ Policy Center (RISK-45)**:
  - ปรับปรุง `DistributionService::acknowledge()` และ `InboxController` ให้รองรับการปฏิบัติราชการแทนตามข้อมูลในตาราง `user_delegations` โดยบันทึกผู้รับและผู้ปฏิบัติการแทนใน Audit Log
  - เพิ่มแถบแจ้งเตือนการมอบอำนาจ ตัวกรองเลือกดูหนังสือแทน และป้ายกำกับ "แทน [ชื่อผู้มอบอำนาจ]" ใน `InboxView.vue`
  - พัฒนา Form Wizard ใน `PolicyCenterView.vue` ช่วยให้ผู้ดูแลระบบแก้ไขนโยบายยอดนิยม (`distribution`, `document_layout`, `numbering`) ด้วยแบบฟอร์มที่เป็นมิตร ควบคู่กับโหมด Raw JSON แบบสองทาง
- **ข้อแนะนำการตั้งชื่อไฟล์แนบอิเล็กทรอนิกส์ (ภาคผนวก ๗ ข้อ ๔.๓)**:
  - เพิ่มคำแนะนำรูปแบบชื่อไฟล์ราชการที่สื่อความหมายในหน้าแนบเอกสาร `DocumentFormView.vue`

### ยกระดับโมดูลสารบรรณ (e-Saraban) ตามระเบียบสำนักนายกรัฐมนตรีฯ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔ — 2026-09-15

- **ระบบตั้งชื่อไฟล์ส่งออกและไฟล์แนบตามมาตรฐานราชการ (ภาคผนวก ๗ ข้อ ๔.๓)** — สร้าง `OfficialSarabanFilenameService` รองรับการออกชื่อไฟล์ `{ปี พ.ศ.}_{รหัสโรมัน}{รหัสหน่วยงาน}_{เลขทะเบียนส่ง}.pdf` โดยค่าเริ่มต้นของสถานศึกษาอาชีวศึกษาคือ `MOE` (กระทรวงศึกษาธิการ) และรองรับไฟล์แนบ `_1.pdf`, `_2.pdf` พร้อมทำความสะอาดอักขระต้องห้ามตามระเบียบ
- **เส้นทางดาวน์โหลดหนังสือราชการ (`GET /api/saraban/documents/{document}/download`)** — ให้บริการดาวน์โหลดไฟล์พร้อมแนบส่วนหัว `Content-Disposition` ด้วยชื่อไฟล์ทางการตามระเบียบ
- **ด่านตรวจบล็อกการส่งเอกสารชั้น "ลับที่สุด" ทางอิเล็กทรอนิกส์ (ข้อ ๒๙ วรรคหนึ่ง, ภาคผนวก ๗ ข้อ ๓.๖)** — ใน `DistributionService` ตรวจจับเอกสาร `top_secret` และปฏิเสธการเวียนออนไลน์ทันทีด้วย `DistributionException` พร้อมแสดงแถบเตือนสีแดงและล็อกปุ่มส่งใน `DistributeDocumentModal.vue`
- **ทะเบียนรับและทะเบียนส่งตามแบบระเบียบงานสารบรรณ (แบบที่ ๑ และ แบบที่ ๒)** — ปรับปรุง `RegisterReportService` และ `RegisterReportExportService` ให้ออกรายงานทะเบียนหนังสือรับและทะเบียนหนังสือส่งตรงตามแบบราชการ พร้อมคอลัมน์มาตรฐานครบถ้วน (ทะเบียนรับที่, วันเดือนปี, จาก, ถึง, ที่, ลงวันที่, เรื่อง, การปฏิบัติ, หมายเหตุ)
- **ชุดทดสอบกำกับ (Official Compliance Suite)** — เพิ่ม `SarabanOfficialComplianceTest` (5 passed / 24 assertions) ผ่านการทดสอบควบคู่กับชุดทดสอบสารบรรณเดิมทั้งหมด 80 tests (358 assertions) และ Frontend Vitest 170 tests ครบถ้วน 100%

### ชุดเครื่องมือ DevOps และการตรวจความพร้อมข้อมูลเปิดใช้งานจริง (Data Onboarding) — 2026-09-14

- **สคริปต์สำรองและกู้คืนข้อมูลอัตโนมัติ (Automated Backup & Restore)** — สร้าง `docker/scripts/backup.sh`, `docker/scripts/backup.ps1`, `docker/scripts/restore.sh`, `docker/scripts/restore.ps1` รองรับทั้งสภาพแวดล้อม Linux และ Windows Server มีกลไกสำรองฐานข้อมูล MySQL และไดเรกทอรี `storage/app/private` พร้อมระบบทำความสะอาดไฟล์สำรองเก่าตามระยะเวลา Retention ที่กำหนด
- **การจำกัดขนาดและตัดรอบ Docker Logs (Log Rotation)** — กำหนดค่า `x-logging` ให้กับคอนเทนเนอร์หลักทั้ง 6 บริการใน `docker-compose.yml` (`max-size: 20m`, `max-file: 5`) ป้องกันปัญหาไฟล์ log บวมกินพื้นที่ดิสก์ในระยะยาว
- **สคริปต์ตรวจเช็คสุขภาพระบบ (Operations Health Check)** — สร้าง `docker/scripts/healthcheck.sh` และ `docker/scripts/healthcheck.ps1` ตรวจสอบสถานะการทำงานของคอนเทนเนอร์, Endpoint `/api/ping`, การเชื่อมต่อ MySQL, และ Scheduler Heartbeats
- **คำสั่งตรวจเช็คความพร้อมข้อมูลก่อนเปิดระบบจริง (`php artisan app:preflight-check`)** — พัฒนา `CheckPreflightReadinessCommand` ตรวจสอบความถูกต้องและครบถ้วนของข้อมูล Master Data 7 ด้านหลัก (ผังองค์กร, ผู้บังคับบัญชา, บุคลากร, ปีงบประมาณและนโยบายวันลา, ปฏิทินวันทำงาน, แบบฟอร์มเอกสาร, ช่องทางแจ้งเตือน) พร้อมรายงานผลเป็นตารางภาษาไทยและคำแนะนำวิธีแก้ไข
- **หน้าจอแดชบอร์ดตรวจความพร้อมระบบ (Pre-flight Dashboard UI & API)** — สกัด Service `PreflightReadinessChecker`, สร้าง API Endpoint `GET /api/admin/preflight-check` ภายใต้การควบคุมสิทธิ์ `system.setting.manage`, พัฒนาแท็บ "ตรวจความพร้อมระบบ (Pre-flight Check)" ใน `AdminSettingsView.vue` พร้อม Hero Banner แสดงสถานะความพร้อม (พร้อมใช้งาน 100% / พร้อมใช้งานโดยมีข้อแนะนำ / ยังไม่พร้อมใช้งาน), ตัวนับสถิติ (ผ่าน, ข้อแนะนำ, ต้องแก้ไข), รายการตรวจสอบ 7 หมวดพร้อมคำแนะนำและปุ่มลัด (Direct Action Links) และทางลัด URL `/admin/preflight-check`
- **เทมเพลตข้อมูลและคู่มือขั้นตอนการเปิดระบบจริง (Go-Live Checklist)** — จัดทำ `docs/onboarding/GO-LIVE-CHECKLIST.md` และตัวอย่างไฟล์นำเข้าบุคลากร `docs/onboarding/users_import_template.csv`
- **ชุดทดสอบกำกับ (Regression Tests)** — เพิ่ม `AdminPreflightCheckApiTest` (4 passed / 67 assertions) และ `CheckPreflightReadinessCommandTest` (2 passed / 4 assertions), ยืนยันความสอดคล้องของ API ด้วย `ApiSurfaceContractTest` และโครงสร้างเมนูด้วย `MenuDriftTest`, ผ่านเกณฑ์ Pint 100% (733 ไฟล์) และ Frontend Unit Tests 170 ผ่านครบถ้วน

### ปิดงานที่ค้างจากการ cutover — 2026-09-12

- **ถอดคอลัมน์ `workflow_instances.is_shadow` ทิ้ง** — ธงของ shadow mode ที่ถูกถอดไปแล้ว · คอลัมน์ที่ไม่มีผู้เขียนแต่ยังมีผู้กรอง (`WorkQueueService` กรอง `is_shadow = false` อยู่) เป็นกับดัก: เผลอเขียน `true` กลับเข้ามาเมื่อไร เรื่องนั้นจะหายจากคิวงานของเจ้าหน้าที่โดยไม่มีข้อความบอกอะไรเลย · ถอดพารามิเตอร์ `$shadow` ออกจาก `WorkflowEngine::start()` / `startOnVersion()` และ `LeaveWorkflowProvisioner::backfill()` ไปพร้อมกัน และเปลี่ยนดัชนี `(status, is_shadow)` เป็น `status` ล้วน
- **`approval_steps` ถูกประกาศเป็นหน้าจอตั้งสายอนุมัติถาวร** (ADR-APPROVAL-CHAIN-EDITOR-20260912) — เดิมค้างไว้เป็นงานว่า "สร้างหน้าจอออกแบบสายแบบอิงเวอร์ชันแล้วจึงถอดทิ้ง" ซึ่งตั้งอยู่บนสมมติฐานว่ามันเป็นหนี้ทางเทคนิค · ผู้ดูแลได้ประโยชน์ของการทำเวอร์ชันครบอยู่แล้วจากหน้าจอเดิม โดยไม่ต้องเรียนรู้อะไรใหม่ งานนี้จึงปิดเป็น "ไม่ทำ" ไม่ใช่ "เลื่อน"

### สายอนุมัติใบลาเดินด้วย workflow engine จริง และถอด shadow mode ทิ้ง — 2026-09-12

- **`workflow.engine_is_source_of_truth` เป็น `true` โดยค่าเริ่มต้นแล้ว** — ทั้งสายขอลาและสายขอยกเลิกตัดสินด้วย workflow engine บนเวอร์ชันที่ instance pin ไว้ · ของเดิม (`leave_approvals`, `leaves.approval_level`, `leaves.cancellation_approval_level`, สถานะใบลา, audit log) ยังถูกเขียนครบทุกแถว การปิดสวิตช์จึงเป็นทางถอยที่ใช้ได้จริงโดยไม่ต้อง revert โค้ด
- **ปิดช่องโหว่สิทธิ์ข้ามสาย** — `LeaveAssigneeResolver` เคยอ่าน snapshot ของสายขอลาตายตัว · ใบลาหนึ่งใบมีสองสายและมีขั้นที่ `step_order` ซ้ำกันได้ ผลคือผู้อนุมัติขั้นแรกของใบลาอนุมัติคำขอยกเลิกได้ ทั้งที่ผู้ดูแลตั้งสายยกเลิกไว้เป็นคนละคน · ตอนที่ engine ยังเดินคู่ขนาน ความผิดนี้ถูกบันทึกไว้เฉย ๆ ไม่มีผลกับใคร และการเทียบผลครอบเฉพาะสายขอลา จึงไม่มีอะไรจับได้
- **เรื่องที่ค้างอยู่ไม่เปลี่ยนสายกลางคัน** — `authoritativeInstance()` หารอบที่ยังเดินอยู่ก่อนเสมอแบบไม่ผูกกับเวอร์ชัน แล้วค่อยออกเวอร์ชันปัจจุบันเมื่อเริ่มรอบใหม่ · ผู้ดูแลแก้สายอนุมัติแล้วมีผลกับใบที่ยื่นหลังจากนั้น ไม่ใช่ใบที่เดินไปครึ่งทางแล้ว
- **คำขอยกเลิกที่ถูกปฏิเสธแล้วยื่นใหม่ได้** — เพิ่ม `workflow_instances.run_no` และย้าย unique index ไปรวม `run_no` · เดิมเรื่องหนึ่งเดินสายเดียวกันซ้ำไม่ได้เลย
- **ใบลาใหม่เปิด workflow instance ตั้งแต่ตอนยื่น** — เวอร์ชันที่ pin จึงตรงกับ snapshot ใน `leave_approvals` ที่บันทึกในจังหวะเดียวกัน
- **ถอด shadow mode ทั้งชุด** — `LeaveWorkflowShadow`, `WorkflowShadowComparison`, คำสั่ง `workflow:shadow-report` / `workflow:shadow-replay`, สวิตช์ `WORKFLOW_SHADOW_MODE` และตาราง `workflow_shadow_comparisons` · ข้อมูลในตารางเป็นบันทึกการทดลอง ไม่ใช่หลักฐานราชการของใบลาใด — ประวัติการอนุมัติจริงอยู่ครบที่ `leave_approvals`, `leave_audit_logs` และ `workflow_actions`
- `$shadow` ที่เหลือในลายเซ็นของ engine เปลี่ยนค่าเริ่มต้นเป็น `false` เพื่อไม่ให้ผู้เรียกใหม่เผลอสร้าง instance ที่คิวงานมองข้าม · migration ปลดธงให้ instance ที่ค้างอยู่ตอน cutover ทันที แทนที่จะรอให้มีคนกดอนุมัติ

### ชื่อเมนูที่อ่านแล้วรู้ว่าจะเจออะไร — 2026-09-10

- **แก้ชื่อเมนู 14 รายการจากทั้งหมด 64 รายการ** โดยไม่แตะ `code`, `route_path`, สิทธิ์ หรือลำดับ — ตัวตนของเมนูคือ `code` การเปลี่ยนชื่อจึงไม่กระทบสิทธิ์หรือเส้นทางใด และไม่มีการเปลี่ยน schema
- **เลิกใช้ชื่อซ้ำ** — "สรุปผลการปฏิบัติงาน" เคยอยู่สองที่ต่างกันแค่วงเล็บ (OT) กลายเป็น "สรุปเวลาปฏิบัติงาน" กับ "สรุปเวลาล่วงเวลา (OT)" · "รายงาน" ที่ชนกันระหว่างกลุ่มรายงานกับงานสารบรรณ แยกเป็น "รายงานสารบรรณ"
- **บอกขอบเขตจากชื่อ** — "ประวัติการลงเวลา" กับ "รายงานการลงเวลา" แยกไม่ออกว่าอันไหนของตัวเอง เปลี่ยนเป็น "การลงเวลาของฉัน" กับ "การลงเวลาบุคลากร"
- **ตัดศัพท์เทคนิคและศัพท์ประดิษฐ์** — "จัดการ/ซิงค์วันลา (HR)" → "จัดการข้อมูลการลา" · "ศูนย์เฝ้าระวังการปฏิบัติการ" → "รายการที่ต้องตามแก้" · "ศูนย์งานกำกับการลา" → "ตรวจหลักฐานการลา" · "จัดการเมนูตามสิทธิ์" → "สิทธิ์การเข้าถึงเมนู" · ศัพท์ตามระเบียบอย่าง "สารบบเรื่อง" "เกษียนหนังสือ" "ทะเบียนหนังสือรับ/ส่ง" คงไว้ตามเดิมโดยเจตนา
- **ชื่อที่ล้นแถบเมนูถูกย่อ** — `AppMenuNav` ใช้ `whitespace-nowrap` ชื่อยาวจึงถูกตัด: "ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด" (32 ตัวอักษร) → "หลักฐานตามระเบียบ" · "ค้นหา / คลังเอกสาร" → "คลังหนังสือ" · "จอง/ออกเลข" → "จองและออกเลขหนังสือ"
- **แถบเมนู, breadcrumb และหัวข้อบนหน้าจอพูดตรงกันทั้งสามที่** — `meta.title` ใน `router/index.js` และ `<h1>/<h2>` ของ 13 หน้าถูกแก้ตาม ไม่ปล่อยให้เมนูเรียกอย่างหนึ่งแล้วหน้าจอเรียกอีกอย่าง
- **เก็บกวาดชื่อที่ไม่ตรงกันมาแต่เดิม** — เมนู "รายงานการลาราชการ" กับหัวข้อหน้าจอใช้คำนี้อยู่แล้ว แต่ breadcrumb เขียนว่า "รายงานสถิติการลา" · แก้ `meta.title` ให้ตรง (ไม่แตะ `TREE` จึงไม่กระทบ fingerprint)
- ระบบที่ติดตั้งไปแล้วได้ชื่อใหม่ผ่าน `2026_09_24_120000_rename_menus_for_readability` ซึ่งอ่านชื่อจาก `MenuSeeder::TREE` แทนที่จะพิมพ์ซ้ำ และล้าง cache `user_menu_tree_*` ที่เก็บแถบเมนูไว้รายคน · อัปเดต `MenuDriftTest::TREE_FINGERPRINT` ตามกติกาของเทสต์นั้น
- **เทสต์ที่ตรวจว่า migration ทำงานจริง ไม่ใช่แค่ว่ามีไฟล์อยู่** — `MenuDriftTest` เดิมยืนยันแค่ว่าไฟล์ที่อ้างถึงมีอยู่ ซึ่ง migration เปล่า ๆ ก็ผ่านได้ · เทสต์ใหม่ seed เมนูแล้วเขียนชื่อเก่าทับ ก่อนเรียก `up()` ตรง ๆ เพื่อจำลอง "ระบบที่ติดตั้งไปแล้ว" ซึ่งเป็นความต่างเพียงอย่างเดียวที่เทสต์ชุดนี้เกิดมาเพื่อกัน
- เก็บกวาดข้อความที่ยังอ้างชื่อเมนูเดิม — คำแนะนำใน `DocumentTools` ที่ชี้ไปเมนู "จอง/ออกเลข" และคำอธิบายสิทธิ์สองรายการใน `PermissionCatalogSeeder`

### ตรวจความพร้อมของข้อมูลก่อนใบลาค้าง — 2026-09-10

- **หน้าตั้งค่าสายอนุมัติบอกล่วงหน้าว่าใครจะติดและเพราะอะไร** — ตัวหาผู้อนุมัติเป็น fail-closed โดยเจตนา (หาไม่เจอ = ไม่มีใครมีสิทธิ์) ผลที่ผู้ใช้เห็นจึงเป็น "ใบลาค้างเงียบ ๆ" ไม่ใช่ข้อความผิดพลาด · ของเดิมมีแค่ "ดูตัวอย่างผู้อนุมัติ" ซึ่งต้องเลือกผู้ยื่นทีละคน ผู้ดูแลจึงไม่มีทางรู้ภาพรวม
- ตรวจด้วย **ตัวหาผู้อนุมัติตัวเดียวกับที่ใช้ตัดสินสิทธิ์ตอนกดอนุมัติ** ให้ครบทุกคนที่ยังใช้งานอยู่ แล้วรายงาน 3 อย่าง: ผู้ยื่นที่จะติด · ขั้นที่ตั้งค่าไว้แล้วใช้ไม่ได้จริง (บทบาทที่ไม่มีใครถือ ผู้ใช้เจาะจงที่ปิดบัญชีแล้ว) · ข้อมูลบุคลากรที่ยังไม่ครบ (ไม่มีสังกัด ไม่มีประเภทบุคลากร หน่วยงานที่ยังไม่มีหัวหน้า)
- **จับกรณี "ผู้ยื่นเป็นผู้อนุมัติของขั้นนั้นเสียเอง"** ซึ่งเดิมไม่มีอะไรเตือน — หัวหน้าที่เป็นผู้อนุมัติเพียงคนเดียวของขั้นตัวเอง ยื่นใบลาแล้วใบจะค้างตลอดไปเพราะอนุมัติใบของตนเองไม่ได้ · ตรวจกับข้อมูลชุดพัฒนาแล้วพบทันที 3 คนจาก 6 คน
- ตรวจให้เองเมื่อเปิดหน้าและหลังบันทึก และรับสายที่ยังไม่บันทึกไปตรวจได้ ผู้ดูแลจึงเห็นผลของสิ่งที่กำลังจะบันทึก ไม่ใช่ผลของสายเดิม · ทักเรื่องหัวหน้าหน่วยงานเฉพาะเมื่อสายมีขั้นที่หาตัวจากผังองค์กรจริง
- อ่านอย่างเดียวทั้งหมด และคิดผลครั้งเดียวต่อหน่วยงาน ไม่ใช่ต่อคน — องค์กรขนาดพันคนยังเป็นการคิวรีหลักสิบครั้ง

### ชื่อไฟล์ของเอกสารที่ดาวน์โหลด — 2026-09-10

- **ใบยกเลิกวันลาไม่ใช้คำนำหน้า `cancel_` อีกแล้ว** — เอกสารที่ดาวน์โหลดขึ้นต้นด้วยชนิดเอกสารเป็นภาษาไทย (`ใบลา_...` / `ใบยกเลิกวันลา_...`) ผู้ใช้ที่พิมพ์ทั้งสองแบบของใบเดียวกันจึงแยกไฟล์ออกจากกันได้ทันทีในโฟลเดอร์ดาวน์โหลด
- **ชื่อไฟล์บันทึกลงเครื่อง Windows ได้เสมอ** — เดิมกรองแค่ `/` และ `\` ส่วน `: * ? " < > |` ผ่านไปได้ · และช่องว่างถูกแทนเฉพาะในชื่อคน ประเภทการลาอย่าง "ลาไปศึกษาต่อ ดูงาน ฝึกอบรม" จึงหลุดเป็นช่องว่างกลางชื่อไฟล์
- **ชื่อไฟล์ภาษาไทยไม่เพี้ยนอีก** — ทุก endpoint ประกาศ `Content-Disposition` ตาม RFC 6266 ครบทั้ง `filename` แบบ ASCII สำหรับตัวอ่านรุ่นเก่า และ `filename*=UTF-8''` ที่เบราว์เซอร์ปัจจุบันใช้ · เดิมบางเส้นทางส่งชื่อไทยดิบใน `filename` อย่างเดียว
- **รายงานที่ส่งออกบอกได้ว่าเป็นรายงานอะไร ช่วงไหน** — ทะเบียนคุมการลาเคยได้ชื่อ `leave-register.csv` เหมือนกันทุกครั้ง ดาวน์โหลดหลายช่วงแล้วไฟล์ทับกัน · ตอนนี้ใช้ชื่อรายงานภาษาไทยพร้อมช่วงวันที่ ครอบคลุมศูนย์รายงานกลาง รายงานที่ออกแบบเอง รายงานคัดกรองเลื่อนเงินเดือน บัตรลา และเอกสารที่ Document Engine สร้างไว้
- ตรรกะการตั้งชื่อรวมอยู่ที่ `App\Support\DocumentFilename` ที่เดียว (เดิมกระจายเป็นสำเนา 3 ชุดใน `LeaveDocumentController` อย่างเดียว) และหน้าจอใช้ชื่อจาก header เสมอ โดยมี `src/utils/downloadFilename.js` เป็นชื่อสำรองที่ให้ผลตรงกัน

### ตรวจระบบลาและการตั้งค่าแบบฟอร์มรอบที่ 2 — 2026-09-10

- **ลบประเภทการลาแล้วสิทธิและยอดวันลาของทุกคนหายไปด้วย** — `leave_policies` และ `leave_balances` ผูกแบบ cascade ด่านเดิมตรวจแค่ "มีใบลาหรือยัง" ประเภทที่ตั้งสิทธิไว้แล้วแต่ยังไม่มีใครลาจึงลบได้ และพานโยบายกับยอดยกมาของทุกคนหายเงียบ ๆ (ยืนยันด้วยการทดลองจริง) — ตอนนี้ปฏิเสธพร้อมบอกจำนวนที่อ้างถึงและทางออก
- **ตัวแปรของแบบฟอร์มที่ต้นทางหายไปถูกปิดอัตโนมัติ** — `stats.leave_type_<id>.*` ของประเภทที่ถูกลบ, `extra.<คีย์>` ของช่องที่ถูกถอด และ `approval.step{N}.*` ของขั้นที่ถูกลด เดิมค้างให้เลือกวางได้ตลอดไปทั้งที่ไม่มีค่ามาเติม และการตรวจก่อนเผยแพร่ไม่ทักเพราะรหัส "มีอยู่จริง" · ปิดเท่านั้นไม่ลบ แบบฟอร์มเวอร์ชันเก่ายังพิมพ์ได้เหมือนเดิม
- **หน้ายื่นใบลาบอกหลักเกณฑ์ก่อนกรอก** — แสดงกติกาของนโยบายที่เลือก (ต้องแนบหลักฐานเมื่อกี่วัน · เพดานต่อครั้ง · ยื่นล่วงหน้า · ลาครึ่งวันได้หรือไม่) และเน้นข้อที่คำขอกำลังชนเกณฑ์ · ป้ายเอกสารแนบเปลี่ยนเป็น "จำเป็น" ตามเกณฑ์จริง · ซ่อนตัวเลือกครึ่งวันเมื่อนโยบายไม่อนุญาต เดิมผู้ใช้กรอกจนเสร็จแล้วค่อยถูกปฏิเสธตอนกดส่ง
- **ลบ `LeaveBalanceService` ที่ไม่มีอะไรเรียกใช้** — คำนวณโควตาจาก `leave_types.max_days` ซึ่งเป็นแบบจำลองก่อนมี `leave_policies` เป็นกับดักสำหรับคนที่มาอ่านทีหลัง
- รายละเอียดและสิ่งที่ยังไม่แก้พร้อมเหตุผล: `docs/checkpoints/LEAVE-SETTINGS-AUDIT-2-2026-09-10.md`

### วางวันที่แยกช่อง วัน / เดือน / ปี พ.ศ. ได้ทุกฟิลด์วันที่ — 2026-09-10

- เพิ่มรูปแบบค่าสำหรับ **แยกส่วนของวันที่**: เฉพาะวันที่ · เดือนชื่อเต็ม · เดือนชื่อย่อ · เดือนเป็นตัวเลข · ปี พ.ศ. · ปี พ.ศ. สองหลัก · ปี ค.ศ. — แบบฟอร์มราชการที่มีช่อง "วันที่ ___ เดือน ___ พ.ศ. ___" จึงวางฟิลด์วันที่เดียวกันสามครั้งแล้วเลือกส่วนที่ต้องการได้ โดยไม่ต้องมีตัวแปรแยกของแต่ละวันที่
- **อ่านวันที่ไทยที่ระบบเดิมส่งมาเป็นข้อความได้ด้วย** (`1 ตุลาคม 2569`, `1 ต.ค. 2569`, `01/10/2569`) — ก่อนหน้านี้ฟิลด์เหล่านั้นแปลงเป็นวันที่ไม่ได้ การแยกส่วนจึงจะพิมพ์วันที่เต็มบรรทัดลงในช่องตัวเลขวันเดียว · ค่าที่ไม่ใช่วันที่จริงยังคืนตามเดิม ไม่เดา
- ใช้ได้กับทุกฟิลด์ชนิดวันที่ รวมถึงวันที่อนุมัติรายขั้น (`approval.step{N}.date`) และช่องวันที่ที่ผู้ดูแลสร้างเองในประเภทการลา (`extra.*`) ซึ่งเดิมไม่มีตัวแปรแยก วัน/เดือน/ปี ให้ใช้

### แยกรายงานออกจากศูนย์จัดการเอกสาร — 2026-09-09

- **รายงานที่ผู้ดูแลออกแบบเองย้ายไปอยู่หมวด "รายงาน"** เป็นหน้า `รายงาน › รายงานที่ออกแบบเอง` (`/reports/custom`) พร้อมหน้าออกแบบรายงานที่ย้ายตามไปเป็น `/reports/custom/designer` — เดิมทั้งสองอย่างซ่อนอยู่ในแท็บของหน้าตั้งค่าเอกสาร ผู้ที่ต้องการเพียงเปิดดูรายงานจึงต้องเข้าเมนู "ตั้งค่าระบบ" ซึ่งเป็นคนละเรื่องกับสิ่งที่กำลังทำ
- **ศูนย์จัดการเอกสาร (เดิมชื่อ "ศูนย์จัดการเอกสารและรายงาน") เหลือเฉพาะเรื่องแบบฟอร์มและเอกสาร** — แบบฟอร์ม, การผูกแบบฟอร์ม, สารบัญตัวแปร, แหล่งข้อมูล, เอกสารที่สร้างแล้ว, ตั้งค่าการพิมพ์ · มีลิงก์ชี้ไปหน้ารายงานให้ผู้ที่เคยใช้แท็บเดิม
- เส้นทางเดิม `/admin/document-center/reports` คงไว้เป็น redirect ไม่ให้ลิงก์หรือบุ๊กมาร์กเดิมเสีย
- เมนูของระบบที่ติดตั้งแล้วได้รับโครงใหม่ผ่าน migration `2026_09_18_100000_move_custom_reports_menu_out_of_document_center` (เพิ่มและเปลี่ยนชื่อเท่านั้น ไม่ลบเมนูใด) พร้อมอัปเดตลายนิ้วมือใน `MenuDriftTest`

### ซ้อมเปิดใช้งานจริงบนเครื่องพัฒนา — พบและแก้บั๊กที่จะพังบนของจริง — 2026-09-09

- **nginx ส่งไฟล์ `.mjs` เป็น `application/octet-stream`** เบราว์เซอร์จึงปฏิเสธ module script ของ worker (pdf.js) หน้าออกแบบไม่ขึ้นแบบฟอร์มต้นฉบับเลย — เพิ่ม location เฉพาะนามสกุลใน `docker/nginx/default.conf` (ไม่ใช้บล็อก `types{}` ระดับ server ซึ่งจะล้างตารางชนิดไฟล์ทั้งชุด) · อาการนี้ไม่เกิดกับ `npm run dev` จึงหลุดถึงเครื่องจริงได้ง่าย
- **pdf.js 6 ไม่รับ URL แบบสตริงแล้ว** (`getDocument({url})` เท่านั้น) — แก้ทั้งหน้าออกแบบแบบฟอร์มและ `PdfWorkspace.vue` ของงานสารบรรณ ซึ่งพังด้วยเหตุเดียวกันโดยยังไม่มีใครสังเกต
- **แคนวาสไม่ถูกวาดเมื่อโหลดครั้งแรก** เพราะยังอยู่สถานะ `loading` ตอน `loadPdf()` เสร็จ — เฝ้า `loading`/`hasPdf` เพิ่มในตัวสั่งวาด
- `document:scaffold-fields` หาแบบฟอร์มที่เพิ่งนำเข้าไม่เจอ (กรองด้วย `render_mode` ของแม่แบบ) และคีย์ฟิลด์เฉพาะยังอ้างชุด `form_*` เดิม — แก้แล้วทั้งสองจุด · `document:import-forms` ไม่สร้างฉบับร่างซ้อนหลังเผยแพร่แล้ว
- เพิ่มด่านกันถอยกลับ: `RuntimeSecurityConfigurationTest` ตรวจ nginx เสิร์ฟ `.mjs` เป็น JavaScript · เทสต์คอมโพเนนต์บังคับรูปแบบพารามิเตอร์ของ pdf.js และตรวจว่ามีการวาดจริงหลังโหลด
- ผลการซ้อมครบทั้ง 3 ขั้น (เตรียมข้อมูล → ตรวจ/เผยแพร่ → เปิดใช้และพิมพ์เอกสารจริง) อยู่ที่ `docs/checkpoints/DEV-REHEARSAL-GO-LIVE-2026-09-09.md`

### ปิดข้อค้างของการตั้งค่าและรายงานระบบลา (LS-01…LS-07) — 2026-09-09

- **LS-01 นโยบายที่ใบลาอ้างอยู่ไม่ถูกเขียนทับอีก** — การแก้นโยบายที่มีใบลาอ้างถึงจะออก "ฉบับใหม่" พร้อมช่วงมีผล และปิดฉบับเดิมไว้เป็นประวัติ (ต้องระบุวันมีผลใหม่ที่หลังกว่าเดิม มิฉะนั้นถูกปฏิเสธพร้อมคำอธิบายวิธีแก้) · ยอดวันลาที่ระบบซิงก์เองตามนโยบายยังทำงานเหมือนเดิม
- **LS-02 นโยบายที่ยังไม่มีผลไม่แตะยอดปัจจุบัน** — `syncBalances()` ทำงานเฉพาะเมื่อนโยบายนั้น "ใช้บังคับอยู่จริง ณ วันนี้" (เปิดใช้งาน อยู่ในช่วงมีผล และเป็นฉบับที่ใหม่ที่สุดในบรรดาที่มีผล) นโยบายที่ปิดอยู่ หมดอายุ หรือยังไม่ถึงวันมีผล จึงลดสิทธิของคนทั้งองค์กรทันทีไม่ได้อีก และหน้าจอบอกชัดว่า "บันทึกแล้วแต่ยังไม่มีผลกับยอดปัจจุบัน"
- **LS-03 วันที่ไม่เลื่อนอีกแล้ว** — `leave_policies.effective_from/effective_to` ส่งเป็นวันที่ล้วน (`date:Y-m-d`) แทน ISO แบบมีเวลา UTC ซึ่งทำให้หน้าจอแสดงวันถอยไปหนึ่งวันและบันทึกทับด้วยวันที่ผิด · ช่อง "มีผลตั้งแต่" ของรายการใหม่ใช้วันที่ตามเวลาไทย ไม่ใช่ UTC
- **LS-05 ปีงบประมาณที่ถูกใช้แล้วลบไม่ได้** — ปฏิเสธเมื่อมีใบลาอ้างถึง และปฏิเสธการลบปีปัจจุบัน
- **LS-06 รายงานยอดคงเหลือไม่ว่างอีก** — `ReportFilter::balanceYear()` แปลงปี พ.ศ. ที่ผู้ใช้เลือกเป็นคีย์ ค.ศ. ของ `leave_balances.year` และ `fiscal_year` ที่รับได้ขยายเป็น 2000–2700 (เดิมปฏิเสธเลข พ.ศ. ทุกค่า)
- **LS-07 ไฟล์ Word ตรงกับที่ประกาศ** — เซิร์ฟเวอร์เป็นผู้ประกาศชื่อไฟล์และชนิดไฟล์ และหน้าจอใช้ค่าจาก header นั้น (เดิมหน้าจอตั้งชื่อ `.docx` แต่เนื้อในเป็น Word แบบ HTML) · ถอดสาขา PhpWord ที่ไม่ได้ติดตั้งและให้เอกสารคนละชุดกับ PDF ออก ดู ADR-WORD-20260909

### ช่องว่างของหน้าตั้งค่าที่ปิดพร้อมกัน — 2026-09-09

- **ระยะขอบและแบบอักษรที่ตั้งไว้มีผลจริงกับเอกสารที่พิมพ์** — เดิมแม่แบบทุกฉบับเขียน `@page { margin: 8mm 12mm }` และชื่อฟอนต์ไว้ตายตัว ค่าที่ผู้ดูแลตั้งจึงไม่เคยถูกใช้เลย · ลำดับความสำคัญ: ค่าของเวอร์ชันแม่แบบ (รายแบบฟอร์ม) มาก่อนค่ากลางของระบบ
- **ตัวเลือกแบบอักษรเหลือเฉพาะฟอนต์ที่ติดตั้งไฟล์ไว้จริง** (TH Sarabun New / TH Sarabun / TH SarabunIT๙) เดิมมีตัวเลือกที่ไม่มีไฟล์ ซึ่งถ้าเลือกแล้วภาษาไทยจะกลายเป็นกล่องสี่เหลี่ยมทั้งฉบับ
- **หน้านโยบายสิทธิมีช่องครบตามที่ API รองรับ** — เพดานต่อครั้ง อายุงานขั้นต่ำ เพดานสะสมข้ามปี ยอดสะสมรวม และชนิดหลักฐานที่ต้องแนบ
- **สิทธิ์ของหน้ากับ API ตรงกัน** — หน้านโยบายสิทธิต้องมี `leave.policy.manage` เท่านั้น (เดิมเปิดหน้าได้ด้วยสิทธิ์ตั้งค่าระบบแล้วเจอ 403 ทุกครั้งที่โหลด) และหน้าประเภทการลาบอกสาเหตุเมื่อไม่มีสิทธิ์ดูรายการแบบฟอร์ม แทนที่จะแสดงช่องว่างเปล่า
- **ช่องเฉพาะแบบฟอร์มตรวจตามชนิดจริง** — ช่องวันที่/ตัวเลข/ตัวเลือกถูกตรวจตามที่ผู้ดูแลนิยามไว้ ไม่ใช่ตรวจเป็นข้อความล้วนทั้งหมด
- **การ์ดสรุปในหน้ารายงานใช้ตัวกรองชุดเดียวกับตาราง** — เดิมสรุปยึดช่วงย้อนหลัง 12 เดือนเสมอ ตัวเลขบนการ์ดกับตารางจึงเป็นคนละชุดข้อมูล
- **บันทึกประวัติการเปลี่ยนการตั้งค่า** — ประเภทการลา ปีงบประมาณ และนโยบายสิทธิ บันทึก audit ครบทั้งเพิ่ม/แก้/ลบ พร้อมค่าก่อนและหลัง

### นำแบบฟอร์มราชการต้นฉบับเข้าเป็นฉบับร่างของแม่แบบเดิม — 2026-09-09

- `php artisan document:import-forms` แนบไฟล์ PDF ต้นฉบับ 5 ฉบับเข้ากับ **แม่แบบที่มีอยู่แล้ว** (`leave_vacation`, `leave_sick_personal`, `leave_cancel`, `leave_ordination`, `leave_paternity`) แทนการสร้างแม่แบบชุดใหม่ `form_*` ขนานกันเหมือนรุ่นเดิม ซึ่งเป็นที่มาของแม่แบบซ้ำที่ DOC-ENGINE-09 เคยตามยุบรวม
- สร้างได้เฉพาะ **ฉบับร่าง** — ไม่เผยแพร่ ไม่แตะเวอร์ชันที่ใช้งานอยู่ และไม่เปลี่ยน `render_mode` ของแม่แบบ เอกสารที่ออกอยู่ทุกวันนี้จึงไม่เปลี่ยนแม้แต่ฉบับเดียว
- ฉบับร่างเปล่าที่มีอยู่แล้วจะถูกเติมไฟล์ให้แทนการสร้างซ้อน · ฉบับร่างที่มีไฟล์แล้วถูกข้าม (รันซ้ำได้โดยไม่ทับงานที่ทำค้าง) · เพิ่ม `--dry-run` · เตือนเมื่อยังพบแม่แบบชุด `form_*` ของรุ่นเก่า
- รันบนเครื่องพัฒนาแล้ว: แม่แบบ 5 รายการมีฉบับร่างชนิด `pdf_overlay` พร้อมไฟล์ต้นฉบับ (อ่านได้ 595×842 pt ทั้งหมด) ยังไม่มีการเผยแพร่และไม่ได้เปิดสวิตช์ใด

### LS-08 — แม่แบบที่ไม่มีเวอร์ชันเลย — 2026-09-09

- คำสั่งใหม่ `php artisan document:backfill-versions` (มี `--dry-run`) สร้างเวอร์ชันแรกให้แม่แบบที่สร้างไว้ก่อนมีระบบเวอร์ชัน โดย **มีผลตั้งแต่วันที่รันเท่านั้น ไม่อ้างผลย้อนหลัง ไม่แตะใบลาใด และรันซ้ำได้**
- ก่อนลงมือสำรวจข้อมูลจริงแบบอ่านอย่างเดียว: ใบลา 1 ใบ · ใบที่ pin เวอร์ชันไว้ 0 ใบ · ไฟล์เอกสารที่เคยออก 0 ไฟล์ — จึงไม่มีประวัติเอกสารให้เสี่ยงเดา การเติมเวอร์ชันเป็นการเติมข้อมูลตั้งต้นที่ขาด ไม่ใช่การประกาศประวัติย้อนหลัง
- ผลบนเครื่องพัฒนา: แม่แบบทั้ง 10 มีเวอร์ชันครบแล้ว · ยังต้องรันบนเครื่องจริงของหน่วยงานหลังตรวจตัวเลขชุดเดียวกันซ้ำ
- `DocumentTemplate::createVersion()` เปลี่ยนเป็น public เพื่อให้คำสั่งใช้ตรรกะเดียวกับ hook ที่ออกเวอร์ชันตามปกติ

### LS-04 — ใบลาเก่าพิมพ์ด้วยแบบฟอร์มที่ตัวเองถูก pin ไว้ — 2026-09-09

- `LeaveDocumentTemplateResolver::templateCodeForLeave()` ยึด code ของแบบฟอร์มจาก **เวอร์ชันที่ใบลา pin ไว้** ก่อน แล้วจึงถอยไปใช้การผูกแบบฟอร์มของประเภทการลาและการเดาจากชื่อไทยตามเดิม
- เดิมเลือกแบบฟอร์มจาก `leave_types.document_template_code` ณ ตอนพิมพ์เสมอ ผู้ดูแลเปลี่ยนการผูกครั้งเดียว ใบลาเก่าทุกใบจึงเปลี่ยนไปพิมพ์บนแบบฟอร์มคนละฉบับกับที่เคยออกไป — การเปลี่ยนการผูกตอนนี้มีผลกับใบที่ยื่นใหม่เท่านั้น
- เวอร์ชันที่ pin ไว้แต่เป็นแม่แบบที่ไม่มี view รองรับ (แม่แบบที่ผู้ดูแลสร้างเอง) ยังเดินเส้นทางเดิมเหมือนก่อน
- regression `LeaveSettingsFormsAuditTest > switching type template must not change an already pinned form` เปลี่ยนจากล้มเป็นผ่าน · ข้ออื่นของ audit (LS-01/02/03/05/06/07/08) ยังไม่ได้แก้ในรอบนี้

### Visual PDF Template Designer — ระยะ 4 — 2026-09-09

- **ตรวจก่อนเผยแพร่ (pre-publish check)** ที่ฝั่งเซิร์ฟเวอร์ แยกเป็นข้อผิดพลาด (ห้ามเผยแพร่) กับข้อควรระวัง (เตือน): ฟิลด์นอกขอบกระดาษ, หน้าที่ไม่มีจริง, ตัวแปรที่ไม่มีอยู่หรือถูกปิด, ยังไม่มีไฟล์ต้นฉบับ, ช่องที่เล็กจนพิมพ์ไม่ครบ, ช่องที่วางทับกัน และแบบฟอร์มที่ยังไม่มีฟิลด์ — หน้าออกแบบเรียกดูผลได้ก่อนกดปุ่ม และการกดเผยแพร่จะบอกข้อควรระวังก่อนยืนยัน
- **พรีวิวสามโหมด**: ข้อความไทยยาวตามกรณีจริง (ตรวจว่าช่องพอไหม), เฉพาะกรอบช่อง (เทียบกับเส้นบนแบบฟอร์ม) และจากใบลาจริงหนึ่งฉบับ — โหมดใบลาจริงต้องผ่าน policy การเปิดใบลานั้นเช่นเดียวกับการเปิดเอกสารตามปกติ และถูกบันทึก audit ว่าใครเปิดของใคร
- **ใบสอบเทียบการพิมพ์** — พิมพ์กากบาทสามจุดที่รู้พิกัดแน่นอน ผู้ดูแลวัดด้วยไม้บรรทัดแล้วให้ระบบคำนวณ `x_offset`/`y_offset`/`scale` ให้ แทนการเดาค่าแล้วลองพิมพ์ซ้ำ และบันทึกเป็นค่าเฉพาะแม่แบบได้จากหน้าออกแบบ
- **เทียบเวอร์ชัน (diff)** — เห็นว่าเวอร์ชันใหม่ต่างจากเวอร์ชันก่อนตรงฟิลด์ใด (เพิ่ม/ลบ/แก้ และแก้ที่คุณสมบัติใด) ก่อนกดเผยแพร่
- **นำเข้า/ส่งออกชุดแบบฟอร์ม** เป็นไฟล์ ZIP เดียว (manifest.json + form.pdf) — ย้ายแบบฟอร์มข้ามเครื่องหรือข้ามสถานศึกษาได้จากหน้าจอ; การนำเข้าสร้างแม่แบบใหม่พร้อมฉบับร่างเสมอ ไม่ทับของเดิม อ่านเฉพาะชื่อไฟล์คงที่สองไฟล์ (กัน Zip Slip) ตรวจว่าเป็น PDF ที่เปิดอ่านได้จริง และกรองคีย์ `options` ให้เหลือเฉพาะที่ระบบรองรับ
- ไม่มี migration ในระยะนี้ · หลักฐาน `docs/checkpoints/DESIGNER-PHASE-4-2026-09-09.md`

### Visual PDF Template Designer — ระยะ 3 — 2026-09-08

- **ช่องเฉพาะของประเภทการลาวางบนแบบฟอร์มได้แล้ว** — `leave_types.extra_fields` ถูกซิงก์เข้า Field Dictionary เป็น `extra.<คีย์>` พร้อมชนิดข้อมูลตามที่นิยามไว้ เดิมระบบมีค่าให้อยู่แล้วแต่ไม่มีตัวแปรให้เลือกวาง
- **ผู้อนุมัติรายขั้น `approval.step{N}.*`** ประกาศตามจำนวนขั้นของสายอนุมัติจริง (เดิมมีแค่ขั้นแรกและขั้นสุดท้าย) และ **สถิติการลารายประเภท** เป็นฟิลด์รายช่อง `stats.leave_type_<id>.<ช่อง>` สำหรับตารางในแบบฟอร์มราชการ
- **ฟิลด์ที่เป็นก้อนข้อมูล (`stats`, `extra`) ถูกปิดในพจนานุกรมของแบบฟอร์ม PDF** เพราะวางเป็นช่องเดียวแล้วพิมพ์ไม่ได้ — แม่แบบ HTML ยังใช้ได้เหมือนเดิม
- **เงื่อนไขแสดง/ซ่อนฟิลด์** (`options.visible_when`) เทียบค่ากับฟิลด์อื่นได้ 4 แบบ (เท่ากับ/ไม่เท่ากับ/มีค่า/ไม่มีค่า) โดยเป็น whitelist ไม่รับนิพจน์อิสระ
- **แก้ป้ายชื่อ/กลุ่ม/คำอธิบายของตัวแปรได้จากหน้าจอ** และปิดการใช้งานตัวแปรได้ (ไม่ลบทิ้ง เพื่อไม่ให้แบบฟอร์มเก่าเสียการเชื่อมโยง) — รหัสฟิลด์ ชนิดข้อมูล และแหล่งข้อมูลยังเป็นของระบบ
- **เปิดใช้แบบฟอร์ม PDF รายประเภทการลาได้จากศูนย์จัดการเอกสาร** พร้อม audit — เดิมตั้งได้ที่ `.env` เท่านั้น; ธง `DOCUMENT_ENGINE_ENABLED` ยังเป็นสวิตช์ตัดตอนระดับระบบเหมือนเดิม
- **หน้า "ประเภทการลา" แสดงสถานะแบบฟอร์มของแต่ละประเภท** (ผูกแม่แบบใด เวอร์ชันใด กี่ฟิลด์ มีไฟล์ต้นฉบับหรือยัง เปิดใช้แล้วหรือยัง) พร้อมลิงก์ตรงไปหน้าออกแบบ
- คำสั่งใหม่ `php artisan document:sync-fields` สำหรับซิงก์พจนานุกรมตัวแปรตอนติดตั้ง/อัปเดต
- ไม่มี migration — เหตุผลของทางเลือกที่ใช้แทนอยู่ใน `docs/DECISION-LOG.md` (ADR-DESIGNER-20260908)

### Visual PDF Template Designer — ระยะ 1–2 — 2026-09-08

- **ระบบพิกัดของหน้าออกแบบเป็นขนาดหน้าจริงของไฟล์ต้นฉบับ** ไม่ใช่ A4 ที่ตรึงไว้ (595×842) อีกต่อไป — วาดหน้า PDF ด้วย pdf.js ลง `<canvas>` อ่านขนาดรายหน้า และมี `GET /admin/document-engine/versions/{version}/geometry` เป็นค่าฝั่งเซิร์ฟเวอร์จากไฟล์เดียวกับที่ตัวเรนเดอร์ใช้
- **ตรวจขอบกระดาษที่ฝั่งเซิร์ฟเวอร์** ทั้งตอนบันทึกตำแหน่งฟิลด์และตอนเผยแพร่ — ฟิลด์ที่ล้นหน้าหรืออ้างหน้าที่ไม่มีจริงถูกปฏิเสธพร้อมข้อความระบุฟิลด์ แทนที่จะหายไปเงียบ ๆ ตอนพิมพ์
- **เครื่องมือจัดวาง**: เส้นตาราง + ดูดเข้าเส้น, ไม้บรรทัด, ขยับด้วยลูกศร (1 pt / Shift 10 pt), เลือกหลายฟิลด์ด้วย Shift แล้วจัดแนว 6 แบบและกระจายระยะ, คัดลอก–วาง, ทำซ้ำไปหน้าอื่น, เลิกทำ/ทำซ้ำ 50 ขั้น และเตือนก่อนออกจากหน้าเมื่อยังไม่บันทึก
- **คุณสมบัติฟิลด์ที่หลังบ้านรองรับอยู่แล้วเปิดใช้ได้จากหน้าจอ**: น้ำหนักอักษร, การล้นข้อความ, รูปแบบค่า, ข้อความคงที่, ขีดเส้นใต้, ระยะห่างตัวอักษร, หมุนข้อความ 90/180/270°, เครื่องหมายติ๊กและค่าที่ถือว่าติ๊ก
- **`format` มีผลจริงแล้ว** ผ่าน `ValueFormatter` (วันที่ไทยเต็ม/ย่อ, พ.ศ./ค.ศ., ตัวเลข, ทศนิยม, เลขไทย, ตัวพิมพ์) — เดิมบันทึกค่าได้แต่ตัวเรนเดอร์ไม่เคยอ่าน; รูปแบบและตัวเลือกที่ไม่รู้จักถูกปฏิเสธที่ validation แทนการเก็บไว้เฉย ๆ
- **`QR_CODE` วาดได้จริง** ด้วย `chillerlan/php-qrcode` ที่ระบบมีอยู่แล้ว ส่วน **`BARCODE` ถูกถอดออกจากตัวเลือก** เพราะตัวเรนเดอร์วาดไม่ได้และเคยพิมพ์ออกมาเป็นข้อความธรรมดา (ดู `docs/KNOWN-LIMITATIONS.md`)
- **แก้บั๊กช่องติ๊กที่ทำให้พิมพ์ไม่ออก** — ช่องติ๊กที่ถูกติ๊กจริงเรียกฟอนต์แกนของ FPDF ซึ่งถูกค้นจาก `FPDF_FONTPATH` ของโครงการ (มีแต่ฟอนต์ไทย) ทำให้การเรนเดอร์ล้มทั้งฉบับ ตอนนี้ใช้ฟอนต์ไทยของระบบวาดเครื่องหมาย
- ไม่มี migration และไม่มีการเปลี่ยน schema ในสองระยะนี้ (ตามข้อกำหนด PRODUCTION PHASE); ขนาดหน้ากระดาษอ่านจากไฟล์ต้นฉบับโดยตรงแทนการเพิ่มคอลัมน์
- แผนเต็มและช่องว่างที่เหลืออยู่ที่ `docs/VISUAL-PDF-TEMPLATE-DESIGNER-PLAN.md` · หลักฐานรอบนี้ `docs/checkpoints/DESIGNER-PHASE-1-2-2026-09-08.md`

### API Surface / Runtime Quality Gates — 2026-09-07

- ปิดสองด่านที่ค้างจากการแก้ระบบลา: เพิ่มทางใช้งาน API ในหน้าสารบรรณและการแจ้งเตือน; pin `minishlink/web-push` เป็น `11.0.0` ตาม lock เดิม
- ตรวจ versioned alias ให้มี canonical action/HTTP methods/security middleware ตรงกัน และตรวจ URL ที่ประกอบจาก attendance composable; ระบุข้อยกเว้นเฉพาะ health, QR และ OpenAPI ที่มีผู้ใช้ภายนอกจริง
- ป้องกันการสร้างสำเนาปกปิดผิดฉบับเมื่อเปิดไฟล์อื่นหรือคำขอโหลดตอบสลับลำดับ พร้อมคืน blob เมื่อออกจากหน้า; ใช้ payload ที่แสดงตอนยืนยัน
- แสดงชื่อช่องทางไลน์ทางการเป็นภาษาไทย; apiSurfaceFlows 17 tests ผ่าน
- ตรวจโค้ดปัจจุบัน: backend 1,141 passed / 0 failed / 0 skipped (4,623 assertions), frontend 127 passed, lint/typecheck/build และ Pint 679 files ผ่าน ดู `docs/checkpoints/API-SURFACE-FIX-2026-09-07.md`
- ไม่มี migration หรือ API endpoint ใหม่ในงานแก้นี้ และไม่มี deploy

### Leave Module Fix — 2026-09-05

- แก้ครบ 4 ข้อจาก audit: ร่างส่วนตัวทุกทางอ่าน, ห้ามเขียนทับใบที่ยื่นแล้ว, บันทึกแทนตัดยอดและซิงค์ลงเวลาใน transaction และปฏิเสธข้ามปีงบประมาณ
- รวมผลอนุมัติที่ LeaveService::completeApproval; รักษา module boundary และตรวจ scope/deny ของการบันทึกแทน
- LeaveModuleAuditTest 11 tests ผ่านโดยคง regression เดิมครบ; frontend lint/typecheck/81 tests/build และ Pint ผ่าน
- เปิดรัน tests ที่เคยข้ามเพราะไม่มีไฟล์ repo; รายงานผลชุดเต็มและความล้มเหลวนอกโมดูลลาไว้ใน `docs/checkpoints/LEAVE-MODULE-FIX-2026-09-05.md` โดยไม่ผ่อน tests
- ไม่มี migration, dependency upgrade หรือ deploy; ไม่เขียนทับประวัติและยอดเก่าอัตโนมัติ

### Leave Module Audit — 2026-09-05

- ตรวจโมดูลลา พบและยืนยัน 4 ปัญหา: อ่านร่างผู้อื่น, แก้ทับใบอนุมัติ, บันทึกแทนไม่ตัดยอดใช้จริง และคิดยอดใบลาข้ามปีงบประมาณรวมกับปีแรก
- เพิ่ม `LeaveModuleAuditTest` 4 regression tests ซึ่งยังล้มเหลวตามข้อค้นพบ พร้อมรายงาน `docs/checkpoints/LEAVE-MODULE-AUDIT-2026-09-05.md` และติดตามใน TASKS/TRACEABILITY
- Pint และ frontend lint/typecheck/81 tests/build ผ่าน; ผล backend ชุดเต็มอยู่ในรายงานและ log ของรอบนี้
- ไม่มีการแก้ application code, migration, API, ข้อมูลจริง หรือ deploy

### SARABAN Visibility Audit — 2026-09-05

- ตรวจครบ 10 ประเด็นและเพิ่ม `docs/checkpoints/SARABAN-VISIBILITY-AUDIT-2026-09-05.md` พร้อม runtime evidence และ quality-gate logs
- ยืนยันเมนู SARABAN ใน DB มี 6/13 รายการ ทั้งที่ Super Admin ปัจจุบันผ่าน 32/32 permissions; หน้า policies/authority เปิด URL ตรงได้จริง
- บันทึก API ที่ยังไม่ผูก UI, permission mapping ที่ไม่ตรงกัน และ ABAC gaps ที่ยังต้องแก้; ไม่เปลี่ยนสิทธิ์ ข้อมูลเมนู migration/API หรือ deploy
- ผลรอบนี้ backend 1,039 passed / 8 skipped (3,825 assertions), Pint 657 files, frontend lint/typecheck/81 tests/build ผ่าน; output build อยู่ใน /tmp ของ container

### Test & Review — 2026-09-05

- ตรวจ working tree และรัน quality gates; พบ P1 เรื่อง retention scope, case scope, legal hold/disposal race และ MalwareScanner binding ที่ขาด
- ผลรอบสุดท้าย: backend 119 failed / 914 passed / 8 skipped; Pint 1 style issue; frontend lint/typecheck/81 tests/build ผ่านในรอบที่ตรวจ
- เพิ่ม `ReviewScopeTest` ในชุด backend ปกติ: 2 กรณี security regression ยังล้มเหลวและต้องแก้ก่อนผ่าน
- รายงานและข้อจำกัด: `docs/checkpoints/TEST-REVIEW-2026-09-05.md`; ไม่มีการแก้ application code, migration, API หรือ deploy ในรอบนี้

### [SEC-AUDIT-2569-09] - 2026-09-02

การแก้ไขตามผลตรวจความปลอดภัยของระบบ E-la · ไม่มีการ deploy ขึ้น Production
ไม่ได้เปิด Workflow Engine และไม่ได้เปิด Document Engine

#### Added
- **SEC-024 · เส้นทางเปลี่ยนรหัสผ่านของตนเอง** `POST /api/profile/password` + `App\Http\Requests\ChangePasswordRequest` — บังคับรหัสผ่านปัจจุบัน (`current_password`) ช่องยืนยัน (`confirmed`) ความยาวขั้นต่ำ 12 ตัวอักษร และรหัสใหม่ต้องไม่ซ้ำรหัสเดิม · ข้อความ validation ภาษาไทยทั้งหมด · จำกัดอัตราด้วย limiter ใหม่ `password-change` (5 ครั้ง/นาที รายผู้ใช้) · เขียน audit `user.password_changed` โดยไม่บันทึกรหัสผ่านหรือ hash · ออกโทเคนใบใหม่ให้อุปกรณ์ที่ยืนยันรหัสเดิมสำเร็จ ส่วนโทเคนใบอื่นถูกเพิกถอนทั้งหมดตามนโยบายเดิมของ `UserObserver`
- **SEC-026 · ตัวระบุบทบาทระบบที่แก้ไม่ได้** migration `2026_09_01_140000_add_system_identity_to_roles_table` เพิ่ม `roles.system_key` (unique, nullable) และ `roles.is_system` พร้อม backfill จากชื่อที่เคยใช้ · `App\Support\SystemRoles` · `App\Services\SystemRoleGuard` กันการถอด/ลบ/ระงับผู้ดูแลระบบสูงสุดคนสุดท้าย (self-lockout)
- **SEC-028 · ด่านกลางของไฟล์ภาพ** `App\Services\Files\ImageUploadService` และ `App\Rules\Base64Image` · เพิ่มชุด allowlist `signatures`, `profile_photos`, `attendance_photos` ใน `config/uploads.php` · `UploadRules::signatureFile()`, `UploadRules::profilePhoto()`
- **SEC-030 · ทะเบียนประวัติรายคน** `GET /api/admin/users/{user}` (limiter ใหม่ `personal-record` 30 ครั้ง/นาที) — เมธอด `UserController::show()` มีอยู่เดิมแต่ไม่เคยมี route ชี้ถึง · พารามิเตอร์ `?id_card=` ค้นด้วยเลข 13 หลักผ่านค่าแฮช โดยไม่ต้องส่งเลขของใครออกไป
- เทสต์ใหม่ฝั่ง backend: `ProfilePasswordChangeTest` (10) `SystemRoleProtectionTest` (8) `LeavePendingCancellationConcurrencyTest` (4) `SignatureAndPhotoUploadSecurityTest` (10) `PersonalIdentifierMaskingTest` (7) และเพิ่ม 5 ข้อใน `SecureFileAuthorizationTest`
- เทสต์ใหม่ฝั่ง frontend: `tests/httpClient.test.js` (11) `tests/authStore.test.js` (10) `tests/thaiUiAndAccessibility.test.js` (24)

#### Fixed
- **SEC-024** ช่อง `password` เคยปนอยู่ในคำขออัปเดตโปรไฟล์ทั่วไป (`POST /api/profile`) โดยไม่ต้องรู้รหัสเดิมและไม่มีช่องยืนยัน · ตอนนี้เส้นทางเดิมตอบ 422 พร้อมชี้ทางที่ถูกต้อง และการแก้โปรไฟล์ทั่วไปไม่ต้องส่งรหัสผ่านเลย
- **SEC-025 · ผู้ใช้ถูกเตะออกจากระบบเพราะ 429/5xx** `authStore.fetchUser()` เคยเรียก `logout()` ใน `catch` ที่ครอบความล้มเหลวทุกชนิด · ตอนนี้ล้างเซสชันเฉพาะ HTTP 401 เท่านั้น · `axios.js` ลองใหม่แบบ exponential backoff เฉพาะคำขออ่านข้อมูลที่เจอ 429 (สูงสุด 2 ครั้ง เคารพ `Retry-After`) · รวมคำขอที่ซ้ำและกำลังวิ่งอยู่ของ `/user`, `/user/menus`, `/settings`, `/notifications` ให้เหลือใบเดียว · `refreshToken()` รวมคำขอพร้อมกันและกลืนความล้มเหลวชั่วคราว · `menuStore` ไม่ล้างเมนูทิ้งเมื่อโหลดไม่สำเร็จ
- **SEC-026 · ด่านป้องกันบทบาทระบบไม่เคยทำงาน** `RoleController` เทียบชื่อ `'SuperAdmin'` แต่บทบาทจริงชื่อ `'Super Admin'` (มีเว้นวรรค) · เปลี่ยนไปตัดสินจาก `system_key` ซึ่งไม่มี API ใดเปิดให้แก้
- **SEC-027 · race condition ในการยกเลิกใบลาที่รออนุมัติ** เดิมคืนโควตา ล้างตารางลงเวลา เปลี่ยนสถานะ และเขียนประวัติ วิ่งนอก transaction และไม่ล็อกแถวใบลา · กดสองครั้งพร้อมกันคืนโควตาสองรอบ · ตอนนี้ทั้งหมดอยู่ใน transaction เดียวพร้อม `lockForUpdate()` และตรวจสถานะซ้ำหลังได้ล็อก · กดซ้ำได้ผลลัพธ์เดิม (idempotent) · เพิ่ม `LeaveAuditLog` ที่เดิมไม่มี
- **SEC-027 · การขยายอำนาจโดยไม่ตั้งใจ** สิทธิ์ `system.user.view` (ดูทะเบียนผู้ใช้) เคยให้อำนาจยกเลิกใบลาของผู้อื่น · เปลี่ยนเป็น `leave.management.manage` หรือ `leave.approval.hr` ซึ่งเป็นสิทธิ์งานลาโดยตรง (หัวหน้าบุคลากรยังทำได้เหมือนเดิม)
- **SEC-028 · ช่องอัปโหลดที่ไม่มีการตรวจ** `profile_photo` ไม่มีกฎ validate ใด ๆ เลยทั้งใน `ProfileController` และ `Admin\UserController` · `signature_base64` ทุกจุดเชื่อชนิดไฟล์จากส่วนหัว data URI ที่ client เขียนเอง ไม่จำกัดขนาด และ `base64_decode()` ไม่ strict · ตอนนี้ตรวจชนิดจาก magic bytes ของเนื้อไฟล์ จำกัดขนาดก่อน decode ตั้งชื่อไฟล์ด้วย UUID และเก็บบน private storage เสมอ
- **SEC-029 · ไฟล์ข้ามชั้นตัดสินสิทธิ์กลาง** `SecureFileAccessService` ถาม Spatie ตรง ๆ ด้วย `hasAnyPermission()` จึงไม่รู้จัก explicit deny · ไม่เทียบชื่อ permission ข้ามรุ่น · และไม่มี organization scope · ตอนนี้ตัดสินผ่าน `PermissionEvaluator` + `OrgScopeResolver`
- **SEC-030 · เลขบัตรประชาชนทั้งองค์กรในคำขอเดียว** `GET /api/admin/users` เคยคืนเลขฉบับเต็มของบุคลากรทุกคน · ตอนนี้ปิดบังเหลือ 4 หลักท้าย ตัด `id_card_number_hash` และ `passport_number` ออกจากรายการ · หน้ารายละเอียดตรวจขอบเขตหน่วยงานและบันทึก personal-data audit · กันไม่ให้ค่าที่ปิดบังถูกเขียนทับข้อมูลจริง
- **UI-01** แปลข้อความที่ผู้ใช้เห็นเป็นภาษาไทย: `Real-time`, `Personnel & Org Structure`, `Close` (sr-only), `404 Not Found`, `Field Dictionary`, `Document Engine` (คงตัวระบุภาษาอังกฤษไว้ในวงเล็บพร้อมคำอธิบายไทย) · ขนาดกระดาษ Letter/Legal มีหน่วยกำกับ
- **UI-01** เติม `aria-label` ภาษาไทยให้ช่องกรอก ตัวเลือก และปุ่มไอคอนที่ยังไม่มีป้ายกำกับ 65 จุดใน 9 หน้าจอหลัก
- **UI-01** ข้อความอังกฤษของเฟรมเวิร์กใน 429/5xx (เช่น `Too Many Attempts.`) ถูกแทนด้วยข้อความไทยที่ชั้น HTTP client ก่อนถึงหน้าจอทุกจุด

#### Changed
- หน้าโปรไฟล์แยกการ์ด "เปลี่ยนรหัสผ่าน" ออกจากฟอร์มข้อมูลส่วนตัว
- หน้าจัดการผู้ใช้โหลดทะเบียนประวัติฉบับเต็มจาก `GET /admin/users/{id}` ก่อนเปิดฟอร์มแก้ไข (เดิมใช้ค่าจากแถวในตาราง ซึ่งตอนนี้เป็นค่าที่ปิดบังแล้ว)
- หน้าโปรไฟล์ใช้ `GET /users/list` แทน `GET /admin/users` สำหรับช่องเลือกผู้ปฏิบัติงานแทน — ต้องการเพียงชื่อ ไม่ใช่ทะเบียนประวัติทั้งหมด
- `AttendanceController::storeAttendanceImage()` เรียกบริการกลางแทนตรรกะสำเนาที่สองซึ่งมีเพดานขนาดและรายการชนิดไฟล์เขียนตายไว้ในตัวเมธอด

### [LEAVE-DRAFT-2] - 2026-09-01

#### Added
- **แก้ไขร่างใบลาได้** · `POST /leaves/{leave}/draft` + `LeaveService::updateDraft()` — เดิมร่างแก้ไม่ได้เลย ต้องลบแล้วกรอกใหม่ทั้งใบทุกครั้งที่พิมพ์ผิดคำเดียว ซึ่งแย่กว่าไม่มีร่าง · คำนวณจำนวนวัน นโยบาย และการแบ่งวันได้รับเงินเดือนใหม่ทุกครั้ง เพราะผู้ใช้เปลี่ยนประเภทการลาหรือช่วงวันได้ · ยังเป็นร่างอยู่จึงไม่ออกเลขที่หนังสือและไม่กันโควตา
- **`GET /leaves/{leave}`** — เปิดใบลาใบเดียว ใช้กติกาการมองเห็นชุดเดียวกับการพิมพ์เอกสาร
- **ปุ่ม "แก้ไขร่าง"** บนการ์ดร่าง เปิดหน้ายื่นใบลาพร้อมค่าที่บันทึกไว้ (`/request?draft=<id>`) · หัวข้อหน้าเปลี่ยนเป็น "แก้ไขร่างใบลา" · กด "ส่งคำขอลา" จากหน้าแก้ร่างจะบันทึกทับแล้วส่งร่างเดิม ไม่สร้างใบใหม่ทิ้งร่างค้างไว้
- **เทสต์ใหม่ 6 ข้อ** ครอบการแก้ร่าง (คำนวณวันใหม่ · เขียน audit · ตรวจด้วยเกณฑ์เดียวกับตอนยื่น · แก้หลังส่งไม่ได้ · คนอื่นแก้ไม่ได้ · เปิดร่างอ่านได้)

#### Changed
- **ยุบตรรกะตรวจและคำนวณคำขอลาให้เหลือที่เดียว** (`resolveRequest()`) ใช้ร่วมกันระหว่างการสร้างใบลาและการแก้ร่าง — ร่างที่แก้แล้วจึงส่งอนุมัติได้แน่นอน ไม่ใช่ผ่านตอนแก้แต่ไปตกตอนกดส่ง
- **แนบไฟล์ใหม่ตอนแก้ร่าง = แทนที่ของเดิม** มิฉะนั้นแก้ร่างสิบครั้งจะได้ไฟล์แนบสิบไฟล์ติดไปกับใบลาตอนยื่นจริง

#### Fixed
- **DEPLOY-007 — stack ที่ขึ้นจากศูนย์ backend วนรีสตาร์ตตลอดไป** · compose ส่ง `DB_APP_USERNAME`/`DB_APP_PASSWORD` ให้ backend แต่**ไม่มีอะไรสร้างบัญชีนั้นใน MySQL เลย** มีแต่ root · ที่ผ่านมาใช้ได้เพราะมีคนสร้างบัญชีด้วยมือไว้ครั้งหนึ่ง พอ volume ถูกสร้างใหม่ก็หายไปพร้อมกัน · เพิ่ม `MYSQL_USER`/`MYSQL_PASSWORD` ให้ image สร้างบัญชีและ GRANT ให้เอง
- **เทสต์ `LeaveBalanceLifecycleTest` แดงเองเมื่อวันที่เปลี่ยน** · นโยบายในชุดนั้นนับวันตามปฏิทิน ช่วงวันที่ใช้ทดสอบจึงได้จำนวนวันไม่เท่ากันแล้วแต่ว่ารันวันไหน — ตรึงวันให้เป็นวันจันทร์เสมอ

### [PERM-HR-VERIFY] - 2026-08-29

#### Fixed
- **หัวหน้าบุคลากรกดปุ่ม "ตรวจสอบสถิติการลา" ไม่ได้** · ปุ่มบังคับสิทธิ์ `leave.approval.hr` แต่ **ไม่มีบทบาทใดในระบบได้รับสิทธิ์นี้เลยนอกจาก Super Admin** — รวมถึง `HR Head` ซึ่งเป็นผู้ทำหน้าที่นี้จริง และเป็น role ที่ผูกกับขั้นตอน "ตรวจสอบโดยหัวหน้าบุคลากร" ในสายอนุมัติมาตรฐาน · บทบาททั้งสาม (หัวหน้าบุคลากร รองผู้อำนวยการ ผู้อำนวยการ) ได้เพียงชื่อชุดเดิม `approve_leave` ซึ่งเทียบเท่า `leave.approval.dept` เท่านั้น · ที่ผ่านมาไม่มีใครสังเกตเพราะบัญชีที่ใช้ทดสอบถูกมอบบทบาท Super Admin ไว้ ซึ่งผ่าน `Gate::before` ทุกกรณีโดยไม่เกี่ยวกับสิทธิ์ที่ถืออยู่จริง
- **`OperationalPermissionSeeder` มอบได้เฉพาะสิทธิ์ที่ตัวเองสร้าง** · การอ้างชื่อจากสารบัญกลางทำให้ seeder ล้มด้วย undefined array key — ตอนนี้หา id แบบ lazy รองรับชื่อที่ seeder อื่นเป็นเจ้าของ ส่วน Super Admin ยังได้เฉพาะชุดที่ seeder นี้เป็นเจ้าของเหมือนเดิม

### [LEAVE-DRAFT] - 2026-08-29

ใบลาที่เข้าสู่กระบวนการแล้วลบไม่ได้ และเพิ่มสถานะ "ร่าง" ให้ยื่นผิดแล้วแก้ได้

#### Added
- **สถานะ "ร่าง"** — มีอยู่ใน enum ของ migration แรกตั้งแต่ต้นแต่ไม่เคยมีโค้ดใช้เลย · ร่าง **ไม่ออกเลขที่หนังสือ ไม่กันโควตา ไม่ผนึกสายอนุมัติ ไม่แจ้งเตือนผู้อนุมัติ และไม่เปิด workflow instance** ทั้งหมดเกิดพร้อมกันตอนกด "ส่งอนุมัติ"
- **`POST /leaves/{leave}/submit`** และ `LeaveService::submitDraft()` — ตรวจเงื่อนไขซ้ำทั้งหมดอีกรอบ เพราะร่างอาจถูกบันทึกไว้นานจนโควตาหมดหรือมีใบลาช่วงเดียวกันแทรกเข้ามา
- **ปุ่ม "บันทึกร่าง"** คู่กับ "ส่งคำขอลา" · แท็บ "ร่าง" ในรายการใบลาของฉัน · ปุ่ม "ส่งอนุมัติ / ลบร่าง" บนการ์ดร่าง
- **เทสต์ใหม่ `LeaveDraftAndDeletionTest`** 10 ข้อ

#### Changed
- **ลบใบลาได้เฉพาะร่างเท่านั้น** · เดิมผู้ที่มีสิทธิ์ `leave.management.manage` ลบได้ทุกสถานะรวมทั้งที่อนุมัติแล้ว และการลบยังคืนโควตาให้ด้วย — ประวัติการใช้วันลาของคนคนหนึ่งถูกลบเกลี้ยงได้ด้วยคลิกเดียว · ทุกสถานะอื่นตอบ 422 พร้อมชี้ทางที่ถูกต้อง (ยกเลิกใบลา / เพิกถอนเอกสาร) ซึ่งเก็บใบลาไว้ในทะเบียนคุมการลาเสมอ
- **เจ้าของใบลาไม่สามารถลบใบที่ยื่นแล้วของตนเองภายใน 5 วันได้อีก** — ให้ใช้ "ยกเลิกใบลา" แทน ผลต่อผู้ใช้เหมือนกันทุกประการแต่ตรวจสอบย้อนหลังได้
- **ร่างเป็นของส่วนตัวจนกว่าจะส่ง** — ผู้มีสิทธิ์ดูทั้งองค์กรก็ไม่เห็นร่างของคนอื่น ผู้ปฏิบัติงานแทนก็ไม่เห็น และร่างไม่ปรากฏในทะเบียนคุมการลา
- **การตรวจใบลาซ้ำช่วงเวลาเหลือที่เดียว** ใน `LeaveService` — ของเดิมใน controller ใช้เงื่อนไขคนละชุด ไม่ยกเว้น `Voided` ไม่รู้จัก `Draft` และไม่ล็อกแถวจึงกันการยื่นพร้อมกันไม่ได้อยู่แล้ว

#### Fixed
- **การลบใบลาไม่เคยเขียน audit log เลยสักบรรทัด** · ลบแล้วไม่มีร่องรอยว่าใครลบ เมื่อไร เพราะอะไร ต่างจาก "เพิกถอนเอกสาร" ที่บังคับใส่เหตุผลและเก็บ `voided_by`/`voided_at` — ตอนนี้การลบร่างเขียนทั้ง `leave_audit_logs` และทะเบียนกลาง
- **เลขที่หนังสือขาดเป็นช่วง ๆ** · เลขถูกออกตอนสร้างใบลาและเป็นลำดับที่เดินหน้าอย่างเดียว ทุกครั้งที่มีคนยื่นผิดแล้วลบ เลขนั้นหายไปตลอดกาล ทะเบียนหนังสือราชการจึงมีช่องว่างที่อธิบายกับผู้ตรวจสอบไม่ได้ · ร่างไม่กินเลข ปัญหานี้จึงหมดไป
- **การตรวจสิทธิ์ตอนลบตอบก่อนการตรวจสถานะ** — ผู้ที่ไม่เกี่ยวข้องได้ 403 เหมือนเดิม ไม่รั่วว่าใบลานั้นอยู่สถานะใด

### [LEAVE-UX] - 2026-08-29

ปรับตามข้อสังเกตของเจ้าของระบบสองเรื่อง

#### Changed
- **แบบฟอร์มใบลาพิมพ์ได้ทุกสถานะ — ยืนยันว่าเป็นพฤติกรรมที่ตั้งใจ** · ขั้นตอนราชการคือผู้ลาพิมพ์ใบลาออกมาเสนอผู้บังคับบัญชาลงนาม ใบที่ยังรออนุมัติจึงเป็นกรณีที่ **ต้อง** พิมพ์ได้ที่สุด · ช่องลงนามที่ยังว่างคือสิ่งที่บอกเองว่าเอกสารยังไม่ผ่านการอนุมัติ ไม่ต้องมีเครื่องหมายอื่น · เพิ่มคำอธิบายใต้ปุ่มบนหน้ารายการใบลาให้ผู้ใช้รู้ว่าพิมพ์ไปเสนอลงนามได้
- **แบบฟอร์มใบยกเลิกต้องเคยมีคำขอยกเลิกจริง** · ใบที่ผู้ยื่นถอนเองตั้งแต่ยังรออนุมัติไม่เคยมีเอกสารที่อนุมัติแล้วให้ยกเลิก จึงไม่ควรมีแบบฟอร์มนี้ — เดิมมีปุ่ม "PDF ยกเลิก" ขึ้นให้กับใบเหล่านั้นด้วย · บังคับที่ฝั่ง server ไม่ใช่แค่ซ่อนปุ่ม
- **หน้าวันลาคงเหลือแยกสองตัวเลขให้ชัด** · เดิมยอดถูกหักทันทีที่กดยื่นโดยไม่มีคำอธิบาย ผู้ใช้เข้าใจว่าถูกตัดวันลาไปแล้วทั้งที่ยังไม่มีใครอนุมัติ · ตอนนี้ตัวเลขใหญ่คือ **คงเหลือตามสิทธิ์** (หักเฉพาะวันที่อนุมัติแล้ว ไม่ขยับตอนกดยื่น) และมีแถบบอก **กันไว้รออนุมัติ X วัน — ยื่นเพิ่มได้อีก Y วัน** เมื่อมีใบค้าง · หน้ายื่นใบลาแสดงทั้ง "คงเหลือตามสิทธิ์" และ "ยื่นได้อีก" คู่กัน เพราะตัวหลังคือตัวที่ตัดสินจริงว่าใบถัดไปจะถูกรับหรือไม่

#### Added
- **`Leave::wasIssued()`** — ใบลานี้เคยได้รับอนุมัติจนออกเป็นเอกสารจริงหรือยัง อ่านจาก `content_hash` ที่ผนึกไว้ตอนอนุมัติขั้นสุดท้าย ใช้แยกใบที่ "ถอนเองตั้งแต่ยังรออนุมัติ" ออกจากใบที่ "อนุมัติแล้วถูกยกเลิก"
- **`Leave::hasCancellationRequest()`** — คอลัมน์ `cancellation_status` มีค่าเริ่มต้นเป็นสตริง `'None'` ไม่ใช่ null การเทียบกับ null จึงเป็นจริงกับทุกใบ (บั๊กที่เจอระหว่างทำ)
- **เทสต์ใหม่ 5 ข้อ** ครอบกฎการพิมพ์เอกสารทุกสถานะ — รวมข้อที่ยืนยันว่าใบที่ยังรออนุมัติต้องพิมพ์ได้

### [LEAVE-AUDIT] - 2026-08-29

ตรวจโมดูลลาและการลงเวลาทั้งเส้น พบ 16 ประเด็น แก้ตามที่เจ้าของระบบอนุมัติครบทั้ง 8 กลุ่ม

#### Added
- **`leave:open-fiscal-year`** — เปิดรอบปีงบประมาณใหม่ ตั้งเวลารัน 1 ต.ค. 00:05 ก่อนคำสั่งทบวันลา · เลื่อน `is_current` และสร้างแถวโควตาปีใหม่ให้บุคลากรที่ยังปฏิบัติงานทุกคนจากนโยบายที่ใช้อยู่
- **`LeaveAttendanceSync`** — ใบลาที่อนุมัติแล้ว ↔ แถววันลาในตารางลงเวลา ทำงานทั้งขาไปและขากลับ
- **`LeaveDayClassifier`** — ที่เดียวที่ตัดสินว่าวันลาวันหนึ่งลงช่องใดของใบลงเวลาและนับกี่วัน
- **`leave_types.attendance_bucket`** — ช่องในใบลงเวลาที่ผู้ดูแลกำหนดเองได้ พร้อมช่อง "พักผ่อน" ที่เพิ่มเข้ามาในใบลงเวลาและรายงาน
- **แจ้งเตือน `attendance.leave_conflict`** — มีการลงเวลาในวันที่ลาอนุมัติแล้ว
- **ตั้งเวลารัน `leave:reconcile-balances`** ทุกสัปดาห์
- **เทสต์ใหม่ 18 ข้อ** — `LeaveAttendanceIntegrityTest` (8), `FiscalYearRolloverTest` (4), `LeavePartialCancellationTest` (6)

#### Fixed
- **ประเภทการลาที่ระเบียบไม่จำกัดวัน อนุมัติไม่ผ่านเลยสักใบ** · `deductBalance()` คิด available = 0 เสมอเมื่อ `annual_quota` เป็น null แล้วเทียบกับจำนวนวันที่ขอ ผู้อนุมัติได้ 422 "วันลาคงเหลือไม่เพียงพอ" ใบลาค้างรออนุมัติตลอดไป — กระทบ 5 ประเภท (ลาเข้ารับการตรวจเลือก · ลาไปศึกษาต่อ · ลาไปปฏิบัติงานในองค์การระหว่างประเทศ · ลาติดตามคู่สมรส · ลาไปฟื้นฟูสมรรถภาพ)
- **ปีงบประมาณไม่เลื่อนเอง** · `is_current` มีแค่ seeder ตอนติดตั้งกับหน้าตั้งค่าที่ผู้ดูแลกดเองเป็นคนเขียน หลัง 30 ก.ย. ระบบจึงยังคิดว่าเป็นปีเดิมตลอดไป
- **ขึ้นปีงบประมาณใหม่แล้วหน้าวันลาคงเหลือว่างเปล่า** · ไม่มีอะไรสร้างแถวโควตาปีใหม่ให้ ยกเว้นการทบวันลาซึ่งครอบเฉพาะลาพักผ่อน
- **ยกเลิกใบลาแล้ววันลายังค้างในใบลงเวลา** · ไม่มีจุดใดลบแถวสถานะ `Leave` เมื่อใบลาถูกยกเลิก ปฏิเสธ หรือเพิกถอน สรุปรายเดือนจึงยังนับเป็นวันลาต่อไปทั้งที่โควตาถูกคืนแล้ว
- **ใบลงเวลาแยกประเภทการลาด้วยการค้นคำในชื่อไทย** · ลาพักผ่อนถูกนับเป็นลากิจ และ "ลาไปช่วยเหลือภริยาที่**คลอด**บุตร" ถูกนับเป็นลาคลอดบุตร
- **วันหยุดที่คร่อมช่วงลาถูกทำเครื่องหมายว่าลาไปด้วย** · ลา 5 วันทำการคร่อมสุดสัปดาห์ขึ้นในใบลงเวลาเป็น 7 วัน
- **ลาครึ่งวันนับเป็นเต็มวันในใบลงเวลา** · โควตาตัด 0.5 วันแต่ใบลงเวลาบันทึกเต็มวัน
- **ยกเลิกลาบางช่วงยื่นซ้อนกันได้ คืนวันลาซ้ำ** · ไม่มีการตรวจช่วงที่ทับกับคำขอเดิม
- **ยกเลิกบางช่วงนับวันด้วยปฏิทินองค์กร** ไม่ใช่ตารางเวลาของเจ้าตัว โควตาเพี้ยนถาวรสำหรับผู้ที่มีตารางเฉพาะ
- **ยกเลิกบางช่วงไม่ปรับวันที่ได้รับเงินเดือน** · paid + unpaid ไม่เท่ากับจำนวนวันทั้งใบอีกต่อไป
- **ลงเวลาออกได้โดยไม่เคยลงเวลาเข้า** · ได้แถวสถานะ "ขาดงาน" ที่มีเวลาออก · แก้ไปด้วย: การค้นแถวของวันนั้นใช้ `whereDate` แล้ว (เดิมหาไม่เจอเงียบ ๆ เมื่อคอลัมน์มีส่วนเวลาติดมา)
- **สถานะลงเวลาวันนี้ใช้เวลาของเซิร์ฟเวอร์** ไม่ใช่บริการเวลากลางตามที่ไฟล์นั้นเองระบุไว้
- **การทบวันลาข้ามปีไม่เคารพเกณฑ์นับรอบของนโยบาย** · นโยบายที่นับตามปีปฏิทินถูกอ่านผิดแถว

#### Changed
- **การอนุมัติใบลาลงวันลาในใบลงเวลาให้ทันที** — เดิมต้องรอผู้ดูแลกดปุ่ม "ซิงค์ข้อมูลการลา" ถ้าไม่มีใครกด ใบลงเวลาจะแสดงว่าคนที่ลาถูกต้องแล้ว "ขาดงาน" · ปุ่มเดิมยังอยู่สำหรับข้อมูลย้อนหลัง
- **ลงเวลาในวันที่ลาอนุมัติแล้ว** — บันทึกได้แต่แจ้งผู้ตรวจสอบ ไม่ห้ามเด็ดขาด เพราะผู้ที่กลับมาปฏิบัติงานกลางคันแล้วยื่นยกเลิกลาทีหลังต้องบันทึกเวลาจริงได้

#### Removed
- **`LeaveService::hasEnoughBalance()`** — ไม่มีผู้เรียกทั้งในโค้ดจริงและในเทสต์ และตรรกะข้างในผิดเรื่องประเภทไม่จำกัดวันแบบเดียวกัน


### [CLEANUP-02] - 2026-08-28

ปิดงานที่เหลือให้พร้อมขึ้น production — ทุกฟีเจอร์ ทุกฟังก์ชัน ทุก action ต้องใช้งานได้จริง

#### Added
- **หน้าจอ "ขอแก้ไขเวลาปฏิบัติงาน"** — ฟีเจอร์นี้มีสายอนุมัติ audit และการแจ้งเตือนครบมาตั้งแต่ LEGACY-08 แต่ไม่มีหน้าจอ และไม่มี endpoint สำหรับดูรายการเลย · เพิ่ม `GET /api/attendance-corrections` และ `GET /api/admin/attendance-corrections` พร้อมปุ่มขอแก้ไขในหน้าประวัติการลงเวลา และรายการพิจารณาในหน้ารายงานการลงเวลา
- **การตั้งค่าการแจ้งเตือนรายบุคคล** ในหน้าโปรไฟล์ — Notification Center อ่าน preference ตอนส่งจริงมาตั้งแต่ LEGACY-10 แต่ไม่เคยมีหน้าจอให้ตั้งค่า
- **จัดการประเภทบุคลากร** ในหน้ากำหนดประเภทบุคลากร — endpoint สร้าง/แก้ไขมีมาตลอดแต่ไม่มีหน้าจอ
- **สถานะคำสั่ง OT บนหน้าลงเวลา** — บอกล่วงหน้าว่ามีคำสั่งที่อนุมัติครอบเวลานี้หรือไม่ แทนที่จะให้กดแล้วเจอข้อความปฏิเสธ
- **ปุ่ม "เลิกใช้เวอร์ชันนี้"** ในหน้าออกแบบแบบฟอร์ม
- **`ability:` middleware** — ด่านสิทธิ์แบบ "อย่างน้อยหนึ่งข้อ" ที่เดินผ่าน Gate จึงรู้จักชื่อที่เทียบเท่าและ explicit deny
- **`ModuleStateResolver`** — เอาค่าตั้งค่าโมดูลมาเติมทับ config ตอน boot ทำให้เปิด-ปิดโมดูลจากหน้าจอได้จริง
- **`LeaveCancellationWorkflowProvisioner`** — สาย "ขอยกเลิกใบลา" บน Workflow Engine (เงื่อนไขก่อน cutover ตามเอกสารปิดเฟส LEGACY-05)
- **`useAttendanceSummary`** composable รวมส่วนที่ซ้ำกันของสองหน้าสรุปการลงเวลา
- **เทสต์ใหม่** — `ApiSurfaceContractTest`, `AbilityMiddlewareTest` (6), `ModuleToggleTest` (7), `LeaveCancellationCutoverTest` (9), คำขอแก้ไขเวลาใน `AttendanceIntegrationTest` (3), ประวัติการดำเนินการใน `CentralAuditLogTest` (2), `ProductionSeedSafetyTest` (4), การติดตั้งครบชุดใน `MenuIntegrityTest` (1)

#### Changed
- **ด่านสิทธิ์ของ route เหลือทางเดียว** — เลิกใช้ `permission:` ของ Spatie ที่ไม่รู้จักชื่อเทียบเท่าและ explicit deny · ชื่อสิทธิ์ชุดเดิมหายจาก route ทั้งหมด และหน้าเว็บเช็คชื่อมาตรฐานชื่อเดียวได้เพราะ `/api/user` ส่งชื่อเทียบเท่ามาให้แล้ว
- **หน้า "โมดูลระบบ"** ตั้งค่ากลไกจริงที่เมนูและ API ใช้ พร้อมแสดงการพึ่งพาระหว่างโมดูลและเตือนโมดูลที่จะถูกปิดตาม
- **ประวัติการดำเนินการของใบลา** อ่านจากทะเบียนกลาง `audit_logs` และแสดงชื่อผู้กระทำ
- **`MenuService`** ตัดสินสิทธิ์ด้วย Gate แทนการเทียบชื่อดิบ

#### Fixed
- **ประวัติการดำเนินการแสดงไม่ครบ** — อ่านเฉพาะ `leave_audit_logs` ผู้ใช้จึงไม่เห็นการยื่นใบลาและการขอยกเลิกที่บันทึกไว้อีกตารางหนึ่ง
- **สวิตช์ในหน้า "โมดูลระบบ" กดแล้วไม่มีอะไรเกิดขึ้น** — เขียนค่าตั้งค่าที่ไม่มีอะไรอ่านอีกแล้วหลังแถบเมนูเปลี่ยนไปอ่านจากตาราง `menus`
- **explicit deny ถูกหลบได้** — route ที่ใช้ `permission:` ไม่ตรวจ `permission_denies` สิทธิ์ที่ถูกตัดเฉพาะรายจึงยังผ่านด่านได้
- **ช่องตัวกรอง "หน่วยงาน" ในสองหน้าสรุปการลงเวลาว่างเปล่า** — เรียก `/admin/departments` ที่ต้องมีสิทธิ์ `manage_departments`
- **หน้าสรุป OT ไม่แสดงข้อความผิดพลาด** — ตารางว่างโดยไม่บอกสาเหตุ
- **`GET /overtime/my-active`** คืนข้อมูลที่ตอบไม่ได้ว่าลงเวลา OT ได้หรือยัง
- **เปิดสวิตช์ cutover บนระบบที่ยังไม่ได้ seed workflow แล้วการอนุมัติล้มทั้งหมด** — ระบบออกเวอร์ชันจาก `approval_steps` ให้เองแล้ว
- **SEC-028 — ติดตั้ง production แล้วได้บัญชีผู้ดูแลระบบสูงสุดที่ใครก็เข้าได้** · `DatabaseSeeder` สร้างผู้ใช้ 6 คนด้วยรหัสผ่านตายตัว (`1111111111111` ถึง `6666666666666`) · SEC-027 เอาค่าเหล่านี้ออกจากหน้าเข้าสู่ระบบไปแล้ว แต่ตัวบัญชียังถูกสร้างอยู่ทุกครั้งที่ `db:seed --force` · ตอนนี้นอก local/testing สร้างผู้ดูแลระบบเพียงบัญชีเดียว รหัสผ่านมาจาก `ADMIN_PASSWORD` ถ้าไม่ตั้งจะสุ่มให้แล้วพิมพ์ครั้งเดียวตอนติดตั้ง ส่วนชุดบัญชีทดลองต้องสั่ง `SEED_DEMO_ACCOUNTS=true` อย่างชัดแจ้งเท่านั้น
- **วันลาคงเหลือบนหน้าจอไม่ขยับตามการตัดและการคืนวัน** — สามจุดที่ทำให้ผู้ใช้เห็นว่า "ยกเลิกแล้วระบบไม่คืนวันลา" ทั้งที่ตัวเลขในฐานข้อมูลถูกต้องมาตลอด:
  1. **หน้าจอหาแถวโควตาผิดปี** · แถว `leave_balances` ถูกคีย์ด้วยปีที่ *นโยบายของการลาประเภทนั้น* กำหนด แต่ `LeaveQueryController::balances()` หาด้วยเลขปีงบประมาณตัวเดียวสำหรับทุกประเภท ประเภทที่นับตามปีปฏิทินจึงอ่านคนละแถวกับที่ระบบจอง/ตัดจริงตลอดช่วง 1 ต.ค. – 31 ธ.ค. (หรือหายไปจากหน้าจอทั้งแถว) · ตอนนี้เลือกปีตามเกณฑ์ของนโยบายแต่ละแถว และรวมยอดใบลาในกรอบเวลาของรอบนั้นจริง ๆ
  2. **หน้าเว็บไม่ดึงโควตาใหม่หลังทำรายการ** · มีแต่ `cancelLeave` ที่ดึง ส่วนการยื่นใบลา อนุมัติ ปฏิเสธ และ **อนุมัติคำขอยกเลิก** ดึงเฉพาะรายการใบลา ตัวเลขคงเหลือจึงค้างจนกว่าจะรีเฟรชทั้งหน้า · รวมเป็น `_refreshAfterBalanceChange()` ที่ทุกคำสั่งซึ่งกระทบโควตาเรียกใช้ และ guard กัน fetch ซ้ำเปลี่ยนไปคืน promise ที่กำลังวิ่งอยู่แทนการ return เปล่า (ของเดิม `await` แล้วได้ค่าชุดเก่า)
  3. **ประเภทการลาที่ระเบียบไม่จำกัดวันแสดงว่า "เหลือ 0 วัน"** · แถวโควตาที่ seeder สร้างไม่ได้ผูก `leave_policy_id` หน้าจอจึงตัดสินไม่ได้ว่าไม่จำกัดโควตา — ลาเข้ารับการตรวจเลือก ลาไปศึกษาต่อ ลาไปปฏิบัติงานในองค์การระหว่างประเทศ ลาติดตามคู่สมรส และลาไปฟื้นฟูสมรรถภาพ ทั้งห้าประเภทดูเหมือนไม่มีสิทธิ์ลาเลย · ตอนนี้ผูกนโยบายที่ใช้จริงให้เมื่อแถวยังไม่มี
- **ติดตั้งใหม่ได้กลุ่มเมนูระดับบนสุดเกินมาหนึ่งกลุ่ม** — `DocumentEngineSeeder` เพิ่มเมนู `admin.document_center` เองซ้ำกับ `settings.document_center` ในโครงหลัก และเพราะกิ่งแม่ที่มันหา (`code = 'admin'`) ไม่มีอยู่แล้ว เมนูซ้ำจึงลอยขึ้นไปเป็นกลุ่มบนสุด — เห็นได้ต่อเมื่อรัน seeder ครบชุดเท่านั้น

#### Removed
- `Admin\SecureExportSettingController` + 3 route `/admin/export-settings` · `GET /admin/permissions/user-counts` · `GET /fiscal-year/current` · `GET /leave-services` + `LeaveGovernanceWorklistController::myLeaves` · `GET /admin/roles/{role}`
- getter ของ auth store 10 ตัวที่เหลือไว้ช่วยแถบเมนูที่เขียนตายตัว และเงื่อนไข `hasPermission('approvals')` ที่อ้างสิทธิ์ที่ไม่มีอยู่จริง
- `enable_leave_menu` / `enable_attendance_menu` ออกจาก `config/system_settings.php` และ `settingStore`
- **`PurgeAndMigrateTo7StandardRolesSeeder`** — เรียก `StandardRolesAndPermissionsSeeder` ที่ถูกลบไปแล้ว รันเมื่อไรก็พัง และงานของมันคือลบ role กับการผูกสิทธิ์ทิ้งทั้งชุด
- **`UpdateUserNamesSeeder`** — เขียนทับชื่อบุคลากรจริงด้วยชื่อสมมติ เป็น one-off ที่ทำไปแล้ว
- **`DocumentEngineSeeder::syncMenu()`** — เมนูมีแหล่งความจริงเดียวคือ `MenuSeeder::TREE`


### [CLEANUP-01 รอบที่ 3–4] - 2026-08-28

รวมระบบเอกสาร ยุบหน้าที่ซ้อนทับ เปิดทางให้ workflow cutover และรวมสารบัญสิทธิ์

#### Added
- **`LeaveApprovalCoordinator`** — จุดตัดสินเดียวของสายอนุมัติใบลา อ่านสวิตช์ `workflow.engine_is_source_of_truth` ที่เตรียมไว้ตั้งแต่ LEGACY-05 แต่ไม่เคยมีโค้ดส่วนใดอ่านเลย ทั้งสองเส้นทางเขียนข้อมูลชุดเดียวกัน (`leave_approvals`, `leaves.approval_level`, `leaves.status`, สิทธิ์วันลา, audit log)
- **`PermissionCatalogSeeder`** — สารบัญชื่อสิทธิ์ที่เดียวของระบบ (มาตรฐาน 39 ชื่อ + ชุดเดิม 10 ชื่อ) สร้างชื่ออย่างเดียวไม่แจกสิทธิ์ พร้อม migration สำหรับระบบที่ติดตั้งแล้ว
- **แม่แบบชนิด HTML บนศูนย์จัดการเอกสาร** — 5 endpoint ใหม่บน `document-engine` (แก้เนื้อหา, สารบัญตัวแปร, พรีวิว, ชุดตั้งต้น, ลบแม่แบบ) และปุ่ม "แก้เนื้อหา / ลบ / ซิงค์แม่แบบตั้งต้น / คืนค่าแบบฟอร์มตั้งต้น" ในหน้าเดียว
- **เทสต์ใหม่** — `LeaveApprovalCutoverTest` (14), `PermissionCatalogTest` (5), `DocumentEngineTest` (+4)

#### Changed
- **หน้า "จัดการแม่แบบเอกสาร" ยุบเข้า "ศูนย์จัดการเอกสารและรายงาน"** — ทั้งสองหน้าอ่านเขียนตาราง `document_templates` ชุดเดียวกันแต่เป็นคนละเมนู คงเส้นทาง `/admin/document-templates` ไว้เป็น redirect
- **หน้า "บริการและเอกสารใบลา" ยุบเข้า "รายการใบลาของฉัน"** — หลักฐานประกอบการลา ประวัติการดำเนินการ และการขอยกเลิกบางช่วง ย้ายมาอยู่ในแถบขยายของแต่ละใบลา คงเส้นทาง `/leave-services` ไว้เป็น redirect
- **รายการ "ใบลาที่ต้องอนุมัติ"** ถามผู้ตัดสินคนเดียวกับตอนกดอนุมัติจริง แทนที่จะเรียก `ApproverResolverService` เอง
- **`config/workflow.php`** อธิบายความหมายของสวิตช์ cutover ตามพฤติกรรมจริง พร้อมระบุขอบเขตว่าครอบเฉพาะสาย "ขอลา"

#### Fixed
- **แหล่งประกาศ route ที่สาม** — `main.js` ลงทะเบียน 8 เส้นทางด้วย `router.addRoute()` โดยใช้ชื่อ route ซ้ำกับ `router/index.js` ซึ่ง Vue Router ถือว่าแทนที่ ผลคือเงื่อนไขสิทธิ์และชื่อหน้าที่เขียนไว้ถูกทิ้งเงียบ ๆ และ `/profile/access-history` ถูกย้ายไป `/my-access-history` โดยไม่มีใครรู้ — ถอดออกทั้งหมด เหลือไฟล์เดียว พร้อม redirect ของ path เดิม
- **`/verify-leave` ไม่มีพารามิเตอร์ `:hash`** ในไฟล์ router ทั้งที่ QR บนเอกสารชี้มาที่ `/verify-leave/{hash}` เสมอ — เส้นทางนี้ทำงานได้เพราะถูก `main.js` ทับไว้เท่านั้น
- **สิทธิ์ของหน้าไม่ตรงกับที่ฝั่ง server บังคับ** — `admin-leave-governance` (ต้องรับ `leave.approval.hr`) และ `admin-leave-policies` (ต้องรับ `leave.policy.manage`)
- **`leave.management.manage` และ `system.role.manage` ไม่เคยถูกสร้าง** ทั้งที่ route, policy และเมนูอ้างถึง — endpoint และเมนูเหล่านั้นใช้ได้เฉพาะ Super Admin ที่ผ่าน `Gate::before`

#### Removed
- **`Admin\DocumentTemplateController`** และ 7 route ของ `/admin/document-templates` · **`DocumentTemplatesView.vue`** · **`LeaveServicesView.vue`**
- **`StandardRolesAndPermissionsSeeder`** — สร้างบทบาทชื่อไทยอีก 7 ตัวที่ระบบไม่ได้ใช้ และถูกกันไม่ให้รันตอนติดตั้งอยู่แล้ว

#### หมายเหตุด้านความปลอดภัยของการ cutover
สวิตช์ `WORKFLOW_ENGINE_SOURCE_OF_TRUTH` ยังเป็น **false** ตามค่าเริ่มต้น กลไกและหลักฐาน parity พร้อมแล้ว
แต่ `docs/checkpoints/LEGACY-05-REPORT.md` ข้อ 11 กำหนดให้ต้องมีหลักฐาน shadow จาก **การอนุมัติจริง**
ตามจำนวนที่ตกลงกันก่อนเปิดใช้ และสาย "ขอยกเลิกใบลา" ยังไม่ถูกนำเข้า engine
การเปิดสวิตช์บน production จึงเป็นการตัดสินใจของเจ้าของระบบหลังตรวจ `php artisan workflow:shadow-report`


### [CLEANUP-01] - 2026-08-28

เก็บกวาดความซ้ำซ้อนตามรายงาน `docs/audits/MENU-FEATURE-INVENTORY-2026-08-27.md` — รอบที่ 1 และ 2

#### Added
- **`ศูนย์รายงานกลาง` (`/report-center`)** — หน้าจอแรกของชั้นรายงานกลางที่สร้างไว้ตั้งแต่ LEGACY-11 แต่ไม่เคยมีหน้าใดเรียกใช้ เลือกได้ 8 รายงาน (บุคลากร / สถิติการลา / สิทธิและวันลาคงเหลือ / การลงเวลา / มาสาย / ขาดงาน / ล่วงเวลา / สรุปผู้บริหาร) กรองตามช่วงวันที่ หน่วยงาน และประเภทบุคลากร แล้วส่งออก PDF / Excel / Word / CSV
- **`GET /api/reporting`** สารบัญรายงานที่ผู้เรียกเปิดดูได้จริง และ **`GET /api/reporting-org-units`** รายชื่อหน่วยงานสำหรับตัวกรองขอบเขต
- **`AppMenuNav.vue`** — แถบเมนูตัวเดียวที่วาดจาก `/api/user/menus` ใช้ร่วมกันทั้งจอใหญ่และมือถือ
- **`NotificationCenter\ChannelAdapterRegistry` / `NotificationChannelTester`** — ทะเบียนช่องทางส่งกลาง และปุ่มทดสอบที่เดินผ่าน adapter ชุดเดียวกับการส่งจริง
- **`POST /api/admin/users/import`** — การนำเข้าผู้ใช้จาก CSV ที่หน้าเว็บมีปุ่มมาตลอดแต่ route ชี้ไปยังเมธอดที่ไม่มีอยู่จริง รหัสผ่านสุ่มให้อัตโนมัติและไม่ถูกส่งกลับ ผู้ใช้ใหม่ตั้งรหัสผ่านเองผ่าน "ลืมรหัสผ่าน"
- **`GET /api/departments`** — รายชื่อหน่วยงานสำหรับช่องตัวกรอง เปิดให้ผู้ใช้ที่ล็อกอินแล้วทุกคน
- **เทสต์ใหม่** — `DeadCodeGuardTest` (3), `MenuIntegrityTest` (8), `UserImportTest` (6), สารบัญรายงานและปีงบประมาณใน `ReportingLayerTest` (3), ปุ่มทดสอบการแจ้งเตือนใน `SystemSettingsTest` (3)

#### Changed
- **เมนูมีแหล่งความจริงเดียว** — `MenuSeeder::TREE` (6 กลุ่ม 33 รายการ) ตรงกับ `router/index.js` ทุกเส้นทาง แถบเมนูอ่านจากตาราง `menus` จริง ๆ แล้ว หน้า "จัดการเมนูตามสิทธิ์" จึงมีผลกับสิ่งที่ผู้ใช้เห็นเป็นครั้งแรก `App.vue` ลดจาก 1,097 เหลือ 529 บรรทัด (เดิมเขียนเมนูตายตัวซ้ำ 2 ก๊อป รวม 80 `<router-link>`)
- **`MenusManagementView`** เปลี่ยนจากหน้าสรุปสิทธิ์รายโมดูลเป็นหน้าจัดการเมนูจริง — เปิด/ปิดเมนู และแก้สิทธิ์ที่ใช้มองเห็นเมนู
- **ชั้นรายงานกลางเป็นภาษาไทยทั้งชุด** — ชื่อรายงาน คอลัมน์ ยอดรวม และ PDF (ฟอนต์ TH Sarabun แทน DejaVu ที่แสดงภาษาไทยไม่ได้)
- **`DocumentTemplatesView.vue`** ใช้ api client กลางแทน `axios` ดิบ — เป็นไฟล์เดียวจาก 46 ไฟล์ที่ยังข้าม interceptor แนบ token, refresh token และการจัดการ error กลาง
- **`ReportsView` / `AttendanceReportView`** ใช้ `GET /api/departments` ร่วมกัน ช่องตัวกรอง "หน่วยงาน" ของทั้งสองหน้ากลับมามีข้อมูล

#### Fixed
- **ปีงบประมาณเพี้ยน 543 ปี** — `ReportFilter` เอาเลข พ.ศ. ไปประกอบเป็นวันที่ตรง ๆ (`2568-10-01`) ซึ่งถูกอ่านเป็น ค.ศ. ช่วงข้อมูลของรายงานจึงผิดทุกครั้งที่ระบบยังไม่มีข้อมูลปีงบประมาณ
- **route 3 เส้นที่ชี้ไปยังเมธอดที่ไม่มีอยู่จริง** — `POST /admin/users/import`, `GET /admin/departments-list`, `GET /admin/roles/{role}`
- **ปุ่มทดสอบการแจ้งเตือน** เดิมเรียก provider ตรง ๆ จึงตอบว่าสำเร็จได้ทั้งที่ช่องทางนั้นส่งของจริงไม่ได้ (ข้าม `isConfiguredFor`)
- **เมนูในฐานข้อมูลชี้ไปยังหน้าที่ไม่มีอยู่** — `/dashboard`, `/calendar`, `/attendance/me`, `/admin/personnel-types`, `/admin/leave-calendar` ถูกล้างด้วย migration `2026_08_28_090000_resync_menus_with_application_routes`

#### Removed
- **ชั้นรายงานเดิมที่ไม่มีหน้าใดเรียก** — `ReportController` (`/reports/summary`, `/reports/export-excel`, `/reports/export-pdf`), `Services\Export\LeaveExcelService`, `resources/views/pdf/report.blade.php`
- **โค้ดตาย** — `LeaveController`, `LeaveAttachmentController`, `SettingController`, `Admin\ExportSettingController`, model `LeaveRequest` / `ApprovalLog` / `Attachment`
- **`TelegramNotificationService`** (proxy ที่ประกาศตัวเองว่าเลิกใช้แล้วและไม่มีใครเรียก) และเมธอด `LineNotificationService::notifyLeaveCreated` ที่ประกอบข้อความเองแล้วส่งตรง ข้าม template / preference / delivery log ของ Notification Center


### [SEC-023R] - 2026-08-27

allowlist กลางของไฟล์อัปโหลด แทน SEC-023 ที่หายไปพร้อมโมดูลสารบรรณ

#### Security
- **`config/uploads.php`** — แหล่งความจริงเดียวของ allowlist ไฟล์อัปโหลด แยกเป็นสามชุด: `attachments` (หลักฐานประกอบการลา / ไฟล์แนบใบลา), `images` (โลโก้และตราสัญลักษณ์), `document_templates` (PDF ต้นฉบับของ Document Engine) พร้อมรายการ `never_allowed` ที่ห้ามปรากฏใน allowlist ใดเลย
- **`App\Support\UploadRules`** — ทุกจุดที่รับไฟล์สร้างกฎจากที่นี่ และได้ด่านสองชั้นเสมอ: `mimetypes:` ตรวจจาก magic bytes ของเนื้อไฟล์ + `extensions:` ตรวจนามสกุลจริง ไฟล์ที่เปลี่ยนนามสกุลหลอกจึงไม่ผ่าน
- **ปิดช่องที่เปิดอยู่** — `ExportSettingController` รับ `svg` (รันสคริปต์ได้เมื่อเบราว์เซอร์เปิด) และ `SettingController` รับ `gif` ทั้งสองถูกจำกัดเหลือ jpg/jpeg/png · จุดที่เคยตรวจด้วย `mimes:` อย่างเดียว (หลักฐานประกอบการลา, ไฟล์แนบใบลา) ได้ด่านเนื้อไฟล์เพิ่ม
- **`UploadAllowlistTest`** — 9 เคส: allowlist ทุกชุดต้องไม่มีชนิดที่รันได้, กฎที่สร้างต้องมีครบสองชั้น, อัปโหลด `.php` / `.html` / `.svg` / `.exe` / `.sh` เข้าเส้นทางหลักฐานการลาต้องถูกปฏิเสธ, สคริปต์ที่เปลี่ยนนามสกุลเป็น `.pdf` ต้องไม่ผ่าน และ PDF จริงต้องยังอัปโหลดได้

### [MODULE-REMOVE-01] - 2026-08-27

ถอนโมดูลสารบรรณอิเล็กทรอนิกส์ (e-Saraban) ออกจากระบบทั้งหมดตามคำสั่งเจ้าของระบบ

#### Removed
- **โค้ดโมดูล** — `Api/Saraban/*` (11 controller), `Models/Saraban/*` (13 model), `Services/Saraban/*`, `Policies/Saraban/DocumentPolicy`, `Requests/Saraban/StoreDocumentRequest`, `SarabanDocumentNotification`, `SarabanModuleServiceProvider`, `SarabanWorkflowProvisioner`, middleware `saraban.classified` (`CanViewClassifiedDocument`), `config/saraban.php`, seeder สารบรรณ 3 ตัว และ migration ของโมดูล 23 ไฟล์
- **หน้าเว็บ** — `views/saraban/*` (6 หน้า), `SarabanSettingsView.vue`, `WorkflowTemplatesView.vue` (สายอนุมัติสารบรรณ), store สารบรรณ 2 ตัว, เมนู sidebar ทั้ง desktop/mobile, เส้นทาง router 11 เส้นทาง, `SARABAN_STATUS_MAP` และสวิตช์เปิด-ปิดโมดูลในหน้าจัดการโมดูล/เมนู
- **API** — ทุกเส้นทางใต้ `/api/saraban/*` รวมเส้นทางสาธารณะ `/api/saraban/verify/{hash}` (การตรวจสอบใบลาผ่าน QR ที่ `/api/leaves/verify/{hash}` ยังทำงานตามเดิม) และ `POST /api/admin/settings/upload-garuda`
- **ค่าตั้งค่าระบบ** — `enable_saraban_menu`, `saraban_incoming_number_format`, `saraban_outgoing_number_format`, `saraban_garuda_logo` (รวมช่องอัปโหลดตราครุฑในหน้าตั้งค่าเอกสาร), `require_pin_for_signature`, `require_pin_for_circular_ack`
- **สิทธิ์** — `saraban.*` ทั้งชุดถูกถอดออกจาก seeder และลบออกจากฐานข้อมูลด้วย migration ตัวบทบาท "เจ้าหน้าที่สารบรรณกลาง" และ `Document Officer` **ยังคงอยู่** ผู้ใช้ไม่หลุดบทบาทและยังใช้ระบบลาและลงเวลาได้ตามปกติ
- **รายงานกลาง** — รายงาน `saraban` ออกจาก `ReportingDataService` / `ReportingScopeService` และจาก `reports` ของโมดูล reporting

#### Changed
- `2026_08_27_000000_drop_saraban_module` — migration ทางเดียวที่ drop ตาราง `saraban_*` ทั้ง 14 ตาราง และล้างร่องรอยในตารางกลาง (สิทธิ์ / เมนู / ค่าตั้งค่า / แม่แบบเอกสารของโมดูล / สายงาน `saraban.document` ใน Workflow Engine / ประเภทแจ้งเตือน `saraban.task`) **ต้องสำรองฐานข้อมูลก่อนรัน**
- `SecureFileAccessService` เลิกอนุญาตไฟล์แนบสารบรรณ เหลือเส้นทางของโปรไฟล์ / ลงเวลา / ใบลา / โลโก้สถานศึกษา
- `2026_08_13_100000_create_core_data_foundation` และ `2026_08_15_220000_create_notification_center` ตัดส่วนที่อ้าง `saraban_documents` และ `saraban.task` ออก เพื่อให้ติดตั้งใหม่จากศูนย์ผ่าน migration ได้

#### Removed (tests)
- `tests/Feature/Saraban/*` (10 ไฟล์), `SarabanWorkflowPinningTest` และเคสสารบรรณใน `OperationalPermissionMatrixTest`, `PrivilegeEscalationGuardTest`, `WorkflowAuthorizationTest`, `RuntimeSecurityConfigurationTest` (SEC-023 allowlist ไฟล์แนบเป็นของโมดูลนี้ตัวเดียว), `SystemSettingsTest` (รูปแบบเลขหนังสือ)

### [SEC-HOTFIX-01] - 2026-08-22

Security hotfix รอบ Saraban / runtime / dependency พร้อมตั้งด่านคุณภาพฝั่งหน้าเว็บ

#### Security
- **SEC-020 Dependency advisory** — `axios` 1.17.0 มี advisory ระดับ high 10 รายการ (DoS จาก recursion ใน `formDataToJSON`, prototype pollution, bypass `maxBodyLength`, NO_PROXY bypass) → pin 1.19.0; `nanoid` และ `postcss` เป็น dependency ทางอ้อมของ vite/@vue/compiler-sfc ที่ยังไม่ปล่อยรุ่นยกเวอร์ชัน → pin ผ่าน `overrides` เป็น 3.3.18 / 8.5.26 ผลลัพธ์ `npm audit` = 0 (composer audit สะอาดอยู่แล้ว) และเพิ่ม `composer audit` / `npm audit --audit-level=high` เข้า CI
- **SEC-021 `filesystems.disks.local.serve`** — ค่านี้เป็น `true` ทำให้ Laravel ลงทะเบียน route `storage/{path}` ที่เสิร์ฟทุกไฟล์ใต้ `storage/app/private` ออกทาง HTTP **โดยไม่ผ่าน authentication** ซึ่งย้อนเจตนาของ SEC-001 ทั้งหมด (ลายเซ็น ภาพลงเวลา ไฟล์แนบสารบรรณอยู่ใต้ root นี้) — ปิดเป็น `false` ทางเข้าเหลือ `GET /api/files/{path}` ทางเดียว
- **SEC-022 Production runtime guard** — เดิมตรวจเฉพาะ `APP_DEBUG` ตอน boot ซึ่งมีผลเป็น HTTP 500 รายคำขอ ตัว container ยังขึ้นและ migrate ตามปกติ จึงดูเหมือน deploy สำเร็จทั้งที่ตั้งค่าไม่ปลอดภัย — เพิ่ม `php artisan app:verify-environment` เป็นคำสั่งแรกของ backend/worker/scheduler ใน compose คืน exit code ไม่ใช่ 0 แล้ว container ออกทันทีก่อน migrate ด่านตรวจครอบ `APP_ENV` ที่พิมพ์ผิด, `APP_DEBUG` ใน production, `APP_KEY` ว่าง, `APP_URL` ที่เป็น http และ `local.serve`
- **SEC-023 ไฟล์แนบสารบรรณ** — กฎตรวจเดิมมีเพียง `file|max:20480` ผู้ที่สร้างหนังสือได้จึงอัปโหลด `.php`, `.html`, `.svg`, `.exe` เข้าสู่ storage ได้ — เพิ่ม allowlist สองชั้นใน `config/saraban.php` (`mimetypes:` ตรวจจาก magic bytes ของเนื้อไฟล์ + `extensions:` ตรวจนามสกุลจริง), ชื่อไฟล์บนดิสก์เป็น UUID เสมอ และ `mime_type` ที่บันทึกมาจาก `getMimeType()` ไม่ใช่ `getClientMimeType()` ที่ปลอมได้
- **SEC-024 ดาวน์โหลดไฟล์แนบพัง** — ทุกจุดที่เขียนไฟล์เขียนลง `private_uploads` แต่ `downloadAttachment()` อ่านจาก disk `public` ปุ่มดาวน์โหลดจึงตอบ 404 กับไฟล์แนบทุกไฟล์ (เทสต์เดิม `Storage::fake('public')` ผิด disk จึงไม่จับ) — แก้ให้อ่าน disk เดียวกับที่เขียน พร้อม `Cache-Control: private, no-store` และ `X-Content-Type-Options: nosniff`
- **SEC-025 IDOR ของ read/state** — `POST /documents/{id}/read` และ `/documents/{id}/state` ผ่านเพียง `permission:saraban.document.view` ระดับ route แล้วเขียนแถวสถานะจาก id ที่ส่งมาตรง ๆ ผู้ใช้ที่ล็อกอินคนใดก็ได้จึงไล่เลข id เพื่อผูกตัวเองกับหนังสือของหน่วยงานอื่น เขียนทับ `read_at` ซึ่งเป็นหลักฐานการรับทราบ และยืนยันการมีอยู่ของหนังสือลับได้ — ทั้งสองเส้นทางใช้ route model binding + `DocumentPolicy::view` + middleware `saraban.classified` แล้ว
- **SEC-026 การเวียนหนังสือ** — `POST /documents/{id}/circular` รับ `user_ids` มาเท่าไรก็สร้างผู้รับเท่านั้นโดยไม่ตรวจสิทธิ์ผู้รับเลย ขณะที่ `/circulars/incoming` ส่งเนื้อหนังสือกลับไปให้ผู้รับ การเวียนจึงเป็นทางลัดส่งหนังสือ (รวมถึงหนังสือชั้นความลับ) ไปให้ใครก็ได้ — ผู้รับต้องมี `saraban.document.view` และต้องมี `saraban.document.view_classified` เมื่อหนังสือเป็นชั้นความลับ มิฉะนั้นตอบ 422 ทั้งคำขอ
- **SEC-027 บัญชีทดสอบบนหน้าเข้าสู่ระบบ** — หน้า login มีปุ่ม "บัญชีทดสอบ" ที่กรอกทั้งอีเมลและ **รหัสผ่าน** ของบัญชี seed ทั้ง 6 บทบาทให้ทันที รวมถึงผู้ดูแลระบบสูงสุด และถูก build ติดไปกับหน้าเว็บ production ใครก็ตามที่เปิดหน้าเข้าสู่ระบบได้จึงเข้าเป็น Super Admin ได้ในคลิกเดียวหากฐานข้อมูลนั้นยังมีบัญชี seed อยู่ — ปิดไว้หลัง `import.meta.env.DEV` ทั้งแผงและตารางบัญชีถูกตัดออกจาก bundle ของ production และ CI ตรวจซ้ำว่าไม่มีค่าเหล่านี้ใน `dist/`
- **DEPLOY-006 Vite dev server ใน production compose** — `docker-compose.yml` (ไฟล์เดียวกับที่ `docs/deployment/PRODUCTION-DEPLOYMENT.md` สั่งให้ใช้ deploy) มี service ที่รัน `npm run dev -- --host 0.0.0.0` และ publish พอร์ต 5173 ออกทุกอินเทอร์เฟซ ซึ่งเป็น dev server ที่ไม่มี authentication, เสิร์ฟ source ดิบและ source map ทั้งโครงการ, และเปิด websocket HMR — ย้ายไป `docker-compose.dev.yml` ที่ต้องสั่งใช้อย่างชัดเจนและผูกพอร์ตไว้ที่ 127.0.0.1 เท่านั้น ส่วน compose หลักเหลือ job `frontend_build` (profile `build`) ที่สร้าง `frontend/dist` แล้วจบการทำงาน

#### Fixed
- **AppButton.vue พัง** — `props.block ? "w-full' : ''` เครื่องหมายคำพูดไม่ตรงกัน เป็น syntax error ที่ทำให้ปุ่มกลางของระบบคอมไพล์ไม่ผ่าน
- **หน้ารายละเอียดหนังสือสารบรรณเปิดไม่ได้เลย** — `import { ICONS }` ตกค้างอยู่ **ภายใน `<template>`** ไม่ใช่ใน `<script setup>` ทำให้ `ICONS` เป็น undefined และหน้าล้มด้วย `Cannot read properties of undefined (reading 'document')` ตั้งแต่เรนเดอร์แรก
- **หน้าทะเบียนหนังสือเปิดไม่ได้เลย** — `useSarabanDocumentStore`, `useAuthStore`, `useRoute`, `api`, `Swal` ถูกใช้โดยไม่ได้ import
- **ลงลายเซ็นแบบวาดใช้ไม่ได้** — `SignaturePad` และ `nextTick` ถูกใช้โดยไม่ได้ import (`signature_pad` เป็น dependency ที่ไม่เคยถูก import ที่ใดเลย)
- **REPORT-005 รายงานสถิติการลาผูกกับชื่อไทยของประเภทการลา** — SQL เดิมเทียบ `leave_types.name LIKE '%ป่วย%'` ตรง ๆ ผู้ดูแลเปลี่ยนชื่อประเภทการลาจากหน้าจอเมื่อไร ยอดในรายงานก็หายไปเงียบ ๆ เมื่อนั้น และประเภทที่ผู้ดูแลเพิ่มเองไม่มีทางเข้ารายงานได้ — ย้ายมายึด `leave_types.code_prefix` ผ่าน `config/leave_reporting.php` + `LeaveReportCategoryResolver` (ประเภทเก่าที่ยังไม่มีรหัสยังนับได้ผ่านทางถอยด้วยชื่อ)

#### Changed
- ข้อความตอบกลับ API ที่ยังเป็นภาษาอังกฤษ 20 รายการ (สารบรรณ / หน่วยงาน / การแจ้งเตือน / ตั้งค่า / ออกจากระบบ) แปลเป็นภาษาไทย
- ป้ายชื่อบทบาทบนแถบเมนูเคยแสดงค่าดิบจากฐานข้อมูล (เช่น `Document Officer`) — แปลงเป็นชื่อไทยที่ชั้นแสดงผล โดยไม่แตะ `roles.name` ที่ server ใช้ตัดสินสิทธิ์
- ป้าย/หัวข้อภาษาอังกฤษล้วนในหน้าตั้งค่าอีเมล–Telegram, จัดการผู้ใช้, โปรไฟล์, ลงเวลา, จัดการ workflow และหน้าเข้าสู่ระบบ แปลเป็นภาษาไทย และลบคอมโพเนนต์ตัวอย่างของ Vite (`HelloWorld.vue`) ที่ไม่มีใครใช้ทิ้ง
- `DocumentPolicy::isInvolved()` นับผู้ได้รับหนังสือเวียนเป็นผู้เกี่ยวข้องด้วย — เดิมระบบเวียนหนังสือขัดกันเอง คือส่งเนื้อหนังสือกลับไปให้ผู้รับแต่ผู้รับกดเปิดหน้ารายละเอียดแล้วได้ 403

#### Added
- **Quality tooling ฝั่งหน้าเว็บ** (เดิมไม่มีเลย มีแต่ `npm run build`) — ESLint 10 + eslint-plugin-vue (flat config ระดับ "จับบั๊กจริง"), `vue-tsc --noEmit` เป็นด่านคอมไพล์ SFC ทั้งโครงการ, Vitest + jsdom พร้อมเทสต์ 14 เคสของ `errorHandler` และ `secureFile` ทั้งหมดถูกผูกเข้า CI
- ทำความสะอาดโค้ดตายที่ linter จับได้ 97 จุด รวมถึงตัวช่วยที่ยังประกอบ URL `/storage/...` ตรง ๆ (แนวทางที่ SEC-001 เลิกใช้แล้ว)
- Test suite ใหม่: `DocumentStateAuthorizationTest` (IDOR read/state 8 เคส), `DocumentAttachmentTest` (upload→download round-trip + ไฟล์อันตราย 12 เคส), `CircularAuthorizationTest` (7 เคส), `LeaveReportCategoryTest` (5 เคส), `RuntimeSecurityConfigurationTest` (ตรึงค่าความปลอดภัยระดับ runtime/deployment 8 เคส) และขยาย `ProductionEnvironmentGuardTest`


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
