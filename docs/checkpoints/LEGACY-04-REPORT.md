# LEGACY-04 — RBAC + ABAC Report

**วันที่:** 13–14 สิงหาคม 2569 (2026-08-13/14)
**ขอบเขต:** LEGACY-04 ตาม `docs/LEGACY-MIGRATION-PLAN.md` (04-1 ถึง 04-9)
**ช่วง commit:** `2bfb71c` → `afab56f` (9 commit · 26 ไฟล์ · +2,275 / −171)
**ผล:** **PARTIAL PASS** — งานที่ทำได้โดยไม่เปลี่ยนสิทธิ์ใครเสร็จครบ · เหลือ 3 จุดที่รอการตัดสินใจ

---

## สรุปผลรายข้อ

| # | งาน | ผล |
|---|---|:---:|
| 04-1 | canonical permission set + `permission_aliases` | **PASS** |
| 04-2 | `PermissionEvaluator` — deny > allow > default deny | **PASS** |
| 04-3 | `permission_scopes` ผูกกับ `org_units` | **PASS** |
| 04-4 | แทน `hasRole()` ทีละโมดูล | **PARTIAL** — 18/24 จุด |
| 04-5 | `Gate::before` ให้ deny ปฏิเสธ Super Admin ได้ | **PASS** |
| 04-6 | รวม `custom_permissions` เข้า Spatie | **PASS** (+ แก้ bug ที่ทำงานพังจริง) |
| 04-7 | compat `role_id` | **PASS** (ปัญหาหมดไปเองแล้ว) |
| 04-8 | frontend เลิกเช็คชื่อ role | **PARTIAL** — 12/14 จุด |
| 04-9 | authorization test matrix | **PASS** |

### Verification ตามที่แผนกำหนด

| เกณฑ์ | ผล |
|---|---|
| `grep hasRole\|hasAnyRole` ใน `app/` = 0 (ยกเว้น compat ที่ประกาศไว้) | **6 จุด** — 3 เป็น Super Admin bootstrap ที่ตั้งใจคงไว้ · 3 รอการตัดสินใจ |
| test matrix ผ่านครบ | ✅ 4/4 |
| **ผู้ใช้ทุกคนได้สิทธิ์เท่าเดิมหรือแคบลง** | ✅ **ไม่มีใครเสียสิทธิ์ 0/7 คน** |
| Rollback ด้วย feature flag | ✅ `USE_NEW_PERMISSION_ENGINE=false` พร้อมเทสต์ยืนยัน |

### ตัวเลขรวม

| | ก่อนเฟส | หลังเฟส |
|---|---:|---:|
| test | 220 | **243** (582 assertions) |
| `hasRole` ใน `app/` | 24 | **6** |
| role check ใน `stores/auth.js` | 14 | **2** |
| ตาราง alias ฝั่ง frontend | 11 ชุด | **0** |
| Pint | 5 ไฟล์ FAIL | **PASS 328 ไฟล์** |

---

## 🔴 สิ่งที่ค้นพบระหว่างทาง — สำคัญกว่าตัว refactor

เฟสนี้ตั้งใจจะ "จัดระเบียบชื่อ" แต่กลับพบว่า **สิ่งที่โค้ดตั้งใจจะทำ กับสิ่งที่มันทำจริง ไม่ตรงกันอย่างเป็นระบบ**

### 1. ชื่อ permission ที่โค้ดบังคับใช้ ส่วนใหญ่ไม่มีอยู่จริง

ฐานข้อมูลมี permission เพียง 15 ตัวและเกือบทั้งหมดเป็นชื่อรุ่นเก่า
ขณะที่โค้ดเช็คชื่อ canonical ที่ไม่เคยถูก seed

**ผลกระทบที่รุนแรงที่สุด — บุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้เลย**

route บังคับ `permission:attendance.checkin|attendance.view_self` แต่ทั้งสองชื่อไม่มีในฐานข้อมูล
ตรวจผู้ใช้ทั้ง 7 คนบน production:

| ผู้ใช้ | ลงเวลาได้ |
|---|:---:|
| admin, superadmin | ✅ |
| director, deputy, hrhead, employee, doc | ❌ **ทุกคน** |

