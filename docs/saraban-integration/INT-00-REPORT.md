# INT-00 — EXISTING SYSTEM ASSESSMENT

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-00 (ROADMAP บรรทัดที่ 1)
**วันที่:** 2026-09-01
**Branch:** `chore/remove-saraban-module` · HEAD `2d8f056`
**สถานะ:** ✅ ผ่าน — ไม่มี blocker ที่ขวาง INT-01

---

## DONE

สำรวจระบบเดิมครบทุกรายการที่ SoT §3 กำหนด และจำแนกการตัดสินใจตาม
`REUSE > EXTEND > MODIFY > CREATE > MIGRATE > DEPRECATE` แล้วทั้งหมด

| รายการตรวจ (SoT §3) | ผลการสำรวจ | ตัดสิน |
|---|---|---|
| Authentication / SSO / Sessions / MFA | Sanctum 4.3.2 · throttle 8 กลุ่ม · **ไม่มี SSO/OIDC · ไม่มี MFA** | REUSE + EXTEND |
| Users | `users` + `uuid` + `org_unit_id` + `is_active` | REUSE |
| Personnel | `personnel`, `personnel_types`, `user_profiles`, `PersonnelSyncService` | REUSE |
| Organization | `org_units` ต้นไม้ + effective dating + `department_org_unit_map` | REUSE |
| Position / Assignment / Acting / Delegation | `personnel_assignments` + `org_unit_managers` + `workflow_delegations` + `DelegationResolver` · **`position` เป็น string** | REUSE + EXTEND |
| RBAC / ABAC / Permission | Spatie 8.3 + `permission_denies` + `permission_scopes` (6 ระดับ) + `PermissionEvaluator` + `OrgScopeResolver` | REUSE |
| Module Registry / Menu Visibility | `ModuleRegistry` + `config/module_registry.php` + `EnsureModuleEnabled` + `MenuService` (Gate-filtered) | REUSE + EXTEND |
| Audit | `audit_logs` กลาง + `AuditLogger` + correlation id | REUSE + EXTEND |
| File Storage / Upload Security / Malware Scan | `private_uploads` + `StoredFile` + `SecureFileAccessService` + allowlist 2 ชั้น · **ไม่มี malware scan / quarantine** | REUSE + EXTEND + MODIFY |
| Notification / Email / Queue / Redis / Scheduler | Notification Center ครบ + adapter In-App/Email/**LINE OA**/**Telegram** + retry · queue `database` · **ไม่มี Redis** | REUSE + EXTEND |
| API / OpenAPI / Rate Limit / Idempotency | `/api` + sanctum + `ability:` + throttle + request id + JSON errors · **ไม่มี OpenAPI · ไม่มี idempotency** | REUSE + CREATE |
| Webhook / Outbox | **ไม่มีทั้งคู่** (`notification_events` ใกล้เคียง outbox) | EXTEND + CREATE |
| Feature Flags | มีแต่ **module toggle** ไม่ใช่ feature flag | CREATE |
| Shared UI / Design System | Vue 3.5 + Tailwind v4 + `@theme` เดียว + UI kit 12 ตัว + `v-can` + `v-secure-src` | REUSE (ดู UI matrix) |
| Logging / Monitoring | `/up` + `/api/ping` + `config/logging.php` · **ไม่มี metrics endpoint** | REUSE + CREATE |
| Backup / DR | `spatie/laravel-backup` + `config/backup.php` · **ยังไม่พบหลักฐาน restore test** | REUSE + EXTEND |
| CI/CD / Deployment | `.github/workflows/ci.yml` 2 job พร้อม gate ครบ · docker-compose 6 service | REUSE + EXTEND |
| Dependencies ของ modules เดิม | 6 โมดูล: system(0) · leave(10) · attendance(20) · overtime(25, ขึ้นกับ attendance) · notification(40) · reporting(50) | REUSE |

**สรุปการตัดสิน:** REUSE 17 · EXTEND 10 · MODIFY 1 · CREATE 12 · MIGRATE 1 · DEPRECATE 0

