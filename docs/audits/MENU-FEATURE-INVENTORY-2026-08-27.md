# รายงานสำรวจเมนู ฟีเจอร์ ฟังก์ชัน และความซ้ำซ้อน

วันที่สำรวจ: 2026-08-27 · branch `chore/remove-saraban-module` · commit `cd34f41`
ขอบเขต: `frontend/src` (Vue 3) + `backend/app`, `backend/routes`, `backend/database/seeders` (Laravel)
วิธีนับ: เมนู = รายการในแถบเมนูจริงของ `App.vue` · ฟีเจอร์ = หน้าจอ (route) · ฟังก์ชัน = API endpoint ที่ผูกกับกลุ่มนั้น

---

## 1. ภาพรวมเชิงตัวเลข

| รายการ | จำนวน |
|---|---|
| กลุ่มเมนูหลักในแถบเมนู | 5 กลุ่ม (+ โปรไฟล์ผ่านการ์ดผู้ใช้) |
| รายการเมนูที่ผู้ใช้เห็นได้ | 33 รายการ |
| Route ทั้งหมดใน router | 45 (auth 39 · guest 3 · redirect 1 · public/error 2) |
| หน้าจอที่มีเมนูชี้ถึง | 33 จาก 39 หน้า auth → **6 หน้าไม่มีทางเข้าจากเมนู** |
| API endpoint | 185 บรรทัดประกาศ (~189 endpoint จริง เพราะ `apiResource` = 5) |
| Controller | 49 ไฟล์ · Service 60+ คลาส · Model 55 ไฟล์ · Migration 101 |
| ไฟล์ view | 43 ไฟล์ · 13,028 บรรทัด |

---

## 2. เมนู → ฟีเจอร์ → ฟังก์ชัน

### 2.1 หน้าหลัก — 1 ฟีเจอร์ · 22 ฟังก์ชันพื้นฐาน

| ฟีเจอร์ | Route | API ที่หน้าเรียก |
|---|---|---|
| แผงควบคุม | `/` | 2 |

ฟังก์ชันพื้นฐานที่ทุกเมนูใช้ร่วมกัน (22 endpoint): login/logout/refresh-token, ลืม-รีเซ็ตรหัสผ่าน, `/user`, โปรไฟล์ 4 (ดู/แก้/สำเนาข้อมูลส่วนบุคคล/ประวัติการเข้าถึง), การมอบหมายแทน 2, การตั้งค่าสาธารณะ, dashboard, การแจ้งเตือน 5, ดาวน์โหลดไฟล์, ปีงบประมาณปัจจุบัน, ping

### 2.2 ระบบลางาน — 11 ฟีเจอร์ · 55 ฟังก์ชัน

| ฟีเจอร์ | Route | API ที่หน้าเรียก |
|---|---|---|
| ยื่นใบลา | `/request` | 1 (+ store) |
| รายการใบลาของฉัน | `/leaves` | 2 |
| บริการและเอกสารใบลา | `/leave-services` | 3 |
| ปฏิทินวันลาทีม | `/team-calendar` | 1 |
| รายงานสถิติการลา | `/reports` | 5 |
| บัตรลา | `/leave-card` | 3 |
| อนุมัติใบลา | `/approvals` | ผ่าน store |
| อนุมัติยกเลิกใบลา | `/cancel-approvals` | ใช้ view เดียวกัน (props `isCancel`) |
| จัดการ/ซิงค์วันลา (HR) | `/admin/leaves-management` | 4 |
| คัดกรองเลื่อนเงินเดือน | `/admin/promotion-screening` | 3 |
| ศูนย์งานกำกับการลา | `/admin/leave-governance` | 6 |

ฟังก์ชันฝั่งหลังบ้าน 55 endpoint กระจายอยู่ใน 14 controller: `LeaveQueryController`, `LeaveRequestController`, `LeaveCancellationController`, `LeaveWorkflowActionController`, `LeaveDocumentController`, `LeaveEvidenceController`, `LeaveGovernanceController`, `LeaveGovernanceWorklistController`, `LeaveAdjustmentController`, `LeaveCardController`, `PromotionScreeningController`, `GovernmentApprovalController`, `GovernmentLeaveReportController`, `ApprovalController`

### 2.3 ระบบลงเวลาออนไลน์ — 6 ฟีเจอร์ · 30 ฟังก์ชัน

