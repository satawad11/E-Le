# แก้ไขโมดูลระบบลางาน — 2026-09-05

ตามคำสั่ง «แก้ไข code ห้ามข้าม» แก้ครบ LEAVE-AUDIT-01 ถึง 04 โดยคง regression tests เดิมและเพิ่มการตรวจผลข้างเคียง ไม่มีการ skip test ใหม่ ลด assertion หรือเพิ่มข้อยกเว้นเพื่อให้ CI ผ่าน

## พฤติกรรมที่แก้

1. ร่างเป็นของเจ้าของเท่านั้น: LeavePolicy ตรวจสถานะก่อนสิทธิ์ทั่วไป และ LeaveModuleServiceProvider ลงทะเบียนข้อห้ามอ่านร่างก่อน Core authorization เพื่อไม่ให้ Super Admin ข้ามได้ ครอบ GET รายฉบับ, PDF/Word และ private assets ที่ถาม Gate; LeaveEvidenceController ป้องกันช่องทางหลักฐานโดยตรงด้วย
2. PUT ใบลาทั่วไปแก้ได้เฉพาะร่างของตนเองโดยผู้มีสิทธิ์จัดการ และส่งต่อ LeaveService::updateDraft เพื่อคำนวณใหม่ภายใต้ lock ใบที่ยื่นแล้วตอบ 422 ให้ใช้ยกเลิก/เพิกถอน; ร่างผู้อื่นตอบ 403 เส้นทาง POST draft สำหรับเจ้าของทั่วไปยังใช้งานตามเดิม
3. การบันทึกแทนผ่าน POST /api/admin/leaves ใช้ transaction รวมสร้างใบลา, กัน/ตัดโควตา, เปลี่ยนเป็น Approved, seal hash, sync attendance และ audit/action log ใช้ completeApproval ร่วมกับการอนุมัติปกติ ไม่สร้างสายอนุมัติ Pending หรือ shadow workflow ให้รายการที่บันทึกเป็นอนุมัติแล้ว ไม่ปลอมลายเซ็นผู้อนุมัติในขั้นที่ไม่ได้เกิดขึ้นจริง เพิ่ม scope check และ explicit deny ที่ FormRequest ของการบันทึกแทน/แก้ไข
4. ตรวจขอบเขตปีด้วย periodYear ของนโยบายทั้งวันเริ่มและสิ้นสุด ถ้าข้ามปีงบประมาณหรือปีปฏิทินตอบ 422 พร้อมข้อความไทยให้แยกใบ ใช้ด่านเดียวกันในการสร้าง, แก้ร่าง, ส่งร่าง และบันทึกแทน

## ไฟล์ที่เปลี่ยนในงานแก้ไข

- backend/app/Policies/LeavePolicy.php
- backend/app/Providers/Modules/LeaveModuleServiceProvider.php
- backend/app/Http/Controllers/LeaveEvidenceController.php
- backend/app/Http/Controllers/LeaveRequestController.php
- backend/app/Http/Controllers/GovernmentApprovalController.php
- backend/app/Http/Requests/StoreLeaveAsAdminRequest.php
- backend/app/Http/Requests/UpdateLeaveRequest.php
- backend/app/Services/LeaveService.php
- backend/app/Services/LeavePolicyService.php
- backend/tests/Feature/LeaveModuleAuditTest.php
- TASKS.md, docs/TRACEABILITY-MATRIX.md, docs/CHANGELOG.md, docs/DECISION-LOG.md และรายงาน/logs รอบนี้

AppServiceProvider มีงานเดิมค้างอยู่ก่อนเริ่ม; กฎร่างที่เคยลองใส่ใน Core ถูกย้ายออกแล้วเพื่อรักษา module boundary

## Verification

- LeaveModuleAuditTest: **11 passed / 41 assertions** (4 เดิมไม่เปลี่ยน expected outcome + 7 กรณีเพิ่ม: Super Admin/PDF/Word/evidence/owner, seal/attendance/audit/no-workflow, rollback, draft recalculation/submitted immutability, fiscal boundaries ทุกทางเข้า, SELF scope และ explicit deny)
- ModuleRegistryTest ร่วมกับ LeaveModuleAuditTest: **22 passed / 190 assertions**
- Frontend lint, typecheck, 81 tests และ build ผ่าน; build อยู่ใน `/tmp/leave-fix-dist` ภายใน container
- Pint ผ่าน 662 files และ git diff --check ผ่าน
- Full suite รอบสุดท้าย: **1,067 passed / 2 failed / 0 skipped · 3,914 assertions · 107.83 วินาที** ใน `leave-fix-backend-final-2026-09-05.log` ความล้มเหลว 2 รายการเป็นข้อจำกัดนอกโมดูลลาด้านล่าง จึงไม่อ้างว่า full quality gate ผ่าน
- รัน full suite ใน container ชั่วคราวด้วย entrypoint PHP โดยตรงและ SQLite :memory: พร้อม mount frontend, compose และ CI แบบอ่านอย่างเดียว ทำให้ tests ที่เคยข้ามเพราะหาไฟล์ไม่พบได้รันจริง ไม่มีการเรียก production migration

## ข้อจำกัดและงานนอกขอบเขต

ชุดตรวจรวมที่เปิดให้เห็น repository ครบพบปัญหาเดิมนอกโมดูลลา 2 เรื่อง ซึ่งไม่ถูกซ่อนหรือแก้ด้วยการผ่อน tests:

- ApiSurfaceContractTest: พบ endpoints สารบรรณ/notification/operations และ versioned API ที่ยังไม่มี frontend call ตามสัญญา (เกี่ยวกับ SARABAN visibility audit เดิม)
- RuntimeSecurityConfigurationTest: composer.json ระบุ minishlink/web-push เป็น ^11.0 แทนเวอร์ชันตายตัว

ไม่มี migration/schema change, public URL ใหม่, dependency update หรือ deploy และไม่ได้ปรับยอด/ประวัติใบลาเก่าที่อาจเสียไปก่อนแก้ ต้องตรวจและแก้ผ่านกระบวนการปรับยอดที่มี audit เป็นงานแยก ไม่เขียนทับ historical records อัตโนมัติ รอบนี้ไม่มี browser E2E หรือ MySQL concurrency run ใหม่

หลักฐาน: `leave-fix-backend-final-2026-09-05.log`, `leave-fix-pint-2026-09-05.log`, `leave-fix-frontend-2026-09-05.log`
