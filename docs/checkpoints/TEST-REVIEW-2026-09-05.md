# Test & Review — 2026-09-05

ตรวจ working tree ปัจจุบันเทียบ HEAD รวมไฟล์ใหม่ของสารบรรณ โดยเน้น authorization, organization scope และการทำลายเอกสาร ไม่ใช่การรับรองทุกเส้นทางหรืออนุมัติขึ้น production

## ผลรีวิว: ยังไม่ผ่าน

### REVIEW-20260905-01 · P1 · ทำลายเอกสารข้ามขอบเขตหน่วยงาน

`backend/app/Http/Controllers/Saraban/RetentionController.php:121` รับ schedule จาก route แล้วเรียก `RetentionService::dispose()` โดยไม่มี resource authorization ต่างจาก `show()`, `schedule()` และ `placeHold()` ที่เรียก document policy ก่อน การตรวจ `ability:saraban.config.manage` และ `step_up` บน route ตรวจความสามารถและการยืนยันตัวตน แต่ไม่ได้ตรวจหน่วยงานเจ้าของเอกสาร ส่วน service ก็ไม่ได้ตรวจ scope

พิสูจน์ด้วยผู้ใช้หน่วย ก ที่มีสิทธิ์ config/policy และโทเคนที่เพิ่งยืนยันตัวตน: policy `view` ของเอกสารหน่วย ข คืน false แต่ POST `/api/saraban/retention/{schedule}/dispose` คืน HTTP 200 และไฟล์ทดสอบถูกลบจริงจาก fake private storage เส้นทาง approve และคิว due ก็ขาด scope เช่นกัน ควรตรวจขอบเขตของความสามารถที่ใช้กระทำต่อเอกสารก่อนอนุมัติ/ทำลาย และกรองคิวด้วย scope เดียวกัน

### REVIEW-20260905-02 · P1 · ปิดและแก้สารบบเรื่องข้ามหน่วยงาน

`backend/app/Http/Controllers/Saraban/CaseController.php:133` ปิดสารบบที่ bind มาโดยไม่ตรวจเจ้าของหรือ organization scope ผู้ใช้ที่มีเพียงความสามารถสร้างหนังสือจึงระบุ ID ของสารบบหน่วยอื่นเพื่อปิดได้ นอกจากนี้ `detach()` ไม่มีทั้ง case/document authorization ส่วน index/show เปิด metadata ของสารบบโดยไม่มี case scope

พิสูจน์ด้วยผู้ใช้หน่วย ก: POST `/api/saraban/cases/{case}/close` ของหน่วย ข คืน HTTP 200 และสถานะเปลี่ยนจาก open เป็น closed ควรมี case policy และใช้กับทุก mutation รวมถึง scope รายการและรายละเอียด

### REVIEW-20260905-03 · P1 · การอายัดแข่งกับการทำลายไฟล์ได้

`backend/app/Services/Saraban/RetentionService.php:184` ตรวจ `isOnHold()` ก่อนเข้า transaction ที่บรรทัด 188 โดยไม่มี row lock ส่วน `placeHold()` ไม่ล็อก document ร่วมกัน หากคำขอทำลายอ่านว่าไม่อายัด แล้วคำขออายัดบันทึกสำเร็จก่อนการลบไฟล์ คำขอแรกยังลบต่อได้แม้มี active hold แล้ว ควรให้ทั้งสองเส้นทางล็อก document เดียวกัน และอ่านสถานะ/hold ซ้ำภายใต้ล็อกก่อนทำลาย

ข้อนี้ยืนยันจาก control flow ยังไม่ได้ทดสอบคำขอขนานบน MySQL ในรอบนี้

### REVIEW-20260905-04 · P1 · ไม่ได้ผูก MalwareScanner กับ implementation