**ข้อสรุปเชิงสถาปัตยกรรม:** ระบบเดิมมี Core ครบเกือบทั้งหมดที่ SoT §32 ต้องการ
โดยเฉพาะ **Workflow Engine ที่ versioned พร้อม pinned instance อยู่แล้ว** (ตรงตาม SoT §12
โดยไม่ต้องสร้างใหม่), ABAC ที่มี explicit deny + scope, Notification Center ที่มี LINE OA
และ Telegram ครบ, และ PDF Engine ที่เรนเดอร์ฝั่ง backend ด้วย FPDI ตามข้อห้ามใน SoT §14
→ **SARABAN ต้องเป็นโมดูลที่เสียบเข้ากับของเหล่านี้ ไม่ใช่สร้างชั้นของตัวเอง**

---

## CHANGED

**ไม่มีการเปลี่ยนแปลงโค้ด / schema / config ใด ๆ**
INT-00 เป็น checkpoint สำรวจอย่างเดียว ตามข้อบังคับ "ห้ามสร้าง duplicate Core ก่อน INT-00 เสร็จ"

---

## FILES

เพิ่มใหม่ 3 ไฟล์ (เอกสารเท่านั้น):

```
docs/saraban-integration/INT-00-REPORT.md
docs/saraban-integration/CORE-COMPATIBILITY-MATRIX.md
docs/saraban-integration/UI-COMPATIBILITY-MATRIX.md
```

ทั้งสอง matrix มีตารางตามแบบฟอร์มของ `*-TEMPLATE.md` อยู่ในหัวข้อ `0.1`
และมีหัวข้อรายละเอียดพร้อมอ้างอิงไฟล์จริงต่อจากนั้น

**ไม่ได้แตะ:** ไฟล์ที่มีการแก้ค้างอยู่ใน working tree ก่อนเริ่มงานนี้
(`ProfileController.php`, `AppServiceProvider.php`, `routes/api.php`,
`UserProfileView.vue`, `ChangePasswordRequest.php`) — เป็นงานของผู้อื่น ไม่เกี่ยวกับ SARABAN

---

## MIGRATIONS

ไม่มี

**ข้อสังเกตเกี่ยวกับ migration เดิมที่ต้องรู้ก่อน INT-03:**
`backend/database/migrations/2026_08_27_000000_drop_saraban_module.php` เป็น migration
**ทางเดียว** ที่ลบตาราง `saraban_*` ทั้ง 14 ตาราง พร้อมร่องรอยในตารางกลาง
(permission, menu, setting, document template, workflow definition `saraban.document`,
notification type `saraban.task`) และ `down()` **ไม่คืนข้อมูล** → ดู RISK-01

**ข้อบังคับของ migration ที่จะเขียนต่อจากนี้:** CI รัน `php artisan migrate --force`
จาก **MySQL 8 ที่ว่างเปล่า** ทุกครั้ง → migration ของ SARABAN ต้องรันจากศูนย์ได้เสมอ
และต้อง backward compatible (additive) ตามข้อบังคับ

---

## TESTS

ยังไม่มีเทสต์ใหม่ (ไม่มีโค้ดใหม่)

**Baseline ที่ต้องคงเขียวตลอดโครงการ:** backend `tests/Feature/` 63 ไฟล์ + `tests/Unit/` 3 ไฟล์ ·
frontend `tests/` 2 ไฟล์ (vitest)

**เทสต์ที่จะถูกกระทบเมื่อเริ่ม INT-02/INT-03 (ระบุไว้ล่วงหน้าเพื่อวางแผน regression):**

