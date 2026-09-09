# ตรวจการตั้งค่า แบบฟอร์ม API และรายงานระบบลา

วันที่ 2026-09-07 · ขอบเขต: ตรวจโค้ด, routes/permissions, ฐานข้อมูลที่ติดตั้ง, หน้าจอ และ automated tests; ไม่แก้ application code หรือข้อมูลจริงในรอบตรวจนี้

## ข้อสรุป

ส่วนประกอบหลักมีครบ แต่ยังรับรองว่าการตั้งค่าและเอกสารทำงานสอดคล้องกันทั้งหมดไม่ได้ พบข้อผิดพลาดที่ยืนยันด้วย regression tests 8 กรณีใน 6 กลุ่ม และพบวันที่เลื่อนจากหน้าจอจริง รวมถึงข้อมูลเวอร์ชันแบบฟอร์มที่ยังไม่ครบในฐานข้อมูล

การสร้าง PDF ของประเภทลาทั้ง 12 ประเภทและใบยกเลิกผ่าน 13/13 กรณี หมายถึง renderer ออก PDF ได้ ไม่ใช่การรับรองความครบถ้วนของเนื้อหา ระเบียบ หรือรูปแบบทุกหน้า

## ภาพรวมแต่ละส่วน

| ส่วน | Frontend | Backend / API | ผลตรวจ |
|---|---|---|---|
| ข้อมูลสถานศึกษา ผู้บริหาร ค่าทั่วไป | AdminSettingsView; LeavePoliciesView | SecureSettingController / SystemSettingService; `/admin/settings` | มี validation, secret masking, transaction และ audit; แต่ค่าฟอนต์/ขอบไม่ได้ถูกใช้ในแบบลามาตรฐานทุกเส้นทาง |
| ประเภทลาและช่องเพิ่มเติม | LeaveTypesManagementView | LeaveTypeController; `/admin/leave-types` | เพิ่ม/แก้/ผูกแบบฟอร์มและ extra fields ได้; ไม่มีแบบเฉพาะ 3 ประเภท และช่องวันที่/ตัวเลือกตรวจชนิดไม่ครบ |
| นโยบายสิทธิ | LeavePoliciesView | LeavePolicyController / LeavePolicyService; `/admin/leave-policies` | มี 48 policies สำหรับบุคลากร 4 ประเภท แต่การแก้ย้อนหลังและ sync ตามช่วงเวลายังไม่ปลอดภัย |
| ปีงบประมาณ | FiscalYearManagementView | FiscalYearController; `/admin/fiscal-years` | CRUD ครบ แต่ลบปีที่ใบลาอ้างอยู่ได้; ไม่มี validation กันช่วงปีซ้อนใน controller |
| ปฏิทิน | WorkCalendarManagementView | WorkCalendarController; `/admin/work-calendar` | ตั้ง holiday/working_day รายแผนกได้; ไม่มีการป้องกันแก้/ลบประวัติที่ใช้คำนวณแล้วใน CRUD นี้ |
| สายอนุมัติ/ยกเลิก | WorkflowManagementView | WorkflowController + resolver; `/admin/workflow-steps`, `/preview` | มี preview ผู้อนุมัติ, fingerprint ป้องกันเขียนทับ และ snapshot; ไม่พบ blocker ใหม่ในขอบเขตนี้ |
| ศูนย์แบบฟอร์ม | DocumentCenterView / DocumentDesigner | DocumentEngineController; `/admin/document-engine/*` | มี HTML, PDF overlay, versions, fields, bindings, calibration; engine สำหรับใบลายังปิดใน runtime และ 8/9 templates ไม่มี version |
| ส่งออกใบลา/ยกเลิก | MyLeavesView / LeaveManagementView | LeaveDocumentController; `/leaves/{leave}/pdf`, `/word` | ตรวจสิทธิ์ view ฝั่ง server; PDF render ได้ แต่ pin เปลี่ยนตาม type mapping ได้ และ Word MIME ไม่ตรงกับไฟล์ที่ UI ตั้งชื่อ |
| บัตรลา | LeaveCardView | LeaveCardController; `/reports/leave-card`, `.pdf` | มี quota/used/ครั้ง/วัน และ PDF; ใช้เลขปี ค.ศ. ตรงกับ balance ต่างจากรายงานกลางที่พบปัญหา |
| ทะเบียนคุม/สรุป | ReportsView | GovernmentLeaveReportController; `/reports/government-leave-register`, `.csv`, `/government-leave-summary` | มี scope, รวมรายการถูกลบ, CSV ครบทุกแถว; summary 12 เดือนแยกจาก filter ตาราง |
| รายงานกลาง/รายงานออกแบบ | ReportCenterView / ReportDesignerView | ReportingController, DocumentReportController; `/reporting/*`, `/admin/document-reports/*` | มี PDF/XLSX/DOCX/CSV ในรายงานกลางและ export audit; รายงาน balance ค้นเลขปีผิดจึงว่างแม้มีข้อมูล |

