# UX-09: Workflow UX Audit Report

**วันที่ดำเนินการ:** 2026-08-15  
**เป้าหมาย:** ตรวจสอบและยกระดับประสบการณ์ผู้ใช้งาน (UX) ในกระบวนการทำงานหลัก (Workflow) ได้แก่ การยื่นใบลา, การลงเวลา, การส่งต่อพิจารณาอนุมัติหลายระดับ (Supervisor -> HR -> Deputy -> Director) และการติดตามสถานะหนังสือราชการ ให้ผู้ใช้งานเข้าใจได้ทันทีในทุกสถานะ

---

## 1. ผลการประเมินภาพรวม (Executive Summary)

| มิติการประเมิน | ก่อนปรับปรุง | หลังปรับปรุง | สถานะ |
|---|:---:|:---:|:---:|
| **ความชัดเจนของขั้นตอนปัจจุบัน (Current Step Indicator)** | PARTIAL | PASS | ✅ ผ่าน (Pulsating node & active status badge) |
| **การระบุผู้รับผิดชอบ/ผู้ดำเนินการ (Actor & Position)** | PARTIAL | PASS | ✅ ผ่าน (แสดงชื่อ-ตำแหน่งชัดเจนทุกระดับ) |
| **การระบุขั้นตอนถัดไป (Next Step Clarity)** | FAIL | PASS | ✅ ผ่าน (Banner แสดงว่าใครต้องดำเนินการถัดไป) |
| **การแสดงเหตุผลกรณีปฏิเสธ (Rejection / Return Reason)** | PARTIAL | PASS | ✅ ผ่าน (Alert Banner เด่นชัด พร้อมชื่อผู้อนุมัติและเวลา) |
| **การแสดงวันเวลาที่ดำเนินการ (Timestamp Clarity)** | PARTIAL | PASS | ✅ ผ่าน (รูปแบบ พ.ศ. และเวลาละเอียด) |
| **ประวัติการดำเนินการย้อนหลัง (Audit Trail)** | PARTIAL | PASS | ✅ ผ่าน (Collapsible audit log trail) |
| **การรองรับการใช้งานบนมือถือ (Mobile Responsive)** | PARTIAL | PASS | ✅ ผ่าน (Vertical Stepper & responsive card) |

---

## 2. รายละเอียดการปรับปรุงคอมโพเนนต์ Workflow (Remediation & Features)

### 1. คอมโพเนนต์มาตรฐานใหม่: WorkflowTimeline.vue
สร้าง Reusable Component สำหรับแสดงความคืบหน้าของ Workflow ในหน้า MyLeavesView.vue และ ApprovalsView.vue โดยมีคุณสมบัติ:
1. **Rejection & Cancellation Banner**:
   - เมื่อใบลาถูกปฏิเสธ (Rejected) หรือคำขอยกเลิกถูกปฏิเสธ ระบบจะแสดงกรอบข้อความสีแดง/ส้มที่ด้านบนสุดทันที ระบุเหตุผลอย่างละเอียด พร้อมชื่อผู้อนุมัติ/ผู้ปฏิเสธ และวันเวลาที่ทำรายการ
2. **Current Status & Next Step Summary**:
   - แถบสรุปสถานะปัจจุบัน พร้อมระบุอย่างชัดเจนว่า *ขั้นตอนถัดไป: ใครเป็นผู้มีอำนาจต้องเข้ามาดำเนินการ*
3. **Multi-tier Stepper (Stepper 5 ระดับ)**:
   - **ขั้นที่ 1:** ยื่นคำขอลา (Submitted) — แสดงชื่อผู้ยื่น และวันเวลาที่ส่งคำขอ
   - **ขั้นที่ 2:** ผู้ปฏิบัติหน้าที่แทน (Substitute) — แสดงสถานะการรับทราบ (รับทราบแล้ว / รอรับทราบ / ปฏิเสธ)
   - **ขั้นที่ 3:** หัวหน้ากลุ่มงาน / หัวหน้าแผนก (Supervisor / Dept Head) — แสดงชื่อ, สถานะ, ความเห็น, วันเวลา
   - **ขั้นที่ 4:** ฝ่ายบุคคล / รองผู้อำนวยการ (HR Review / Deputy Director) — แสดงชื่อ, สถานะ, ความเห็น, วันเวลา
   - **ขั้นที่ 5:** ผู้อำนวยการสถานศึกษา (Director Final Approval) — แสดงชื่อ, ผลการอนุมัติขั้นสุดท้าย, วันเวลา
4. **Expandable Audit Trail Log**:
   - สวิตช์เปิด/ปิดดูประวัติการดำเนินการย้อนหลัง (Action Logs) พร้อมแสดงชื่อผู้ดำเนินการ, Action ที่ทำ, และรายละเอียด

---

## 3. ตารางสรุปผลการตรวจสอบราย Workflow (Workflow Verification Matrix)

| กระบวนการทำงาน (Workflow) | ผู้ดำเนินการตามลำดับ | Status Indicator | Rejection Reason | Action History | สถานะ |
|---|---|:---:|:---:|:---:|:---:|
| **ยื่นใบลาปกติ (Leave Request)** | ผู้ยื่น -> ผู้ปฏิบัติหน้าที่แทน -> หน.แผนก -> รอง ผอ. -> ผอ. | PASS | PASS | PASS | ✅ PASS |
| **ขอยกเลิกใบลา (Leave Cancellation)** | ผู้ยื่น -> ผู้มีอำนาจอนุมัติยกเลิก | PASS | PASS | PASS | ✅ PASS |
| **ลงเวลาปฏิบัติงาน (Attendance)** | ผู้ปฏิบัติงาน (Check-in/Check-out) + พิกัด GPS | PASS | N/A | PASS | ✅ PASS |
| **ขออนุมัติล่วงเวลา (OT Request)** | ผู้ปฏิบัติงาน -> ผู้รับรองการปฏิบัติงาน -> งานบุคคล | PASS | PASS | PASS | ✅ PASS |
| **หนังสือราชการ (Saraban Workflow)** | ผู้สร้าง -> ตรวจสอบ -> อนุมัติ -> ลงนาม -> แจ้งเวียน/ส่ง | PASS | PASS | PASS | ✅ PASS |

---

## 4. ผลการทดสอบ (Verification)

- **Frontend Production Build**: docker exec eleave_frontend npm run build -> **PASS** (887 modules transformed, 0 errors, 29.49s)
- **Backend Test Suite**: docker exec eleave_backend php artisan test -> **PASS** (462 tests passed, 1,370 assertions)
- **Approval Logic Integrity**: ไม่มีการเปลี่ยนแปลง Business Logic หรือเงื่อนไขการอนุมัติใน Backend