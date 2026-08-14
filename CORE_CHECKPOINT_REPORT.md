# CORE CHECKPOINT REPORT

**วันที่ตรวจ:** 14 สิงหาคม 2569 (2026-08-14)
**ขอบเขต:** เงื่อนไขผ่านของ CORE CHECKPOINT ตาม `docs/LEGACY-MIGRATION-PLAN.md` และ `docs/checkpoints/CORE-CHECKPOINT.md`
**เฟสที่ปิดมาแล้ว:** LEGACY-00 → LEGACY-05
**ผลรวม:** ✅ **PASS** — เงื่อนไขครบทุกข้อ (รอลงนามอนุมัติ)

> การตรวจรอบแรกวันเดียวกันได้ผล **CONDITIONAL** เพราะ P1 ค้าง 3 รายการ
> เจ้าของระบบสั่งให้แก้ทั้งหมดก่อนผ่าน — แก้เสร็จแล้วและตรวจซ้ำ ดูข้อ 10

---

## สรุปผู้บริหาร

| เงื่อนไข | ผล |
|---|:---:|
| P0 gaps = 0 | ✅ **ผ่าน** — 16/16 ปิดครบ |
| P1 gaps = 0 | ✅ **ผ่าน** — 18/18 ปิดครบ |
| Authorization test matrix + IDOR test | ✅ **ผ่าน** |
| Backup + restore drill | ✅ **ผ่าน** — 73 ตาราง ตรงกันทั้งหมด |
| Data reconciliation | ✅ **ผ่าน** — อธิบายได้ครบทุกส่วนต่าง |
| `docs/TRACEABILITY-MATRIX.md` อัปเดตครบ | ✅ **ผ่าน** |
| `CORE_CHECKPOINT_REPORT.md` + อนุมัติ | ⏳ เอกสารนี้ · **รออนุมัติ** |

**ยังไม่เริ่ม LEGACY-06 และยังไม่ทำ Workflow Cutover** — รอคำสั่ง

---

## 1. P0 Gaps — ✅ 16/16 ปิดครบ

| # | Gap | เดิม | หลักฐานการปิด |
|---|---|---|---|
| 1 | `ATT-003` ภาพลงเวลาเปิดสาธารณะ | ชื่อไฟล์เดาได้ + disk public | `AttendanceImageSecurityTest` — *check in uses detected mime and a random file name* |
| 2 | `ATT-004` upload ไม่ตรวจ MIME/ขนาด | นามสกุลจาก client | `AttendanceImageSecurityTest` — *rejects a non image payload disguised as png*, *rejects an image larger than five megabytes* |
| 3 | `BACKUP-001` ไม่มี backup อัตโนมัติ | package ไม่ได้ติดตั้ง | `spatie/laravel-backup ^10.3` ใน `composer.json` · `Schedule::command('backup:run')->dailyAt('02:00')` · container `eleave_scheduler` ทำงาน · **ไฟล์สำรองอัตโนมัติจริง** 2026-08-13 02:00, 2026-08-14 02:01 |
| 4 | `BACKUP-002` ไม่มี restore drill | ไม่เคยซ้อม | ซ้อมใหม่วันนี้ — ดูข้อ 4 |
| 5 | `CORE-AUTH-001` token ไม่หมดอายุ/ไม่มี lockout | — | `AuthenticationHardeningTest` |
| 6 | `CORE-AUTH-002` token ผ่าน query string | 7 endpoint | `AttendanceExportAuthorizationTest` · route ย้ายเข้า `auth:sanctum` แล้ว |
| 7 | `CORE-AUTHZ-001` endpoint ไม่มี authorization | หลายเส้น | `RouteAuthorizationAuditTest::test_every_non_public_api_route_requires_authentication` — ตรวจทุก route อัตโนมัติ |
| 8 | `CORE-AUTHZ-005` ไม่มี Policy เลย | `app/Policies/` ว่าง | `LeavePolicy`, `Saraban/DocumentPolicy` + `LeaveAuthorizationTest`, `DocumentAuthorizationTest` |
| 9 | `LEAVE-005` IDOR ใบลา | export ไม่ตรวจสิทธิ์ | `LeaveAuthorizationTest`, `LeaveExportTest` |
| 10 | `REPORT-002` export นอก auth | 7 endpoint | `AttendanceExportAuthorizationTest` |
| 11 | `SARABAN-002` เอกสารลับเปิดให้ทุกคน | middleware dead code | `ClassifiedDocumentTest` — *uncleared user cannot open a classified document*, *classified documents are hidden from the list* |
| 12 | `SARABAN-003` e-signature ข้ามการยืนยันตัวตน | `mode` จาก client | `SignatureTest` |
| 13 | `SARABAN-004` ลบ/แก้เอกสารไม่ตรวจสิทธิ์ | — | `DocumentAuthorizationTest` |
| 14 | `SEC-STD-001` ไฟล์บุคคลอยู่ public | ลายเซ็น/เซลฟี่ | `SecureFileAuthorizationTest` |
| 15 | `SEC-STD-003` secret รั่วจากเว็บ | `info.php`, `test_db.php`, credential ใน compose | ไฟล์ทั้งสองถูกลบ (ยืนยันแล้ว) · `docker-compose.yml` ใช้ `${VAR:?}` จาก `.env` ที่ gitignore |
| 16 | `TEST-001` ไม่มี IDOR/authz test | 0 test | 49 test class ผ่านทั้งหมด · มี authz/IDOR test 12 class |