ชื่อไฟล์ปฏิทินและ designer ตรวจจากรายการ source ประกอบด้านล่าง; ห้ามตีความว่าการมี route หรือ build ผ่านเป็นการรับรองทุก business rule

## ปัญหาที่ยืนยันแล้วและแนวแก้

### LS-01 · P1 — แก้นโยบายที่ใบลาอนุมัติอ้างอยู่แบบเขียนทับ

`backend/app/Http/Controllers/Admin/LeavePolicyController.php:32` ใช้ `$policy->update(...)` กับแถวเดิม ไม่มี version ใหม่หรือห้ามแก้แถวที่ถูกใช้งานแล้ว Test เปลี่ยน fiscal_year เป็น calendar_year แล้วใบอนุมัติเดิมอ่านฐานรอบใหม่ทันที ทำให้การอ้างนโยบายย้อนหลังไม่คงที่

แนวแก้: รักษาแถวที่ถูกอ้างอิง สร้างนโยบายรุ่นใหม่พร้อมช่วงมีผล หรือปฏิเสธการแก้ค่าหลักของรุ่นที่ถูกใช้ ตรวจช่วงซ้อนและบันทึก audit ใน transaction

### LS-02 · P1 — นโยบายปิด/หมดอายุ/ยังไม่ถึงวันใช้เปลี่ยนยอดปัจจุบัน

`backend/app/Services/LeavePolicyService.php:124` sync quota โดยไม่ตรวจ is_active/effective_to หรือว่านโยบายถูกเลือกใช้จริง ณ วันนั้น Test ทั้ง 3 สถานการณ์ทำ quota ปัจจุบันจาก 30 เหลือ 1 รวมถึงนโยบายมีผล 15 ก.ย. ขณะวันทดสอบคือ 7 ก.ย.

แนวแก้: resolve นโยบายที่มีผลจริงตามบุคลากรและรอบยอดก่อน sync; อ้าง personnel assignment ณ วันที่มีผล ไม่ยึดเฉพาะ users.personnel_type_id; ให้การเขียน policy กับ sync สำเร็จหรือ rollback พร้อมกัน

### LS-03 · P1 — เปิดแก้นโยบายแล้ววันที่ถอยหนึ่งวัน

`frontend/src/views/admin/LeavePoliciesView.vue:49` ใช้ `.slice(0,10)` กับ ISO UTC ที่ API ส่งมา Browser ยืนยันรายการแสดง **1 ต.ค. 2568** แต่ช่อง “มีผลตั้งแต่” ใน modal เป็น **2025-09-30** แม้ไม่ได้เปลี่ยนค่า เกิดจากการตัด `2025-09-30T17:00:00Z` แทนการอ่านวันที่ท้องถิ่น

แนวแก้: API ส่ง date-only สำหรับ business date และ UI ใช้ date formatter ร่วมกัน; เพิ่ม round-trip test ว่าเปิดแล้วบันทึกโดยไม่เปลี่ยนวันที่ต้องได้วันเดิม

### LS-04 · P1 — เปลี่ยนการผูกแบบฟอร์มแล้วใบเก่าเลิกใช้ version ที่ pin

`LeaveDocumentController.php:42` เลือก view จากประเภทลาปัจจุบัน; `LeaveDocumentTemplateResolver.php:76` ยอมรับ pinned version ต่อเมื่อ code ตรงกับ mapping ปัจจุบันเท่านั้น Test pin ORIGINAL_FORM แล้วเปลี่ยน type mapping กลับเรนเดอร์ REPLACEMENT_FORM

แนวแก้: ให้ pinned version เป็นตัวกำหนดชนิดและเนื้อหาเอกสารเก่าโดยตรง การเปลี่ยน mapping ใช้กับใบใหม่เท่านั้น และแยกการเลือกแบบใบยกเลิกให้ชัด

### LS-05 · P1 — ลบปีงบประมาณที่ใบลาอ้างอยู่ได้

`backend/app/Http/Controllers/FiscalYearController.php:77` ลบโดยไม่ตรวจการอ้างอิง Test ได้ HTTP 200 แทนการปฏิเสธ 422 การเปลี่ยน/ลบช่วงปีมีผลต่อ resolver และการเปิดดูรายงานย้อนหลัง

แนวแก้: กันลบหรือเปลี่ยนคีย์/ช่วงปีที่ถูกใช้แล้ว, ตรวจช่วงซ้อน และเปลี่ยนปี current ภายใน transaction ที่คงปีปัจจุบันได้หนึ่งรายการ

### LS-06 · P1 — รายงานยอดคงเหลือว่างเพราะใช้ปีคนละระบบ

