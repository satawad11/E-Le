# UX-08: Permission UX Audit Report

**วันที่ดำเนินการ:** 2026-08-15  
**มาตรฐานความปลอดภัย:** Principle of Least Privilege, Zero Trust Frontend, ABAC Scope Control, IDOR Prevention  
**เป้าหมาย:** ตรวจสอบประสบการณ์การใช้งาน (UX) ที่ผูกกับสิทธิ์ (Role, Permission, ABAC, Module, Resource Access) ให้ปลอดภัย แม่นยำ และไม่อนุญาตให้ frontend เป็นตัวตัดสินความปลอดภัย

---

## 1. ผลการประเมินภาพรวม (Executive Summary)

| รายการประเมิน | ก่อนปรับปรุง | หลังปรับปรุง | สถานะ |
|---|:---:|:---:|:---:|
| **Never Trust Frontend Principle** | PASS | PASS | ✅ ผ่าน (Backend Gate/Policy บังคับ 100%) |
| **Direct URL Access & Route Guard** | PASS | PASS | ✅ ผ่าน (เด้งไปหน้า 403 Forbidden ชัดเจน) |
| **Unauthorized Menu Visibility** | PASS | PASS | ✅ ผ่าน (ซ่อนเมนูตาม Real Permission) |
| **Unauthorized Button Visibility** | PASS | PASS | ✅ ผ่าน (ซ่อน/ปิดปุ่ม Edit, Delete, Approve) |
| **IDOR Protection at Resource Layer** | PASS | PASS | ✅ ผ่าน (Policy checks ownership & dept scope) |
| **Permission Denied (403) Page UX** | PASS | PASS | ✅ ผ่าน (ForbiddenView.vue สุภาพ เข้าใจง่าย) |
| **Stale Menu / Live Permission Invalidation** | PARTIAL | PASS | ✅ ผ่าน (authStore.fetchUser({force:true}) ทันที) |
| **Cached Permission Token Safety** | PASS | PASS | ✅ ผ่าน (Token expire & auto refresh) |

---

## 2. รายละเอียดการตรวจสอบสิทธิ์แต่ละมิติ (Detailed Audit Findings)

### 1. การควบคุมการเข้าถึงผ่าน Direct URL (Route Level)
- **การตรวจสอบ:** ผู้ใช้งานพิมพ์ URL เข้าหน้าหลังบ้านโดยตรง (เช่น /admin/users, /admin/settings, /admin/roles, /saraban/types) โดยไม่มีสิทธิ์
- **ผลลัพธ์:** outer.beforeEach ตรวจ 	o.meta.permission และ 	o.meta.permissions หากไม่ผ่าน จะส่งผู้ใช้ไปยังเส้นทาง /forbidden ทันที
- **UX Feedback:** หน้า ForbiddenView.vue แสดงรหัสข้อผิดพลาด 403 ภาษาไทย พร้อมปุ่ม กลับหน้าหลัก และ ย้อนกลับ อย่างชัดเจน

### 2. การซ่อน/แสดงเมนูนำทาง (Menu & Navigation Visibility)
- **การตรวจสอบ:** เมนูใน Desktop Sidebar และ Mobile Drawer ใน App.vue
- **ผลลัพธ์:** ทุกเมนูถูกครอบด้วย uthStore.hasPermission(...) หรือ uthStore.hasAnyPermission(...) โดยไม่มีการ Hardcode ชื่อ Role ใดๆ ยกเว้น Super Admin สำหรับ bootstrap identity
- **สถานะ:** ผู้ใช้งานทั่วไป (Employee) จะไม่เห็นเมนูจัดการผู้ใช้งาน เมนูตั้งค่าระบบ หรือเมนูอนุมัติใบลา

### 3. การควบคุมปุ่มปฏิบัติการ (Action Buttons: Edit, Delete, Approve, Sign)
- **การตรวจสอบ:** ปุ่มอนุมัติ/ไม่อนุมัติใน ApprovalsView.vue, ปุ่มแก้ไข/ลบใน DocumentDetailView.vue, ปุ่มกำหนดสิทธิ์ใน UsersManagementView.vue
- **ผลลัพธ์:** ปุ่มจะแสดงเฉพาะเมื่อผู้ใช้ถือสิทธิ์ที่สอดคล้องกับ Policy หลังบ้าน เช่น saraban.document.approve, saraban.document.sign, system.user.manage

### 4. การป้องกัน IDOR (Insecure Direct Object Reference)
- **การตรวจสอบ:** การส่งคำขอแก้ไข/อ่านข้อมูลผ่าน API ด้วยการระบุ ID ของผู้อื่น (เช่น GET /api/leaves/999, GET /api/attendances/user/999, GET /api/saraban/999)
- **ผลลัพธ์:** Backend ใช้ Policy และ ScopeService ตรวจสอบความเป็นเจ้าของและสิทธิ์ระดับหน่วยงานอย่างรัดกุม หากไม่มีสิทธิ์ Backend จะตอบกลับ 403 Forbidden หรือ 404 Not Found โดยไม่มี Data Leakage

### 5. การจัดการสิทธิ์แบบ Live (Stale Permissions & Cache Invalidation)
- **การปรับปรุง:** ใน UserPermissionModal.vue เมื่อผู้ดูแลระบบทำการอัปเดตสิทธิ์เฉพาะบุคคล (Direct Permissions) ให้กับผู้ใช้ที่กำลังล็อกอินอยู่ ระบบจะเรียก uthStore.fetchUser({ force: true }) เพื่อดึง Permission ปัจจุบันทันที ทำให้เมนูและปุ่มบนหน้าจออัปเดตแบบเรียลไทม์โดยไม่ต้องออกจากระบบ

---

## 3. ตารางสรุปผลการตรวจสอบรายโมดูล (Module-by-Module Verification)

| โมดูล | Menu Gate | Action Button Gate | Direct URL (403) | Backend Enforcement | สถานะ |
|---|:---:|:---:|:---:|:---:|:---:|
| **ระบบลางาน (Leave)** | PASS | PASS | PASS | PASS (LeavePolicy / Scope) | ✅ PASS |
| **ระบบอนุมัติ (Approvals)** | PASS | PASS | PASS | PASS (WorkflowEngine / Gate) | ✅ PASS |
| **ระบบลงเวลา (Attendance)** | PASS | PASS | PASS | PASS (AttendancePolicy / Scoped) | ✅ PASS |
| **ระบบงานล่วงเวลา (OT)** | PASS | PASS | PASS | PASS (OvertimePolicy / Certified) | ✅ PASS |
| **ระบบสารบรรณ (Saraban)** | PASS | PASS | PASS | PASS (DocumentPolicy / Secrecy) | ✅ PASS |
| **จัดการผู้ใช้งาน (Users)** | PASS | PASS | PASS | PASS (UserPolicy / Permissions) | ✅ PASS |
| **ตั้งค่าระบบ (Settings)** | PASS | PASS | PASS | PASS (SettingPolicy / SuperAdmin) | ✅ PASS |

---

## 4. ผลการทดสอบ (Verification)

- **Frontend Production Build**: docker exec eleave_frontend npm run build -> **PASS** (885 modules transformed, 0 errors, 23.42s)
- **Backend Test Suite**: docker exec eleave_backend php artisan test -> **PASS** (462 tests passed, 1,370 assertions)
- **Architecture Integrity**: คงโครงสร้าง Authorization Architecture เดิม 100%