# implement_plan.md — โมดูลสารบัญอิเล็กทรอนิกส์ (e-Saraban)
## แผนพัฒนาแบบเฟส สำหรับเพิ่มเข้าระบบ E-Leave เดิม

> Task ที่มี `// turbo` = agent ทำต่อได้เองโดยไม่ต้องหยุดถาม
> Task ที่ไม่มี `// turbo` = ต้อง confirm กับผู้ใช้ก่อนดำเนินการ

---

## Pre-Phase 0: ตรวจสอบระบบเดิม (ต้องทำก่อนเริ่มเขียนโค้ด — ไม่ turbo)

- [ ] ตรวจสอบโครงสร้างตาราง `users`, `roles`, `permissions`, `model_has_roles` ของจริง
- [ ] ตรวจสอบว่ามีตาราง org chart/approval hierarchy จาก Leave หรือไม่ (เช่น `departments`, `approval_chains`)
- [ ] ตรวจสอบ Auth guard ที่ Vue SPA ใช้เรียก API (Sanctum token / Bearer JWT)
- [ ] ตรวจสอบ routing convention เดิม (`routes/api.php` prefix, versioning `/api/v1/*` หรือไม่)
- [ ] ตรวจสอบ Vue project structure (`src/modules/*`, Pinia store pattern, router guard pattern)
- [ ] ยืนยันคำตอบ 5 ข้อใน CLAUDE.md หัวข้อ 8 (Open Questions) กับผู้ใช้

**Output ของ Pre-Phase 0:** เอกสารสรุปโครงสร้างจริง (`docs/existing-system-audit.md`) ก่อนเข้าสู่ Phase 1

---

## Phase 1: Core Registry (ทะเบียนรับ-ส่ง + RBAC)

### 1.1 Database
- [ ] // turbo — สร้าง migration `saraban_document_types` (seed: หนังสือภายนอก, ภายใน, ประทับตรา, สั่งการ, ประชาสัมพันธ์, หนังสือรับรอง)
- [ ] // turbo — สร้าง migration `saraban_departments` (หรือ reuse ตารางเดิมถ้ามีจาก Pre-Phase 0)
- [ ] // turbo — สร้าง migration `saraban_document_sequences` (year, department_id, document_type_id, running_number)
- [ ] // turbo — สร้าง migration `saraban_documents` (เลขที่, เรื่อง, ประเภท, ชั้นความลับ, ชั้นความเร็ว, สถานะ, created_by, department_id)
- [ ] // turbo — สร้าง migration `saraban_document_incoming` (วันที่รับ, จากหน่วยงาน, ผู้ลงทะเบียนรับ)
- [ ] // turbo — สร้าง migration `saraban_document_outgoing` (วันที่ส่ง, ถึงหน่วยงาน, วิธีจัดส่ง)
- [ ] // turbo — สร้าง migration `saraban_document_attachments` (path, mime, uploaded_by, ocr_text nullable)
- [ ] // turbo — สร้าง migration `saraban_audit_logs`

### 1.2 Backend
- [ ] // turbo — Model `Document`, `DocumentType`, `DocumentSequence`, `DocumentIncoming`, `DocumentOutgoing`, `DocumentAttachment` พร้อม relationships
- [ ] // turbo — Service `DocumentNumberGeneratorService` (atomic running number ตามที่ยืนยันจาก Pre-Phase 0)
- [ ] // turbo — API Resource Controllers: `DocumentController`, `DocumentIncomingController`, `DocumentOutgoingController`
- [ ] // turbo — Form Requests พร้อม validation (ชั้นความลับ, ประเภทหนังสือ ฯลฯ)
- [ ] — สร้าง Spatie permissions ชุดแรก + assign role เริ่มต้น (ต้อง confirm รายชื่อ role กับผู้ใช้ก่อน)
- [ ] // turbo — Middleware ตรวจสิทธิ์ตามชั้นความลับ (`CanViewClassifiedDocument`)
- [ ] // turbo — API Feature Tests สำหรับ CRUD + การออกเลขที่

### 1.3 Frontend
- [ ] // turbo — Pinia store `useSaraban DocumentStore`
- [ ] // turbo — หน้า: รายการเอกสาร, ฟอร์มลงทะเบียนรับ, ฟอร์มออกเลขที่ส่ง, หน้ารายละเอียดเอกสาร
- [ ] // turbo — เพิ่มเมนู "งานสารบัญ" ใน sidebar เดิม (แยก section)
- [ ] // turbo — Route guard ตาม permission `saraban.*`

**Deliverable Phase 1:** ลงทะเบียนรับ-ส่งหนังสือได้จริง, ออกเลขที่อัตโนมัติไม่ชนกัน, ดูรายการ/รายละเอียดเอกสารได้ตามสิทธิ์

---

## Phase 2: Workflow เดินเรื่อง + ลงนาม + หนังสือเวียน