| เทสต์ | จะถูกกระทบเพราะ |
|---|---|
| `ModuleRegistryTest`, `ModuleToggleTest` | เพิ่ม manifest `saraban` |
| `MenuIntegrityTest` | เพิ่มเมนู SARABAN |
| `PermissionCatalogTest`, `AuthorizationMatrixTest`, `OperationalPermissionMatrixTest` | เพิ่ม namespace `saraban.*` |
| `ApiSurfaceContractTest` | เพิ่ม endpoint (ต้องมีหน้าจอเรียกจริง) |
| `DeadCodeGuardTest` | โค้ดที่ยังไม่มีผู้เรียก |
| `LeaveEvidenceAccessTest`, `AttendanceImageSecurityTest` | ถ้าแตะ `SecureFileAccessService` (ดู MODIFY) |
| `CentralAuditLogTest` | ถ้าเพิ่ม hash chain ให้ audit |
| `NotificationCenterTest` | เพิ่ม `notification_types` ของ SARABAN |

---

## SECURITY

**สิ่งที่ระบบเดิมมีแล้วและ SARABAN ต้องใช้ต่อ (ห้ามทำเอง):**
default deny + explicit deny ที่ชนะทุก allow · scope-based ABAC 6 ระดับ ·
private storage + secure download ที่ตรวจสิทธิ์รายไฟล์ · allowlist อัปโหลดสองชั้น
(magic bytes + นามสกุล) พร้อมรายการ `never_allowed` · security headers · throttle รายกลุ่ม ·
`ProductionEnvironmentGuard` · การบันทึกการเข้าถึงข้อมูลส่วนบุคคล (PDPA ม.30) ·
CI ตรวจ advisory ของ dependency ทั้งสองฝั่งให้เป็นศูนย์ · CI ตรวจว่าบัญชีทดสอบไม่หลุดไปกับ bundle

**ช่องว่างที่ SoT บังคับแต่ระบบเดิมยังไม่มี — ทั้งหมดเป็นงานของ Core ไม่ใช่ของ SARABAN:**

| รหัส | ช่องว่าง | SoT | ต้องแก้ที่ | Checkpoint |
|---|---|---|---|---|
| SEC-GAP-01 | ไม่มี MFA / Step-up Re-auth | §24 | Core Identity | INT-11 |
| SEC-GAP-02 | ไม่มี malware scan + quarantine | §26 | Core File Service | INT-04 |
| SEC-GAP-03 | `audit_logs` ไม่มีกลไกกันแก้เชิงเทคนิค | §25 | Core Audit (additive) | INT-04 |
| SEC-GAP-04 | ไม่มี idempotency key บน API | §17 | Core API middleware | INT-15 |

> ถ้าปล่อยให้ SARABAN แก้ 4 ข้อนี้เอง จะกลายเป็นระบบที่สองทันที ซึ่งขัด SoT §4 โดยตรง

---

## REGRESSION

ยังไม่ต้องรัน — ไม่มีการเปลี่ยนแปลงโค้ด

**ขอบเขต regression ที่ผูกไว้สำหรับทุก checkpoint ตั้งแต่ INT-01 เป็นต้นไป:**
โมดูล `leave`, `attendance`, `overtime`, `notification`, `reporting`
และระบบกลาง (menu, permission, module toggle, secure file, audit)
→ **SARABAN ผ่านอย่างเดียวไม่นับว่าเสร็จ**

---

## UI-QA

**ยังไม่มีหน้าจอให้เทียบ** — แต่ INT-00 ได้กำหนด baseline ของการเทียบไว้แล้วใน
`UI-COMPATIBILITY-MATRIX.md`

**ผลการตรวจ Design System เดิม:** มี Visual Source of Truth ที่ใช้งานได้จริงและครบพอ —
`@theme` เดียวที่ `src/style.css` (75 บรรทัด) · App shell + sidebar + mobile drawer ใน `App.vue` ·
UI kit 12 component ที่ `components/ui/` · toast กลาง · `v-can` · `v-secure-src` ·
layout convention ที่สม่ำเสมอทุกหน้า admin · a11y (skip link, focus ring, `aria-label` ไทย)