รายการที่หายไปทั้งหมด (ยืนยันกับ production แล้ว):

```
attendance.checkin              attendance.view_self
attendance.ot                   attendance.ot_summary
system.menu.manage              admin.users.manage_permissions
saraban.setting.manage
```

**เป็นปัญหาที่มีมาก่อน LEGACY-04** สอดคล้องกับที่ตาราง `attendances` มีข้อมูลเพียง 1 แถวตั้งแต่ baseline

ผม**ไม่เติมให้เอง** เพราะเป็นการให้สิทธิ์ใหม่ — บันทึกไว้ใน `KNOWN_MISSING_PENDING_DECISION`
พร้อมเทสต์คู่กันที่จะล้มถ้ามีคนเติมแล้วลืมถอดชื่อออกจากรายการ

### 2. ชื่อ role ที่โค้ดเช็ค ส่วนใหญ่ไม่มีอยู่จริง

| มีจริง | ไม่มีเลย |
|---|---|
| `Super Admin`, `Director`, `Deputy Director`, `HR Head`, `Document Officer`, `Employee` | `SuperAdmin`, `ผู้ดูแลระบบสูงสุด`, `ผู้อำนวยการ`, `รองผู้อำนวยการ`, `เจ้าหน้าที่บุคลากร (HR)`, **`หัวหน้าแผนกวิชา / หัวหน้าฝ่าย`**, `Head of Department`, `admin_saraban`, `เจ้าหน้าที่สารบัญ`, `Saraban Officer`, `admin`, `super_admin` |

ผลที่ตามมา:

- ตรรกะ **"หรี่ขอบเขตให้เห็นเฉพาะฝ่ายตน" เป็น dead code ทั้ง 5 จุด** ไม่เคยทำงานเลย
  ทุกคนที่ผ่านด่านแรกเห็นข้อมูลทั้งองค์กรมาตลอด
- `MenuService::$isAdmin` เป็น `false` เสมอ
- `Document Officer` ที่มีจริง **ไม่ถูกนับเป็นเจ้าหน้าที่สารบรรณ** เพราะโค้ดเช็ค `admin_saraban`
  → โมดูลสารบรรณใช้ได้แค่ Super Admin

### 3. การกำหนดสิทธิ์รายบุคคลพังทั้งฟีเจอร์

`users.custom_permissions` ถูกลบไปใน LEGACY-03 แต่ `Admin\UserController` ยังเขียนลงไป
(`PermissionController` แก้ไปแล้วแต่ตัวนี้ตกหล่น) และหน้าจัดการผู้ใช้ส่งค่านี้มาทุกครั้ง

ทุกครั้งที่ผู้ดูแลกำหนดสิทธิ์รายบุคคล → `SQLSTATE: no such column` → **500**

เขียนเทสต์พิสูจน์ก่อนแก้ ล้มทั้ง 3 กรณี (สร้าง / แก้ไข / ล้างสิทธิ์) แก้แล้วผ่านครบ

### 4. `/api/dashboard` ใช้ `MONTH()` ที่เป็นฟังก์ชันเฉพาะ MySQL

พังทันทีบน SQLite ที่ชุดทดสอบใช้ → **แดชบอร์ดรวมไม่เคยถูกทดสอบเลย**
ยืนยันด้วย git ว่ามีมาก่อนและ diff ของเฟสนี้ไม่ได้แตะบรรทัดนั้น
เป็นตัวอย่างรูปธรรมของ DB-RISK-008 ที่บันทึกไว้ตั้งแต่ LEGACY-00

---

## สิ่งที่ทำ

### 04-1 canonical permission + alias

migration additive `2026_08_13_130000_create_authorization_foundation`
สร้าง `permission_aliases`, `permission_denies`, `permission_scopes`

map 6 คู่ตามที่เจ้าของระบบยืนยัน — **เพิ่มอย่างเดียว ไม่ถอนของเดิม**:

| ชื่อเก่า | → canonical | role ที่ได้เพิ่ม |
|---|---|---|
| `approve_leave` | `leave.approval.dept` | Deputy, Director, HR Head, Super Admin |
| `export_reports` | `leave.report.view` | + Document Officer |
| `view_all_attendances` | `attendance.view_all` | + Document Officer |
| `edit_users` | `system.user.manage` | Super Admin |
| `view_users` | `system.user.view` | HR Head, Super Admin |
| `manage_settings` | `system.setting.manage` | Super Admin |

