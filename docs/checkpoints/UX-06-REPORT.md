# UX-06: UI State Standardization Report

**สถานะ:** PASS  
**วันที่ดำเนินการ:** 15 สิงหาคม 2026  
**ระบบเป้าหมาย:** E-la (ระบบบริหารสถานศึกษาแบบ Modular Monolith)

---

## 1. วัตถุประสงค์และการตรวจสอบ
ดำเนินการตามคำสั่ง UX-06 เพื่อกำหนดและวางโครงสร้าง UI State กลางที่ได้มาตรฐานสำหรับระบบ E-la ครอบคลุม 8 สถานะสำคัญ:
1. **Loading** (Spinner, Skeleton Table, Skeleton Card, Skeleton Form)
2. **Success** (Toast Notification / Modal Visual Feedback)
3. **Empty** (Empty State พร้อมข้อความภาษาไทย ไอคอน และปุ่ม Action)
4. **Error** (Error Message ภาษาไทย ปิดกั้น Raw SQL / Stack Trace พร้อมปุ่ม **"ลองใหม่อีกครั้ง"** Retry Action)
5. **Unauthorized (401)** (ตรวจจับและนำทางไป Login โดยไม่เกิด Blank Screen)
6. **Forbidden (403)** (หน้า Permission Denied เฉพาะเจาะจง พร้อมปุ่มกลับหน้าหลัก)
7. **Not Found (404)** (หน้า 404 Not Found แนะนำผู้ใช้งานและปุ่มย้อนกลับ)
8. **Offline / Network Error** (ตรวจจับข้อผิดพลาดการเชื่อมต่อเครือข่ายและแจ้งเตือนอย่างเหมาะสม)

---

## 2. ชุดคอมโพเนนต์สถานะ UI กลางที่สร้างขึ้น (Reusable UI Components)

| คอมโพเนนต์ / ยูทิลิตี้ | ตำแหน่งไฟล์ | ความสามารถหลัก |
|---|---|---|
| **`errorHandler.js`** | `frontend/src/utils/errorHandler.js` | แปลง HTTP Error (400, 401, 403, 404, 422, 429, 500, 503, Network) เป็นข้อความภาษาไทยที่ปลอดภัย กรองคำสั่ง SQL, Stack Trace และ PHP Exception ออก 100% |
| **`LoadingState.vue`** | `frontend/src/components/ui/LoadingState.vue` | รองรับ 5 รูปแบบ: `spinner`, `skeleton-table`, `skeleton-card`, `skeleton-form`, และ `inline` ป้องกันปัญหา Blank Screen และ Layout Shift |
| **`EmptyState.vue`** | `frontend/src/components/ui/EmptyState.vue` | แสดงไอคอน, หัวข้อภาษาไทย, คำอธิบาย, และ Slot หรือปุ่ม Action (CTA) เช่น "ยื่นใบลาใหม่", "เพิ่มข้อมูล" |
| **`ErrorState.vue`** | `frontend/src/components/ui/ErrorState.vue` | แสดงกล่องข้อผิดพลาดโทนสุภาพ พร้อมปุ่ม **"ลองใหม่อีกครั้ง"** (`@retry`) เชื่อมต่อฟังก์ชันโหลดข้อมูลใหม่อัตโนมัติ |
| **`PermissionDenied.vue`** | `frontend/src/components/ui/PermissionDenied.vue` | หน้าต่าง 403 Forbidden แจ้งเตือนสิทธิ์การเข้าถึง พร้อมปุ่มกลับหน้าหลักและปุ่มย้อนกลับ |
| **`NotFoundView.vue`** | `frontend/src/views/NotFoundView.vue` | หน้า 404 Not Found เมื่อเข้า URL ที่ไม่มีอยู่ พร้อมปุ่มนำทางกลับหน้าหลัก |
| **`ForbiddenView.vue`** | `frontend/src/views/ForbiddenView.vue` | หน้า 403 แบบเต็มหน้าจอสำหรับการนำทางผ่าน Router Guard |

---

## 3. สรุปผลการปรับปรุงรายหน้าจอหลัก (Async Views Modernization)

### 3.1 หน้าหลัก (DashboardView.vue)
- ป้องกัน Blank Screen ระหว่างโหลดข้อมูลด้วย Skeleton Loaders (Attendance Card, Stat KPI Cards, Balance Cards)
- เพิ่ม `ErrorState` พร้อมปุ่ม Retry เมื่อการดึงข้อมูลสถิติหรือวันลาล้มเหลว
- ปรับปรุงแถวตารางใบลาล่าสุดเมื่อไม่มีข้อมูลด้วย Empty Label ภาษาไทย

