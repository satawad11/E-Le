# UX-01: Global Layout Audit Report

**สถานะ:** PASS  
**วันที่ดำเนินการ:** 15 สิงหาคม 2026  
**ระบบเป้าหมาย:** E-la (ระบบบริหารสถานศึกษาแบบ Modular Monolith)

---

## 1. วัตถุประสงค์และการตรวจสอบ
ดำเนินการตามคำสั่ง UX-01 เพื่อตรวจและปรับ Application Layout กลางให้เป็นมาตรฐานเดียวกันทั้งระบบ โดยไม่กระทบ Business Logic เดิม, ไม่แก้ API, Database Schema หรือ Authorization Logic

---

## 2. ไฟล์ที่ตรวจสอบ (Inspected Files)
1. `frontend/src/App.vue` (AppShell & Central Layout)
2. `frontend/src/router/index.js` (Route definitions & Navigation guards)
3. `frontend/src/views/ForgotPasswordView.vue` (Guest view layout & branding)
4. `frontend/src/views/ResetPasswordView.vue` (Guest view layout & branding)
5. `frontend/src/views/VerifyLeaveView.vue` (Guest verification view)
6. `frontend/src/views/LeaveCardView.vue` (Nested `<main>` & double padding audit)
7. `frontend/src/views/LeaveServicesView.vue` (Nested `<main>` & double padding audit)
8. `frontend/src/views/ReportsView.vue` (Nested `<main>` & double padding audit)
9. `frontend/src/views/MyAccessHistoryView.vue` (Nested `<main>` & double padding audit)
10. `frontend/src/views/admin/LeaveGovernanceView.vue` (Nested `<main>` & double padding audit)
11. `frontend/src/views/admin/LeavePoliciesView.vue` (Nested `<main>` & double padding audit)
12. `frontend/src/views/admin/PersonnelAssignmentsView.vue` (Nested `<main>` & double padding audit)
13. `frontend/src/views/admin/PromotionScreeningView.vue` (Nested `<main>` & double padding audit)
14. `frontend/src/views/admin/WorkCalendarView.vue` (Nested `<main>` & double padding audit)
15. `frontend/src/views/saraban/DocumentListView.vue` (Padding audit)

---

## 3. ปัญหาที่พบ (Identified Issues)
1. **Desktop AppShell Overflow / Scrolling**:
   - `App.vue` ใช้ `min-h-screen` ที่ container หลัก ส่งผลให้ Sidebar เลื่อนหลุดเฟรมเมื่อหน้าเพจมีข้อมูลยาว
2. **Mobile Navigation Cut-off & Backdrop**:
   - เมนูมือถือเดิมเป็น Dropdown ต่อท้าย Header ที่ไม่มี `overflow-y-auto` ทำให้เมนูเมื่อกาง Submenu ล้นตัดขอบจอ และไม่มี Backdrop ปิดเมนูเมื่อแตะภายนอก
3. **Bare Layout Detection**:
   - `isBareLayout` เดิมตรวจเฉพาะ `['login', 'verify-leave']` ทำให้หน้า `forgot-password` และ `reset-password` หลุดแสดง Sidebar และเริ่มนับ Idle timer
4. **Nested `<main>` Semantic Tags**:
   - พบ 10 Views มีการใส่ `<main>` ซ้อนภายใน `<main>` ของ `App.vue` ซึ่งผิดมาตรฐาน HTML5 semantic markup และเกิดปัญหา double padding (`p-4 sm:p-6` ซ้อน `p-4 md:p-8`)
5. **Brand Styling Inconsistencies**:
   - บางหน้า (`ForgotPasswordView.vue`, `ResetPasswordView.vue`, `WorkCalendarView.vue`) มีการใช้โทนสี Indigo / Blue แทนที่จะเป็นสีแดงเลือดหมูมาตรฐานสถาบัน (`#800000`)
