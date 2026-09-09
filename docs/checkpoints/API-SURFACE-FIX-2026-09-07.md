# ปิด Quality gate ทั้ง 2 หลังแก้ระบบลา

วันที่ตรวจซ้ำ: 2026-09-07 · คำสั่งผู้ใช้: “ดำเนินการแก้ทั้ง 2” และ “ดำเนินการต่อ”

## ผล

`ApiSurfaceContractTest` และ `RuntimeSecurityConfigurationTest` ผ่านแล้ว รวม regression ระบบลา 11 กรณีเดิมครบ โดยไม่ข้าม tests ผลชุดเต็มอ้างอิงโค้ดปัจจุบันซึ่งมีงานอื่นบันทึกเพิ่มระหว่าง 5–7 กันยายน จึงมีจำนวน tests มากกว่ารอบก่อน

| การตรวจ | ผล | หลักฐาน |
|---|---|---|
| Backend full suite | 1,141 passed / 0 failed / 0 skipped · 4,623 assertions | `api-surface-backend-2026-09-07.log` |
| สอง quality gates + LeaveModuleAuditTest | 19 passed · 404 assertions | `api-surface-targeted-2026-09-07.log` |
| PHP Pint | PASS · 679 files | `api-surface-pint-2026-09-07.log` |
| Frontend lint / typecheck | PASS | `api-surface-frontend-2026-09-07.log` |
| Frontend tests | 127 passed · 14 files | `api-surface-frontend-2026-09-07.log` |
| Frontend production build | PASS · output `/tmp/api-surface-dist-20260907` ใน container | `api-surface-frontend-2026-09-07.log` |
| UI regression เฉพาะงาน | 17 passed | `api-surface-ui-tests-2026-09-07.log` |

ข้อความ ERROR “ทดสอบ rollback” ใน log เป็น fault injection ที่ test ตั้งใจให้เกิด และ assertion ตรวจการ rollback ผ่าน ไม่ใช่ suite ล้มเหลว

## สิ่งที่แก้

1. **Runtime dependency pin** — `backend/composer.json` เปลี่ยน `minishlink/web-push` จาก `^11.0` เป็น `11.0.0` และปรับ lock metadata ให้ตรงกัน รุ่นใน lock เดิมคือ 11.0.0 อยู่แล้ว จึงไม่อัปเกรดแพ็กเกจ ผล Composer วันที่ 5 กันยายนอยู่ใน `api-surface-composer-2026-09-05.log`
2. **API surface** — เพิ่มผู้ใช้ API ใน UI ที่ขาดจริง และปรับการตรวจ URL ให้สะท้อนการเรียกผ่าน composable กับ versioned aliases โดยไม่ยกเว้นทั้งโมดูล:
   - Alias `/api/v1/saraban/*` ต้องมี canonical route ที่ action/HTTP methods ตรงกัน คง middleware เดิมครบและมี `idempotent`; canonical route ยังต้องมี UI consumer
   - อ่าน base path ของ `useAttendanceSummary` และ suffix ของ `runReport` จาก implementation จริง
   - ระบุเส้นทางภายนอกเป็นรายรายการ: health สำหรับ monitoring, QR สำหรับหนังสือ และ OpenAPI สำหรับ integration โดยมี backend tests รองรับ
3. **UI consumers** — เชื่อมหลักฐานลงนาม/finalize/เปรียบเทียบฉบับ/ปกปิด/ทางเข้าเกษียน, retention/holds/disposal, authority holders, incoming detail/duplicate check, case documents, saved views, operational health/flags และ notification health/dead letters/subscriptions เข้าหน้าจอที่เกี่ยวข้อง การทำงานสำคัญแสดงยืนยันและรองรับ server 423 ด้วยการยืนยันรหัสผ่านซ้ำ
4. **ความถูกต้องของ redaction preview** — แก้เพิ่มวันที่ 7 กันยายนให้แก้พื้นที่ได้เฉพาะไฟล์ต้นทางที่โหลดให้ตรวจจริง เปิดสำเนาหรือฉบับเปรียบเทียบแล้วเป็นอ่านอย่างเดียว ทิ้งผลโหลดเก่าที่ตอบช้า คืน blob เมื่อออกจากหน้า และเก็บ payload ที่ตรงกับรายการยืนยัน
5. **ภาษา UI** — แสดงรหัสช่องทาง `line_oa` เป็น “ไลน์ทางการ” และช่องทางที่ไม่รู้จักเป็น “ช่องทางอื่น”

