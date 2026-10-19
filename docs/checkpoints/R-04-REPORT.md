# R-04 REPORT — Permission Engine + manifest + เมนู

วันที่: 2026-10-19 · Branch: `rebuild/R-04` (แตกจาก `rebuild/2569` @ `833df6c` หลัง merge PR #32) · ฐานข้อมูลที่ใช้: ทดสอบเท่านั้น
(sqlite `:memory:` ของ phpunit และ MySQL `eleave_r02_new` — ไม่แตะ `eleave`)

## 1. สิ่งที่ทำ (TASKS R04-1 … R04-9)

| Task | ผล |
|---|---|
| R04-1 | รูปแบบ manifest (`app/Modules/<Code>/manifest.php`: features/actions, templates + denies, defaults L1–L5, menu_groups, menus) · `App\Authz\Manifest\ModuleRegistry` (normalize) · `ManifestValidator` (ตรวจทั้งชุด) · `ModuleSynchronizer` + คำสั่ง `modules:sync [--reset-defaults]` — idempotent, retire ไม่ลบ, ไม่ทับการปรับของผู้ดูแล, บันทึก permission_audit_logs · `ModuleManifestSeeder` |
| R04-2 | manifest ของ core: `core` (ก.), `personnel` + `org` (ข.), `access` + `audit` (ช.), `system` (ซ.) — เมนูครบทุกแถวของ PLAN §8.2 ก., ข., ช., ซ. · `workflow`/`document`/`notification` ประกาศเฉพาะสิทธิ์ที่เมนูตั้งค่าระบบข้อ 2–4 ใช้ (ส่วนที่เหลือ R-08) |
| R04-3 | `App\Authz\PermissionEngine` — `can()` (Decision: allow/deny + เหตุผล + แหล่ง), `scopeQuery()`, `effective()`, `explain()`, `scopesOf()`, `widestScope()` · แหล่งสิทธิ์: assignment ที่มีผล (position_templates ตามตำแหน่งหรือระดับ L1–L5; acting เฉพาะช่วงคำสั่ง) ∪ role grant ที่ยังไม่หมดอายุ ∪ override allow (ต้องมีวันหมดอายุ) − deny (ชุดสิทธิ์/override) · resource ต้องอยู่ใน scope ของแหล่งเดียวกัน · cache ต่อผู้ใช้ต่อวัน + version (ล้างเมื่อ AssignmentChanged, role grant/override/template/position_template/permission เปลี่ยน) |
| R04-4 | กฎกลาง: `not_own`, `maker_checker`, `hr_field_guard` (ใน `can()`), `grant_ceiling` (`canGrant()`), `two_person` (`requiresSecondPerson()`) |
| R04-5 | route macro `->permission('x.y.z')` · `RequirePermission` ถาม engine · route ของ core ทั้งหมดใช้ macro · controller ตรวจระดับรายการด้วย `authorizeResource()` (นอก scope = 404) · `EnsureRouteUsesPermissionEngine` **แทน** `DenyUntilPermissionEngine` |
| R04-6 | `GET /api/me/permissions` (permission, scope, แหล่ง, หน่วย) · `GET /api/me/menu` (`MenuBuilder`: กรองสิทธิ์, กลุ่มว่างไม่แสดง, ชื่อตาม scope, badge คำขอแก้ไขรอตรวจ, โมดูลที่ปิดไม่แสดง, หน้ารายละเอียดไม่อยู่ในเมนู) |
| R04-7 | `App\Authz\Workflow\ApproverChainResolver` — `head_of_level`, `functional_template`, `specific_assignment` · ข้ามชั้นว่าง/ผู้ยื่น (`skip_policy` next/queue) · รวมชั้นติดกันที่เป็นคนเดียวกันพร้อมบันทึกทุกตำแหน่ง · บันทึก assignment ที่ลงนาม (รวม acting) · หมดชั้น = `unresolved` (คิวงานบุคคล) ไม่มี fallback ตามชื่อ role |
| R04-8 | Permission Matrix fixture + persona tests ทุกคอลัมน์ของ PLAN §8.3 (ใช้ `CollegeFixture::withAccessPersonas()`) · IDOR tests · CI grep (`scripts/check-authz-patterns.sh` + allowlist) |
| R04-9 | รายงานนี้ |
| ผูก field access | `PersonnelFieldAccess` → `EnginePersonnelFieldAccess` (ADR-R-017) · ลบ `DenyByDefaultPersonnelFieldAccess` |

แก้ตามผลตรวจ R-03:

| ประเด็น | ผล | เทสต์ |
|---|---|---|
| throttle `POST /api/personnel/{uuid}/reveal` | limiter `personal-data` 10 ครั้ง/นาที/ผู้ใช้ | `CoreApiAuthorizationTest::test_reveal_is_throttled` |
| throttle `POST /api/personnel/import` | limiter ใหม่ `imports` 5 ครั้ง/นาที/ผู้ใช้ | `CoreApiAuthorizationTest::test_import_is_throttled_and_uses_the_detected_file_type` |
| SheetReader กัน zip bomb | ตรวจขนาดรวมหลังแตกไฟล์จาก central directory ≤ 50 MB และจำนวนรายการ ≤ 1,000 ก่อนอ่าน · อ่านแต่ละรายการด้วย stream จำกัดขนาดจริง (ไม่เชื่อค่าที่ zip ประกาศอย่างเดียว) | `SheetReaderSafetyTest` (4) |
| `guessExtension()` แทน `getClientOriginalExtension()` | ชนิดไฟล์จากเนื้อไฟล์ · csv ต้องระบุ `sheet` | เทสต์ import ข้างต้น (csv ที่ตั้งชื่อเป็น .xlsx อ่านเป็น csv · ไบนารีชื่อ .xlsx = 422) |

## 2. ไฟล์ / migration / API ที่เปลี่ยน

| ประเภท | รายการ |
|---|---|
| Migration | `2026_10_19_100000_r04_add_effect_to_template_permissions` (additive: `effect` allow/deny, `scope` null ได้) |
| เพิ่ม — Authz | `app/Authz/{PermissionEngine, Grant, Decision, AuthzScope}` · `Authz/Contracts/{AuthzResource, ScopedByAuthz}` · `Authz/Manifest/{ModuleRegistry, ManifestValidator, ModuleSynchronizer, ManifestException}` · `Authz/Menu/MenuBuilder` · `Authz/Workflow/ApproverChainResolver` |
| เพิ่ม — อื่น | manifest 9 ไฟล์ · `MeController` · `ModulesSyncCommand` · `EnginePersonnelFieldAccess` · `Models/Concerns/AuthorizesByPersonnel` · `ModuleManifestSeeder` · `scripts/check-authz-patterns.sh` + `role-literal-allowlist.txt` |
| แก้ | `AppServiceProvider` (scoped engine/registry, macro, listener, limiter `imports`) · `bootstrap/app.php` · `RequirePermission` · `Controller::authorizeResource()` · controller องค์กร/บุคลากร (scopeQuery, 404, audit การแก้หน่วย/ตำแหน่ง) · `SheetReader` · model Personnel/OrgUnit/Assignment/PersonnelChangeRequest/User/PermissionChangeRequest (AuthzResource) · `TemplatePermission` (effect) · `.github/workflows/ci.yml` |
| ลบ | `DenyByDefaultPersonnelFieldAccess` · `Models/Workflow/WorkflowShadowComparison` (ตกค้างจาก R-02 — ตารางถูกตัดแล้ว ไม่มีผู้ใช้) |
| API ใหม่ | `GET /api/me/permissions` · `GET /api/me/menu` |
| API ที่เปิดใช้ (ผ่าน engine) | `/api/org/*`, `/api/personnel/*` 16 route (ของ R-03) |

## 3. ผลการตรวจ

| การตรวจ | ผล |
|---|---|
| `php artisan test` | ✅ **210 tests, 1,221 assertions, 0 failures** (R-03 จบที่ 166/1,133) |
| `vendor/bin/pint --test` | ✅ 637 files PASS |
| `bash scripts/check-authz-patterns.sh` (CI grep §2.3) | ✅ ผ่าน — ไม่พบ hasRole/Gate::before/spatie · ชื่อ role เป็น literal เหลือ 7 ไฟล์โมดูลเดิมใน allowlist (ห้ามเพิ่ม ต้องว่างก่อนจบ R-08) |
| `migrate:fresh --seed` บน MySQL (`eleave_r02_new`) | ✅ รวม sync manifest · `modules:sync` รอบสองไม่สร้างอะไรเพิ่ม (idempotent) |

เทสต์ใหม่ (`tests/Feature/Authz/` 6 ไฟล์ + `Core/SheetReaderSafetyTest`) = 58 tests:

| ไฟล์ | จำนวน | ครอบคลุม |
|---|---|---|
| `ManifestSyncTest` | 12 | manifest จริงผ่านการตรวจและ sync ผ่านคำสั่ง · idempotent · ไม่ทับการปรับ/คืนด้วย --reset-defaults · retire ไม่ลบและกลับมาใช้ได้ · คงสถานะเปิด/ปิดโมดูล · manifest ผิด 7 แบบถูกปฏิเสธ |
| `MenuSpecTest` | 2 | ทุกแถวของ PLAN §8.2 ก., ข., ช., ซ. (24 แถว: route, สิทธิ์, scope, in_menu) · ลำดับกลุ่ม/รายการ |
| `PermissionEngineTest` | 13 | **L2 ไม่รั่วไป subtree ของ L3 ของคนเดียวกัน** · แหล่งที่มาของการตัดสิน · acting เฉพาะช่วงคำสั่ง · override deny ชนะ (ทั้งหมด/เฉพาะหน่วย) · allow ไม่มีวันหมดอายุไม่มีผล · grant หมดอายุ/ถอนแล้วไม่มีผล · admin/auditor ไม่เห็นข้อมูลผู้อื่น · โมดูลปิด/บัญชีไม่ active/สิทธิ์ไม่รู้จัก · กฎกลาง 3 ข้อ · grant_ceiling + two_person · scopeQuery · explain · cache ล้างเมื่อสิ้นสุดตำแหน่ง |
| `PersonaMatrixTest` | 12 | เมนู 8 persona ผ่าน `GET /api/me/menu` · ชื่อเมนูตาม scope · badge · Permission Matrix 8 persona × 23 permission · `/api/me/permissions` |
| `CoreApiAuthorizationTest` | 8 | IDOR 404 · ไม่มีสิทธิ์ 403 · งานบุคคลแต่งตั้งตนเองไม่ได้ · reveal ต้องอยู่ใน scope + เหตุผล + log · Maker–Checker ผ่าน API · throttle 2 จุด |
| `ApproverChainResolverTest` | 7 | ไต่ตามสายของหน่วยตนเอง · ข้ามชั้นที่ไม่มี · ไม่อนุมัติตนเอง · รวมชั้นคนเดียวกัน (รองผอ. + รักษาการ ผอ.) · คิวงานบุคคล · skip_policy queue · functional_template/specific_assignment |
| `SheetReaderSafetyTest` | 4 | zip bomb · จำนวนรายการ · ไฟล์ปกติยังอ่านได้ · ค่าเริ่มต้น 50 MB |

เทสต์เดิมที่ปรับ: `Rebuild/DenyByDefaultTest` (กติกา route ใหม่: ผู้ไม่มีสิทธิ์ถูกปฏิเสธทุก route, route ที่ผ่านต้องประกาศสิทธิ์ที่มีใน manifest, route โมดูลเดิมถูกกันก่อน controller) · `BaselineSeederTest` (permission มาจาก manifest) · `SchemaBaselineTest` (migration `_r0x_`) · ลบ `Core/CoreApiDeniedUntilEngineTest` (แทนด้วย `CoreApiAuthorizationTest`)

## 4. ผล persona tests (PLAN §8.3) — ผ่านทุกช่อง

ผ่าน `GET /api/me/menu` ด้วยบัญชีจริงของ `CollegeFixture` (✔ = เห็น · — = ไม่เห็น)

| กลุ่มเมนู | บุคลากร L5 | หัวหน้า L4 | หัวหน้า L3 | รองฯ L2 | ผอ. L1 | งานบุคคล | ผู้ดูแลระบบ | ผู้ตรวจสอบ |
|---|---|---|---|---|---|---|---|---|
| ส่วนบุคคล | ✔ หน้าหลัก · ข้อมูลของฉัน (+ประวัติการเข้าถึง) · การแจ้งเตือน | ✔ (เหมือน L5) | ✔ (เหมือน L5) | ✔ (เหมือน L5) | ✔ + งานรออนุมัติ | ✔ + งานรออนุมัติ | ✔ + งานรออนุมัติ | ✔ (เหมือน L5) |
| ข้อมูลบุคลากร | โครงสร้างองค์กร | "บุคลากรสาขางานยานยนต์" (T) · โครงสร้าง | "บุคลากรงานบุคลากร" (T) · โครงสร้าง | "บุคลากรฝ่ายวิชาการ" (T) · โครงสร้าง · รายงาน | รายชื่อบุคลากร (A) · โครงสร้าง · รายงาน | ครบ 6 เมนู | — | — |
| บัญชีและสิทธิ์ | — | 5 ทบทวนสิทธิ์ | 5 | 5 | 4, 5 | 1 บัญชีผู้ใช้ (อ่าน) | ครบ 6 (ข้อ 4 ยืนยันคำขอของตนเองไม่ได้ด้วยกฎ maker_checker) | 1, 2, 5, 6 |
| ตั้งค่าระบบ | — | — | — | — | — | 2 สายอนุมัติ (เสนอ — two_person) | ครบ 5 | — |

ผู้ถือหลายตำแหน่ง (multi = ครู + หัวหน้าแผนกช่างยนต์ + รองผอ. ฝ่ายบริหารทรัพยากร): เมนู "รายชื่อบุคลากร (2 หน่วย)" · สิทธิ์รายงานและชั้นลับของ L2 ใช้กับฝ่ายบริหารทรัพยากรเท่านั้น ไม่ใช้กับแผนกช่างยนต์

Permission Matrix (scope ที่กว้างที่สุด) — 184 ช่องตรงทั้งหมด ตัวอย่าง:

| permission | L5 | L4 | L3 | L2 | L1 | HR | admin | auditor |
|---|---|---|---|---|---|---|---|---|
| personnel.profile.view | own | subtree | subtree | subtree | all | all | own | own |
| personnel.profile.view_confidential | own | own | own | subtree | all | all | own | own |
| personnel.profile.reveal | own | own | own | own | own | all | own | own |
| personnel.report.view | — | — | — | subtree | all | all | — | — |
| personnel.change_request.review / assignment.manage / import.manage | — | — | — | — | — | all | — | — |
| org.unit.view | all | all | all | all | all | all | — | — |
| access.review.confirm | — | subtree | subtree | subtree | subtree | — | — | — |
| access.change.approve | — | — | — | — | all | — | all | — |
| access.account.view | — | — | — | — | — | all | all | all |
| audit.log.view / access.review.manage | — | — | — | — | — | — | all | all |
| system.* / access.template.manage / access.grant.manage | — | — | — | — | — | — | all | — |

**หมายเหตุการตีความตาราง 8.3**
- "งานรออนุมัติของฉัน" แสดงเมื่อมีสิทธิ์ `*.*.approve` หรือ `*.*.review` — ใน R-04 หัวหน้า L2–L4 ยังไม่มี (ได้เมื่อโมดูลลามี `leave.approval.approve` ใน R-08) จึงยังไม่เห็นเมนูนี้
- L2/L1 เห็น "โครงสร้างองค์กร" ด้วย เพราะ §8.2 ข.3 กำหนด "ทุกคนดูได้" (ตาราง 8.3 ไม่ได้ระบุซ้ำ)
- ผู้ดูแลระบบและผู้ตรวจสอบไม่เห็นกลุ่มข้อมูลบุคลากรด้วยชุดสิทธิ์ที่ประกาศ `denies` (ADR-R-015) แต่ยังดูข้อมูลของตนเองได้
- แถว ระบบการลา · ลงเวลา · OT · สารบรรณ ของตาราง 8.3 ทดสอบใน R-08 เมื่อโมดูลเหล่านั้นมี manifest

## 5. เทสต์ใน `tests/Legacy` ที่ย้ายหรือลบในเฟสนี้ (Q7)

| ไฟล์ | การจัดการ | เทสต์ใหม่ที่ครอบคลุม |
|---|---|---|
| AccessGovernance2569Test, AccessGovernancePhase2Test | ลบ | `Authz/PersonaMatrixTest`, `Authz/ApproverChainResolverTest`, `Authz/PermissionEngineTest` |
| AuthorizationMatrixTest, OperationalPermissionMatrixTest | ลบ | `Authz/PersonaMatrixTest::test_permission_matrix` |
| AuthorizationCleanupAndVerificationTest, RbacCleanupTest, UserDirectPermissionTest | ลบ | `Authz/PermissionEngineTest` (override ต้องมีวันหมดอายุ, deny ชนะ), `Rebuild/ForbiddenPatternsTest` |
| PermissionEvaluatorTest, PermissionScopeCommandTest, OrgScopeResolverTest | ลบ | `Authz/PermissionEngineTest::test_scope_query_*`, `test_level_two_rights_do_not_leak_*` |
| PrivilegeEscalationGuardTest | ลบ | `Authz/PermissionEngineTest::test_grant_ceiling_and_two_person` |
| LegacyModernizationMigrationRollbackTest | ลบ | `Rebuild/SchemaBaselineTest` |
| Workflow/LeaveWorkflowShadowParityTest | ลบ | `Authz/ApproverChainResolverTest` |

ลบ 13 ไฟล์ · ไม่มีไฟล์ที่ย้ายกลับ · คงเหลือ **67 ไฟล์** (R-03 เหลือ 80) · กลุ่ม "ล้าสมัยโดยแผน" หมดแล้ว · บันทึกครบใน `backend/tests/Legacy/README.md`

## 6. Go/No-Go R-04

| เกณฑ์ (runbook) | ผล |
|---|---|
| persona tests ผ่านทุกช่อง | ✅ 8 persona × 4 กลุ่มเมนูของ core + matrix 184 ช่อง (แถวโมดูลธุรกิจ = R-08) |
| IDOR tests ผ่าน | ✅ นอก scope = 404 · ไม่มีสิทธิ์ = 403 |
| `/api/me/menu` ตรงผังเมนู | ✅ `MenuSpecTest` + `PersonaMatrixTest` |
| CI grep สะอาด | ✅ (allowlist 7 ไฟล์โมดูลเดิมที่ต้องหมดใน R-08) |

**ผล: Go**

## 7. ข้อจำกัดและสิ่งที่ต้องทำต่อ

1. **โมดูลเดิม (ลา ลงเวลา OT สารบรรณ แจ้งเตือน)** ยังตอบ 403 ทุก route เพราะยังไม่ประกาศสิทธิ์จาก manifest — R-08
2. ชื่อ role เป็น literal เหลือ 7 ไฟล์ของโมดูลเดิม (`scripts/role-literal-allowlist.txt`) — CI ห้ามเพิ่ม ต้องว่างก่อนจบ R-08
3. ทะเบียนโมดูลธุรกิจเดิม (`config/modules.php`) ยังใช้โหลด providers/route ของโมดูลเดิม (ADR-R-018)
4. ค่าเริ่มต้นของชุดสิทธิ์ (โดยเฉพาะ L1–L4 และงานบุคคล) เป็นข้อเสนอ — รอผู้ลงนามรับรอง Permission Matrix (Q5) · ผู้ดูแลปรับได้ใน R-07 โดยไม่แก้โค้ด
5. ยังไม่มี API มอบ/ถอน role grant และ override (R-05) — ในเฟสนี้สร้างผ่านโค้ด/fixture เท่านั้น · `canGrant()`/`requiresSecondPerson()` พร้อมให้ R-05 ใช้
6. `ApproverChainResolver` ยังไม่ถูกเรียกจาก workflow engine ของการลา (ต่อใน R-08) · หน้า Simulator ใช้ `explain()` ใน R-07
7. R-00 ยังไม่ครบ · Q8 ยังเปิด

## 8. วิธีตรวจซ้ำ
```bash
cd backend
php artisan test
vendor/bin/pint --test
bash scripts/check-authz-patterns.sh
php artisan modules:sync
```

**หยุดรอคำสั่ง**
