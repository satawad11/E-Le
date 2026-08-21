# DOC-ENGINE: Universal Document & Report Engine — รายงานปิดเฟส

> **สถานะ**: ✅ **DOC-ENGINE-00 ถึง 10 PASS** (เหลือ 05 บางส่วนและ 07 ที่เป็นงานผู้ดูแล ไม่ใช่งานโค้ด)
> **วันที่จัดทำ**: 2026-08-20
> **ระบบเป้าหมาย**: E-la — โมดูลแรกที่ใช้งานคือ E-Leave
> **เอกสารอ้างอิง**: `docs/checkpoints/DOC-ENGINE-00-ASSESSMENT.md`, `docs/14-DOCUMENT-PDF-STANDARDS.md`, `docs/12-AUDIT-LOGGING.md`, ADR-DOC-ENGINE-01

---

## 1. สรุปผู้บริหาร

### ปัญหาเดิม

1. **แบบฟอร์มถูกเลือกด้วยชื่อไทยที่เขียนตายในโค้ด** — ผู้ดูแลเพิ่มหรือเปลี่ยนชื่อประเภทการลาไม่ได้โดยไม่ทำให้พิมพ์ผิดแบบฟอร์ม
2. **เอกสารสร้างจาก HTML ทุกครั้งที่กดพิมพ์** — จัดตำแหน่งให้ตรงแบบฟอร์มราชการทำด้วย CSS ซึ่งเปราะ และผู้ดูแลแก้เองไม่ได้
3. **ไม่เก็บไฟล์เอกสารที่ออกไปแล้ว** — พิมพ์ย้อนหลังคือการสร้างใหม่จากข้อมูล *ปัจจุบัน* คนย้ายแผนกแล้วเอกสารเก่าเปลี่ยนตาม
4. **รายงานเขียนตายใน PHP** — เพิ่มรายงานใหม่ต้องแก้โค้ดทุกครั้ง

### สถาปัตยกรรมหลังปรับปรุง

```text
Business Module (E-Leave)
        ↓  LeaveDocumentGateway  ← Compatibility Layer (ธง + fallback)
Document Engine
        ↓
Binding Rule → Template → Version → Field Mapping
        ↓            ↑
Data Provider (whitelist)  ← Field Dictionary
        ↓
PdfOverlayRenderer (FPDI + ฟอนต์ไทยฝังในระบบ)
        ↓
Document Generated File (sha256 + revision, IMMUTABLE)
```

**หลักการที่ยึดตลอดทั้งเฟส**: ของเดิมต้องทำงานได้เหมือนเดิมเสมอ — ธงปิดโดยค่าเริ่มต้น, engine ล้มเมื่อใดก็ถอยไปเส้นทางเดิมเงียบ ๆ, migration เพิ่มอย่างเดียวไม่ DROP/RENAME, และเอกสารที่ออกไปแล้วห้ามเปลี่ยน

---

## 2. ผลงานรายเฟส

| เฟส | ผลลัพธ์ | สถานะ |
|---|---|---|
| **00** Assessment | สำรวจ 17 หัวข้อ ระบุความเสี่ยง breaking change 12 ข้อ + migration/rollback plan · ไม่แก้โค้ด | ✅ |
| **01** Core schema | 5 migration แบบ additive: `document_type`, `render_mode`, version `status`/`pdf_file_path`/`published_*` + ตารางใหม่ 7 ตาราง | ✅ |
| **02** Renderer | FPDI overlay + ฟอนต์ TH SarabunNew ฝังในระบบ + `CoordinateConverter` + print calibration + feature flag | ✅ |
| **03** Snapshot & Final | `document_generated_files` พร้อม sha256/revision, IMMUTABLE บังคับที่โมเดล, เอกสารย้อนหลังอ่านจากไฟล์ที่เก็บ | ✅ |
| **04** PDF Designer | หน้าออกแบบลาก/ปรับขนาดฟิลด์บนแบบฟอร์มจริง หลายหน้า ซูม พรีวิว เผยแพร่ จัดการเวอร์ชัน | ✅ |
| **05** ย้าย Leave Type | นำแบบฟอร์มราชการ 5 ฉบับเข้าระบบ + วางชุดฟิลด์ตั้งต้น 23–26 ช่อง | 🔶 เหลือจัดตำแหน่งจริง (งานผู้ดูแล) |
| **06** Report Engine | นิยามรายงานในฐานข้อมูล, grouping/subtotal/grand total/aggregate, export PDF/Excel/CSV | ✅ |
| **07** Report Designer | เลือกแหล่งข้อมูล/คอลัมน์/ผลรวม/จัดกลุ่ม และทดลองประมวลผลจากหน้าจอ | ✅ |
| **08** Security/Audit/Test | สิทธิ์ `document.*`, audit ครบทุกการกระทำ, 33 tests เฉพาะ engine | ✅ |
| **09** Consolidation | รวมแม่แบบซ้ำเหลือหนึ่งรายการต่อแบบฟอร์ม, หนึ่งแบบฟอร์มต่อหนึ่งประเภทการลา | ✅ |
| **10** Pinned form | ใบลาพิมพ์ด้วยเวอร์ชันที่ pin ไว้ตอนยื่น — ใบเก่าคงแบบฟอร์มเดิม | ✅ |

