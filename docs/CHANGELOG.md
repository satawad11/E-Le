# CHANGELOG

All notable changes to the E-la project will be documented in this file.

## [Unreleased]

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