| ฟีเจอร์ | Route | API ที่หน้าเรียก |
|---|---|---|
| ลงเวลาปฏิบัติงาน | `/attendance/check-in` | 6 |
| ประวัติการลงเวลา | `/attendance/history` | 1 |
| รายงานการลงเวลา | `/admin/attendances` | 5 |
| สรุปผลการปฏิบัติงาน | `/admin/attendances/summary` | 4 |
| คำสั่งปฏิบัติงานล่วงเวลา (OT) | `/admin/overtime-orders` | 8 |
| สรุปผลการปฏิบัติงาน (OT) | `/admin/ot-summary` | 4 |

หมายเหตุ: ทั้งกลุ่มถูกซ่อนทั้งหมดเมื่อ `settingStore.isAttendanceEnabled` เป็นเท็จ

### 2.4 จัดการผู้ใช้งาน — 4 ฟีเจอร์ (แสดง 3) · 18 ฟังก์ชัน (+4 จาก `apiResource`)

| ฟีเจอร์ | Route | API ที่หน้าเรียก | สถานะ |
|---|---|---|---|
| รายชื่อผู้ใช้งาน | `/admin/users` | 9 | ใช้งาน |
| กำหนดประเภทบุคลากร | `/admin/personnel-assignments` | 3 | ใช้งาน |
| จัดการบทบาทและสิทธิ์ | `/admin/roles` | 5 | ใช้งาน |
| จัดการเมนูตามสิทธิ์ | `/admin/menus` | 2 | **ซ่อนเมนูไว้** (คอมเมนต์ใน `App.vue:332`) |

### 2.5 ตั้งค่าระบบ — 12 ฟีเจอร์ (+2 หน้าลูก) · 59 ฟังก์ชัน

| กลุ่มย่อย | ฟีเจอร์ | Route | API |
|---|---|---|---|
| ทั่วไป | ข้อมูลสถานศึกษาและแบบฟอร์ม | `/admin/settings` | 5 |
| ทั่วไป | ปีงบประมาณ | `/admin/fiscal-years` | 4 |
| ทั่วไป | ปฏิทินวันทำการ | `/admin/work-calendar` | 5 |
| ทั่วไป | จัดการแผนกวิชา | `/admin/departments` | 7 |
| ระบบลา | นโยบายสิทธิการลา | `/admin/leave-policies` | 7 |
| ระบบลา | ประเภทการลา | `/admin/leave-types` | 5 |
| ระบบลงเวลา | ตั้งค่าระบบลงเวลา | `/admin/attendance-settings` | 2 |
| ระบบ | จัดการลำดับการอนุมัติ | `/admin/workflow` | 5 |
| ระบบ | การแจ้งเตือน | `/admin/notifications` | 4 |
| ระบบ | จัดการแม่แบบเอกสาร | `/admin/document-templates` | 7 |
| ระบบ | ศูนย์จัดการเอกสารและรายงาน | `/admin/document-center` | 15 |
| ระบบ | └ ออกแบบรายงาน | `/admin/document-center/reports` | 5 |
| ระบบ | └ ออกแบบแบบฟอร์มเอกสาร | `/admin/document-center/templates/:id` | 10 |
| ระบบ | โมดูลระบบ | `/admin/modules` | 2 |

### 2.6 หน้าที่ไม่มีเมนูชี้ถึง (6 หน้า)

`/profile` (เข้าจากการ์ดผู้ใช้) · `/profile/access-history` · `/admin/menus` (ซ่อน) · `/admin/document-center/reports` · `/admin/document-center/templates/:id` (เข้าจากหน้าแม่) · `/verify-leave` (เข้าจาก QR)

---

## 3. จุดที่ซ้ำซ้อน (เรียงตามผลกระทบ)

### D1 — ระบบเมนูมี 2 ชุด และชุดที่ใช้จริงเขียนตายตัว 2 ก๊อป 🔴 สูง

