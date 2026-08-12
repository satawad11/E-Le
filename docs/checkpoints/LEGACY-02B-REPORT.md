# LEGACY-02B — Authorization & IDOR Security Hardening

**วันที่:** 12 สิงหาคม 2569 (2026-08-12)
**ขอบเขต:** ปิด P0 ที่ค้างจาก `LEGACY-00-REPORT.md` เท่านั้น — SEC-003, SEC-004, SEC-009
**ผล:** **PASS** — ปิดครบทั้ง 3 รายการ · test 173/173 · ไม่มี regression

---

## วิธีทำงาน: เขียนเทสต์ก่อนแก้โค้ด

ตามที่กำหนด — เขียนเทสต์ให้ล้มก่อน แล้วจึงแก้ให้ผ่าน

**สถานะเริ่มต้น (ก่อนแก้โค้ดใด ๆ):**

```
Tests: 27 failed, 16 passed (45 assertions)
```

27 ข้อที่ล้มคือข้อที่คาดว่าต้องได้ 401/403 แต่ระบบตอบ 200 — พิสูจน์ว่าช่องโหว่มีอยู่จริง
ไม่ใช่แค่ข้อสงสัยจากการอ่านโค้ด

**สถานะหลังแก้:** `43 passed (47 assertions)`

---

## SEC-003 — IDOR ดาวน์โหลดใบลาของผู้อื่น

### ปัญหา

`GET /api/leaves/{leave}/pdf` และ `/word` มี middleware แค่ `auth:sanctum`
controller เริ่มด้วย `$leave->load(...)` ทันทีโดยไม่ตรวจ ownership
ผู้ใช้ที่ล็อกอินคนใดก็ได้จึงดาวน์โหลดใบลาของคนอื่น ซึ่งมี **เหตุผลการลาป่วย
ที่อยู่ติดต่อ และลายเซ็น** อยู่ในเอกสาร

### สิ่งที่ทำ

สร้าง `app/Policies/LeavePolicy.php` และลงทะเบียนใน `AppServiceProvider`
เรียกผ่าน `$this->authorize('view', $leave)` ที่ `exportPdf()` และ `exportWord()`

**ลำดับการตัดสินสิทธิ์:**

| # | เงื่อนไข | เหตุผลเชิงธุรกิจ |
|---|---|---|
| 1 | เป็นเจ้าของใบลา | — |
| 2 | เป็นผู้ปฏิบัติงานแทน (`substitute_id`) | ต้องเห็นรายละเอียดงานที่รับมอบหมาย |
| 3 | มีชื่อในสายอนุมัติของใบลาฉบับนั้น (`leave_approvals.approver_id`) | ต้องเห็นสิ่งที่ตนต้องอนุมัติ |
| 4 | มี permission ระดับองค์กร | `leave.management.manage`, `leave.approval.hr`, `leave.approval.final`, `leave.report.view` |
| 5 | มี `leave.approval.dept` **และอยู่หน่วยงานเดียวกับเจ้าของใบลา** | หัวหน้าฝ่ายเห็นเฉพาะฝ่ายตน |
| — | นอกจากนี้ | ปฏิเสธ |

ชุด permission ในข้อ 4 เลือกให้ตรงกับที่ `LeaveController::index` ใช้ตัดสิน "ดูได้ทั้งหมด"
อยู่แล้ว เพื่อ**รักษาพฤติกรรมของผู้ใช้ที่เคยได้รับอนุญาต**

**ไม่มีการอ้างอิงชื่อ role แม้แต่จุดเดียว** — ใช้ permission ล้วน

### เทสต์ที่ครอบ (10 ข้อ)

| กรณี | คาดหวัง |
|---|---|
| เจ้าของเปิดใบลาตัวเอง | 200 |
| คนนอกเปิดใบลาคนอื่น (pdf) | **403** |
| คนนอกเปิดใบลาคนอื่น (word) | **403** |
| อยู่ฝ่ายเดียวกันแต่ไม่มี permission | **403** |
| หัวหน้าฝ่าย (`leave.approval.dept`) เปิดใบลาในฝ่ายตน | 200 |
| หัวหน้าฝ่ายเปิดใบลา**ข้ามฝ่าย** | **403** |
| HR (`leave.management.manage`) เปิดใบลาใดก็ได้ | 200 |
| ผู้อนุมัติที่ถูกมอบหมายในใบลานั้น | 200 |
| ผู้ปฏิบัติงานแทน | 200 |
| ไม่ได้ล็อกอิน | 401 |