## ไฟล์งาน

การแก้หลักวันที่ 5 กันยายนอยู่ใน repository แล้วเมื่อเริ่มต่อวันที่ 7 กันยายน:

- `backend/composer.json`, `backend/composer.lock`, `backend/tests/Feature/ApiSurfaceContractTest.php`
- `frontend/src/components/saraban/{ConfirmOperation,DocumentTools,RetentionPanel,OperationsTools,SavedViews}.vue`
- `frontend/src/components/notifications/WebPushToggle.vue`
- `frontend/src/views/saraban/{DocumentFormView,CommandCenterView,DocumentsView,IncomingRegisterView,IncomingView,AuthorityMatrixView,CasesView}.vue`
- `frontend/src/views/admin/TelegramSettingsView.vue`
- `frontend/tests/apiSurfaceFlows.test.js`

แก้เพิ่มเติมรอบต่อ: `DocumentTools.vue`, `OperationsTools.vue`, `apiSurfaceFlows.test.js`; อัปเดต `TASKS.md`, `docs/TRACEABILITY-MATRIX.md`, `docs/CHANGELOG.md`, `docs/DECISION-LOG.md` และรายงาน/log ของวันที่ 7 กันยายน งานใหม่ของ task อื่นที่บันทึกเข้ามาคงไว้ตามเดิม

## การยืนยันพฤติกรรม

- Component tests ใช้ component จริงกับ API mock ตรวจการยืนยันก่อน mutation, 423/password failure, finalize state, comparison IDs, redaction payload, hold/disposal, permission visibility, notification retry และ saved view save/apply/delete
- เพิ่ม 3 regression tests: เปิดสำเนาแล้วปิดแก้พื้นที่ต้นทาง, response เก่ามาถึงหลังฉบับใหม่ยังสร้างสำเนาจากฉบับใหม่, และคืน blob ที่โหลดเสร็จหลัง unmount
- Browser smoke ที่ `http://localhost:5173`: เข้าบัญชีทดสอบจากปุ่มในหน้าล็อกอิน; หน้าการแจ้งเตือนโหลดสถานะทั้ง 5 ช่องทางและคิวว่างได้ ชื่อไลน์แสดงภาษาไทย; หน้าค้นหาและคลังเอกสารโหลดรายการและแสดงผล “ยังไม่มีมุมมองที่บันทึกไว้” หลังเรียก API
- เปิดหน้ารายละเอียดหนังสือจากทะเบียนสำเร็จ พบเครื่องมือตรวจหลักฐาน/เปรียบเทียบ/เกษียน/ปกปิดและการเก็บรักษา โดยช่องแก้รายละเอียดและปุ่มออกฉบับสมบูรณ์ถูกปิดตามสถานะที่แสดง
- Browser smoke เป็นการอ่าน ไม่ได้ส่งข้อความ อนุมัติ ทำลาย หรือเปลี่ยนข้อมูลเอกสารจริง การทดสอบ mutations ใช้ isolated automated fixtures

## วิธีรันและขอบเขต

```text
docker compose exec -T backend php artisan test --compact
docker compose exec -T backend php artisan test --filter='ApiSurfaceContractTest|RuntimeSecurityConfigurationTest|LeaveModuleAuditTest'
docker compose exec -T backend vendor/bin/pint --test
docker compose exec -T frontend sh -c 'npm run lint && npm run typecheck && npm run test && npm run build -- --outDir /tmp/api-surface-dist-20260907'
```

Backend container ปัจจุบันมี frontend source, compose files และ `.github` mount ครบ ทำให้ gate ที่เคยข้ามเพราะหาไฟล์ไม่พบได้รันจริง `phpunit.xml` บังคับ SQLite `:memory:` และ APP_ENV=testing; ชุดนี้ไม่ได้ยืนยัน concurrency บน MySQL production

ไม่มี migration/schema change, endpoint ใหม่ หรือ deploy ในงานแก้นี้ Server authorization/scope/audit เดิมยังบังคับใช้ ผล audit เก่าที่ล้มเหลวเก็บไว้เป็นประวัติและปิดสถานะด้วยผลตรวจใหม่นี้ ไม่แก้ตัวเลขย้อนหลัง