- แถบเมนูจริงเขียน hard-code อยู่ใน `frontend/src/App.vue` (1,097 บรรทัด) และเขียน **ซ้ำอีกชุดสำหรับจอมือถือ** — รวม 80 `<router-link>` ที่ต้องแก้คู่กันทุกครั้ง เงื่อนไขสิทธิ์ก็ถูกคัดลอกซ้ำทั้งสองชุด
- ขณะเดียวกันมีระบบเมนูไดนามิกครบชุดที่ **ไม่มีใครใช้**: ตาราง `menus`, `Menu` model, `MenuController` (4 method), `MenuService`, `MenuSeeder`, `stores/menuStore.js`, endpoint `GET /user/menus`, `GET/PUT /admin/menus`
- `MenuSeeder` ยังชี้ไปยัง route ที่ไม่มีอยู่จริง: `/dashboard`, `/calendar`, `/attendance/me`, component `views/Dashboard.vue` — ข้อมูลเมนูใน DB จึงพาไปหน้า 404 เกือบทั้งหมดถ้าเปิดใช้
- หน้า "จัดการเมนูตามสิทธิ์" (`MenusManagementView`) แท้จริงเรียกแค่ `/admin/permissions/modules` และ `/admin/permissions/user-counts` — **ไม่ได้จัดการเมนูเลย** ชื่อเมนูกับสิ่งที่ทำไม่ตรงกัน
- ขัดกับข้อบังคับใน `AGENTS.md`: "ห้าม hard-code policy, workflow, **menu**, organization name หรือ personnel type"

### D2 — ระบบเอกสารมี 2 ชุดคู่ขนาน 🔴 สูง

| ชุดเก่า | ชุดใหม่ |
|---|---|
| `DocumentTemplateController` (8 method) · 7 endpoint | `DocumentEngineController` (23 method) · 22 endpoint |
| model `DocumentTemplate` | model `DocumentTemplateVersion` + `Models/Document/*` อีก 7 model |
| `DocumentTemplatesView` (395 บรรทัด) | `DocumentCenterView` + `DocumentDesignerView` (1,204 บรรทัด) |
| `DefaultDocumentTemplateService`, `LeaveDocumentTemplateResolver` | `Services/DocumentEngine/*` (11 คลาส + Providers) |

ทั้งสองชุดปรากฏเป็นเมนูแยกกันในกลุ่ม "ตั้งค่าระบบ → ระบบ" และ `LeaveTypesManagementView` ยังผูกกับชุดเก่าอยู่ ผู้ดูแลระบบต้องเดาว่าจะแก้แม่แบบที่หน้าไหน

### D3 — ระบบรายงาน/ส่งออกมี 5 ชุด (2 ชุดตายสนิท) 🔴 สูง

| ชุด | endpoint | ใช้จากหน้าเว็บ? |
|---|---|---|
| `ReportController` (`/reports/summary`, `export-excel`, `export-pdf`) | 3 | **ไม่มีเลย** |
| `ReportingController` + `Services/Reporting/*` (7 คลาส, 8 ชนิดรายงาน) | 3 | **ไม่มีเลย** |
| `GovernmentLeaveReportController` | 3 | ใช้ใน `ReportsView` |
| `DocumentReportController` + `ReportDesignerView` | 4 | ใช้ |
| `AttendanceController` ส่งออกเอง (preview/pdf/word/excel ของ summary และ OT summary) | 8 | ใช้ |

เครื่องมือ export ก็มี 3 ชุด: `Services/Export/{LeaveExcelService,LeaveWordService}`, `Services/Reporting/ReportExportService`, `Services/DocumentEngine/ReportEngine` — ตรรกะหัวกระดาษ/ตราครุฑ/ฟอนต์ไทยถูกทำซ้ำในหลายที่

เพิ่มเติม: `LeaveCardController`, `PromotionScreeningController`, `OvertimeReportController` เป็นรายงานเฉพาะกิจอีก 3 ตัวที่ไม่ผ่านชั้นรายงานกลาง

### D4 — Workflow อนุมัติมี 2 ชุด และยังไม่ cutover 🟠 กลาง-สูง

- ชุดเดิม: `ApprovalStep`, `LeaveApproval`, `LeaveActionLog` — **ยังเป็น source of truth ของใบลา**
- ชุดใหม่: `Services/Workflow/WorkflowEngine` + `Models/Workflow/*` (9 model) — ใช้จริงแล้วกับ **การแก้ไขเวลา** และ **คำสั่ง OT** แต่กับใบลายังเดินแบบ shadow (`LeaveWorkflowShadow`) เท่านั้น
- ผลคือกฎการอนุมัติถูกดูแล 2 ที่ และปุ่มอนุมัติกระจายใน 6 controller: `GovernmentApprovalController`, `LeaveCancellationController`, `LeaveWorkflowActionController`, `LeaveAdjustmentController`, `AttendanceCorrectionController`, `OvertimeOrderController`
- สถานะนี้เป็นไปตามแผน (`CURRENT_PHASE.md` ระบุว่า cutover 05-4 ต้องขออนุมัติแยก) แต่ค้างนานเท่าไรก็เป็นหนี้ทางเทคนิคเท่านั้น

