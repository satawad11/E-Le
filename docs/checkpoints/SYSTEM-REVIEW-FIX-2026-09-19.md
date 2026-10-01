# ปิดงานแก้ข้อค้นพบจากการตรวจระบบ

วันที่ตรวจยืนยัน: 2026-09-19

ขอบเขต: ข้อค้นพบ 6 รายการจากการตรวจแบบอ่านอย่างเดียวเมื่อ 2026-09-14 และคำสั่งให้แก้ทั้งหมด โค้ดและ regression tests ที่เริ่มแก้ในงานนี้ถูกรวมเข้า repository แล้วระหว่างการพัฒนาต่อ (รวม commit `0847ad4`); รอบปิดงานตรวจสถานะล่าสุดที่ `b1b7433` พร้อมเติมเอกสาร ไม่เขียนทับงานที่พัฒนาระหว่างนั้น

## การแก้และหลักฐาน

| ข้อ | พฤติกรรมหลังแก้ | ไฟล์หลัก | Regression test |
|---|---|---|---|
| P1 ต่ออายุโทเคน | abilities ของโทเคนใหม่ตรงกับเดิม รวม `[]` และ `*`; โทเคนเก่าถูกเพิกถอน | `backend/app/Http/Controllers/AuthController.php` | `ApiIdempotencyAndWebhookTest::test_refresh_preserves_narrow_empty_and_wildcard_token_scopes` |
| P1 มอบอำนาจผิดคน | ผู้มอบต้องอยู่ใน candidates ของเรื่องและขั้นนั้น; ไม่ใช้บทบาทร่วมกันเป็นเหตุให้ผ่าน และผู้อนุมัติแบบเจาะจงที่ไม่มี fallback role ยังมอบอำนาจได้ | `backend/app/Services/Workflow/WorkflowEngine.php`, `DelegationResolver.php` | `WorkflowAuthorizationTest::test_delegation_must_come_from_the_named_assignee_not_a_role_peer` (legacy/workflow), `test_a_named_assignee_without_the_fallback_role_can_delegate` |
| P1 สิทธิ์ใบลาข้ามโมดูล | ผู้จัดการใบลาที่มีสิทธิ์อ่านสารบรรณอย่างเดียวอนุมัติสารบรรณไม่ได้ | `backend/app/Services/Workflow/WorkflowEngine.php` | `SarabanWorkflowAndAuthorityTest::test_leave_management_does_not_grant_saraban_approval` |
| P1 สถานะสายงาน 500 | แปลงชื่อ action จาก engine เป็น transition metadata ของเวอร์ชัน/ขั้นที่ถูกต้องก่อนส่ง JSON | `backend/app/Services/Saraban/DocumentWorkflowService.php` | `SarabanWorkflowAndAuthorityTest::test_the_current_assignee_can_read_transition_metadata` |
| P2 multipart idempotency | hash ฟิลด์/query/เนื้อหาไฟล์และข้อมูลไฟล์แบบเรียงคีย์ ไม่ใช้ boundary หรือชื่อไฟล์ชั่วคราวเป็นตัวตัดสิน | `backend/app/Http/Middleware/EnsureIdempotentRequest.php` | `ApiIdempotencyAndWebhookTest::test_multipart_replays_ignore_boundary_but_compare_fields_and_file_contents` |
| P2 ช่วงเวลา OT | ใช้ approved window ที่ขั้นก่อนบันทึกไว้เมื่อผู้อนุมัติไม่แก้เอง; ถ้ายังไม่มีจึงใช้ scheduled window | `backend/app/Services/Overtime/OvertimeOrderService.php`, `frontend/src/views/admin/OvertimeOrdersView.vue` | `OvertimeApprovalChainTest::test_final_approval_preserves_the_permitted_window_unless_explicitly_changed`, `frontend/tests/overtimeApprovalWindow.test.js` |

## API และการย้ายข้อมูล

- แก้พฤติกรรมของ `POST /api/refresh-token`, API อนุมัติใบลา, `GET/POST /api/saraban/documents/{document}/workflow` (รวม prefix v1), multipart mutations ใต้ `/api/v1/saraban`, และ `POST /api/overtime/orders/{order}/approve`
- ไม่มี endpoint ใหม่ ไม่มี schema change หรือ migration และไม่เปลี่ยน dependency versions
- ไม่ได้ deploy หรือเรียก mutation บนฐานข้อมูลใช้งานจริง
- ไม่มี architectural decision ใหม่: เป็นการบังคับสิทธิ์และการรักษาข้อมูลให้ตรงกับมาตรฐานเดิม

## ผลตรวจรอบปิดงาน

| ด่านตรวจ | ผล |
|---|---|
| Pint `vendor/bin/pint --test` | ผ่าน 768 ไฟล์ |
| PHPStan/Larastan level 5 | ผ่าน ไม่มี error เพิ่มจาก baseline ของโครงการ |
| Backend `php artisan test --compact --display-skipped` | 1,380 passed / 2 skipped / 5,744 assertions; 310.81 วินาที |
| ตรวจซ้ำ `ConfigDriftTest` และ `RuntimeSecurityConfigurationTest` เมื่อ mount repository ครบ | 9 passed / 0 skipped / 26 assertions; รวมสองข้อที่ถูกข้ามในชุดเต็ม |
| Frontend ESLint | ผ่าน |
| Frontend `vue-tsc --noEmit` | ผ่าน |
| Frontend Vitest | ผ่าน 213 tests / 33 files |
| Frontend Vite build | ผ่าน |
| `git diff --check` | ผ่าน |

PHPStan รันผ่าน container ชั่วคราว image `e-le-backend` โดย mount `backend/` รวม vendor บน host, ปิด network และกำหนด `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array` เพราะ vendor volume ของ container backend ที่เปิดอยู่ยังไม่มี PHPStan ไม่ติดตั้งหรืออัปเกรด dependency ของบริการที่กำลังรัน

สองข้อที่ถูกข้ามในรอบชุดเต็มคือการตรวจ memory limit ใน `docker/php/php.ini` และการตรวจ ES modules ใน `docker/nginx/default.conf` ซึ่งอยู่นอก mount ของ container เดิม ตรวจซ้ำทั้งสองคลาสใน container ชั่วคราวที่ mount `D:/E-Le:/repo` และทำงานใน `/repo/backend` ผ่านครบแล้ว จึงไม่มีข้อทดสอบที่ยังไม่ได้ตรวจจากการข้ามครั้งนั้น (ไม่นำ 9 ข้อไปบวกยอดชุดเต็ม เพราะมี 7 ข้อที่ตรวจซ้ำ)

## ข้อจำกัด

- การรัน backend regression ใช้ SQLite ในหน่วยความจำตาม `phpunit.xml`; รอบนี้ไม่ได้ทดสอบโหลดหรือ concurrency บน MySQL
- ไม่ใช่การรับรองว่าระบบทั้งหมดไม่มีข้อบกพร่องอื่น; ขอบเขตปิดงานคือข้อค้นพบ 6 รายการข้างต้น
- โทเคนที่เคยถูกขยายสิทธิ์ก่อนการแก้ไม่ถูกเพิกถอนย้อนหลังโดยอัตโนมัติ เพราะไม่มีข้อมูล abilities เดิมให้กู้คืนจากแถวโทเคนใหม่
- กุญแจ multipart ที่บันทึกด้วย hash แบบเก่าจะไม่ตรงกับรูปแบบใหม่และอาจตอบ 422 ภายในอายุกุญแจ ต้องตรวจสถานะรายการเดิมก่อนออกคำขอใหม่
