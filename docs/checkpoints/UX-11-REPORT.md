# UX-11: Cross-module UX Consistency Audit Report

**วันที่ดำเนินการ:** 2026-08-15  
**เป้าหมาย:** เปรียบเทียบและตรวจสอบความสม่ำเสมอของ UX/UI ในทุกโมดูลหลัก (Leave, Attendance, Saraban, Admin Modules, User Profile, Reports, Dashboard, Auth) และแก้ไขจุด Inconsistency ที่มีผลกระทบสูง (High-Impact) โดยเฉพาะการแสดงผลวันที่ พ.ศ./ค.ศ. และรูปแบบ UI ทั่วทั้งระบบ

---

## 1. ผลการประเมินความสม่ำเสมอรายมิติ (Cross-module Consistency Matrix)

| มิติการตรวจสอบ | เกณฑ์มาตรฐานของระบบ (Standard) | ก่อนปรับปรุง | หลังปรับปรุง | สถานะ |
|---|---|:---:|:---:|:---:|
| **1. Page Header** | Title (`text-2xl font-bold text-gray-900`) + Subtitle (`text-sm text-gray-500`) + Action CTA | PARTIAL | PASS | ✅ สม่ำเสมอทุกโมดูล |
| **2. Breadcrumb** | AppShell Header แสดง Path ตำแหน่งย่อยในระบบ (`Home / Section / Page`) | PASS | PASS | ✅ ใช้งานผ่าน App.vue |
| **3. Search Bar** | Input พร้อมไอคอนแว่นขยายด้านซ้าย (`pl-10`), Placeholder ชัดเจน | PASS | PASS | ✅ สม่ำเสมอ |
| **4. Filter Controls** | Dropdowns / Date Pickers / Segmented Chips คุมโทนเดียวกัน | PASS | PASS | ✅ สม่ำเสมอ |
| **5. Table Layout** | `<table class="w-full text-sm">` Thead `bg-gray-50`, Tbody `hover:bg-gray-50` | PASS | PASS | ✅ สม่ำเสมอ |
| **6. Pagination** | Prev/Next + หมายเลขหน้าพร้อมแสดงจำนวนรายการ (`startItem-endItem จาก total`) | PASS | PASS | ✅ สม่ำเสมอ |
| **7. Form Controls** | Label ตัวหนา + เครื่องหมาย `*` สีแดง + Input `rounded-xl border-gray-200` | PASS | PASS | ✅ สม่ำเสมอ |
| **8. Create / Edit** | Modal/Drawer หรือหน้า Dedicated พร้อม Loading Submit | PASS | PASS | ✅ สม่ำเสมอ |
| **9. Delete Confirmation** | มี Modal ยืนยันการลบทุกจุด ป้องกันการลบข้อมูลโดยอุบัติเหตุ | PASS | PASS | ✅ ปลอดภัย 100% |
| **10. Status Badges** | เขียว=อนุมัติ/สำเร็จ, เหลือง/ส้ม=รอพิจารณา, แดง=ไม่อนุมัติ/ยกเลิก | PASS | PASS | ✅ สี Semantic ชัดเจน |
| **11. Modal** | `bg-black/50 backdrop-blur-sm`, `rounded-2xl`, ปุ่มปิดมุมขวา, Escape Key | PASS | PASS | ✅ สม่ำเสมอ |
| **12. Toast / Notification** | `AlertList.vue` เชื่อมโยง `useAlertStore()` กลาง | PASS | PASS | ✅ สม่ำเสมอ |
| **13. Confirmation Dialog** | SweetAlert2 / Custom Confirmation Modal | PASS | PASS | ✅ สม่ำเสมอ |
| **14. Button Order** | "ยกเลิก" อยู่ด้านซ้าย/ก่อน, "บันทึก/ยืนยัน" อยู่ด้านขวา/หลัง | PASS | PASS | ✅ สม่ำเสมอทุกฟอร์ม |
| **15. Date Format (พ.ศ.)** | แปลงปี ค.ศ. เป็น พ.ศ. เสมอผ่าน `ThaiDateFormatter` | ⚠️ INCONSISTENT | PASS | ✅ รวมศูนย์ผ่าน ThaiDateFormatter 100% |
| **16. Thai Wording** | ศัพท์มาตรฐานราชการและระบบสถานศึกษา (บันทึก, ยกเลิก, ค้นหา, ยืนยัน, จัดการ) | PASS | PASS | ✅ ถูกต้องตามระเบียบ |

---

## 2. การปรับปรุง High-Impact Inconsistencies ที่ดำเนินการ (Remediation)

### 1. มาตรฐานการจัดรูปแบบวันที่ (Date Formatting Standardization)
เดิมมีหลายโมดูลที่เขียนฟังก์ชันแปลงวันที่เองแบบกระจัดกระจาย ทำให้รูปแบบแสดงผล พ.ศ. ไม่เหมือนกัน (บางจุดไม่มีเลข 0 นำหน้า, บางจุดใช้ `Intl.DateTimeFormat` ที่แตกต่างกัน) ได้ทำการ Refactor ให้เรียกใช้ `ThaiDateFormatter` จากศูนย์กลางทั้งหมด:
- **`UsersManagementView.vue`**: เปลี่ยนจาก Custom String Concat (`d/m/y+543` ไม่มี 0 นำหน้า) เป็น `ThaiDateFormatter.toThaiSlash(dateStr)`
- **`FiscalYearManagementView.vue`**: เปลี่ยนจาก Custom `thaiMonths` array เป็น `ThaiDateFormatter.toThaiShortText(dateString)`
- **`WorkCalendarView.vue`**: เปลี่ยนจาก `Intl.DateTimeFormat` แบบ inline เป็น `ThaiDateFormatter.toThaiShortText(value)`
- **`DocumentDetailView.vue`**: เปลี่ยนจาก Custom `thaiMonths` array เป็น `ThaiDateFormatter.toThaiShortText(dateStr)`
- **`LeavePoliciesView.vue`**: เปลี่ยนจาก Custom `toLocaleDateString` เป็น `ThaiDateFormatter.toThaiShortText(v)`

### 2. มาตรฐานคำศัพท์ภาษาไทย (Standardized Thai Vocabulary)
- **การดำเนินการ (Actions):** `บันทึกข้อมูล`, `ยกเลิก`, `ค้นหา...`, `แก้ไข`, `ลบรายการ`, `ยืนยัน`, `ส่งคำขอ`, `ลงนามอนุมัติ`
- **สถานะทั่วไป (Statuses):** `รอพิจารณา` (Pending), `อนุมัติแล้ว` (Approved), `ไม่อนุมัติ` (Rejected), `ยกเลิกแล้ว` (Cancelled)
- **ข้อความแจ้งว่าง (Empty States):** `ไม่พบข้อมูล...`, `ยังไม่มีรายการ...`

---

## 3. สรุปผลการทดสอบ (Verification)

- **Frontend Production Build**: `docker exec eleave_frontend npm run build` -> **PASS** (887 modules transformed, 0 errors, built in 20.51s)
- **Backend Test Suite**: `docker exec eleave_backend php artisan test` -> **PASS** (462 tests passed, 1,370 assertions)
- **Business Logic & Schema Integrity**: ไม่มีการดัดแปลงโครงสร้างฐานข้อมูล หรือเปลี่ยน Business Logic ใน Backend