**สิ่งที่ต้องสร้างใหม่ 9 รายการ** (SoT §2 อนุญาตเฉพาะสิ่งที่ Core ไม่มี) —
ของกลางไป `components/ui/` (AppDrawer, AppDataTable, AppTabs, AppFileUpload, HandwritingCanvas)
ของสารบรรณไป `components/saraban/` (PdfWorkspace, ReceiveStampOverlay, SignaturePlacement,
WorkflowRouteVisualizer)

**dependency ใหม่ที่จำเป็น: 1 ตัวเท่านั้น** — ตัวแสดง PDF ในเบราว์เซอร์
(`signature_pad`, `sweetalert2`, `chart.js`, `vue-quill`, `date-fns`, `@vuepic/vue-datepicker`
มีอยู่แล้วทั้งหมด)

---

## EVIDENCE

| หลักฐาน | ที่มา |
|---|---|
| Single Source of Truth | `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` (338 บรรทัด, repo root) |
| Framework & version | `backend/composer.json`, `frontend/package.json` |
| Module registry + dependency graph | `backend/config/module_registry.php`, `backend/app/Services/Modules/*` |
| ABAC | `backend/app/Services/{PermissionEvaluator,OrgScopeResolver}.php` · migration `2026_08_13_130000` |
| Personnel / Organization / Assignment | migrations `2026_08_13_100000`, `2026_08_16_010000`, `2026_08_14_110000` |
| Workflow versioning + pinned instance | migration `2026_08_14_200000` (`workflow_versions.status`, `workflow_instances.workflow_version_id`) |
| Audit | migration `2026_08_13_110000` · `backend/app/Services/AuditLogger.php` |
| Notification + channels | migration `2026_08_15_220000` · `backend/app/Services/NotificationCenter/Adapters/*` |
| File safety | `backend/config/uploads.php` · `backend/app/Support/{StoredFile,UploadRules}.php` |
| PDF engine + พิกัด | `backend/app/Services/DocumentEngine/{PdfOverlayRenderer,CoordinateConverter}.php` |
| Numbering pattern (มี lock) | `backend/app/Services/LeaveDocumentNumberService.php` |
| Design system | `frontend/src/style.css`, `frontend/src/App.vue`, `frontend/src/components/ui/*` |
| ด่าน CI | `.github/workflows/ci.yml` |
| API surface guard | `backend/tests/Feature/ApiSurfaceContractTest.php` |
| การถอดโมดูลสารบรรณเดิม | migration `2026_08_27_000000_drop_saraban_module.php` · commit `cd34f41` |
| โค้ดสารบรรณรุ่นเก่า (Historical Reference) | git history ก่อน `cd34f41` · worktree `.claude/worktrees/jolly-euclid-08076a/` |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | การรับมือ | ถึงกำหนดที่ |
|---|---|---|---|---|
| RISK-01 | **ข้อมูลหนังสือของสารบรรณรุ่นเก่าอาจสูญไปแล้ว** — migration ถอดโมดูลเป็นทางเดียวและกู้เองไม่ได้ | **สูง** | ก่อนออกแบบ schema ต้องยืนยันว่า production เคยมีข้อมูลสารบรรณจริงหรือไม่ และมี backup ก่อน `cd34f41` หรือไม่ · ถ้ามี ต้อง MIGRATE ไม่ใช่ CREATE ทับ | **INT-03** |
| RISK-02 | Step-up re-auth ต้องแก้ Core Identity ซึ่งกระทบทุกโมดูล | สูง | ทำเป็น checkpoint แยก + feature flag ให้ย้อนได้ทันที (แบบเดียวกับ `USE_NEW_PERMISSION_ENGINE`) + regression เต็ม | INT-11 |
| RISK-03 | `personnel_assignments.position` เป็น string ทำให้ Authority Matrix และการ resolve ตามตำแหน่งไม่แม่น | กลาง | เพิ่ม `positions` + `position_id` แบบ nullable/additive + backfill + เทสต์ว่าไม่มีใครเสียข้อมูล | INT-01/INT-03 |
| RISK-04 | `SecureFileAccessService::canView()` เป็น if-chain ต่อโมดูล — ต่อ SARABAN แบบเดิมจะทำให้จุดตัดสินสิทธิ์ไฟล์เปราะ | กลาง | เปลี่ยนเป็น resolver registry พร้อม regression ของ leave/attendance | INT-04 |
| RISK-05 | ไม่มี Redis — metric ของ queue latency และ Command Center จะวัดได้จำกัดบน queue แบบ database | กลาง | **ห้ามเพิ่ม infra เอง** · เสนอเป็น ADR ให้เจ้าของระบบตัดสิน | INT-20 |
| RISK-06 | `ApiSurfaceContractTest` + `DeadCodeGuardTest` ทำให้สร้าง backend ล่วงหน้าโดยไม่มี UI ไม่ได้ | กลาง | ทุก checkpoint ต้องส่ง backend + frontend คู่กัน | ทุก INT |
| RISK-07 | Production+ (SoT §18–§23) มี 9 ระบบย่อยที่ต้อง CREATE ทั้งหมด — ปริมาณงานสูงกว่าที่ roadmap สื่อ | กลาง | เดินตาม INT-16 ถึง INT-19 อย่างเคร่งครัด ห้ามทำล่วงหน้าแบบครึ่ง ๆ กลาง ๆ | INT-16+ |
| RISK-08 | เพิ่ม dependency แสดง PDF อาจติด `npm audit --audit-level=high` ของ CI | ต่ำ | ตรวจ advisory ก่อนเลือก · มี precedent การ pin ผ่าน `overrides` ใน `package.json` (SEC-020) | INT-10 |

