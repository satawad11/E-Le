# ตรวจโมดูลระบบลางาน — 2026-09-05

ตรวจ working tree ปัจจุบันตามคำสั่ง «ตรวจ Module ระบบลางาน» โดยอ่านข้อกำหนดโครงการ, มาตรฐาน security/RBAC/workflow/testing/audit และเอกสาร LEAVE-01/02/03 เปรียบเทียบ routes, FormRequests, policies, services, approval/cancellation และจุดเรียกจาก frontend พบข้อบกพร่องที่ยืนยันด้วย API tests 4 รายการ ยังไม่ผ่านการตรวจรับด้านความปลอดภัยและความถูกต้องของยอดลา

## ข้อค้นพบที่ยืนยันแล้ว

### LEAVE-AUDIT-01 · P1 · ร่างส่วนตัวเปิดได้ผ่าน URL รายฉบับ

`backend/app/Policies/LeavePolicy.php:52–70` ตรวจเจ้าของ/ผู้แทน/สิทธิ์ระดับองค์กรโดยไม่ตรวจสถานะ Draft ขณะที่ `LeaveQueryController::index` ซ่อนร่างของผู้อื่นไว้ `LeaveRequestController::show` ใช้ policy นี้แล้วส่งใบลาและ evidences กลับ ผู้มี `leave.management.manage` ซึ่งไม่ใช่เจ้าของและไม่ใช่ Super Admin จึงเปิด `GET /api/leaves/{id}` ของร่างได้ HTTP 200 รวมเหตุผลและที่อยู่ติดต่อ ทั้งที่ร่างต้องเป็นส่วนตัวจนกว่าจะส่ง

หลักฐาน: `LeaveModuleAuditTest::test_another_users_draft_cannot_be_opened_directly_by_a_manager` คาด 403 แต่ได้ 200 ควรบังคับเจ้าของร่างในด่านอ่านรายฉบับ และตรวจเส้นทาง PDF/Word ที่ใช้ policy เดียวกันด้วย

### LEAVE-AUDIT-02 · P1 · แก้ทับใบลาที่อนุมัติแล้วโดยไม่รักษายอดและประวัติ

`backend/app/Http/Controllers/LeaveRequestController.php:193–201` กับ `UpdateLeaveRequest` ตรวจเพียงสิทธิ์จัดการ แล้วเรียก `$leave->update($data)` รับประเภท/วันเริ่ม/วันสิ้นสุด/เหตุผลได้ทุกสถานะ ไม่มีการตรวจสถานะหรือคำนวณ total_days, paid_days, reserved_days, balance, policy และ attendance ใหม่ จึงเปลี่ยนใบอนุมัติจาก 7–8 ก.ย. เป็น 7–17 ก.ย. ได้ แต่จำนวนวันยังเป็น 2 การเปลี่ยนประเภทลายังทำให้ยอดเดิมผูกผิดประเภท การแก้เนื้อหาหลัง seal ทำให้ hash ไม่ตรงและไม่มี revision ของเอกสาร

หลักฐาน: `LeaveModuleAuditTest::test_approved_leave_cannot_be_overwritten_through_generic_update` คาด 422 แต่ได้ 200 ควรจำกัด generic update และใช้กระบวนการปรับแก้/เพิกถอนที่เก็บประวัติสำหรับใบที่ส่งแล้ว แยกจากการแก้ร่าง

### LEAVE-AUDIT-03 · P1 · บันทึกแทนเป็น Approved แต่ไม่ตัดยอดใช้จริง

`backend/app/Http/Controllers/LeaveRequestController.php:175–190` เรียก createLeaveRequest ซึ่งกันโควตาแบบ Pending แล้วตั้ง status เป็น Approved โดยตรง ข้าม `deductBalance()` และ `LeaveAttendanceSync::apply()` ซึ่งเส้นทางอนุมัติปกติเรียกใน `GovernmentApprovalController:109–117` ใบลา 2 วันจึงมี used=0 และ reserved ค้าง อีกทั้งข้ามการ seal เนื้อหาและปล่อย approval snapshot ไว้ Pending การแสดงสถานะอนุมัติจึงไม่สอดคล้องกับยอดและข้อมูลลงเวลา

