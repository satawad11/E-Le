# CLAUDE.md — โมดูลสารบัญอิเล็กทรอนิกส์ (e-Saraban Module)
## เพิ่มเข้าในระบบเดิม: DMTC E-Leave System

> ไฟล์นี้คือบริบทหลักสำหรับ AI coding agent (Claude Code / Antigravity) ใช้อ่านก่อนเริ่มงานทุกครั้ง
> อ่านคู่กับ `implement_plan.md` (แผนงานเป็นเฟส) และ `SKILL.md` (แพทเทิร์นเฉพาะโดเมนงานสารบัญ)

---

## 1. บริบทโปรเจกต์

| หัวข้อ | รายละเอียด |
|---|---|
| ระบบเดิม | DMTC E-Leave & Attendance System |
| หน่วยงาน | วิทยาลัยเทคนิคดอนเมือง (DMTC) |
| Backend | Laravel (REST API, ไม่ใช้ Inertia) |
| Frontend | Vue 3 SPA แยกจาก backend เรียกผ่าน API (axios) |
| Auth/RBAC | Spatie laravel-permission (มีอยู่แล้ว) |
| DB | MySQL |
| งานที่เพิ่ม | โมดูล "สารบัญอิเล็กทรอนิกส์" (e-Saraban) — รับ-ส่งหนังสือราชการ, เดินเรื่องอนุมัติ, ลงนามอิเล็กทรอนิกส์, หนังสือเวียน, ติดตามงาน |

**อ้างอิงระเบียบ:** ระเบียบสำนักนายกรัฐมนตรีว่าด้วยงานสารบรรณ พ.ศ. 2526 และฉบับแก้ไขเพิ่มเติม (ฉบับที่ 4) พ.ศ. 2564 (รองรับหนังสืออิเล็กทรอนิกส์)

---

## 2. กฎเหล็ก — ห้ามฝ่าฝืน

1. **ห้ามแก้ไข schema ตารางเดิมของระบบ Leave** (`leaves`, `leave_types`, `leave_approvals` ฯลฯ) — โมดูล Saraban ต้องเป็น additive เท่านั้น
2. **ทุกตารางใหม่ต้องมี prefix `saraban_`** เช่น `saraban_documents`, `saraban_document_workflow_steps`
3. **ใช้ตาราง `users` เดิมร่วมกัน** ผ่าน foreign key เท่านั้น ห้ามสร้างตาราง users ใหม่
4. **Namespace แยกชัดเจน**
   - Models: `App\Models\Saraban\*`
   - Controllers: `App\Http\Controllers\Api\Saraban\*`
   - Services: `App\Services\Saraban\*`
   - Requests: `App\Http\Requests\Saraban\*`
   - Vue: `src/modules/saraban/**` (แยกโฟลเดอร์จาก `src/modules/leave/**`)
5. **Spatie permissions ใหม่ทั้งหมดขึ้นต้นด้วย `saraban.`** ห้ามชนกับ permission เดิมของ Leave (เช่น `leave.approve`)
6. **ห้าม hardcode วันที่แบบ ค.ศ.** ทุกจุดที่แสดงผลผู้ใช้ต้องแปลงเป็น พ.ศ. (ค.ศ. + 543) — เก็บใน DB เป็น ค.ศ./ISO ตามปกติ แปลงเฉพาะชั้น presentation
7. **ข้อมูลอ่อนไหว (เลขบัตรประชาชนผู้ลงนามภายนอก, เนื้อหาหนังสือลับ)** เข้ารหัสด้วย AES-256 ตามแพทเทิร์นเดียวกับ `dmtc-finance`
8. **ทุก endpoint ที่แก้ไข/ลบ/ลงนามเอกสาร ต้องบันทึก audit log** (`saraban_audit_logs`) พร้อม user_id, action, ip, timestamp
9. **ห้าม breaking change กับ API เดิมของ Leave** — endpoint ใหม่ทั้งหมดอยู่ใต้ prefix `/api/saraban/*`

---

## 3. Tech Stack ที่ใช้ในโมดูลนี้