6. **Active Route Matchers**:
   - การตรวจสอบ Active route สำหรับเปิด Collapsible Submenu ใน `App.vue` ตรวจไม่ครอบคลุม subroutes ใหม่ใน Saraban, Attendance, และ Admin

---

## 4. สิ่งที่แก้ไขและปรับปรุง (Implemented Fixes)
1. **ปรับมาตรฐาน AppShell Desktop Layout (`App.vue`)**:
   - ตั้งค่า Root Container เป็น `h-screen flex bg-gray-50 overflow-hidden font-sans`
   - ตั้งค่า Sidebar เป็น `h-screen flex flex-col flex-shrink-0 z-30` พร้อม `overflow-y-auto` ที่ Nav list
   - กำหนด Main Content Area เป็น `flex-1 flex flex-col h-screen min-w-0 overflow-hidden` และ `<main class="flex-1 p-4 md:p-6 lg:p-8 overflow-y-auto">` ให้ scroll อย่างอิสระ
2. **สร้าง Mobile Slide-over Drawer มาตรฐาน (`App.vue`)**:
   - เพิ่ม Backdrop `fixed inset-0 bg-black/60 backdrop-blur-sm z-40 md:hidden`
   - เพิ่ม Drawer Panel `fixed inset-y-0 left-0 w-72 max-w-[85vw] bg-[#800000] z-50 md:hidden flex flex-col h-full`
   - ภายใน Drawer ประกอบด้วย: ส่วนหัว + ข้อมูลผู้ใช้ + Nav list แบบ scroll ได้อิสระ + ปุ่มออกจากระบบที่ตรึงท้าย
   - เพิ่ม Transition `drawer-fade` และ `drawer-slide`
   - เพิ่ม Watcher ปิด Drawer อัตโนมัติเมื่อ Route เปลี่ยน
3. **จัดระเบียบ Z-Index Hierarchy**:
   - Sticky Header: `z-10` / `z-30`
   - Mobile Backdrop: `z-40`
   - Mobile Drawer: `z-50`
   - Modals (Logout/Dialogs): `z-50`
4. **ขยาย `isBareLayout` และ Active Route Matchers**:
   - `isBareLayout` รองรับ `route.meta.requiresGuest`, `route.meta.bare`, `login`, `forgot-password`, `reset-password`, `verify-leave`
   - ตรวจจับ Active Routes ครบทุกโมดูล (Leave, Attendance, Saraban, User, Settings)
5. **แก้ไข Nested `<main>` และ Double Padding ใน 10 Views**:
   - เปลี่ยน `<main>` ภายใน Views เป็น `<div class="max-w-7xl mx-auto space-y-6">`
6. **ปรับ Brand Consistency**:
   - ปรับสี Button/Focus State ใน `ForgotPasswordView.vue`, `ResetPasswordView.vue`, และ `WorkCalendarView.vue` เป็น `#800000`

---

## 5. การตรวจสอบความถูกต้อง (Verification & Testing)
1. **Frontend Build Verification**:
   - คำสั่ง: `docker exec eleave_frontend npm run build`
   - ผลลัพธ์: **PASS** (879 modules transformed, 0 errors, build time: 50.39s)
2. **Backend Regression Testing**:
   - คำสั่ง: `docker exec eleave_backend php artisan test`
   - ผลลัพธ์: **PASS** (462 tests passed, 1370 assertions, 0 failures)
3. **Responsiveness Audit**:
   - Desktop (>=1024px): Sidebar คงที่, Header สะอาด, Content scroll อิสระ
   - Tablet (768px - 1023px): Collapsible sidebar / Responsive padding `md:p-6`
   - Mobile (<768px): Sticky top header + Touch-friendly slide-over drawer

---

## 6. สรุปความเสี่ยงและประเด็นคงค้าง (Regression Risk & Remaining Issues)
- **Regression Risk**: ต่ำมาก (ไม่มีการแตะ API, Controller, Model, DB Schema หรือ Permission Guards)
- **Remaining Issues**: ไม่มี
- **ผลการประเมินภาพรวม**: **PASS**