**จงใจไม่ map อะไรไปเป็น `attendance.ot_summary`** เพราะเดิมไม่มี role ใดถืออยู่ — มีเทสต์ล็อกไว้

> งานข้อนี้ยังแก้ **regression ที่ผมสร้างเองใน LEGACY-02B** ด้วย: ตอนนั้นผมใส่
> `can:attendance.view_all` ลง route โดยไม่รู้ว่าชื่อนั้นไม่มีในฐานข้อมูล
> ทำให้ ผอ./รอง ผอ./HR export ข้อมูลลงเวลาไม่ได้ และผมรายงานผิดว่า
> "รักษาพฤติกรรมเดิมของผู้ใช้ที่ได้รับอนุญาต"

### 04-2 / 04-5 PermissionEvaluator

ลำดับ **explicit deny > allow > default deny** ตาม `docs/06-RBAC-ABAC.md`

- `Gate::before` ให้ **explicit deny ชนะแม้กระทั่ง Super Admin** ซึ่งเดิมผ่านทุกอย่างโดยไม่มีทางปฏิเสธ
- เทียบชื่อ permission ข้ามรุ่นให้อัตโนมัติ และกันไม่ให้หลบ deny ด้วยการเรียกอีกชื่อหนึ่ง
- rollback: `USE_NEW_PERMISSION_ENGINE=false` กลับพฤติกรรมเดิมทันทีโดยไม่ต้อง revert

### 04-3 OrgScopeResolver

รองรับ `SELF / UNIT / DESCENDANTS / ORGANIZATION / ASSIGNED / CUSTOM`
ไต่ต้นไม้ `org_units` ทีละชั้นแทน recursive CTE (ทำงานได้ทั้ง MySQL 8 และ SQLite · กันวนซ้ำ)

**ค่าเริ่มต้นเลือกให้ตรงกับพฤติกรรมเดิมของแต่ละจุด:**

| จุดใช้งาน | default | เหตุผล |
|---|---|---|
| Policy รายรายการ | `UNIT` | เดิมเทียบ `department_id` ตรง ๆ |
| การแสดงรายการ/รายงาน | `ORGANIZATION` | เดิมไม่เคยถูกจำกัดเลย (role ที่ใช้หรี่ไม่มีอยู่จริง) |

ผู้ดูแลหรี่ให้รายคนได้ภายหลังโดยไม่ต้องแก้โค้ด

### 04-4 ถอด `hasRole` — 18/24 จุด

| ส่วน | ไฟล์ | จุด |
|---|---|---:|
| 1/4 | `LeaveController::index` | 2 |
| 2/4 | `ApprovalController`, `LeaveCardController`, `GovernmentApprovalController` | 8 |
| 3/4 | `DashboardController`, `ReportController` | 6 |
| 4/4 | `LeaveController` ที่เหลือ | 4 |

เพิ่ม `LeaveVisibility` เป็นจุดเดียวที่ตอบว่า "ใครเห็นใบลาของใคร"

### 04-6 / 04-7

ถอดการเขียน `custom_permissions` ที่ทำให้ระบบพัง + ถอดออกจาก `fillable`/`casts`
ตัดการรวม `role_id` ที่ซ้ำซ้อน 4 จุด (DB-RISK-002 หมดไปเองแล้วเพราะคอลัมน์ถูกลบ)

### 04-8 frontend

`stores/auth.js`: ถอด getter ที่ไม่มีใครเรียก 4 ตัว · เปลี่ยน `isManager`/`isAdmin`/`isUserManager`/`isHRHead`
เป็น permission ล้วน · **ถอดตาราง alias 11 ชุดออกทั้งหมด** · รวมการเช็ค Super Admin 12 จุดเป็น helper เดียว

### 04-9 test matrix

เทสต์ตัวหลักดึงชื่อ permission ทุกตัวที่ route บังคับใช้ แล้วเทียบกับ**ชุดที่ seeder สร้างจริง**
ไม่ใช่ fixture ที่เทสต์สร้างเอง — ซึ่งเป็นจุดบอดที่ทำให้ regression ของ LEGACY-02B หลุดไปได้