---

## 2. P1 Gaps — ✅ 18/18 ปิดครบ

### ปิดแล้ว (15 รายการเดิม)

| Gap | หลักฐาน |
|---|---|
| `CORE-AUDIT-001` audit ไม่ครบ | `audit_logs` กลาง + `CentralAuditLogTest` |
| `CORE-AUTHZ-002` permission 3 generation | canonical set + `permission_aliases` + `AuthorizationMatrixTest` |
| `CORE-AUTHZ-003` hard-code role 31 จุด | เหลือ 3 จุด (Super Admin bootstrap) · `AuthorizationMatrixTest::test_role_name_checks_survive_only_at_the_declared_bootstrap_points` บังคับอัตโนมัติ |
| `CORE-AUTHZ-004` ไม่มี explicit deny | `PermissionEvaluator` + `permission_denies` + `PermissionEvaluatorTest` |
| `CORE-DB-003` void ใบลาพังจาก enum | `leaves.status` → varchar + CHECK |
| `CORE-ORG-001` ไม่มี org hierarchy | `org_units` + `CoreDataFoundationTest` |
| `CORE-ORG-002` ไม่มี org scope | `OrgScopeResolver` + `permission_scopes` + `OrgScopeResolverTest` |
| `CORE-PER-002` ไม่มีประวัติการดำรงตำแหน่ง | `personnel_assignments` effective-dated |
| `CORE-WF-001` workflow ไม่ versioned | workflow engine 9 ตาราง + version pinning + `WorkflowEngineTest` |
| `DEPLOY-002` env separation | `ProductionEnvironmentGuard` บังคับตอน boot + `ProductionEnvironmentGuardTest` |
| `DEPLOY-003` ไม่มี worker/scheduler | container `eleave_worker` + `eleave_scheduler` ทำงาน · `jobs`=0 `failed_jobs`=0 |
| `LEAVE-003` template ไม่ versioned | `document_template_versions` + `leaves.document_template_version_id` (pin อัตโนมัติใน `Leave::booted()`) |
| `LEAVE-006` role check ไม่สอดคล้อง | รวมเป็น Spatie อย่างเดียว |
| `NOTIFY-004` ไม่มี queue worker | worker ทำงาน · คิวค้าง 16 รายการเดิมถูกประมวลผลหมดแล้ว (`jobs`=0) |
| `TEST-003` migration test บน MySQL 8 | CI job *Migrate from empty MySQL 8 database* + รันจริงวันนี้ (ข้อ 6) |

### แก้เพิ่มในรอบนี้ (3) — ✅