---

## 3. Definition of Done เทียบข้อกำหนด

| ข้อกำหนด | สถานะ | หลักฐาน |
|---|---|---|
| เพิ่ม Template ใหม่ได้จาก Admin | ✅ | `test_an_admin_can_upload_map_publish_and_preview_a_form` |
| เพิ่ม Version ใหม่ได้ | ✅ | `test_publishing_retires_the_previous_version_without_deleting_it` |
| Version เก่าไม่กระทบ | ✅ | `test_a_document_keeps_the_version_it_was_pinned_to` |
| เพิ่ม Field Mapping ได้ | ✅ | `test_an_admin_can_upload_map_publish_and_preview_a_form` |
| ภาษาไทยถูกต้อง | ✅ | `test_it_renders_thai_text_onto_the_official_form` (ฟอนต์ฝังในระบบ ไม่พึ่ง host) |
| Signature / Checkbox ถูกต้อง | ✅ | `test_checkbox_and_signature_fields_render_without_failing` |
| Final PDF Immutable | ✅ | `test_a_finalized_document_cannot_be_modified_or_deleted` |
| เอกสารย้อนหลังไม่เปลี่ยน | ✅ | `test_a_historical_document_is_served_from_the_stored_file`, `test_a_leave_submitted_before_the_pdf_form_was_published_keeps_the_old_form` |
| Leave Type เลือก Template อัตโนมัติ | ✅ | `test_binding_rule_picks_the_template_for_a_leave_type` |
| หลาย Leave Type ใช้ Template เดียวกันได้ | ✅ | `test_several_leave_types_can_share_one_template` |
| สร้างรายงานจาก Data Source ได้ | ✅ | `test_an_admin_can_create_a_report_from_the_screen` |
| Export PDF / Excel | ✅ | `test_an_admin_can_run_and_export_a_report` |
| Engine ใช้กับ Module อื่นได้ | ✅ | Engine รับ `module`/`entity_type`/`data_source` เป็นพารามิเตอร์ ไม่มีการอ้าง E-Leave ในชั้น engine |
| Legacy System ยังทำงาน | ✅ | `test_the_engine_is_off_by_default_and_leaves_the_old_path_untouched`, `test_an_unbound_leave_type_falls_back_to_the_existing_renderer` |
| ไม่มี Destructive Migration | ✅ | migration ทั้ง 5 เพิ่มอย่างเดียว มี `down()` ที่ลบเฉพาะของที่เฟสนี้สร้าง |
| Audit Log ครบ | ✅ | `test_generating_a_document_is_recorded_in_the_audit_log` + action ครบ 12 รายการ |
| Automated Tests ผ่าน | ✅ | 583 tests / 1922 assertions |
| Report Designer จากหน้าจอ | ✅ | `ReportDesignerView.vue` |
| PDF ตรงกับต้นฉบับ | 🔶 | ต้องวางตำแหน่งฟิลด์ให้ตรงช่องจริงก่อน (งานผู้ดูแลผ่านหน้าออกแบบ) |

---

## 4. ความปลอดภัย