รันครั้งแรกก็จับ permission ที่หายไป 7 ตัวได้ทันที

---

## หลักฐานว่าไม่มีใครเสียสิทธิ์

เทียบ snapshot รายคน ก่อนเริ่มเฟส vs หลังจบเฟส

| ผู้ใช้ | ก่อน | หลัง | เสียสิทธิ์ |
|---|---:|---:|---|
| admin | 15 | 21 | — |
| superadmin | 15 | 21 | — |
| director | 3 | 6 | — |
| deputy | 3 | 6 | — |
| hrhead | 4 | 8 | — |
| employee | 0 | 0 | — |
| doc | 2 | 4 | — |

**จำนวนผู้ใช้ที่เสียสิทธิ์: 0**

ส่วนที่เพิ่มคือชื่อ canonical ที่เทียบเท่าชื่อเดิมที่ถืออยู่แล้ว ไม่ใช่ความสามารถใหม่

ไฟล์: `docs/checkpoints/legacy-00/permission-snapshot-before-legacy04.json` และ `...-after-legacy04.json`

---

## หนี้ทางเทคนิคที่เฟสนี้สร้างขึ้นโดยตั้งใจ

กฎ "ห้ามใครได้หรือเสียสิทธิ์" บังคับให้เลือก permission ที่ตรง**ชุดผู้ใช้เดิม**
ไม่ใช่ที่ชื่อสื่อความหมายถูกต้อง ผลคือ:

| จุด | permission ที่ใช้ | ทำไมถึงเลือกตัวนี้ |
|---|---|---|
| ยกเลิกใบลาของผู้อื่น | `system.user.view` | เป็นสิทธิ์ที่ HR Head ถือและ Director/Deputy/Document Officer ไม่ถือ — ตรงชุด {Super Admin, HR Head} เดิมพอดี |
| แดชบอร์ดรวม | `leave.approval.dept` | ตรงชุด {Director, Deputy, HR Head, Super Admin} และกัน Document Officer ไม่ให้ได้เพิ่ม |

ใส่คอมเมนต์กำกับไว้ทุกจุดแล้ว **ควรตั้ง permission เฉพาะทาง** (เช่น `leave.request.cancel_any`)
ตอนจัดระเบียบ permission ชุดใหญ่

---

## สิ่งที่ผมทำพลาดในเฟสนี้

บันทึกไว้เพื่อให้ตรวจสอบย้อนหลังได้

1. **push ทั้งที่ Pint ยังแดง** (`be3c783`) — ต่อคำสั่งด้วย `&&` หลัง `tail` ซึ่งไม่สะท้อน exit code ของ Pint
   จับได้ตอนตรวจซ้ำ แก้ใน `afab56f`
2. **เกือบเพิ่มสิทธิ์ให้ `leave.approval.hr`** ในชุด "เห็นใบลาคนอื่น" — เทสต์ที่เขียนก่อนแก้จับได้ว่า
   วันนี้ผู้ถือสิทธิ์นั้นไม่เห็นรายการใบลา
3. **เกือบให้ Document Officer ได้แดชบอร์ดรวม** ถ้าเลือก `leave.report.view` มาตัดสิน
4. **เกือบทำ HR Head เสียสิทธิ์ยกเลิกใบลา** ถ้าใช้ `leave.management.manage` อย่างเดียว
5. **เขียนเทสต์ที่ตั้งข้อมูลขัดกันเอง** (กำหนด `org_unit_id` พร้อม `department_id`) แล้วเกือบสรุปว่าโค้ดผิด
   ทั้งที่โค้ด production ถูก — แก้ได้เพราะใส่ debug ดูของจริงแทนการเดา

> ข้อ 2–4 ถูกจับได้ด้วยการเขียนเทสต์**ก่อน**แก้โค้ดทุกครั้ง ซึ่งเป็นเหตุผลที่แผนกำหนดวิธีนี้ไว้

---

## สิ่งที่ยังไม่ได้ทำ และเหตุผล