| Gap | สิ่งที่ทำ | หลักฐาน |
|---|---|---|
| **`DEPLOY-001`** Production-grade web server | เพิ่ม service `nginx` (nginx:1.27-alpine) เป็นทางเข้าเดียว · `backend` เปลี่ยนจาก `php artisan serve` เป็น **`php-fpm`** และ **ไม่เปิดพอร์ตออกนอกอีกต่อไป** (`expose: 9000`) | `curl -i http://localhost:8001/api/ping` → `200` + `Server: nginx/1.27.5` · เรียก `localhost:8000` ตรง ๆ ไม่ติดต่อได้ |
| **`DEPLOY-004`** Docker stack สอดคล้องกัน | ลบ `/Dockerfile` และ `/docker-entrypoint.sh` ที่ซ้ำซ้อน · เหลือ `backend/Dockerfile` ชุดเดียว (php-fpm + mysql-client) · `docker/nginx/default.conf` **ถูกใช้งานจริงแล้ว** · vite dev proxy ชี้ผ่าน nginx เพื่อให้ dev เจอกฎเดียวกับ prod | `/storage/logos/anything.png` → **404** ทั้ง dev และ prod (เดิมกฎนี้เป็น dead code) |
| **`CORE-WF-002`** Saraban workflow | ✅ transition ทุกเส้นมี authorization อยู่แล้ว · ✅ **เพิ่ม version pinning** — เปิดสายงานสารบรรณเป็น `active` และเอกสารทุกฉบับ pin `workflow_version_id` ของตัวเองผ่าน `SarabanWorkflowProvisioner::pin()` / `syncState()` | `SarabanWorkflowPinningTest` (9 tests) · definition active · activeVersion = 1 |

> **`CORE-WF-002` ปิดได้เพราะแก้เรื่องสิทธิ์ก่อน** — เดิมสายงานเปิดใช้ไม่ได้เพราะ
> `saraban.document.approve` / `.sign` เป็นของ Super Admin เท่านั้น จึงไม่มีผู้อนุมัติในสาย
> migration `2026_08_14_220000` ให้สิทธิ์ชุดนี้แก่ ผอ./รอง ผอ. ตามที่เจ้าของระบบอนุมัติ

---

## 3. Authorization Matrix + IDOR — ✅ ผ่าน

`php artisan test` — **332 passed / 871 assertions · 0 failed** (50 test class)

| ด้าน | test class |
|---|---|
| Route authorization ทุกเส้น | `RouteAuthorizationAuditTest` |
| Permission matrix | `AuthorizationMatrixTest`, `OperationalPermissionMatrixTest` |
| Deny > allow > default deny | `PermissionEvaluatorTest` |
| Organization scope (ABAC) | `OrgScopeResolverTest`, `DashboardReportScopeTest`, `LeaveVisibilityTest` |
| IDOR — ใบลา | `LeaveAuthorizationTest`, `LeaveCancellationAuthorizationTest` |
| IDOR — เอกสาร/เอกสารลับ | `DocumentAuthorizationTest`, `ClassifiedDocumentTest` |
| IDOR — ไฟล์ส่วนบุคคล | `SecureFileAuthorizationTest`, `PersonalDataProtectionTest` |
| IDOR — workflow | `WorkflowAuthorizationTest` |
| ยกระดับสิทธิ์ | `PrivilegeEscalationGuardTest` |
| Attendance export | `AttendanceExportAuthorizationTest` |

---

## 4. Backup + Restore Drill — ✅ ผ่าน

**ซ้อมจริงวันนี้** (ไม่ใช่การอ้างผลเก่าจาก LEGACY-00 ซึ่งตอนนั้น schema มี 55 ตาราง)

```bash
docker exec eleave_backend php artisan backup:run --only-db
# → Created zip · Verifying backup integrity... · Backup verified: 1 files in archive.
# → storage/backups/Laravel/2026-08-14-17-49-16.zip (37.75 KB)

# restore เข้าฐานข้อมูลแยก แล้วเทียบจำนวนแถวทุกตาราง
# → tables compared: 73   mismatches: 0
```

**ผล: 73 ตาราง · ไม่ตรงกัน 0 ตาราง**
หลักฐานเต็ม: `docs/checkpoints/legacy-00/restore-verification-core-checkpoint.txt`

**หลักฐานว่า backup อัตโนมัติทำงานจริง** — มีไฟล์สำรองที่ scheduler สร้างเอง:
`2026-08-13-02-00-10.zip`, `2026-08-14-02-01-45.zip` (ตรงกับตารางเวลา `dailyAt('02:00')`)

---

## 5. Data Reconciliation — ✅ ผ่าน

