# LEGACY-04 — FINAL REPORT (ปิดงาน)

**วันที่:** 14 สิงหาคม 2569 (2026-08-14)
**ขอบเขต:** เฉพาะรายการที่ค้างอยู่ตาม `LEGACY-04-REPORT.md` ตามการตัดสินใจ 4 ข้อของเจ้าของระบบ
**baseline:** `LEGACY-04-REPORT.md` (PARTIAL PASS · commit `a5eed2a`)
**ผล:** **LEGACY-04 = PASS**

---

## สรุปสิ่งที่ปิดไป

| ค้างอยู่ตอน PARTIAL | สถานะตอนนี้ |
|---|---|
| permission 7 ตัวที่ route บังคับใช้แต่ไม่มีในฐานข้อมูล | ✅ สร้างและแจกตามหน้าที่แล้ว |
| บุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้ | ✅ แก้แล้ว · ยืนยันกับฐานข้อมูลจริง |
| `Document Officer` ไม่ถูกนับเป็นเจ้าหน้าที่สารบรรณ | ✅ แก้แล้วด้วย permission ไม่ใช่ชื่อ role |
| `hasRole` 3 จุดสุดท้าย (Saraban ×2, MenuService) | ✅ ถอดหมดแล้ว |
| `isSarabanOfficer` ฝั่งหน้าเว็บยังเช็คชื่อ role | ✅ เปลี่ยนเป็น permission แล้ว |
| หัวหน้าแผนก/หัวหน้าฝ่าย ยังไม่มีโครงสร้างรองรับ | ✅ `org_unit_managers` แบบ effective-dated |
| scope ของ ผอ./รอง ผอ. ยังไม่ถูกกำหนด | ✅ บันทึกเป็น ADR-007 + กลไกรองรับครบ |

---

## 1. Permission ที่เพิ่ม

### 1.1 เจ็ดตัวตาม Decision 1

ทั้งเจ็ดตัวถูก route บังคับใช้อยู่แล้วแต่ **ไม่เคยมีแถวในฐานข้อมูล** ผลคือทุก endpoint
ที่เกี่ยวข้องใช้ได้เฉพาะ Super Admin

| permission | ความหมาย |
|---|---|
| `attendance.checkin` | บันทึกเวลาเข้า-ออกงานของตนเอง |
| `attendance.view_self` | ดูประวัติการลงเวลาของตนเอง |
| `attendance.ot` | บันทึกเวลาทำงานล่วงเวลา |
| `attendance.ot_summary` | ดูสรุป OT ของทั้งองค์กร |
| `system.menu.manage` | จัดการเมนูและบทบาท |
| `admin.users.manage_permissions` | กำหนดสิทธิ์รายบุคคล |
| `saraban.setting.manage` | ตั้งค่าระบบสารบรรณ |

### 1.2 ชุดสารบรรณตาม Decision 2

Decision 2 กำหนดให้แยก permission อย่างน้อย 6 ด้าน ตรวจแล้วพบว่าฐานข้อมูลจริง
**ไม่มี permission `saraban.*` เลยสักตัว** (seeder ที่สร้างไว้ไม่เคยถูกรันบนระบบนี้)
จึงต้องสร้างทั้งชุด

| ด้านที่ Decision 2 กำหนด | permission |
|---|---|
| ดูเอกสาร | `saraban.document.view` |
| สร้าง/แก้ไข | `saraban.document.create`, `saraban.document.edit` |
| รับ/ส่ง | `saraban.document.register`, `saraban.circular.broadcast` |
| ลบ | `saraban.document.delete` |
| ดูเอกสารลับ | `saraban.document.view_classified` |
| ตั้งค่าระบบสารบรรณ | `saraban.setting.manage` |
| (เพิ่มเติมที่โค้ดบังคับใช้อยู่) | `saraban.document.approve`, `.sign`, `.report`, `.manage_types` |

> **ชื่อใหม่ตัวเดียวในงานนี้คือ `saraban.document.register`**
>
> Decision 1 ห้ามตั้งชื่อ alias รุ่นใหม่โดยไม่จำเป็น ตัวนี้จำเป็นเพราะ Decision 2
> สั่งให้ "รับ/ส่ง" เป็นสิทธิ์แยก ขณะที่ของเดิมฝังหน้าที่เจ้าหน้าที่ทะเบียนไว้กับ
> `saraban.document.manage_types` (ตั้งค่าประเภทหนังสือ) ซึ่งเป็นคนละเรื่องกัน
> ถ้าไม่แยก การให้สิทธิ์รับ/ส่งจะพ่วงสิทธิ์ตั้งค่าไปด้วยเสมอ