หลักฐาน: `LeaveModuleAuditTest::test_admin_recording_an_approved_leave_consumes_the_balance` ยืนยัน status=Approved แต่ used จริง 0 แทน 2 ควรรวมผลของการอนุมัติไว้ในบริการกลางและ transaction เดียวพร้อม audit ไม่ตั้งสถานะลอย ๆ ใน controller

### LEAVE-AUDIT-04 · P1 · ใบลาข้ามปีงบประมาณคิดยอดทั้งใบกับปีเริ่มต้น

`backend/app/Services/LeavePolicyService.php:107–114` ปฏิเสธเฉพาะการข้าม calendar_year ส่วน fiscal_year ใช้วันที่เริ่มหา balance เพียงแถวเดียว `LeaveService::createLeaveRequest` กันจำนวนวันทั้งใบในแถวนั้น ใบลา 30 ก.ย.–2 ต.ค. ผ่าน HTTP 201 และนำ 3 วันไปใช้ปีเดิม โดยไม่มีการแบ่งยอดไปปีใหม่ กระทบสิทธิคงเหลือ/ยอดยกมาและรายงานรายปี

หลักฐาน: `LeaveModuleAuditTest::test_fiscal_year_leave_must_be_split_at_the_period_boundary` คาด 422 แต่ได้ 201 ภายใต้การออกแบบปัจจุบันที่หนึ่งใบมี policy/balance period เดียว ควรปฏิเสธและให้แยกใบข้ามปี หรือออกแบบแบ่งยอดรายปีอย่างชัดเจนก่อนอนุญาต

## การตรวจสอบและข้อจำกัด

- เพิ่ม `backend/tests/Feature/LeaveModuleAuditTest.php` จำนวน 4 regression tests ในชุดปกติ ไม่ skip หรือเปลี่ยน expected result ให้ตรงบั๊ก
- ทดสอบใน backend container โดย phpunit บังคับ SQLite `:memory:`; ไม่ใช้ฐานข้อมูล production สำหรับ mutation
- ผล full suite: **1,042 passed / 4 failed / 8 skipped · 3,842 assertions · 137.58 วินาที** ความล้มเหลวทั้ง 4 มาจาก LeaveModuleAuditTest ตรงกับข้อค้นพบ; ไม่ถือว่า quality gate ผ่าน
- Pint ผ่าน 660 files หลังจัดรูปแบบ test ใหม่ และ frontend lint/typecheck/81 tests/build ผ่าน; build ไป `/tmp/leave-audit-dist` ใน container
- ไม่ได้ทดสอบ browser E2E ใหม่, MySQL concurrency ใหม่, ตรวจสิทธิ์รายบัญชีใน production หรือยืนยันความถูกต้องตามกฎหมาย รายงานนี้เป็นการตรวจ implementation ตามข้อกำหนดใน repository
- ประเด็น scope/explicit deny ในเส้นทางจัดการและการอ่านยังต้องขยาย role × scope matrix; ไม่ถือว่ารายการที่ไม่ได้รายงานผ่านทั้งหมด
- working tree มีงานค้างจำนวนมากตั้งแต่ก่อนเริ่ม ผลนี้อ้างอิงไฟล์ปัจจุบัน ไม่ใช่ clean commit และไม่สรุปว่าปัญหาทั้งหมดเกิดจาก diff ล่าสุด

## สิ่งที่ส่งมอบ

เปลี่ยนเฉพาะ regression test, รายงานนี้, TASKS.md, docs/TRACEABILITY-MATRIX.md, docs/CHANGELOG.md และ logs ของ quality gates ไม่มีการแก้ application code, API contract, migration/schema, dependency, ข้อมูลจริง หรือ deploy และไม่มี architectural decision ใหม่

หลักฐาน: `leave-audit-backend-2026-09-05.log`, `leave-audit-pint-2026-09-05.log`, `leave-audit-frontend-2026-09-05.log` ใน directory เดียวกับรายงาน