### D5 — สิทธิ์มี 2 ชุดชื่อ ถูก OR กันเกือบทุกจุด 🟠 กลาง

- Route ส่วนใหญ่เขียนแบบ `permission:manage_settings|system.setting.manage`, `view_users|system.user.view`, `edit_users|system.user.manage`
- ฝั่งหน้าเว็บก็ต้อง `hasAnyPermission([...])` สองชื่อทุกจุด ทั้งใน router meta และใน `App.vue` ทั้ง 2 ก๊อป
- Seeder สิทธิ์มี 5 ตัวที่ทับซ้อนกัน: `RolesAndPermissionsSeeder` (ใช้จริง), `StandardRolesAndPermissionsSeeder` (คอมเมนต์ในโค้ดระบุเองว่า "ทับซ้อน"), `PurgeAndMigrateTo7StandardRolesSeeder` (one-off ที่ลบข้อมูล), `ModulePermissionSeeder`, `OperationalPermissionSeeder`
- นิยามโมดูลมี 2 ไฟล์: `config/modules.php` (สิทธิ์ต่อโมดูล) และ `config/module_registry.php` (ทะเบียนโมดูล + `legacy_permissions`) ซึ่งรายการสิทธิ์ไม่ตรงกัน

### D6 — Audit log มี 3 ระบบ 🟠 กลาง

`AuditLog` + `AuditLogger` · `LeaveAuditLog` + `LeaveAuditService` · `LeaveActionLog` · (`ApprovalLog` เป็น model ที่ไม่มีใครเรียกแล้ว) — การสืบย้อนเหตุการณ์หนึ่งครั้งต้องเปิดหลายตาราง

### D7 — Setting/Export setting ซ้ำชั้น 🟡 ต่ำ

`SecureSettingController` (ใช้) คู่กับ `SettingController` (ไม่มี route) · `SecureExportSettingController` (ใช้) คู่กับ `ExportSettingController` (ไม่มี route) · หน้า "ตั้งค่าแบบฟอร์ม & ส่งออก" ถูกยุบเป็นแท็บของ `/admin/settings` แล้ว แต่ API ยังแยกเป็น 3 endpoint ของตัวเอง

### D8 — Notification มี 3 ชั้น 🟡 ต่ำ

`Services/NotificationCenter/*` (มี `TelegramChannelAdapter`, `LineOaChannelAdapter` อยู่แล้ว) แต่ยังเหลือ `TelegramNotificationService`, `TelegramService`, `LineNotificationService` ที่ระดับ root และ `SecureSettingController` ยังเรียกใช้ตัวเก่าอยู่

### D9 — โค้ดตายที่ลบได้ทันที 🟢 ความเสี่ยงต่ำ

| ไฟล์ | เหตุผล |
|---|---|
| `app/Http/Controllers/LeaveController.php` | 0 method · ไม่มี route |
| `app/Http/Controllers/LeaveAttachmentController.php` | 0 method · ไม่มี route |
| `app/Http/Controllers/SettingController.php` | ไม่มี route (ถูกแทนด้วย Secure*) |
| `app/Http/Controllers/Admin/ExportSettingController.php` | ไม่มี route |
| `app/Models/LeaveRequest.php` | ไม่มีการอ้างอิงในโค้ดเลย |
| `app/Models/ApprovalLog.php` | ไม่มีการอ้างอิงในโค้ดเลย |
| `app/Models/Attachment.php` | ไม่มีการอ้างอิงในโค้ดเลย |

### D10 — หน้าจอเนื้อหาทับกัน 🟡 ต่ำ

- `/leaves` (รายการใบลาของฉัน) กับ `/leave-services` (บริการและเอกสารใบลา) — ทั้งคู่คือ "ใบลาของฉัน" ต่างกันแค่ปุ่มบริการ ผู้ใช้ทั่วไปเห็นสองเมนูที่ดูเหมือนกัน
- `AttendanceSummaryView` กับ `AttendanceOTSummaryView` โครงเดียวกันเกือบทั้งไฟล์ ต่างที่ endpoint และคอลัมน์
- `/admin/document-templates` กับ `/admin/document-center` (ดู D2)

### D11 — `DocumentTemplatesView.vue` เรียก `axios` ดิบ 🟠 กลาง