`backend/app/Rules/FreeOfMalware.php:41` เรียก `app(MalwareScanner::class)` เมื่อเปิดการตรวจไวรัส แต่ไม่มี provider ใด bind interface นี้กับ `ClamAvScanner` และ `AppServiceProvider::register()` ว่าง ผลคืออัปโหลดไฟล์ได้ HTTP 500 (`Target [App\Contracts\Files\MalwareScanner] is not instantiable`) ก่อนส่งข้อมูลไป scanner ควรลงทะเบียน binding ที่ Core และเพิ่ม test ตรวจการ resolve จริงโดยไม่แทน interface ด้วย mock

ยืนยัน read-only บน application bootstrap ด้วย `$app->bound(App\Contracts\Files\MalwareScanner::class)` ได้ `false`; backend รอบสุดท้ายมี failure ที่เกิดจาก exception นี้จำนวนมาก `MalwareScanningTest` เดิมใช้ instance ปลอม จึงไม่พิสูจน์ production binding

## การตรวจสอบ

- ชุด backend เดิม: `docker exec eleave_backend php artisan test --compact` — 1,031 passed, 8 skipped, 3,802 assertions (157.84 วินาที)
- Regression เพิ่ม: `docker exec eleave_backend php artisan test --compact --filter=ReviewScopeTest` — 2 failed, 3 assertions; ล้มที่ security contract จริง ไม่ใช่การเตรียม fixture
- Pint ก่อนเพิ่ม regression: `vendor/bin/pint --test` — ผ่าน 654 ไฟล์; ไฟล์ regression ผ่าน Pint แยก
- Frontend: `npm run lint`, `npm run typecheck`, `npm test`, `npm run build` ผ่าน; Vitest 81 tests / 9 files
- typecheck ใช้ config โครงการ (`checkJs: false`) ไม่ใช่การตรวจ JavaScript แบบ strict
- Regression ใช้ SQLite `:memory:` และ `Storage::fake('private_uploads')` พร้อม bearer token จริงใน test framework ไม่ลบไฟล์ผู้ใช้
- **Backend รอบสุดท้าย:** 119 failed, 8 skipped, 914 passed, 3,363 assertions (128.61 วินาที) รวม scope regression 2 ข้อและข้อผิดพลาด MalwareScanner; ไม่ถือว่ารอบสุดท้ายผ่าน
- **Pint รอบสุดท้าย:** FAIL — 656 files, 1 style issue (`class_attributes_separation`) ที่ `database/seeders/OperationalPermissionSeeder.php`
- ระหว่างงานพบ container backend หยุด/เริ่มใหม่และ working tree เปลี่ยนเพิ่มเติม (เช่น OperationalPermissionSeeder) ซึ่งไม่ได้แก้โดยงานรีวิวนี้ จึงบันทึกผลแต่ละรอบแยกกัน ไม่อ้างว่ารอบแรกและรอบสุดท้ายเป็น snapshot เดียวกัน การรันซ้ำครั้งแรกชน container ที่หยุดอยู่ ก่อนรันใหม่จนจบ
- หลักฐานคำสั่ง: `test-review-2026-09-05/` (ผลเต็มรอบสุดท้ายเก็บใน ZIP เพื่อลดขนาด)

## สิ่งที่ส่งมอบและข้อจำกัด

เพิ่ม `backend/tests/Feature/ReviewScopeTest.php` ไว้ในชุดทดสอบปกติ เพื่อให้ CI ตรวจพบสองช่องโหว่ ไม่ skip หรือเปลี่ยน assertion ให้ผ่าน ยังไม่ได้แก้ application code ตามขอบเขตคำสั่ง test/review

อัปเดต TASKS, TRACEABILITY-MATRIX และ CHANGELOG ไม่มี migration/API change หรือ architectural decision ใหม่ จึงไม่แก้ DECISION-LOG

ไม่ได้รัน browser E2E/UAT, fresh MySQL migration, concurrency proof, dependency audit หรือ deployment ในรอบนี้ ชุดเดิมมี 8 skipped จึงไม่ถือว่าครอบคลุมการทดสอบทั้งหมด และผลเดิมที่ผ่านไม่หักล้าง security regressions ที่พบใหม่