- [ ] // turbo — Migration `saraban_document_workflow_templates`, `saraban_document_workflow_steps`
- [ ] // turbo — Migration `saraban_document_signatures` (signer_id, signed_at, signature_hash, ip_address)
- [ ] // turbo — Migration `saraban_document_circulars`, `saraban_document_circular_recipients`
- [ ] — ออกแบบ state machine (draft → ... → archived) และ mapping กับ permission แต่ละ role (ต้อง confirm ผังอนุมัติจริงของวิทยาลัยก่อน)
- [ ] // turbo — Service `DocumentWorkflowService` (transition state, validate ลำดับขั้น, บันทึก step history)
- [ ] // turbo — e-Signature: บันทึก hash + timestamp (พิจารณาความสอดคล้องกับ พ.ร.บ. ธุรกรรมทางอิเล็กทรอนิกส์)
- [ ] // turbo — QR Code verify endpoint (public, read-only, ไม่เปิดเผยเนื้อหาลับ)
- [ ] // turbo — Frontend: หน้า timeline เดินเรื่อง, ปุ่มอนุมัติ/ตีกลับ/ลงนาม, หน้าส่งหนังสือเวียน + tracking การรับทราบ

**Deliverable Phase 2:** เดินเรื่องอนุมัติ-ลงนามอิเล็กทรอนิกส์ได้ครบ, ส่งหนังสือเวียนและติดตามสถานะรับทราบได้

---

## Phase 3: ติดตามงาน + แจ้งเตือน + ค้นหา

- [ ] // turbo — Migration `saraban_document_tasks` (assignee_id, due_date, status)
- [ ] // turbo — Scheduled command แจ้งเตือนล่วงหน้า (3 วัน / 1 วัน / เกินกำหนด)
- [ ] — เชื่อม LINE Notify/OA (ถ้ามี integration เดิมจาก Leave ให้ reuse; ถ้าไม่มีต้อง confirm scope ก่อน)
- [ ] // turbo — Full-text search (Laravel Scout + database driver หรือ Meilisearch ถ้า infra รองรับ)
- [ ] // turbo — Dashboard "เรื่องค้าง" แยกตามฝ่าย/บุคคล
- [ ] // turbo — Filter ค้นหา: ปี พ.ศ., ประเภท, ฝ่าย, ชั้นความลับ, สถานะ

**Deliverable Phase 3:** ระบบแจ้งเตือนอัตโนมัติ, ค้นหาเอกสารได้ครบทุกมิติ, dashboard เรื่องค้าง

---

## Phase 4: รายงาน + การจัดเก็บ/ทำลาย + PDPA Hardening

- [ ] // turbo — Migration `saraban_document_retention_policies`, `saraban_document_destruction_lists`
- [ ] // turbo — Job แจ้งเตือนก่อนครบกำหนดทำลาย + generate บัญชีขอทำลาย (แบบ สร.6)
- [ ] // turbo — Report module: สถิติรายเดือน/ปี, เรื่องค้าง/เกินกำหนด, Export Excel/PDF
- [ ] // turbo — พิมพ์ฟอร์มราชการมาตรฐาน (ครุฑ, TH Sarabun New, ระยะขอบตามระเบียบ) ผ่าน dompdf/laravel-pdf
- [ ] — Audit ความปลอดภัย: encryption ข้อมูลอ่อนไหว, สิทธิ์เข้าถึงชั้นความลับ, PDPA data retention policy (ต้อง review กับผู้ดูแลระบบก่อน deploy จริง)
- [ ] // turbo — Full audit log coverage ทุก endpoint แก้ไข/ลบ/ลงนาม/ดาวน์โหลด

**Deliverable Phase 4:** ระบบพร้อมใช้งานจริง มีรายงานผู้บริหาร, การจัดการวงจรชีวิตเอกสารครบ, ผ่าน PDPA compliance check

---

## Testing Checklist (ทุกเฟส)
- [ ] Unit test: DocumentNumberGeneratorService (ทดสอบ concurrent request ไม่ให้เลขซ้ำ)
- [ ] Feature test: workflow state transition ครบทุก path (approve/reject/re-submit)
- [ ] Feature test: permission — user ที่ไม่มีสิทธิ์ห้ามเห็นหนังสือชั้นความลับ
- [ ] Regression test: ระบบ Leave เดิมทำงานปกติหลังติดตั้งโมดูลใหม่ (ไม่มี migration/route ชนกัน)

---

## หมายเหตุสำหรับ Agent
เริ่มทำงานจาก **Pre-Phase 0 เท่านั้น** ห้ามข้ามไป Phase 1 จนกว่าจะได้คำตอบ Open Questions ครบจาก CLAUDE.md เพราะการออกแบบ running number และ workflow ผูกกับคำตอบเหล่านั้นโดยตรง เปลี่ยนทีหลังจะกระทบ migration ที่สร้างไปแล้ว