เป็นไฟล์เดียวในทั้งโปรเจกต์ (จาก 46 ไฟล์ที่เรียก API) ที่ `import axios from 'axios'` แทนที่จะใช้ client กลาง `src/axios.js` → ไม่ได้ผ่าน interceptor แนบ token, การ refresh token อัตโนมัติ และการจัดการ error กลาง

---

## 4. ข้อเสนอแผนอัปเดต

### รอบที่ 1 — เก็บกวาดความเสี่ยงต่ำ (ไม่กระทบผู้ใช้)
1. ลบโค้ดตายตาม D9 (4 controller + 3 model) พร้อมเพิ่มเทสต์กันคืนกลับ
2. รวม `TelegramService`/`TelegramNotificationService`/`LineNotificationService` เข้า `NotificationCenter` adapter แล้วให้ `SecureSettingController` เรียกผ่านช่องทางเดียว (D8)
3. แก้ `DocumentTemplatesView.vue` ให้ใช้ api client กลาง (D11)
4. ตัดสินใจเรื่องรายงานที่ไม่มีใครเรียก (D3): เปิดใช้ `/reporting/*` แทนของเดิม หรือลบทิ้ง — **ต้องเลือกทางเดียว**

### รอบที่ 2 — รวมแหล่งความจริงของเมนู (D1)
5. ให้ `MenuSeeder` สะท้อน route จริงทั้ง 33 รายการ (code/route_name/route_path/permissions ตรงกับ `router/index.js`)
6. เขียน `AppSidebar.vue` ตัวเดียวที่ render จาก `menuStore` และให้จอมือถือใช้ component เดียวกัน — ตัดโค้ดซ้ำ 80 `<router-link>` ออกจาก `App.vue`
7. ทำ `MenusManagementView` ให้จัดการเมนูจริง แล้วเปิดเมนูที่ซ่อนไว้กลับมา
8. เพิ่มเทสต์: ทุก route ที่ `requiresAuth` ต้องมี record ในตาราง `menus` และ permission ตรงกับ router meta

### รอบที่ 3 — รวมระบบเอกสารและรายงาน (D2, D3)
9. ย้ายแม่แบบใน `DocumentTemplate` เข้าเป็น version ของ Document Engine ด้วย migration (ห้ามลบของเดิมก่อนย้ายครบ)
10. ตัดเมนู "จัดการแม่แบบเอกสาร" ให้เหลือทางเข้าเดียวคือ "ศูนย์จัดการเอกสารและรายงาน" และเปลี่ยน `LeaveTypesManagementView` ไปใช้ engine ใหม่
11. ให้รายงานทุกตัว (บัตรลา, คัดกรองเลื่อนเงินเดือน, ทะเบียนลา, สรุปลงเวลา, OT) ผ่าน `ReportingDataService` ชั้นเดียว และเหลือ export service ชุดเดียว
12. ยุบ `/leave-services` เข้า `/leaves` เป็นแท็บ (D10) และรวม `AttendanceSummaryView`/`AttendanceOTSummaryView` เป็น component เดียวที่รับ config

### รอบที่ 4 — ต้องขออนุมัติก่อน (มีความเสี่ยงต่อข้อมูล)
13. **Workflow cutover 05-4** (D4) — ย้ายใบลามาใช้ `WorkflowEngine` แล้วเลิกใช้ `ApprovalStep` — ตาม `CURRENT_PHASE.md` ต้องได้รับอนุมัติแยกและ shadow ต้องตรง 100% ต่อเนื่อง
14. **ถอดชื่อสิทธิ์ชุดเก่า** (D5) — หลัง cutover ค่อยลบ `manage_settings`/`view_users`/`edit_users` ออกจาก route, router meta และ `App.vue` แล้วเหลือ seeder สิทธิ์ตัวเดียว
15. **รวม audit log** (D6) เป็นตารางเดียวพร้อม migration ที่ย้ายประวัติเดิมครบ — ห้ามลบ record ประวัติตามข้อบังคับ

### สิ่งที่ต้องหยุดถามก่อนทำ (ตาม Stop conditions ใน `CLAUDE.md`)
- ข้อ 13, 14, 15 เปลี่ยน architecture หลัก / มีความเสี่ยง schema conflict
- ข้อ 4 (รายงานที่ไม่มีใครเรียก) ต้องให้เจ้าของระบบยืนยันว่ารายงานชุดไหนคือชุดที่ต้องใช้จริง