เทียบกับ baseline `docs/checkpoints/legacy-00/rowcount-baseline-2026-08-12T22.txt` (55 ตาราง · 302 แถว)

### จำนวนตาราง

| | baseline | ปัจจุบัน | ส่วนต่าง |
|---|---:|---:|---:|
| ตาราง | 55 | **73** | +18 |

ตารางใหม่ทั้ง 18 อธิบายได้ครบ:

| เฟส | ตาราง |
|---|---|
| LEGACY-03 (5) | `org_units`, `department_org_unit_map`, `personnel_assignments`, `audit_logs`, `document_template_versions` |
| LEGACY-04 (4) | `permission_aliases`, `permission_denies`, `permission_scopes`, `org_unit_managers` |
| LEGACY-05 (9) | `workflow_definitions`, `workflow_versions`, `workflow_steps`, `workflow_transitions`, `workflow_instances`, `workflow_tasks`, `workflow_actions`, `workflow_delegations`, `workflow_shadow_comparisons` |

### จำนวนแถวของตารางเดิม

| ตาราง | baseline | ปัจจุบัน | คำอธิบาย |
|---|---:|---:|---|
| `permissions` | 15 | **39** | +24 — canonical permission (LEGACY-04) + ชุดที่ระบบใช้จริง 7 ตัว + saraban 11 ตัว |
| `role_has_permissions` | 27 | **83** | +56 — การแจกสิทธิ์ตาม Decision 1/2 ที่อนุมัติแล้ว |
| `migrations` | 94 | **103** | +9 — migration ของ LEGACY-03/04/05 |
| `personal_access_tokens` | 1 | 2 | +1 — การล็อกอินระหว่างตรวจสอบ |
| `personal_data_access_logs` | 8 | 9 | +1 — การเข้าถึงข้อมูลระหว่างตรวจสอบ (ถูกบันทึกตามที่ควร) |
| อื่น ๆ ทั้งหมด | | **เท่าเดิมทุกตาราง** | `leaves` 3 · `leave_approvals` 6 · `approval_steps` 3 · `users` 7 · `roles` 6 · `leave_policies` 49 ฯลฯ |

**ไม่มีตารางใดมีจำนวนแถวลดลง** — ไม่มีข้อมูลสูญหาย

---

## 6. Migrations / Seeds / Tests / Lint / Build — ✅ ผ่าน

| คำสั่ง | ผล |
|---|---|
| `php artisan test` | ✅ **332 passed / 871 assertions · 0 failed** |
| `vendor/bin/pint --test` | ✅ **PASS 362 files** |
| `npm run build` | ✅ built in 27.70s |
| `composer audit` | ✅ **No security vulnerability advisories found** |
| `php artisan migrate` (ฐานว่าง MySQL 8) | ✅ **103/103 migration · exit 0** |

> **migration บนฐานว่าง:** รันบนฐานข้อมูล `eleave_migrate_test` ที่สร้างใหม่จากศูนย์
> ครบทั้ง 103 migration บน MySQL 8 — ปิด `TEST-003` ด้วยการรันจริง ไม่ใช่แค่มี CI job
>
> (การรันครั้งก่อนหน้าที่ล้มเหลวเกิดจาก**ผู้ตรวจเอง**ไปรัน migrate ต่อจากรอบที่ถูกขัดจังหวะกลางคัน
> ทำให้ migration เดิมรันซ้ำบนคอลัมน์ที่ตัวเองเพิ่งเพิ่ม — ไม่ใช่ข้อบกพร่องของโค้ด)

---

## 7. รายการตรวจของ `CORE-CHECKPOINT.md`