`ReportingDataService.php:167` ค้น `leave_balances.year` ด้วย `$filter->fiscalYear` ซึ่งมาจากปี พ.ศ. ใน FiscalYear Test เลือกปี 2569 แต่แถว balance เป็น 2026 ได้ rows ว่าง ทั้งที่มี quota 30 และ used 2; runtime มี balance 72 แถวในปี 2026

นอกจากนี้ `ReportingController.php:110` validate fiscal_year เป็น 2000–2200 แต่ ReportFilter ตีความเลขปีเป็น พ.ศ. จึงต้องตกลงรูปแบบ API ให้ตรงกันทั้งพารามิเตอร์ year และ fiscal_year_id

แนวแก้: แยกปีที่แสดงกับคีย์ที่ใช้ค้นอย่างชัดเจน ใช้ตัวแปลงร่วมกับ LeavePolicyService และเพิ่มตรวจ UI/API/ทุก export ว่า rows/ยอดตรงกัน

### LS-07 · P2 — Word ที่ดาวน์โหลดไม่ใช่ DOCX ตามที่ UI ระบุ

`frontend/src/views/MyLeavesView.vue:584` บังคับชื่อ `.docx` และ MIME DOCX แต่ runtime ไม่มี PhpWord ใน composer dependencies ทำให้ `LeaveDocumentController.php:349` ใช้ HTML `.doc` และตอบ `application/vnd.ms-word` Test ยืนยัน MIME ไม่ตรงจริง ผู้ใช้จึงได้ไฟล์นามสกุลและเนื้อหาคนละชนิด

แนวแก้: กำหนดสัญญา export ให้ตรงกัน เลือกสนับสนุน `.doc` ตาม response หรือทำ DOCX จริงพร้อมตรวจเนื้อหาและ template parity; ไม่ควรเพียงเพิ่ม PhpWord เพราะ native branch ปัจจุบันไม่ใช้ pinned HTML/extra fields และไม่ได้แยกเนื้อหาใบยกเลิก

### LS-08 · P1 — ข้อมูลติดตั้งยังไม่พร้อมสำหรับการ pin แบบฟอร์มครบทุกใบ

หลักฐาน `leave-settings-runtime-2026-09-07.json`: leave templates 9 รายการ มี version เฉพาะ leave_cancel 1 รายการ อีก 8 แบบไม่มี version; engine_enabled=false และ enabled_leave_types=[] แม้มี binding 9 รายการ ดังนั้น UI สำหรับ PDF overlay มีอยู่แต่ยังไม่ใช่เส้นทางออกใบลาที่ใช้งานจริง

แนวแก้: ตรวจ migration/seeding และกู้ประวัติ version จากหลักฐานก่อนผูกใบเก่า ห้ามเติม version ปัจจุบันให้เอกสารย้อนหลังโดยเดา; เตรียม template/field mapping และ acceptance ก่อนเปิด engine ตามขอบเขตที่กำหนด

## ช่องว่างอื่นที่พบจากโค้ดและหน้าจอ

- **การตั้งค่านโยบายไม่ครบใน UI:** API รองรับ minimum_service_days, max_days_per_request, max_carry_forward, max_accumulated, required_evidence_type แต่ฟอร์มไม่มีช่องครบ มี checkbox สะสมวันลาแต่ตอนสร้างส่ง max_carry_forward=0 โดยผู้ใช้แก้เพดานนี้ใน modal ไม่ได้
- **ขอบกระดาษและฟอนต์:** AdminSettingsView บันทึก default_font/margin_* ได้ แต่แบบมาตรฐาน `resources/views/pdf/leave.blade.php:25` ใช้ `@page margin: 8mm 12mm` และ THSarabunNew ตายตัว Resolver ส่งต่อ paper/orientation แต่ไม่ฉีดค่าขอบหรือฟอนต์จาก settings ให้ CSS; ควรกำหนดลำดับ global/template/version แล้วให้ preview กับ export ใช้ค่าชุดเดียวกัน
- **สิทธิ์หน้า/API ไม่ตรง:** route หน้านโยบายอนุญาต leave.policy.manage หรือ system.setting.manage แต่ load เรียกทั้ง API นโยบายที่ต้องมีสิทธิ์แรก และ `/admin/settings` ที่ต้องมีสิทธิ์หลัง ผู้มีสิทธิ์เดียวจะเจอ 403 บางส่วน; หน้าประเภทลาที่มีเพียง manage_leave_types ก็อาจโหลดรายการ template ไม่ได้เพราะเป็นอีก permission
- **Validation/audit:** StoreLeaveRequest บังคับ extra fields ที่ required แต่ date/number/select ยังถูก validate เป็น string โดยไม่ตรวจชนิดและตัวเลือกตาม definition; CRUD LeavePolicy/LeaveType/FiscalYear/WorkCalendar ไม่มี audit call หรือ model observer ที่พบในการไล่เส้นทางนี้ ต่างจาก SystemSettingService และ DocumentEngineController ที่มี audit ชัดเจน
- **สรุปกับตัวกรองรายงาน:** ReportsView โหลด summary months=12 ตอน mounted แยกจาก from/to/department/personnel filters ของทะเบียน เมื่อกรองตารางตัวเลขสรุปด้านบนจึงยังเป็นคนละชุดข้อมูล; ควรใช้ filter เดียวกันหรือแสดงขอบเขตให้ชัด

