# R-03 REPORT — Core องค์กรและบุคลากร

วันที่: 2026-10-19 · Branch: `rebuild/R-03` (แตกจาก `rebuild/2569` @ `460a199` หลัง merge PR #31) · ฐานข้อมูลที่ใช้: ทดสอบเท่านั้น
(sqlite `:memory:` ของ phpunit และ MySQL `eleave_r02_new` สำหรับ smoke test — ไม่แตะ `eleave`)

## 1. ผลตัดสินของเจ้าของระบบที่บันทึกก่อนเริ่ม

| คำถาม | ผล | บันทึกที่ |
|---|---|---|
| Q7 | อนุมัติ `tests/Legacy` · รายงานทุกเฟสต้องระบุเทสต์ Legacy ที่ย้าย/ลบ · ต้องว่างก่อนผ่าน R-09 | TASKS (Q7, R09-5), AGENTS.md §Checkpoint, ADR-R-010 |
| Q9 | ใช้ `role_grants.attributes` (json) ไม่มี `grant_attributes` · LINE/Telegram เก็บใน `notification_channels` (R-08) | TASKS (Q9, R08-notification-1), PLAN §4.2, ADR-R-012 |
| Q10 (ใหม่) | ล็อกอินด้วยอีเมลหรือ `personnel_code` · เลขประจำตัวประชาชนปิดเป็นค่าเริ่มต้น | TASKS (Q10), `config/auth.php`, ADR-R-013 |
| Q8 | ยังรองานบุคคล — ไม่แก้ในเฟสนี้ | TASKS (Q8 เปิดอยู่) |
| — | เพิ่มงาน R-08 leave: แก้ `/api/leaves/verify/{hash}` และ `/qrcode` ที่ยังอ้าง `user.profile` | TASKS R08-leave-1 |

## 2. สิ่งที่ทำ (TASKS R03-1 … R03-7)

| Task | ผล |
|---|---|
| R03-1 | `App\Services\Org\OrgTree` — `create` (path/depth, level ลูก > parent, root เดียว) · `move` (อัปเดต path/depth ทั้ง subtree ใน transaction, ห้ามวงวน) · `close` (ปิดแทนลบ ต้องปิดหน่วยลูก/สิ้นสุดตำแหน่งก่อน) · `ancestors` · `subtreeIds` · `nearestOfLevel` (รวมตัวเอง, ข้ามชั้นที่ไม่มี) · `headOf` (acting ชนะในช่วงคำสั่ง) · audit ทุกการเปลี่ยน |
| R03-2 | API `Org\OrgUnitController` (index/store/update/move/close — ไม่มีลบ), `Org\PositionController` (index/store/update — ตำแหน่งบริหารประเภทหน่วยละหนึ่ง) · route ใต้ `/api/org/*` ประกาศ `permission:` ที่ R-04 จะต่อ · **ตอบ 403 ทุก endpoint** |
| R03-3 | contract `PersonnelFieldAccess` (ผูก `DenyByDefaultPersonnelFieldAccess`) · `FieldClassificationRegistry` (ไม่จัดชั้น = sensitive) · `PersonnelPresenter` (ชั้นที่ไม่มีสิทธิ์ = null + `masked`, sensitive ส่งเฉพาะค่าปิดบัง `••••2345`) · `SensitiveReveal` (เหตุผล ≥ 10 ตัวอักษร → ตรวจฟิลด์ → ตรวจสิทธิ์ → log ก่อนคืนค่า) · `PersonnelEditor` (hr_field_guard) · `PersonnelChangeRequestService` (Maker–Checker) · `FieldClassificationSeeder` (6 หมวด 46 ฟิลด์) |
| R03-4 | `App\Services\Personnel\AssignmentService` — `add` (primary ซ้อนไม่ได้, management ของหน่วยซ้อนไม่ได้, ตำแหน่งบริหารต้องตรงประเภทหน่วย, primary ต้องเป็นตำแหน่งงาน, acting ต้องอ้างตำแหน่งบริหาร มีวันสิ้นสุด และไม่ใช่ผู้ดำรงเอง, เลขคำสั่งบังคับยกเว้น primary ที่นำเข้าครั้งแรก, บุคลากรพ้นสภาพเพิ่มไม่ได้) · `end` (ไม่ขยาย ไม่ปิดซ้ำ ต้องมีเหตุผล) · ไม่มี update/delete · event `AssignmentChanged` หลัง commit · API `Org\AssignmentController` (403) |
| R03-5 | `App\Services\Import\PersonnelImporter` — แผ่นงาน org_units → positions → personnel → assignments · dry-run เป็นค่าเริ่มต้น (ประมวลผลจริงแล้ว rollback) · ข้อผิดพลาดรายแถวพร้อมเลขแถวในไฟล์ · commit เมื่อไม่มีข้อผิดพลาดเลยเท่านั้น · หน่วยลูกอยู่ก่อนหน่วยแม่ได้ · ตรวจหลักตรวจสอบเลขประจำตัวประชาชน · ข้ามรหัสที่มีอยู่แล้ว ไม่เขียนทับ · ใช้ OrgTree/AssignmentService จึงได้กฎเดียวกับหน้าจอ · คำสั่ง `personnel:import` และ `personnel:import-template` · `SheetReader`/`SheetWriter` (ADR-R-014) · API `POST /api/personnel/import` (403) |
| R03-6 | เทสต์ CORE-01/02 ขั้นต่ำครบ (ข้อ 4) + fixture `Tests\Fixtures\CollegeFixture` |
| R03-7 | รายงานนี้ |
| Q10 | `AuthController` ค้นตามลำดับ อีเมล → `personnel_code` → (ถ้าเปิด) เลขประจำตัวผ่าน hash · รับช่อง `login` หรือ `email` |

## 3. ไฟล์ที่เปลี่ยน

| ประเภท | รายการ |
|---|---|
| เพิ่ม — service | `Services/Org/OrgTree` · `Services/Personnel/{AssignmentService, PersonnelPresenter, SensitiveReveal, PersonnelEditor, PersonnelChangeRequestService, FieldClassificationRegistry, DenyByDefaultPersonnelFieldAccess}` · `Services/Import/PersonnelImporter` · `Support/Spreadsheet/{SheetReader, SheetWriter}` · `Contracts/Personnel/PersonnelFieldAccess` · `Events/AssignmentChanged` |
| เพิ่ม — HTTP/CLI | `Http/Controllers/Org/{OrgUnit, Position, Assignment}Controller` · `Http/Controllers/Personnel/PersonnelController` · `Console/Commands/{ImportPersonnelCommand, PersonnelImportTemplateCommand}` |
| เพิ่ม — seed/test | `FieldClassificationSeeder` (อยู่ใน DatabaseSeeder) · `tests/Fixtures/{CollegeFixture, FakePersonnelFieldAccess}` · `tests/Feature/Core/*` 7 ไฟล์ |
| แก้ | `AuthController` + `config/auth.php` (Q10) · `AppServiceProvider` (ผูก contract) · `routes/api.php` (16 route ใหม่) · `Rebuild/CoreModelsTest` (เทสต์ login ตาม Q10) |
| เอกสาร | TASKS, AGENTS, CURRENT_PHASE, PLAN §4.2, DECISION-LOG (ADR-R-012/013/014), CHANGELOG, TRACEABILITY, `tests/Legacy/README.md` |
| Migration | ไม่มี — R-03 ใช้ schema ของ R-02 ทั้งหมด |

API ใหม่ (ทุกตัวตอบ 403 ในเฟสนี้):
`GET/POST /api/org/units` · `PUT /api/org/units/{uuid}` · `POST /api/org/units/{uuid}/move|close` · `GET/POST /api/org/positions` · `PUT /api/org/positions/{uuid}` ·
`POST /api/org/assignments/{uuid}/end` · `POST /api/personnel/import` · `GET /api/personnel/{uuid}` · `POST /api/personnel/{uuid}/reveal` ·
`POST /api/personnel/{uuid}/change-requests` · `POST /api/personnel/change-requests/{uuid}/review` · `GET/POST /api/personnel/{uuid}/assignments`

## 4. ผลการตรวจ

| การตรวจ | ผล |
|---|---|
| `php artisan test` | ✅ **166 tests, 1,133 assertions, 0 failures** (R-02 จบที่ 92/787) |
| `vendor/bin/pint --test` | ✅ 619 files PASS |
| `migrate:fresh --seed` บน MySQL (`eleave_r02_new`) | ✅ 14 migrations + 17 seeders |
| smoke test นำเข้าบน MySQL (ไฟล์ตัวอย่างสังเคราะห์ 4 แผ่นงาน) | ✅ dry-run 0 error → commit สำเร็จ 14 แถว · `headOf(root, 2026-11-05)` = acting · path `/1/5/9/11/` |
| deny-by-default | ✅ ทุก route ใหม่ตอบ 403 แม้เป็นเจ้าของข้อมูล และข้อมูลไม่เปลี่ยน |

เทสต์ใหม่ (`tests/Feature/Core/`):

| ไฟล์ | จำนวน | ครอบคลุม "Tests ขั้นต่ำ" |
|---|---|---|
| `OrgTreeTest` | 11 | path หลังย้าย subtree · ห้ามวงวน · level ลูก > parent (สร้างและย้าย) · root เดียว · ancestors · nearestOfLevel ข้ามชั้น · headOf คืน acting ในช่วงคำสั่ง · ปิดแทนลบ |
| `AssignmentServiceTest` | 11 | primary ซ้อนไม่ได้ · ย้าย = ปิดแถวเดิม + เพิ่มแถวใหม่ · management ซ้อนไม่ได้ · ตำแหน่งตรงประเภทหน่วย · เลขคำสั่ง · กฎ acting · end ไม่ขยาย/ไม่ซ้ำ · event + audit · ไม่มี update/delete |
| `MultiPositionPersonnelTest` | 4 | **ครู ช่างยนต์ + หัวหน้าแผนกช่างยนต์ + รองผอ. ฝ่ายบริหารทรัพยากร + รักษาการ ผอ.** — assignment ตามวันที่ · เป็นหัวหน้าเฉพาะหน่วยของแต่ละตำแหน่ง · ระดับ 2 เหนือหน่วยต้นสังกัดคือฝ่ายวิชาการ ไม่ใช่ฝ่ายบริหารทรัพยากร · primary ที่สองถูกปฏิเสธ |
| `PersonnelPrivacyTest` | 11 | ปิดบังถูกชั้น L1–L5 (5 persona) · sensitive ไม่ออกจาก presenter ทุกระดับ · ฟิลด์ไม่จัดชั้น = ปิด · ค่าเริ่มต้น deny · reveal ไม่มีเหตุผล = 422 และไม่ log · reveal เฉพาะ sensitive + ต้องมีสิทธิ์ · reveal ถูกบันทึก |
| `PersonnelChangeControlTest` | 9 | Maker–Checker (ผู้ยื่น/เจ้าของข้อมูลพิจารณาไม่ได้แม้มีสิทธิ์) · ค่าเดิมคงอยู่จนอนุมัติ · **แก้ hr_only ของตนเองไม่ได้แม้มีสิทธิ์ HR** · ค่า sensitive ไม่ลง audit |
| `PersonnelImportTest` | 10 | dry-run ไม่บันทึก · commit ผ่านกฎ domain · ข้อผิดพลาดรายแถว + ไม่บันทึกเลยเมื่อมีข้อผิดพลาด · ไม่สร้างบัญชี/ไม่รับรหัสผ่าน + audit · เลขประจำตัวซ้ำในไฟล์ · CSV + คำสั่ง · แม่แบบ · อ่าน xlsx ภาษาไทย + วันที่ serial · checksum |
| `CoreApiDeniedUntilEngineTest` | 16 | ทุก endpoint ใหม่ตอบ 403 |
| `Rebuild/CoreModelsTest` (เพิ่ม) | +2 | Q10: เลขประจำตัวปิดเป็นค่าเริ่มต้น / ใช้ได้เมื่อเปิด config · login ด้วย personnel_code |

## 5. เทสต์ใน `tests/Legacy` ที่ย้ายหรือลบในเฟสนี้ (Q7)

| ไฟล์ | การจัดการ | เทสต์ใหม่ที่ครอบคลุม |
|---|---|---|
| PersonnelEntityFoundationTest | ลบ | `Rebuild/SchemaBaselineTest`, `Rebuild/CoreModelsTest`, `Core/PersonnelPrivacyTest` |
| CoreDataFoundationTest | ลบ (dual-write departments ↔ org_units ถูกตัดตามแผน) | `Rebuild/SchemaBaselineTest`, `Core/OrgTreeTest` |
| PersonnelSyncTest | ลบ (ซิงก์ users ↔ personnel ถูกตัด) | `Core/AssignmentServiceTest`, `Core/MultiPositionPersonnelTest` |
| SchoolStructureCommandTest | ลบ (คำสั่ง org:apply-structure ถูกตัด) | `Rebuild/BaselineSeederTest`, `Core/PersonnelImportTest` |
| UserImportTest | ลบ | `Core/PersonnelImportTest` (+ สิทธิ์: `Core/CoreApiDeniedUntilEngineTest`) |

ไม่มีไฟล์ที่ย้ายกลับเข้าชุดปกติ · คงเหลือใน `tests/Legacy`: **80 ไฟล์** (จาก 85) · ไฟล์ที่เกี่ยวกับ R-03 แต่ยังเก็บไว้: PositionCatalogTest, PersonnelApiAlignmentTest, PersonnelDomainCoreServicesTest, OrgScopeResolverTest (รอ R-04 เปิด API/scope), PersonalDataProtectionTest, SecureFileAuthorizationTest (R-06)

## 6. Go/No-Go R-03

| เกณฑ์ (runbook) | ผล |
|---|---|
| เทสต์ CORE-01/02 ผ่าน | ✅ |
| fixture + เทสต์คนเดียวหลายตำแหน่ง | ✅ `CollegeFixture` + `MultiPositionPersonnelTest` |
| API ยังไม่ต่อ PermissionEngine ตอบ 403 ทุก endpoint | ✅ |
| นำเข้าไฟล์ตัวอย่างจริงของวิทยาลัยได้ (dry-run ไม่มี error) | ⏳ **ยังไม่มีไฟล์จริง** — ตัวนำเข้าผ่านไฟล์ตัวอย่างสังเคราะห์ทั้งบน sqlite และ MySQL · สร้างแม่แบบให้งานบุคคลด้วย `php artisan personnel:import-template` แล้วส่งกลับมาเพื่อ dry-run |

**ผล: Go แบบมีเงื่อนไข** — ข้อที่ 4 ต้องใช้ไฟล์จริงจากงานบุคคล ผม dry-run ให้ได้ทันทีเมื่อได้ไฟล์ (ไม่ต้องแก้โค้ด)

## 7. ข้อจำกัดและสิ่งที่ต้องทำต่อ

1. **ชั้นที่แต่ละระดับเห็น (L1–L5)** ในเทสต์เป็น matrix ตั้งต้น — R-04 ต้องผูก `PersonnelFieldAccess` กับ PermissionEngine และ persona tests ต้องได้ผลตรงตาราง
2. **ค่า `editable_by`** ใน `FieldClassificationSeeder` เป็นค่าเสนอ (เช่น ชื่อ–สกุลต้องยื่นคำขอ, เบอร์โทรแก้เองได้) — งานบุคคลควรทบทวน
3. ยังไม่มี API สร้าง/แก้ข้อมูลบุคลากรรายคน (นอกจากนำเข้าและคำขอแก้ไข) และหน้าจอ — เป็นงาน R-06
4. ข้อมูลการศึกษา/อบรม/ใบอนุญาต/เอกสารมีตารางและการจัดชั้นแล้ว แต่ยังไม่มี service แก้ไข (R-06)
5. วันที่มีผลของ root/ฝ่ายยังเป็นวันประกาศระเบียบ 2026-03-24 (R-02) — แก้ผ่านไฟล์นำเข้าไม่ได้เพราะนำเข้าข้ามรหัสที่มีอยู่แล้ว ต้องแก้ใน R-06 หรือด้วยคำสั่งที่ตรวจสอบได้
6. R-00 ยังไม่ครบ (ทดสอบ restore, export ถาวร, ลงนาม) · Q8 ยังเปิด

## 8. วิธีตรวจซ้ำ
```bash
cd backend
php artisan test
vendor/bin/pint --test
php artisan personnel:import-template storage/app/personnel-import-template.xlsx
php artisan personnel:import path/to/file.xlsx
```

**หยุดรอคำสั่ง**