ชื่ออื่นทั้งหมดเป็น canonical ที่โค้ดบังคับใช้อยู่แล้ว — ไม่มีการสร้างชื่อรุ่นใหม่

**ไฟล์:**
`backend/database/seeders/OperationalPermissionSeeder.php` (แหล่งความจริงเดียว)
`backend/database/migrations/2026_08_14_100000_seed_operational_permissions.php`

migration เรียก seeder ตัวเดียวกัน ระบบที่ติดตั้งแล้วกับติดตั้งใหม่จึงได้ผลตรงกันเสมอ

---

## 2. Role / User ที่ได้รับ

| permission | Employee | Document Officer | HR Head | Deputy | Director | Super Admin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| `attendance.checkin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `attendance.view_self` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `attendance.ot` | — | — | ✅ | — | — | ✅ |
| `attendance.ot_summary` | — | — | ✅ | ✅ | ✅ | ✅ |
| `saraban.document.view` | — | ✅ | — | — | — | ✅ |
| `saraban.document.create` | — | ✅ | — | — | — | ✅ |
| `saraban.document.edit` | — | ✅ | — | — | — | ✅ |
| `saraban.document.register` | — | ✅ | — | — | — | ✅ |
| `saraban.circular.broadcast` | — | ✅ | — | — | — | ✅ |
| `saraban.document.report` | — | ✅ | — | — | — | ✅ |
| `saraban.document.delete` | — | — | — | — | — | ✅ |
| `saraban.document.view_classified` | — | — | — | — | — | ✅ |
| `saraban.document.manage_types` | — | — | — | — | — | ✅ |
| `saraban.setting.manage` | — | — | — | — | — | ✅ |
| `system.menu.manage` | — | — | — | — | — | ✅ |
| `admin.users.manage_permissions` | — | — | — | — | — | ✅ |

### เหตุผลของแต่ละการตัดสิน

**ทุก role ได้สิทธิ์ลงเวลา** — ทุกคนในองค์กรเป็นบุคลากรที่ต้องลงเวลา ทั้งสองสิทธิ์นี้
เห็นได้เฉพาะข้อมูลของตนเอง ไม่เปิดข้อมูลของใครเพิ่ม

**`attendance.ot` ให้ HR เท่านั้น** — Decision 1 ระบุ "ผู้ดูแล OT ... กำหนดตามหน้าที่จริง"
ผู้ดูแล OT ในองค์กรนี้คืองานบุคลากร

**`attendance.ot_summary` ให้ HR + ผอ. + รอง ผอ.** — ทั้งสามถือ `attendance.view_all`
อยู่ก่อนแล้ว จึงเห็นข้อมูลการลงเวลาทั้งองค์กรได้ตั้งแต่ต้น สรุป OT ไม่ใช่ข้อมูลชั้นใหม่
สำหรับกลุ่มนี้ **ไม่ให้** Document Officer แม้จะถือ `attendance.view_all` เพราะไม่มีหน้าที่ด้าน OT
(มีเทสต์บังคับความสัมพันธ์นี้ไว้ — ถ้าวันหนึ่งมีคนให้ `ot_summary` แก่ผู้ที่ไม่มี
`view_all` เทสต์จะล้ม)

**Document Officer ไม่ได้สี่ตัว** (`delete`, `view_classified`, `manage_types`,
`setting.manage`) ตามที่ Decision 2 สั่งไว้ตรง ๆ — มอบหมายรายบุคคลได้ผ่านหน้าจัดการสิทธิ์
มีเทสต์ยืนยันว่าการมอบหมายรายบุคคลใช้ได้จริงและไม่หลุดไปทั้ง role

**สิทธิ์ระดับระบบให้ Super Admin เท่านั้น** — `system.menu.manage`,
`admin.users.manage_permissions`, `saraban.setting.manage` และสิทธิ์อันตรายของสารบรรณ
มีเทสต์ที่ไล่ทุก role ในฐานข้อมูลเพื่อยืนยันว่าไม่มี role อื่นถือ

**ไม่ได้ให้ `saraban.document.approve` / `.sign` แก่ ผอ./รอง ผอ.** — การตัดสินใจที่
ได้รับครอบคลุมเฉพาะ Document Officer การให้ผู้บริหารอนุมัติ/ลงนามหนังสือเป็นการขยายสิทธิ์
ที่ยังไม่ได้รับอนุมัติ จึงคงสถานะเดิมไว้ (ดูข้อ 9 — รายการที่ต้องตัดสินใจต่อ)

---

## 3. Snapshot สิทธิ์ ก่อน / หลัง

ดึงจากฐานข้อมูลจริง (`eleave` บน MySQL 8) ทั้งสองครั้ง

ไฟล์: `docs/checkpoints/legacy-00/permission-snapshot-before-legacy04-final.json`
และ `...-after-legacy04-final.json`

| ผู้ใช้ | role | ก่อน | หลัง | ได้เพิ่ม | **เสียสิทธิ์** |
|---|---|---:|---:|---|---|
| admin@eleave.local | Super Admin | 21 | 39 | +18 | **ไม่มี** |
| superadmin@example.com | Super Admin | 21 | 39 | +18 | **ไม่มี** |
| director@example.com | Director | 6 | 9 | checkin, view_self, ot_summary | **ไม่มี** |
| deputy@example.com | Deputy Director | 6 | 9 | checkin, view_self, ot_summary | **ไม่มี** |
| hrhead@example.com | HR Head | 8 | 12 | checkin, view_self, ot, ot_summary | **ไม่มี** |
| employee@example.com | Employee | 0 | 2 | checkin, view_self | **ไม่มี** |
| doc@example.com | Document Officer | 4 | 12 | ลงเวลา 2 + สารบรรณ 6 | **ไม่มี** |

**จำนวนผู้ใช้ที่เสียสิทธิ์ระดับ permission: 0**

### ตรวจผลจริงที่ระดับ ability

```
                     checkin view_self  ot  ot_summary view_all saraban.view delete classified