---

## SEC-004 — `hr-verify` ไม่ตรวจสิทธิ์

### ปัญหา

`POST /api/leaves/{leave}/hr-verify` เริ่มด้วย `$leave->hr_verified_by = $request->user()->id`
ทันที ไม่มีการตรวจสิทธิ์เลย ผู้ใช้ทั่วไปรับรองสถิติการลาของใครก็ได้

### สิ่งที่ทำ

`LeavePolicy::hrVerify()` ต้องมี `leave.approval.hr` หรือ `leave.management.manage`
เรียกผ่าน `$this->authorize('hrVerify', $leave)`

ใช้ permission `leave.approval.hr` ที่ **มีอยู่ในระบบแล้ว** (สร้างโดย migration
`2026_08_09_020000_seed_leave_governance_access.php`) จึง **ไม่ต้องแก้ schema
และไม่ต้องสร้าง permission ใหม่**

### เทสต์ที่ครอบ (5 ข้อ)

| กรณี | คาดหวัง |
|---|---|
| ผู้ใช้ทั่วไป | **403** + `hr_verified_at` ยังเป็น null |
| เจ้าของใบลาเอง (ไม่มี permission) | **403** |
| ผู้มี `leave.approval.hr` | 200 + บันทึก `hr_verified_by` ถูกต้อง |
| ผู้มี `leave.management.manage` | 200 |
| ไม่ได้ล็อกอิน | 401 |

---

## SEC-009 — Export ข้อมูลลงเวลาทั้งองค์กร

### ปัญหา

7 endpoint อยู่ **นอก** กลุ่ม `auth:sanctum` โดยตั้งใจ (มีคอมเมนต์ในโค้ดเดิมว่า
*"We put preview and exports outside sanctum group so window.open works with ?token= query param"*)

- middleware มีแค่ `[api]`
- controller ตรวจเพียงว่า token ใช้ได้ **ไม่ตรวจ permission**
- token เดินทางใน query string → โผล่ใน access log, referrer, browser history

### สิ่งที่ทำ — ฝั่ง backend

ย้ายทั้ง 7 endpoint เข้ากลุ่ม `auth:sanctum` + `throttle` + `can:`

| endpoint | permission |
|---|---|
| `summary/preview`, `summary/export/pdf`, `summary/export/word` | `attendance.view_all` |
| `ot-summary/preview`, `ot-summary/export/{pdf,word,excel}` | `attendance.ot_summary` |

แยก permission สองชุดเพราะระบบเดิมแยกไว้แบบนี้อยู่แล้ว (`/admin/attendances/ot-summary`
ใช้ `can:attendance.ot_summary` มาก่อน) — รักษาพฤติกรรมเดิม

ถอดโค้ดอ่าน token จาก query string ออกทั้ง 7 จุด แทนด้วย `$request->user()`
และลบ `use Laravel\Sanctum\PersonalAccessToken` ที่ไม่ได้ใช้แล้ว

### สิ่งที่ทำ — ฝั่ง frontend

เดิมใช้ `window.open(url + '&token=' + token)` ซึ่งแนบ Authorization header ไม่ได้

เพิ่ม `openAuthenticatedReport()` ใน `utils/secureFile.js` — ยิงผ่าน axios
(ซึ่งแนบ Bearer header ให้อยู่แล้ว) รับกลับเป็น blob แล้ว:
- ถ้ามี `Content-Disposition: attachment` → ดาวน์โหลดพร้อมชื่อไฟล์เดิม
- ถ้าไม่มี (หน้า preview ที่เป็น HTML) → เปิดแท็บใหม่