| ข้อ | ผล | หลักฐาน |
|---|:---:|---|
| Organization hierarchy/effective history correct | ✅ | `org_units` + `CoreDataFoundationTest` |
| Personnel types configurable; assignment history preserved | ✅ | `personnel_types` + `personnel_assignments` effective-dated |
| Authentication/session controls pass | ✅ | `AuthenticationHardeningTest` |
| RBAC+ABAC deny-by-default | ✅ | `PermissionEvaluatorTest` |
| Cross-user/cross-unit IDOR tests pass | ✅ | ดูข้อ 3 |
| Workflow versioning and actor resolution pass | ✅ | Leave (`WorkflowEngineTest`) · Saraban (`SarabanWorkflowPinningTest`) — ทั้งสองสายมี version pinning แล้ว |
| Delegation/acting approval auditable | ✅ | `WorkflowEngineTest` — actor/effective actor/delegated_by ครบ |
| Dynamic module/menu ไม่กลายเป็น security boundary | ✅ | `MenuService` ใช้ permission · backend บังคับทุก route (`RouteAuthorizationAuditTest`) |
| Audit contains required mutation records | ✅ | `CentralAuditLogTest` · `workflow_actions` append-only |
| Migrations, seeds, tests, lint, typecheck, build pass | ✅ | ดูข้อ 6 |
| No critical/high unresolved security issue | ✅ | P0 = 0 · `composer audit` สะอาด |

---

## 8. สิ่งที่ยังต้องตัดสินใจ (ไม่ใช่เงื่อนไขของ checkpoint)

| # | เรื่อง | สถานะ |
|---|---|---|
| 1 | **Workflow Cutover (05-4)** | **ยังไม่ทำ** · ยังไม่แนะนำให้ทำ — ฐานข้อมูลมีใบลา 3 ใบและถูกลบทั้งหมด หลักฐาน shadow จึงมีเพียง 6 จุด ต้องมีการอนุมัติจริงสะสมตามจำนวนที่ตกลงกันก่อน |
| 2 | **`ATT-INT-001`** vendor scanner integration | ยังไม่เริ่ม (P2 · จะเป็น P1 ถ้าเป็น go-live requirement) — **ต้องได้ข้อมูล vendor จากเจ้าของระบบก่อน** ไม่ใช่เงื่อนไขของ CORE CHECKPOINT |
| 3 | **TLS / reverse proxy ขาหน้า** | nginx พร้อมรับ TLS แล้วแต่ยังไม่ได้ตั้ง certificate — เป็นงานของขั้นตอน deploy จริง ไม่ใช่ของ checkpoint |

---

## 9. สรุป

**P0 = 0 · P1 = 0 · ไม่มีช่องโหว่ความปลอดภัยค้าง · ไม่มีข้อมูลสูญหาย**

เงื่อนไขผ่านครบทุกข้อ **CORE CHECKPOINT = PASS** (รอลงนามอนุมัติ)

ตามคำสั่ง **ยังไม่เริ่ม LEGACY-06** และ **ยังไม่ทำ Workflow Cutover**

---

## 10. รายละเอียดการแก้ P1 ทั้ง 3 รายการ (รอบที่สอง)

### 10.1 `DEPLOY-001` + `DEPLOY-004` — รวม stack และใช้ web server จริง

**ปัญหาที่แท้จริง:** repository มี Dockerfile **สองชุดที่ไม่ตรงกัน**
- `/Dockerfile` (`php:8.4-fpm`) คู่กับ `docker/nginx/default.conf` — **ไม่มีใครใช้**
- `/backend/Dockerfile` (`php:8.4-cli`) ที่ compose ใช้จริง แล้วรันด้วย `php artisan serve`

ผลข้างเคียงที่อันตรายที่สุดคือกฎ `location ^~ /storage { return 404; }`
ซึ่งเป็นด่านสุดท้ายของช่องโหว่ไฟล์สาธารณะ (SEC-001) **ไม่เคยมีผลบังคับใช้เลย**

**สิ่งที่ทำ**

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `backend/Dockerfile` | เปลี่ยนเป็น `php:8.4-fpm` + `default-mysql-client` (จำเป็นสำหรับ backup) + entrypoint แก้สิทธิ์ storage |
| `backend/docker-entrypoint.sh` | ใหม่ — แก้ path ให้ตรงกับ `/var/www/html` ที่ compose ใช้จริง |
| `/Dockerfile`, `/docker-entrypoint.sh` | **ลบทิ้ง** — ต้นเหตุของ stack ที่สอง |
| `docker/nginx/default.conf` | แก้ `root` เป็น `/var/www/html/public`, `fastcgi_pass` เป็น upstream `backend:9000`, เสิร์ฟ SPA จาก `frontend/dist` |
| `docker-compose.yml` | `backend` รัน `exec php-fpm` และ **ไม่เปิดพอร์ตออกนอก** · เพิ่ม service `nginx` พอร์ต 8001 พร้อม healthcheck |
| `frontend/vite.config.js` | proxy ชี้ `http://nginx` แทน `http://backend:8000` เพื่อให้ dev เจอกฎเดียวกับ prod |