employee@example.com    Y        Y       n      n         n         n          n        n
doc@example.com         Y        Y       n      n         Y         Y          n        n
director@example.com    Y        Y       n      Y         Y         n          n        n
deputy@example.com      Y        Y       n      Y         Y         n          n        n
hrhead@example.com      Y        Y       Y      Y         Y         n          n        n
```

ตรงกับตารางในข้อ 2 ทุกช่อง

---

## 4. `hasRole` ที่ยังเหลือ พร้อมเหตุผล

`grep hasRole|hasAnyRole` ใน `backend/app/` = **3 จุด** ทั้งหมดเป็น Super Admin bootstrap
ที่ประกาศไว้ตั้งแต่ต้น

| ไฟล์ | บรรทัด | เหตุผล |
|---|---:|---|
| `app/Providers/AppServiceProvider.php` | 69 | ทาง rollback เมื่อ `USE_NEW_PERMISSION_ENGINE=false` |
| `app/Providers/AppServiceProvider.php` | 78 | `Gate::before` — Super Admin ผ่านทุก ability |
| `app/Services/PermissionEvaluator.php` | 141 | ตัวตัดสินกลางถาม identity เดียวกัน |

เป็น **bootstrap identity**: ถ้าผูกกับ permission จะเกิดปัญหาไก่กับไข่ตอนตั้งค่าระบบครั้งแรก
(ยังไม่มีใครถือ permission ใดเลย จึงไม่มีใครมอบ permission ให้ใครได้)

ฝั่งหน้าเว็บเหลือ **1 จุด** ด้วยเหตุผลเดียวกัน (`isSuperAdminUser` ใน `stores/auth.js`)
ส่วน getter `roleName` ใช้แสดงผลอย่างเดียว ไม่ตัดสินสิทธิ์

> เพิ่ม **เทสต์อัตโนมัติ** แทนการ grep ด้วยมือ:
> `AuthorizationMatrixTest::test_role_name_checks_survive_only_at_the_declared_bootstrap_points`
> ไล่ทุกไฟล์ใน `app/` และล้มทันทีถ้ามีใครเพิ่มการตัดสินสิทธิ์จากชื่อ role ที่จุดใหม่

### จุดที่ถอดออกในรอบนี้

| ไฟล์ | เดิมเช็ค | ตอนนี้ |
|---|---|---|
| `app/Policies/Saraban/DocumentPolicy.php` | `hasRole(['Super Admin','admin_saraban'])` | `can('saraban.document.register')` |
| `app/Http/Controllers/Api/Saraban/DocumentController.php` | `hasRole([...,'admin_saraban'])` | `can('saraban.document.register')` |
| `app/Services/MenuService.php` | `hasRole('admin') \|\| 'super_admin' \|\| 'admin_saraban'` | `can('system.menu.manage')` |
| `app/Services/Saraban/DocumentWorkflowService.php` | `permission('saraban.document.manage_types')` | `permission('saraban.document.register')` |
| `frontend/src/stores/auth.js` | ชื่อ role 4 ชื่อ | `saraban.document.register` |

ทั้งสามชื่อที่ถอดออก (`admin`, `super_admin`, `admin_saraban`) **ไม่มีอยู่จริงในฐานข้อมูล**
ตรรกะเหล่านั้นจึงไม่เคยทำงานเลย — `MenuService::$isAdmin` เป็น `false` เสมอ และ
เจ้าหน้าที่สารบรรณตัวจริงไม่เคยถูกนับเป็นเจ้าหน้าที่ทะเบียน

### seeder ที่เคยสร้าง role ผี

`SarabanPermissionSeeder` เดิม **สร้าง role ขึ้นมาเอง 5 ชื่อ** (`admin_saraban`,
`Saraban Officer`, `เจ้าหน้าที่สารบัญ`, `manager`, `staff`) แล้วแจกสิทธิ์ให้ role เหล่านั้น
ซึ่งไม่มีผู้ใช้จริงคนใดถืออยู่ ตอนนี้เปลี่ยนเป็นเรียก `OperationalPermissionSeeder`
มีเทสต์ยืนยันว่าการติดตั้งไม่สร้างชื่อเหล่านี้อีก

---

## 5. Scope ของ Director / Deputy / Manager (Decision 3 + 4)

บันทึกเป็น **ADR-006** และ **ADR-007** ใน `docs/20-DECISION-LOG.md`

### ค่าเริ่มต้นที่กำหนด

| ตำแหน่ง | scope | สถานะการบังคับใช้ |
|---|---|---|
| ผู้อำนวยการ | `ORGANIZATION` | มีผลอยู่แล้ว (พฤติกรรมปัจจุบัน) |
| รองผู้อำนวยการ | `DESCENDANTS` ของหน่วยงาน/สายงานที่ได้รับมอบหมาย | กลไกพร้อม · รอข้อมูลการมอบหมาย |
| HR | ตาม permission + scope ที่ได้รับ | มีผลอยู่แล้ว |
| หัวหน้าแผนก/หน่วยงาน | `UNIT` หรือ `DESCENDANTS` | กลไกพร้อม · รอข้อมูลการมอบหมาย |
| Super Admin | ทั้งระบบ · explicit DENY override ได้ | มีผลอยู่แล้ว (04-5) |

### โครงสร้างที่เพิ่ม — `org_unit_managers`

Decision 3 สั่งชัดว่า **ห้ามสร้าง role ชื่อ "หัวหน้าแผนก" เพื่อใช้เป็น business logic**
ตำแหน่งหัวหน้าจึงเก็บเป็น **ข้อมูลการมอบหมาย** ผูกกับ `org_units`

| คอลัมน์ | รองรับอะไร |
|---|---|
| `org_unit_id`, `user_id` | ใครดูแลหน่วยงานไหน |
| `assignment_type` | `MANAGER` / `ACTING` (ผู้รักษาการ) / `DELEGATE` (ผู้รับมอบอำนาจ) |
| `scope` | `UNIT` หรือ `DESCENDANTS` ตาม Decision 3 |
| `effective_from` / `effective_to` | effective dating — ไม่ overwrite ประวัติ ตาม PROJECT_RULES ข้อ 7 |

`OrgScopeResolver` ตัดสินตามลำดับ:

1. แถวใน `permission_scopes` ที่ผู้ดูแลกำหนดเอง — **ชนะเสมอ** (ใช้หรี่ขอบเขตรายคนได้
   โดยไม่ต้องถอนการมอบหมาย)
2. การมอบหมายที่ **มีผล ณ วันนี้**
3. ค่าเริ่มต้นของจุดเรียก (เท่าพฤติกรรมเดิม)

**ตารางถูกสร้างว่างเปล่า — ไม่มีใครได้หรือเสียสิทธิ์จากการเพิ่มโครงสร้างนี้**
มีเทสต์ยืนยันข้อนี้ (`test_without_any_assignment_nothing_changes`) พร้อมเทสต์ว่า
การมอบหมายที่หมดอายุหรือยังไม่ถึงกำหนดให้สิทธิ์ไม่ได้

**ไม่มีการ hard-code ชื่อบุคคลหรือชื่อฝ่ายในโค้ดทั้งสิ้น**

---

## 6. Security regression — สิ่งที่ตรวจพบและปิดเพิ่ม

ข้อกำหนดความปลอดภัยของงานนี้ระบุว่าการเติม permission ต้องไม่ทำให้ผู้ใช้เห็นข้อมูล
เกินหน้าที่ ตอนไล่ route เพื่อวางสิทธิ์ พบช่องโหว่ **ที่มีมาก่อนงานนี้** 3 จุด

### 6.1 ผู้ถือ `edit_users` มอบ permission ให้ตัวเองได้

```
เดิม: permission:edit_users|system.user.manage|admin.users.manage_permissions
      └─ ครอบ PUT /admin/users/{user}/permissions ด้วย