เปลี่ยน 7 จุดเรียกใน `AttendanceSummaryView.vue` และ `AttendanceOTSummaryView.vue`
พร้อมข้อความ error ภาษาไทย (401 = เซสชันหมดอายุ, 403 = ไม่มีสิทธิ์ออกรายงานนี้)

**ยืนยันแล้วว่าไม่มี `token=` เหลืออยู่ใน `frontend/src/` อีกเลย**

### เทสต์ที่ครอบ (28 ข้อ = 7 endpoint × 4 กรณี)

| กรณี | คาดหวัง |
|---|---|
| ไม่ได้ล็อกอิน | **401** |
| ล็อกอินแล้วแต่ไม่มี permission | **403** |
| มี permission | 200 |
| ส่ง token ทาง query string | **401** (ไม่รับอีกต่อไป) |

---

## ผลการตรวจสอบ

### Test suite

| ชุด | ผล |
|---|---|
| ก่อนแก้โค้ด (เทสต์ใหม่อย่างเดียว) | **27 failed / 16 passed** |
| หลังแก้ (เทสต์ใหม่อย่างเดียว) | **43 passed (47 assertions)** |
| **ทั้งระบบ** | **173 passed (398 assertions)** |
| ก่อนเริ่ม LEGACY-02B | 130 passed (351 assertions) |

เพิ่ม 43 เทสต์ · **ไม่มีเทสต์เดิมข้อใดพัง**

### Middleware ที่บังคับใช้จริง (จาก router)

```
api/leaves/{leave}/pdf                      api, auth:sanctum, throttle:60,1   + LeavePolicy::view
api/leaves/{leave}/word                     api, auth:sanctum, throttle:60,1   + LeavePolicy::view
api/leaves/{leave}/hr-verify                api, auth:sanctum, throttle:60,1   + LeavePolicy::hrVerify
api/admin/attendances/summary/preview       api, auth:sanctum, throttle:60,1, can:attendance.view_all
api/admin/attendances/summary/export/pdf    api, auth:sanctum, throttle:60,1, can:attendance.view_all
api/admin/attendances/summary/export/word   api, auth:sanctum, throttle:60,1, can:attendance.view_all
api/admin/attendances/ot-summary/preview    api, auth:sanctum, throttle:60,1, can:attendance.ot_summary
api/admin/attendances/ot-summary/export/pdf api, auth:sanctum, throttle:60,1, can:attendance.ot_summary
api/admin/attendances/ot-summary/export/word  api, auth:sanctum, throttle:60,1, can:attendance.ot_summary
api/admin/attendances/ot-summary/export/excel api, auth:sanctum, throttle:60,1, can:attendance.ot_summary
```

> การตรวจสิทธิ์รายทรัพยากรของใบลาอยู่ใน Policy ไม่ใช่ middleware จึงไม่ปรากฏในรายการนี้
> — ดูหลักฐานจากเทสต์แทน

### ยิงจริงแบบไม่ส่ง token

| endpoint | ผล |
|---|---|
| `/api/leaves/1/pdf` | 401 |
| `/api/admin/attendances/summary/preview` | 401 |
| `/api/admin/attendances/summary/export/pdf` | 401 |
| `/api/admin/attendances/ot-summary/export/excel` | 401 |
| `/api/ping` | 200 (ระบบยังทำงาน) |

### อื่น ๆ

| การตรวจ | ผล |
|---|---|
| `npm run build` | ✓ 877 modules · 40.40s |
| Pint ไฟล์ที่แก้ในรอบนี้ (7 ไฟล์) | **PASS** |

---

## การปฏิบัติตามกฎที่กำหนด