| เรื่อง | ติดอะไร |
|---|---|
| `hasRole` 3 จุดสุดท้าย (`Saraban/DocumentController`, `Saraban/DocumentPolicy`, `MenuService`) | รอคำตอบว่า **`Document Officer` ควรถือสิทธิ์สารบรรณหรือไม่** |
| `isSarabanOfficer` ฝั่ง frontend | เรื่องเดียวกัน |
| เติม permission 7 ตัวที่หายไป | เป็นการให้สิทธิ์ใหม่ ต้องได้รับอนุมัติ |
| หน้าจอจัดการ `permission_scopes` | เป็น feature ใหม่ อยู่นอกขอบเขต LEGACY-04 |
| ลบ fallback `department_id` ใน `LeavePolicy` | ต้องยืนยันก่อนว่าผู้ใช้ทุกคนมี `org_unit_id` ครบ |

### `hasRole` ที่คงไว้โดยตั้งใจ (3 จุด)

`AppServiceProvider` (2) + `PermissionEvaluator` (1) — เป็น **bootstrap identity** ของ Super Admin
เหมือนกับที่ frontend คงไว้ 1 จุด ถ้าผูกกับ permission จะเกิดปัญหาไก่กับไข่ตอนตั้งค่าระบบครั้งแรก
มีเทสต์ยืนยันว่า role ชื่อนี้มีอยู่จริง

---

## คำถามที่ต้องการคำตอบ

| # | คำถาม | ผลถ้าไม่ตอบ |
|---|---|---|
| 1 | **จะเติม permission 7 ตัวที่หายไปไหม** โดยเฉพาะ `attendance.checkin` / `attendance.view_self` | บุคลากรลงเวลาเข้า-ออกไม่ได้ต่อไป |
| 2 | `Document Officer` ควรถือสิทธิ์สารบรรณหรือไม่ | โมดูลสารบรรณใช้ได้แค่ Super Admin · `hasRole` 3 จุดสุดท้ายถอดไม่ได้ |
| 3 | องค์กรมีตำแหน่ง "หัวหน้าแผนก/หัวหน้าฝ่าย" จริงหรือไม่ | scope `UNIT` ยังไม่มีใครใช้ |
| 4 | ผอ./รอง ผอ. ควรเห็นทั้งองค์กรหรือเฉพาะสายงาน | ตอนนี้เห็นทั้งองค์กรตามพฤติกรรมเดิม |

---

## Rollback

| จะย้อน | วิธี |
|---|---|
| ตรรกะการตัดสินสิทธิ์ทั้งหมด | `USE_NEW_PERMISSION_ENGINE=false` — ไม่ต้องแตะฐานข้อมูล มีเทสต์ยืนยัน |
| โค้ดทั้งเฟส | `git revert` ช่วง `2bfb71c..afab56f` |
| permission ที่ alias เพิ่มให้ role | ต้องทำด้วยมือ — migration `down()` จงใจไม่ถอน เพราะการถอนจะทำให้ผู้ใช้เสียสิทธิ์ที่ควรมีตั้งแต่แรก |

---

## สรุป: **PARTIAL PASS**

งานที่ทำได้โดยไม่เปลี่ยนสิทธิ์ของใครเสร็จครบ · test 243/243 · Pint ผ่านทั้งโปรเจกต์ · build ผ่าน
· ไม่มีผู้ใช้คนใดเสียสิทธิ์

ให้ PARTIAL เพราะเหลือ 3 จุดที่ถอด `hasRole` ไม่ได้จนกว่าจะได้คำตอบ และเพราะ
**พบข้อบกพร่องที่กระทบการใช้งานจริงซึ่งยังไม่ได้แก้** (การลงเวลา)

> ข้อสังเกตสำหรับผู้ตรวจ: เฟสนี้ใช้เวลากับ**การค้นพบ**มากกว่าการแก้ไข
> ทุกครั้งที่เขียนเทสต์ก่อนแก้ จะพบว่าสิ่งที่โค้ดตั้งใจทำกับสิ่งที่มันทำจริงไม่ตรงกัน
> ซึ่งเป็นผลของการไม่มีเทสต์ครอบชั้น authorization มาก่อน — สิ่งที่ 04-9 ปิดไปแล้ว

---

## หยุดที่นี่ — รอคำสั่งก่อนเริ่ม LEGACY-05

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-14 | PARTIAL PASS |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