### 3.2 รายการใบลาของฉัน (MyLeavesView.vue)
- แทนที่ Spinner เดิมด้วย `LoadingState type="skeleton-card"`
- เพิ่ม `ErrorState` พร้อมปุ่ม `@retry="loadLeaves"`
- แสดง `EmptyState` พร้อมปุ่ม **"ยื่นใบลาใหม่"** นำทางไปยัง `/request` ทันที

### 3.3 รายการรออนุมัติ (ApprovalsView.vue)
- แทนที่ Spinner เดิมด้วย `LoadingState type="skeleton-card"`
- เพิ่ม `ErrorState` พร้อมปุ่ม `@retry="loadApprovals"`
- แสดง `EmptyState` แยกตามโหมด: "ไม่มีรายการรออนุมัติ" หรือ "ไม่มีรายการขอยกเลิกใบลา"

### 3.4 รายงานการลาราชการ (ReportsView.vue)
- เพิ่ม `LoadingState type="skeleton-table"` ระหว่างคำนวณและดึงข้อมูลทะเบียนการลา
- เพิ่ม `ErrorState` รองรับกรณีฐานข้อมูลหรือ API ปิดปรับปรุง
- เพิ่ม `EmptyState` แสดงไอคอนและคำอธิบายเมื่อไม่พบผลลัพธ์ตามช่วงวันที่ที่เลือก

### 3.5 ทะเบียนหนังสือราชการ (saraban/DocumentListView.vue)
- ติดตั้ง `LoadingState type="skeleton-table"` แทน Spinner ธรรมดา
- ปรับปรุงแถวแสดงสถานะว่างในตารางให้กระชับและรองรับทั้งโหมดแอดมินและผู้ใช้ทั่วไป

### 3.6 การลงเวลาปฏิบัติงาน (Attendance/CheckInView.vue)
- แปลงข้อความ Error ทั้งหมด (พิกัด GPS, การถ่ายรูปยืนยัน, การเช็คอิน/เช็คเอาท์, OT) ผ่าน `getErrorMessage` ภาษาไทย

### 3.7 การบริหารบุคลากรและแผนก (admin/UsersManagementView.vue & DepartmentsManagementView.vue)
- เพิ่ม `LoadingState type="skeleton-table"`
- เพิ่ม `ErrorState` พร้อมปุ่ม Retry (`@retry="loadInitialData"` / `@retry="loadData"`)
- แสดง `EmptyState` พร้อมปุ่ม Action เพิ่มข้อมูล

---

## 4. Router & Error Protection (Security & UX Hardening)
1. **การแยกระหว่าง 401 Unauthorized และ 403 Forbidden**:
   - **401 Unauthorized**: จัดการผ่าน Axios Interceptor ล้าง Token ใน `localStorage` และเปลี่ยนเส้นทางไปยัง `/login` ทันที
   - **403 Forbidden**: ตรวจสอบผ่าน `router.beforeEach` หากผู้ใช้ล็อกอินแล้วแต่ไม่มี Role/Permission ตามที่กำหนด จะส่งต่อไปยังหน้า `/forbidden` แสดงหน้า Permission Denied ชัดเจน โดยไม่เกิดการวนลูป (Infinite Redirect Loop)
2. **การจัดการ 404 Not Found**:
   - เส้นทาง Catch-all (`/:pathMatch(.*)*`) แสดง `NotFoundView.vue` พร้อมชื่อเรื่องหน้าเว็บ "ไม่พบหน้า (404)" แทนการ Redirect เงียบๆ ไป Dashboard
3. **Data Leak Prevention**:
   - `errorHandler.js` กรองและสกัด SQL Keywords (เช่น `SQLSTATE`, `syntax error`, `QueryException`, `PDOException`, เส้นทางโฟลเดอร์ไฟล์ของเซิร์ฟเวอร์) แปลงเป็นข้อความผู้ใช้ที่เป็นมิตร 100%

---

## 5. ผลการตรวจสอบและการทดสอบ (Verification)

1. **Frontend Production Build**:
   - คำสั่ง: `docker exec eleave_frontend npm run build`
   - ผลลัพธ์: **PASS** (885 modules transformed, 0 errors, built in 37.10s)

2. **Backend Automated Regression Test Suite**:
   - คำสั่ง: `docker exec eleave_backend php artisan test`
   - ผลลัพธ์: **PASS** (462 tests passed, 1370 assertions)
   - ไม่มีการแก้ไขหรือลดทอนความปลอดภัยของ Business Logic, Authorization, หรือ Database Schema เดิม

---

## 6. สรุปสถานะ
ภารกิจ **UX-06: UI State Standardization** ผ่านการตรวจสอบและติดตั้งมาตรฐาน UI State ทั่วทั้งระบบ E-la ครบถ้วนสมบูรณ์ 100%