| กฎ | สถานะ |
|---|---|
| ห้าม Refactor RBAC ทั้งระบบ | ✓ เพิ่ม Policy เดียวและ middleware บน route ไม่แตะ Spatie/permission catalogue |
| ห้ามสร้าง Workflow ใหม่ | ✓ |
| ห้ามเปลี่ยน Leave Policy | ✓ ไม่แตะ `leave_policies` หรือ `LeavePolicyService` |
| ห้ามเปลี่ยน Attendance architecture | ✓ เปลี่ยนเฉพาะชั้น authentication/authorization ตรรกะรายงานเดิมไม่ถูกแตะ |
| ห้ามเพิ่ม Feature ใหม่ | ✓ |
| ห้ามแก้ Database Schema | ✓ **ไม่มี migration ใหม่** — ใช้ permission ที่มีอยู่แล้วทั้งหมด |
| ห้าม hard-code role name | ✓ `LeavePolicy` ใช้ permission ล้วน · grep ยืนยันไม่มี `hasRole` ในโค้ดที่เพิ่ม |
| ห้าม breaking API change โดยไม่จำเป็น | ⚠️ ดูด้านล่าง |
| รักษาพฤติกรรมเดิมของผู้ใช้ที่ได้รับอนุญาต | ✓ ชุด permission เลือกให้ตรงกับที่ `LeaveController::index` ใช้อยู่ |

### ⚠️ Breaking change ที่จำเป็นและตั้งใจ

**การเลิกรับ token ทาง query string เป็น breaking change** สำหรับ client ที่เรียก
export ด้วย URL ตรง ๆ (เช่น bookmark เดิม หรือสคริปต์ภายนอกถ้ามี)

หลีกเลี่ยงไม่ได้ เพราะกลไกนั้น**คือ**ตัวช่องโหว่ — หน้าเว็บของระบบแก้ให้ใช้ authenticated
request แล้วเรียบร้อย ผู้ใช้ผ่านหน้าจอปกติจะไม่รู้สึกถึงความต่าง

---

## ข้อจำกัดที่ยังเหลือ (ไม่อยู่ในขอบเขต LEGACY-02B)

| # | เรื่อง | หมายเหตุ |
|---|---|---|
| 1 | **ไม่มี organization scope ใน export** | `attendance.view_all` เป็น permission ระดับองค์กรตามชื่อ ระบบเดิมไม่มีแนวคิด "export เฉพาะฝ่ายตน" การเพิ่ม scope ที่นี่จะเป็นการเปลี่ยนพฤติกรรมและต้องมี org hierarchy ก่อน (CORE-ORG-001 · LEGACY-03) — เทสต์ข้อ "ผิด scope = 403" จึงยังทำไม่ได้อย่างมีความหมายในสถาปัตยกรรมปัจจุบัน |
| 2 | **HR ที่ลาเอง ยังรับรองใบลาตัวเองได้** | ถ้าผู้ใช้มี `leave.approval.hr` และเป็นเจ้าของใบลา จะผ่าน policy · การห้ามเป็น *segregation of duties* ซึ่งเป็นกฎธุรกิจใหม่ที่อยู่นอกขอบเขต "ห้ามเปลี่ยน Leave Policy" — **แนะนำให้พิจารณาใน LEGACY-05** |
| 3 | `/api/files/{path}` ยังไม่มี ABAC รายไฟล์ | จาก LEGACY-02 §02-A เดิม |
| 4 | `sanctum.expiration = NULL` (SEC-012) | รอคำตอบว่าจะตั้งกี่นาที |
| 5 | Pint ฟ้อง 4 ไฟล์ที่ **มีมาก่อนรอบนี้** | `GovernmentApprovalController.php`, `LeavePolicyService.php`, `routes/leave_government.php`, `GovernmentLeaveGovernanceTest.php` — ผมไม่ได้แตะไฟล์เหล่านี้และไม่แก้ style ให้ เพราะอยู่นอกขอบเขตและกฎห้าม refactor · **CI job ของ Pint จะแดงจนกว่าจะจัดการ** |

---

## Rollback

`git revert` commit ของรอบนี้ · ไม่มี migration และไม่มีการเปลี่ยนแปลงข้อมูล
จึงย้อนได้ด้วย code revert อย่างเดียว

---

## สรุป: **PASS**

SEC-003, SEC-004, SEC-009 ปิดครบ พร้อมเทสต์ที่พิสูจน์ว่าเคยล้มก่อนแก้และผ่านหลังแก้
ไม่มี regression · ไม่แตะ schema · ไม่ hard-code role

**หยุดที่นี่ รอคำสั่งก่อนไป Phase ถัดไป**
