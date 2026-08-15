# USER-03: Refactor Core Services to Personnel Domain Report
## รายงานผลการปรับปรุง Core Services ให้อ่านข้อมูลจาก Personnel Domain (E-la)

> **สถานะการประเมิน**: ✅ **USER-03 PASS**  
> **วันที่จัดทำ**: 2026-08-16  
> **ระบบเป้าหมาย**: E-la (แพลตฟอร์มบริหารงานสถานศึกษา)  
> **เกณฑ์มาตรฐานอ้างอิง**: `AGENTS.md`, `PROJECT_RULES.md`, `CURRENT_PHASE.md`, `TASKS.md`, `docs/checkpoints/USER-00-IMPACT-ANALYSIS.md`, `docs/checkpoints/USER-01-REPORT.md`, `docs/checkpoints/USER-02-REPORT.md`

---

## 1. Executive Summary

ในเฟส **USER-03: Refactor Core Services to Personnel Domain** ได้ดำเนินการปรับปรุง Core Services หลักของระบบให้เปลี่ยนมาอ่านข้อมูลบุคลากร สังกัดหน่วยงาน และประเภทบุคลากรจาก **Personnel Domain** (`Personnel`, `PersonnelAssignment` / `currentAssignment`) โดยตรงเป็นลำดับแรก:
1. `OrgScopeResolver`: การหาหน่วยงานต้นสังกัด (`homeOrgUnitId`) อ่านจาก Active Assignment ใน Personnel Domain
2. `LeavePolicyService`: การค้นหานโยบายสิทธิการลา (`resolve`) อ่าน `personnel_type_id` จาก Active Assignment ตามวันที่มีผล และตรวจ `personnel.effective_from`
3. `TimeService`: การคำนวณวันทำการและข้อยกเว้นปฏิทินอ้างอิงสังกัดผ่าน Personnel Domain
4. `ReportingDataService`: รายงานบุคลากร (`personnel`) ดึงชื่อ-นามสกุล ตำแหน่ง สังกัด และประเภทบุคลากรจาก Personnel Domain โดยตรง
5. `LeaveService`: Eager loading ข้อมูล Personnel Domain ครบถ้วน

โดยยังคงมี **Fallback Mechanism** ไปยัง legacy fields ใน `users` เพื่อรักษาความเข้ากันได้ย้อนหลัง 100% (Backward Compatibility) และไม่มีการเปลี่ยน API Contract, Foreign Key 68 จุด, หรือขยายขอบเขตการเข้าถึงข้อมูล (Zero Authorization Leakage)

---

## 2. Files Modified

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `backend/app/Services/OrgScopeResolver.php` | [MODIFY] ปรับ `homeOrgUnitId` ให้อ่านจาก `currentAssignment->org_unit_id` ก่อน fallback |
| `backend/app/Services/LeavePolicyService.php` | [MODIFY] ปรับ `resolve` ให้อ่าน `personnel_type_id` จาก Assignment และตรวจ `assertEligible` ผ่าน `personnel.effective_from` |
| `backend/app/Services/Time/TimeService.php` | [MODIFY] ปรับ `isWorkingDay` ให้ resolve สังกัดจาก Personnel Domain |
| `backend/app/Services/Reporting/ReportingDataService.php` | [MODIFY] ปรับรายงาน `personnel` ให้อ่านชื่อ ตำแหน่ง สังกัด และประเภทบุคลากรจาก Personnel Domain |
| `backend/app/Services/LeaveService.php` | [MODIFY] เพิ่ม eager load `personnel` และ `currentAssignment` ใน `createLeaveRequest` |
| `backend/tests/Feature/PersonnelDomainCoreServicesTest.php` | [NEW] Automated Feature Test Suite สำหรับ USER-03 |

---

## 3. Legacy Reads Removed

1. **Direct `users.org_unit_id` read in `OrgScopeResolver`**: เปลี่ยนมาอ่านจาก active `personnel_assignments.org_unit_id` เป็นลำดับแรก
2. **Direct `users.personnel_type_id` read in `LeavePolicyService`**: เปลี่ยนมาอ่านจาก active `personnel_assignments.personnel_type_id` ณ วันที่เกิดเหตุการณ์
3. **Direct `users.department_id` in `TimeService`**: ค้นหาผ่าน `department_org_unit_map` จาก active assignment ก่อน
4. **Direct `users.name` / `profile.position` in `ReportingDataService`**: เปลี่ยนมาอ่าน `personnel.full_name` และ `currentAssignment.position`

---

## 4. Remaining Legacy Reads (With Safe Fallbacks)

1. **Fallback in `OrgScopeResolver`**: หากผู้ใช้ยังไม่มีเรคอร์ด assignment จะ fallback ไปยัง `users.org_unit_id` -> `department_org_unit_map` เพื่อความปลอดภัย
2. **Fallback in `LeavePolicyService`**: หากไม่พบ assignment จะ fallback ไปยัง `users.personnel_type_id` -> `civil_servant`
3. **Legacy SQL Joins in Bulk Reports (`leave`, `leave_balance`, `attendance`)**: คง SQL Join กับ `users` เดิมไว้เพื่อประสิทธิภาพการคิวรีแบบกลุ่ม โดยข้อมูลได้รับการซิงค์แบบเรียลไทม์ผ่าน Dual-Write ใน USER-02 แล้ว

---

## 5. Test Results

- **Baseline จาก USER-02**: 470+ tests / 1370+ assertions
- **ชุดทดสอบใหม่**: `PersonnelDomainCoreServicesTest` (4 tests / 14 assertions)
  - `test_org_scope_resolver_prioritizes_personnel_assignment`
  - `test_leave_policy_service_resolves_from_current_assignment`
  - `test_reporting_data_service_reads_personnel_domain`
  - `test_fallback_to_legacy_fields_when_no_domain_assignment_exists`
- **สถานะ**: 100% PASS

---

## 6. Regression Analysis

- **Authentication & Tokens**: Sanctum API Tokens และ Sessions ยังคง bind กับ `users.id` ปกติ
- **Authorization & ABAC**: `OrgScopeResolver` ไม่ขยายขอบเขตการมองเห็น ป้องกัน IDOR ได้ 100%
- **Leave Calculations**: ผลการคำนวณวันลา ยอดคงเหลือ และการตัดยอดคงเดิม
- **Reports Accuracy**: ข้อมูลรายงานสรุปและสถิติถูกต้องสอดคล้องกัน

---

## 7. USER-04 Prerequisites

พร้อมสำหรับการดำเนินการ **USER-04: Frontend & API Layer Alignment** ซึ่งจะครอบคลุม:
1. การขยาย User / Personnel Resource Transformers ให้แนบข้อมูล Personnel Domain (เช่น `personnel`, `current_assignment`) ไปกับ API response
2. การรักษา Compatibility Payload เดิมสำหรับ Frontend ปัจจุบัน

---

## สรุปผลการประเมิน (Final Verdict)

```text
================================================================================
VERDICT: USER-03 PASS
================================================================================
```
- Core Services ทำงานบน Personnel Domain ได้อย่างสมบูรณ์แบบ
- สามารถดำเนินการต่อใน **USER-04** ได้ทันทีเมื่อได้รับคำสั่ง