**ผลตรวจจริง**

```
GET /api/ping            → 200   Server: nginx/1.27.5   X-Powered-By: PHP/8.4.24
GET /storage/logos/x.png → 404   ← กฎที่เคยเป็น dead code ทำงานแล้ว
GET /                    → 200   (SPA)
GET /admin/settings      → 200   (SPA fallback)
GET /api/user (no token) → 401
localhost:8000 ตรง ๆ     → ติดต่อไม่ได้ (ไม่เปิดพอร์ตแล้ว)
```

### 10.2 `CORE-WF-002` — Saraban workflow version pinning

**ต้องแก้เรื่องสิทธิ์ก่อน** — migration `2026_08_14_220000_grant_saraban_approval_to_executives`
ให้ ผอ. และ รอง ผอ. ได้ `saraban.document.view` / `.approve` / `.sign` / `.report`

ตรวจผลจริงบนฐานข้อมูล:

```
director  view=Y approve=Y sign=Y report=Y | delete=n classified=n register=n
deputy    view=Y approve=Y sign=Y report=Y | delete=n classified=n register=n
doc       view=Y approve=n sign=n report=Y | delete=n classified=n register=Y
employee  ทุกอย่าง = n
```

**ให้เท่าที่จำเป็นเท่านั้น** — ผู้บริหารยังไม่ได้สิทธิ์ลบเอกสาร เอกสารลับ
ตั้งค่าระบบสารบรรณ และทะเบียนรับ-ส่ง (มีเทสต์บังคับไว้)

**จากนั้นเปิด version pinning** — migration `2026_08_14_230000`
เปิดสายงานสารบรรณจาก `draft` เป็น `active` และผูกเอกสารทุกฉบับเข้ากับเวอร์ชัน

`DocumentWorkflowService::transitionState()` ขยับ instance ตามหลังทุกครั้ง
โดย **ตรรกะเดิมยังเป็นผู้ตัดสินสถานะทั้งหมด** และห่อ try/catch ไว้เพื่อไม่ให้
การผูก workflow ทำให้การรับ-ส่งหนังสือล้ม · ปิดได้ด้วย `WORKFLOW_SARABAN_PINNING=false`

**เทสต์ที่เพิ่ม** — `SarabanWorkflowPinningTest` 9 รายการ ครอบทั้งการ pin,
idempotency, การเดินตามสาย, การตีกลับ, audit, และ **การพิสูจน์ว่าไม่เปลี่ยน
พฤติกรรมเดิมของโมดูลสารบรรณ**

---

## คำสั่งที่ใช้ตรวจ (ทำซ้ำได้)

```bash
docker exec eleave_backend php artisan test
docker exec eleave_backend ./vendor/bin/pint --test
docker exec eleave_backend composer audit
docker exec eleave_frontend sh -c "cd /app && npm run build"
docker exec eleave_backend php artisan backup:run --only-db
docker exec eleave_backend php artisan workflow:shadow-report
docker exec -e DB_DATABASE=eleave_migrate_test eleave_backend php artisan migrate --force
```

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-14 | **PASS** — P0 = 0 · P1 = 0 |
| ผู้ตรวจทาน | เจ้าของระบบ (satawad11) | 2026-08-14 | ☑ **อนุมัติ** |

---

## สถานะหลังอนุมัติ

**CORE CHECKPOINT ผ่านและได้รับอนุมัติแล้วเมื่อ 2026-08-14**

ประตูที่เปิดออก: เริ่ม LEGACY-06 (Time Foundation) ขึ้นไปได้ตาม `docs/LEGACY-MIGRATION-PLAN.md`

สิ่งที่ **ยังไม่ได้รับอนุมัติ** และยังห้ามทำจนกว่าจะมีคำสั่งแยก:
- **Workflow Cutover (05-4)** — ยังต้องรอหลักฐาน shadow จากการอนุมัติจริง
- **ATT-INT-001** vendor scanner integration — รอข้อมูล vendor