## แบบฟอร์มครบตามรายการที่ติดตั้ง

| ประเภท | template code ปัจจุบัน | PDF test | ข้อจำกัด |
|---|---|---|---|
| ลาป่วย | leave_sick_personal | ผ่าน | ใช้แบบกลางร่วม 3 ประเภท |
| ลาคลอดบุตร | leave_sick_personal | ผ่าน | ใช้แบบกลางร่วม 3 ประเภท |
| ลากิจส่วนตัว | leave_sick_personal | ผ่าน | ใช้แบบกลางร่วม 3 ประเภท |
| ลาช่วยเหลือภริยาคลอดบุตร | leave_paternity | ผ่าน | มีช่อง wife_name/childbirth_date/level |
| ลาพักผ่อน | leave_vacation | ผ่าน | ต้องตรวจยอดตามรอบให้ตรงกันทุกเส้นทาง |
| ลาอุปสมบท | leave_ordination | ผ่าน | มีช่องเพิ่มเติมวัด/วันที่/ประวัติ |
| ลาประกอบพิธีฮัจญ์ | leave_hajj | ผ่าน | extra_fields ในข้อมูลติดตั้งยังว่าง |
| ลาศึกษาต่อ/ดูงาน/ฝึกอบรม | leave_study | ผ่าน | extra_fields ในข้อมูลติดตั้งยังว่าง |
| ลาติดตามคู่สมรส | leave_spouse | ผ่าน | extra_fields ในข้อมูลติดตั้งยังว่าง |
| ลาตรวจเลือก/เตรียมพล | ไม่ได้ผูก | ผ่านแบบ fallback | ไม่มีแบบเฉพาะ |
| ลาปฏิบัติงานองค์การระหว่างประเทศ | ไม่ได้ผูก | ผ่านแบบ fallback | ไม่มีแบบเฉพาะ |
| ลาฟื้นฟูสมรรถภาพด้านอาชีพ | ไม่ได้ผูก | ผ่านแบบ fallback | ไม่มีแบบเฉพาะ |
| ใบยกเลิกลา | leave_cancel | ผ่าน | พิมพ์ได้เมื่อมีคำขอยกเลิกจริง |

มี official_travel อีกหนึ่ง template ในหมวด leave แต่ไม่มีประเภทลาที่ผูกใช้อยู่ใน 12 ประเภทนี้ จึงไม่ถือว่าเป็นแบบลาประเภทที่ 13

## หลักฐานการตรวจและข้อจำกัด

- เพิ่ม `backend/tests/Feature/LeaveSettingsFormsAuditTest.php`: 8 failed / 13 passed · 68 assertions ไม่มี skipped; failures เป็นข้อกำหนดที่เพิ่มเพื่อยืนยันบั๊กเดิม ไม่ได้แก้ assertions ให้ยอมรับพฤติกรรมผิด
- Baseline ก่อน audit: backend 1,141 passed / 0 skipped ตาม API-SURFACE-FIX-2026-09-07; ผล full suite ใหม่ดู `leave-settings-backend-2026-09-07.log`
- Frontend lint/typecheck/127 tests/build ผ่าน; Pint 680 files ผ่าน ดู `leave-settings-frontend-2026-09-07.log`, `leave-settings-pint-2026-09-07.log`
- Browser เปิดหน้านโยบายและ modal แก้ไขเพื่อยืนยันวันที่; อ่านรายการศูนย์เอกสารเท่านั้น ไม่กดบันทึก และอ่าน runtime เฉพาะ config/catalog/count ที่เกี่ยวข้อง
- Tests ใช้ SQLite :memory: และ templates ตั้งต้น ไม่ใช่ mutation บนฐานจริง ไม่มีการตรวจรับรูปแบบทุกหน้าด้วยภาพหรือทบทวนความถูกต้องตามกฎหมาย และไม่รับรอง MySQL concurrency จาก suite นี้
- ไม่มี application change, migration, API change, dependency installation หรือ deploy ในรอบ audit; เปลี่ยนเฉพาะ regression tests, TASKS/TRACEABILITY/CHANGELOG และหลักฐานรายงาน ไม่เกิด architectural decision ใหม่