```

`edit_users` คือสิทธิ์แก้ไข **ข้อมูล** ผู้ใช้ ไม่ใช่สิทธิ์ **มอบ** สิทธิ์
ถ้าเติม `admin.users.manage_permissions` เข้าไปเฉย ๆ ช่องนี้จะยังเปิดอยู่

**แก้:** แยก 4 endpoint การจัดการสิทธิ์ออกมาให้ต้องมี `admin.users.manage_permissions` เท่านั้น

### 6.2 ผู้ถือ `edit_users` สร้าง role แล้วผูก permission ใดก็ได้

```
เดิม: permission:edit_users|system.menu.manage
      └─ ครอบ apiResource /admin/roles ด้วย
```

ทางอ้อมที่ให้ผลเดียวกับ 6.1

**แก้:** เหลือ `system.menu.manage` อย่างเดียว

### 6.3 `saraban.setting.manage` เปิดค่าตั้งค่าทั้งระบบ

```
เดิม: permission:manage_settings|system.setting.manage|saraban.setting.manage
      └─ ครอบ SMTP, โทเคน Telegram/LINE, ล้างค่าลับ, ปีงบประมาณ, เทมเพลตเอกสาร
```

ถ้าแจก `saraban.setting.manage` ตาม Decision 1 โดยไม่แตะ route ผู้ดูแลงานสารบรรณ
จะเห็นและแก้ค่าลับของทั้งระบบได้ทันที

**แก้:** ถอดออกจากกลุ่มนั้น และสร้าง `/api/saraban/settings` ที่เปิดเฉพาะ 5 คีย์ของงานสารบรรณ
(`require_pin_for_signature`, `require_pin_for_circular_ack`, รูปแบบเลขหนังสือเข้า/ออก,
ตราครุฑ) พร้อมปฏิเสธคีย์นอกขอบเขตด้วย 422

### ผลกระทบต่อผู้ใช้ปัจจุบัน

| จุด | ใครเสียความสามารถ |
|---|---|
| 6.1 / 6.2 | ปัจจุบันมีแต่ Super Admin ที่ถือ `edit_users` → **ไม่มีใครเสีย** |
| 6.3 | `saraban.setting.manage` เพิ่งถูกสร้างในงานนี้ → **ไม่มีใครเสีย** |

ทั้งสามจุดเป็นการ**ปิดช่อง** ไม่ใช่การถอนสิทธิ์ที่ใครใช้งานอยู่จริง
ล็อกด้วย `PrivilegeEscalationGuardTest` (7 เทสต์)

### หน้าเว็บที่แก้ตาม

ปุ่มที่เคยแสดงตาม role แล้วโดน backend ปฏิเสธ ถูกเปลี่ยนไปถามสิทธิ์ตัวเดียวกับที่ route บังคับ

| จุด | เดิม | ตอนนี้ |
|---|---|---|
| ปุ่มลบหนังสือ (รายการ + ลบหลายรายการ) | `isSarabanOfficer` | `saraban.document.delete` |
| ปุ่มอนุมัติหนังสือ | `isSarabanOfficer \|\| isManager` (สิทธิ์อนุมัติ **ใบลา**) | `saraban.document.approve` |
| ปุ่มลงนาม | `isManager \|\| isSarabanOfficer \|\| ผู้สร้าง` | `saraban.document.sign` |
| เมนูจัดการหมวดหมู่เอกสาร | `isAdmin \|\| isSarabanOfficer` | `saraban.document.manage_types` |
| เมนู/หน้าตั้งค่าสารบรรณ | `manage_settings` | `saraban.setting.manage` |

---

## 7. ผลการทดสอบ

| รายการ | ผล |
|---|---|
| `php artisan test` (ชุดเต็ม) | ✅ **268 passed / 693 assertions · 0 failed** |
| Authorization matrix | ✅ ผ่าน (`KNOWN_MISSING_PENDING_DECISION` ว่าง) |
| Attendance permission tests | ✅ ผ่าน |
| Saraban authorization tests | ✅ 39 tests / 84 assertions |
| Privilege escalation guard | ✅ 7 tests |
| Org scope + manager assignment | ✅ 13 tests |
| Pint | ✅ **PASS 334 ไฟล์** |
| `npm run build` | ✅ ผ่าน |

| | ก่อนปิดงาน | หลังปิดงาน |
|---|---:|---:|
| test | 243 (582 assertions) | **268 (693 assertions)** |
| permission ในฐานข้อมูลจริง | 21 | **39** |
| `hasRole` ใน `app/` | 6 | **3** |
| permission ที่ route บังคับแต่ไม่มีจริง | 7 | **0** |
| role ผีที่ seeder สร้าง | 5 | **0** |

### เทสต์ที่เพิ่มในรอบนี้

| ไฟล์ | จำนวน | ตรวจอะไร |
|---|---:|---|
| `OperationalPermissionMatrixTest` | 9 | การแจกสิทธิ์ตรงตาม Decision 1/2 **และไม่เกิน** |
| `PrivilegeEscalationGuardTest` | 7 | ช่องยกระดับสิทธิ์ 3 จุดปิดแล้ว |
| `OrgScopeResolverTest` (เพิ่ม) | +7 | การมอบหมายหัวหน้า · effective date · ผู้รักษาการ |
| `AuthorizationMatrixTest` (เพิ่ม) | +2 | `hasRole` audit อัตโนมัติ · ห้ามมี role หัวหน้าแผนก |

**หลักการที่ใช้:** เขียนเทสต์ก่อนเปลี่ยน assignment ทุกครั้ง และเทสต์ทุกตัวตรวจทั้ง
"ให้แล้ว" และ "ไม่ได้ให้เกิน" เพราะความผิดพลาดของ LEGACY-04 ทุกครั้งมาจากการเดา
ว่าใครถืออะไร

`KNOWN_MISSING_PENDING_DECISION` ใน `AuthorizationMatrixTest` **ว่างแล้ว** และมีเทสต์คู่กัน
ที่ล้มถ้ามีใครเติมชื่อกลับเข้าไปโดยไม่มีเหตุผล

---

## 8. Rollback

| จะย้อน | วิธี |
|---|---|
| ตรรกะการตัดสินสิทธิ์ทั้งหมด | `USE_NEW_PERMISSION_ENGINE=false` |
| การแจก permission รอบนี้ | ต้องทำด้วยมือ — `down()` จงใจไม่ถอน เพราะการถอนจะทำให้บุคลากรลงเวลาไม่ได้อีกครั้ง |
| `org_unit_managers` | `down()` ลบตารางได้ปกติ (ตารางว่าง ไม่มีใครใช้) |
| โค้ดทั้งงาน | `git revert` ช่วงของ commit นี้ |

---

## 9. สิ่งที่ยังไม่ได้ทำ และเหตุผล

| เรื่อง | ทำไมถึงไม่ทำ |
|---|---|
| ให้ ผอ./รอง ผอ. อนุมัติและลงนามหนังสือราชการ | การตัดสินใจที่ได้รับครอบคลุมเฉพาะ Document Officer · ปัจจุบันยังเป็น Super Admin เท่านั้น **ต้องการคำตอบ** |
| ให้บุคลากรทั่วไปเข้าถึงโมดูลสารบรรณ | เดียวกัน — Decision 2 พูดถึง Document Officer เท่านั้น |
| หน้าจอจัดการ `org_unit_managers` และ `permission_scopes` | เป็นฟีเจอร์ใหม่ อยู่นอกขอบเขตการปิด LEGACY-04 · ตอนนี้ตั้งค่าผ่านฐานข้อมูล |
| ลบ fallback `department_id` ใน `LeavePolicy` | ต้องยืนยันก่อนว่าผู้ใช้ทุกคนมี `org_unit_id` ครบ |
| seeder ที่ไม่ได้ใช้ 2 ตัว | `StandardRolesAndPermissionsSeeder` และ `PurgeAndMigrateTo7StandardRolesSeeder` ยังสร้าง role ชื่อ `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย` ซึ่งขัด Decision 3 · ตรวจแล้วว่า **ไม่มีโค้ดใดเรียก** และไม่อยู่ใน `DatabaseSeeder` · แนะนำให้ลบทิ้งตอนจัดระเบียบ seeder |
| permission เฉพาะทางแทนตัวยืมใช้ | `leave.request.cancel_any` แทน `system.user.view` ในหน้ายกเลิกใบลาของผู้อื่น — หนี้ที่ LEGACY-04 บันทึกไว้ ยังคงอยู่ |
| route `GET /admin/roles` ที่ถูกบัง | มีการประกาศซ้ำสองที่ — `UserController@roles` ใต้ `can:view_users` (บรรทัด ~131) ถูก `apiResource` ที่ประกาศทีหลังทับจนกลายเป็น dead code · **เป็นมาก่อนงานนี้และไม่ได้เปลี่ยนว่าใครเข้าถึงได้จริงบนระบบนี้** (HR Head ไม่เคยผ่านทั้งเงื่อนไขเดิมและใหม่) · ควรรวมให้เหลือที่เดียวตอนจัดระเบียบ route |

---

## 10. หมายเหตุสำหรับผู้ตรวจ

**สิ่งที่ต่างจากรอบก่อน:** รอบนี้ไม่ใช่การ refactor แต่เป็นการ **เปลี่ยนสิทธิ์ของคนจริง**
จึงเลือกทำแบบระมัดระวังกว่า:

- ดึง snapshot จากฐานข้อมูลจริงทั้งก่อนและหลัง แล้ว diff รายคน ไม่ใช้ fixture
- ตรวจซ้ำที่ระดับ ability ผ่าน Gate จริง ไม่ใช่แค่ดูตาราง `role_has_permissions`
- ทุกเทสต์ระบุทั้งสิ่งที่ต้องได้และสิ่งที่ต้องไม่ได้

**สิ่งที่พบระหว่างทางและอาจสำคัญกว่าตัวงาน:** การเติมสิทธิ์ตามที่อนุมัติแบบตรงไปตรงมา
จะเปิดช่องยกระดับสิทธิ์ทันที 3 จุด เพราะ route เดิมรวมสิทธิ์คนละระดับไว้ในกลุ่มเดียวกัน
ด้วย `|` การอ่านชื่อ permission อย่างเดียวจึงไม่พอ ต้องตามไปดูว่ากลุ่ม route นั้น
ครอบอะไรอยู่บ้าง

---

## สรุป: **LEGACY-04 = PASS**

- permission ที่ระบบบังคับใช้ มีอยู่จริงครบทุกตัว
- บุคลากรทั่วไปลงเวลาเข้า-ออกได้แล้ว — ยืนยันกับฐานข้อมูลจริง
- เจ้าหน้าที่สารบรรณตัวจริงใช้โมดูลสารบรรณได้ โดยไม่ได้สิทธิ์ลบ/เอกสารลับ/ตั้งค่าระบบ
- `hasRole` เหลือ 3 จุดที่เป็น bootstrap identity และมีเทสต์กันไม่ให้เพิ่ม
- ไม่มีผู้ใช้คนใดเสียสิทธิ์ · ช่องยกระดับสิทธิ์ปิดเพิ่ม 3 จุด
- โครงสร้างหัวหน้าหน่วยงานพร้อมใช้ โดยไม่สร้าง role สำหรับตำแหน่ง

---

## หยุดที่นี่ — รอคำสั่งก่อนเริ่ม LEGACY-05

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-14 | PASS |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