- Laravel 11.x (ของเดิม)
- REST API (Resource Controllers + API Resources สำหรับ response shape)
- Laravel Sanctum (สมมติว่าใช้ auth token เดิมของ Leave — ตรวจสอบก่อนเริ่ม Phase 1)
- Spatie/laravel-permission (เดิม)
- Vue 3 + Pinia (state management) + Vue Router + Axios
- Vite build (ตามเดิม)
- PDF generation: `barryvdh/laravel-dompdf` หรือ `spatie/laravel-pdf` สำหรับพิมพ์หนังสือราชการฟอร์มมาตรฐาน (ครุฑ, TH Sarabun New)
- QR Code: `simplesoftwareio/simple-qrcode` สำหรับ QR verify เอกสาร
- OCR (ถ้าต้องการค้นหาเนื้อหาสแกน): พิจารณา Phase หลัง (ไม่บังคับ Phase 1-4)

---

## 4. โครงสร้างเลขที่หนังสือ (Running Number)

รูปแบบ: `[หน่วยงาน]/[ประเภท] เลขที่ xxx/[พ.ศ.]`
ตัวอย่าง: `ศธ 0584.1/ว123 ลงวันที่ 12 กรกฎาคม 2569`

กฎการ generate เลข:
- แยก running number ตาม **ประเภทหนังสือ** (ภายนอก/ภายใน/คำสั่ง/ประกาศ/หนังสือเวียน ฯลฯ) และ **ฝ่าย/แผนกวิชา**
- รีเซ็ตตาม **ปีงบประมาณไทย** (1 ต.ค. - 30 ก.ย.) หรือปีปฏิทิน พ.ศ. — **ต้องถามผู้ใช้ก่อน Phase 1 ว่าวิทยาลัยใช้แบบไหน**
- ต้อง atomic/lock กัน race condition (ใช้ DB transaction + `lockForUpdate()`)
- เก็บ sequence ในตาราง `saraban_document_sequences` แยกตาม (year, department_id, document_type)

---

## 5. Workflow เดินเรื่อง

สถานะเอกสาร (state machine):
```
draft → pending_review → pending_approval → approved_pending_signature
→ signed → dispatched → archived
(ทุกจุดสามารถ reject กลับไป draft พร้อม comment)
```

เส้นทางเดินเรื่องอิงจาก org chart ของวิทยาลัย — ถ้า Leave System มี approval chain/hierarchy อยู่แล้ว **ให้ตรวจสอบก่อนว่า reuse ตารางเดิมได้หรือไม่** ก่อนสร้าง `saraban_org_chart` ใหม่ซ้ำซ้อน

---

## 6. คำสั่งที่ใช้บ่อย (เติมให้ตรงกับโปรเจกต์จริงก่อนเริ่มงาน)

```bash
# Backend
php artisan migrate
php artisan test --filter=Saraban
php artisan route:list --path=api/saraban

# Frontend
npm run dev
npm run test:unit -- saraban
```

---

## 7. ลำดับการอ่านไฟล์สำหรับ Agent

1. อ่านไฟล์นี้ (CLAUDE.md) ก่อนเสมอ
2. อ่าน `implement_plan.md` เพื่อดูว่าตอนนี้อยู่ Phase ไหน / task ไหน
3. อ่าน `SKILL.md` เมื่อทำงานเกี่ยวกับ: เลขที่หนังสือ, workflow engine, e-signature, พิมพ์ฟอร์มราชการ
4. Task ที่มี `// turbo` marker = ทำได้อัตโนมัติโดยไม่ต้องรอ confirm เพิ่มเติม (เช่น สร้าง migration, model, boilerplate CRUD)
5. Task ที่ **ไม่มี** `// turbo` = ต้อง confirm กับผู้ใช้ก่อน (เช่น แก้ schema ที่กระทบระบบ Leave, ลบข้อมูล, เปลี่ยนรูปแบบเลขที่หนังสือ)

---

## 8. สิ่งที่ต้องยืนยันกับผู้ใช้ก่อนเริ่ม Phase 1 (Open Questions)

- [ ] ใช้ปีงบประมาณ หรือปีปฏิทิน พ.ศ. ในการรีเซ็ต running number?
- [ ] Leave System มี org chart/approval hierarchy อยู่แล้วหรือไม่ (ชื่อตาราง?)
- [ ] มี Auth guard/token structure แบบไหน (Sanctum? Passport? JWT?)
- [ ] ต้องการ OCR ค้นหาเนื้อหาสแกน หรือพอแค่ full-text บน metadata/เนื้อหาที่พิมพ์ในระบบ
- [ ] ต้องการเชื่อม LINE Notify/LINE OA สำหรับแจ้งเตือนหรือไม่ (Leave มี integration นี้อยู่แล้วหรือไม่)