---

## BLOCKERS

**ไม่มี blocker ที่ขวาง INT-01**

| รหัส | สถานะ |
|---|---|
| ~~ไฟล์ Source of Truth~~ | ✅ **คลี่คลาย** — พบ `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` ที่ repo root |
| ~~ไม่มี Approved Visual Reference~~ | ✅ **คลี่คลาย** — SoT §2 กำหนดให้ **UI ของระบบเดิมเป็น Visual Source of Truth** จึงไม่ต้องรอไฟล์ภาพใด ๆ |
| RISK-01 (ข้อมูลสารบรรณเดิม) | ⏳ **ยังเปิดอยู่ แต่ยังไม่ถึงกำหนด** — เป็นคำถามที่ต้องตอบก่อน **INT-03** ไม่ใช่ INT-01 จึงเดินหน้าต่อได้ |

---

## NEXT

**→ INT-01 — Core Contract Mapping**

ขอบเขต: ตรึง "สัญญา" ระหว่าง SARABAN กับ Core ให้เป็นสิ่งที่บังคับได้ด้วยเทสต์
ไม่ใช่แค่ข้อตกลงในเอกสาร เพื่อให้ข้อห้าม "ห้าม duplicate Core" มีผลจริงตลอดอีก 21 checkpoint

1. `docs/saraban-integration/INT-01-CORE-CONTRACT.md` — ระบุรายความสามารถว่า SARABAN
   ต้องเรียก service/ตาราง/ID ตัวใด พร้อม API ที่แน่นอน (ไม่ใช่คำแนะนำกว้าง ๆ)
2. เทสต์บังคับสัญญา (guard test) ที่ล้มทันทีเมื่อมีใครสร้าง Core ซ้ำ — ตาราง personnel/org/
   permission/audit/login ของ SARABAN, การเรียก `now()` ในโดเมน, การอ้าง `department_id`
3. เทสต์ characterization ของ resolver เดิม (`ApproverResolverService` + `DelegationResolver`)
   เพื่อพิสูจน์ว่า Core ตอบคำถาม "ใครมีอำนาจอะไร ณ เวลาใด" ได้จริงก่อนไปสร้าง Authority Matrix (SoT §13)
4. บันทึกผล regression baseline เต็มชุดเป็นหลักฐานสถานะเขียวก่อนแตะโค้ด

**ยังไม่แตะ** schema ของ SARABAN (INT-03), manifest/permission (INT-02) และ UI ใด ๆ