| ประเด็น | มาตรการ |
|---|---|
| ห้าม SQL อิสระจาก UI | แหล่งข้อมูลมาจาก `DataProviderRegistry` ที่ประกาศในโค้ดเท่านั้น ตารางเป็นเพียงรายการให้เลือก · `test_an_unknown_data_source_is_refused` |
| ขอบเขตข้อมูลของรายงาน | provider เรียก `ReportingScopeService` เดิม รายงานใหม่จึงไม่มีทางเห็นข้อมูลกว้างกว่าสิทธิ์ผู้เรียก |
| Authorization ฝั่งเซิร์ฟเวอร์ | ทุก endpoint ผ่าน `permission:` middleware · `test_admin_endpoints_reject_users_without_document_permission` |
| IDOR | เอกสารใบลาผ่าน `LeavePolicy::view` เหมือนเดิม · `test_another_persons_leave_document_cannot_be_downloaded` |
| ไฟล์เอกสาร | เก็บใน disk `private_uploads` เข้าถึงผ่าน endpoint ที่ตรวจสิทธิ์เท่านั้น ไม่มี public URL |
| ความถูกต้องของไฟล์ | เก็บ sha256 ของทุกฉบับที่ออก |

---

## 5. ไฟล์สำคัญ

```text
backend/app/Services/DocumentEngine/
  DocumentEngine.php          จุดเข้าเดียวของการออกเอกสาร
  TemplateResolver.php        binding rule → template → version
  PdfOverlayRenderer.php      วางฟิลด์บน PDF ต้นฉบับ (FPDI)
  CoordinateConverter.php     หน่วยและระบบพิกัด
  DataProviderRegistry.php    whitelist แหล่งข้อมูล
  ReportEngine.php            รายงาน + grouping/aggregate
  LeaveDocumentGateway.php    Compatibility Layer ของ E-Leave
  Providers/                  LeaveRequestDataProvider, LeaveStatisticsDataProvider

backend/app/Console/Commands/
  InstallDocumentEngineFonts.php     ฟอนต์ไทยของ renderer
  ImportDocumentFormTemplates.php    นำแบบฟอร์มราชการเข้าระบบ
  ScaffoldDocumentFieldMappings.php  ชุดฟิลด์ตั้งต้น
  ConsolidateDocumentTemplates.php   รวมแม่แบบซ้ำ

frontend/src/views/admin/
  DocumentCenterView.vue      ศูนย์จัดการเอกสารและรายงาน (7 แท็บ)
  DocumentDesignerView.vue    หน้าออกแบบวางฟิลด์บน PDF
  ReportDesignerView.vue      หน้าออกแบบรายงาน
```

---

## 6. งานที่เหลือ

| # | เรื่อง | ผู้รับผิดชอบ |
|---|---|---|
| 1 | วางตำแหน่งฟิลด์บนแบบฟอร์มทั้ง 5 ฉบับให้ตรงช่องจริง แล้วกดเผยแพร่ | ผู้ดูแลระบบ (ผ่านหน้าออกแบบ) |
| 2 | เปิดธง `DOCUMENT_ENGINE_ENABLED` + ระบุประเภทการลาทีละประเภท | ผู้ดูแลระบบ |
| 3 | อัปโหลดแบบฟอร์มของอีก 3 ประเภทที่ยังไม่มี (ตรวจเลือก/เตรียมพล, องค์การระหว่างประเทศ, ฟื้นฟูสมรรถภาพ) | ผู้ดูแลระบบ |
| 4 | ตัดสินใจเรื่อง workflow engine ที่ยังรัน shadow คู่ขนาน (ค้างจาก ADR-APPROVAL-02) | เจ้าของระบบ |

---

## 7. Rollback

| ระดับ | วิธี | เวลา |
|---|---|---|
| L1 | `DOCUMENT_ENGINE_ENABLED=false` — กลับเส้นทางเดิมทั้งหมด | ทันที ไม่ต้อง deploy |
| L2 | ปิดเฉพาะบางประเภทลาที่ `DOCUMENT_ENGINE_LEAVE_TYPES` | ทันที |
| L3 | ปลดเวอร์ชัน PDF ที่เผยแพร่ (RETIRED) — กลับไปใช้แม่แบบ HTML | ทันที ผ่านหน้าจอ |
| L4 | `git revert` — schema เป็น additive จึงอยู่ต่อได้โดยไม่กระทบระบบเดิม | ~นาที |
| L5 | `php artisan migrate:rollback` — `down()` ลบเฉพาะของที่เฟสนี้สร้าง | ~นาที |
