# CORE COMPATIBILITY MATRIX — SARABAN V1.2 Integration

INT-00 · สำรวจเมื่อ 2026-09-01 · branch `chore/remove-saraban-module` (HEAD `2d8f056`)

Source of Truth: `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`

เอกสารนี้จำแนก **ทุก Core Capability ของระบบเดิม** ตามการตัดสินใจ
`REUSE > EXTEND > MODIFY > CREATE > MIGRATE > DEPRECATE` (SoT §0)
ครอบคลุมรายการตรวจทั้งหมดที่ SoT §3 กำหนด

ทุกแถวอ้างอิงไฟล์จริงในโค้ดเบส ไม่มีรายการใดเป็นการคาดเดา

---

## 0. สรุประดับสูง

| ผลการตัดสิน | จำนวน | ความหมาย |
|---|---|---|
| REUSE | 17 | ใช้ของเดิมตรง ๆ ห้ามสร้างซ้ำ |
| EXTEND | 10 | ต่อยอดของเดิมแบบ backward-compatible |
| MODIFY | 1 | แก้ของเดิมโดยต้องมี regression |
| CREATE | 12 | เป็น Domain ของ SARABAN แท้ ๆ ไม่มีของเดิมรองรับ |
| MIGRATE | 1 | ย้ายข้อมูลจากโมดูลสารบรรณรุ่นเก่าที่ถูกถอดออกไปแล้ว |
| DEPRECATE | 0 | — |

**ข้อสรุปสำคัญ:** ระบบเดิมมี Core ครบเกือบทั้งหมดที่ SoT §32 (Final Definition of Done) ต้องการ
โดยเฉพาะ Module Registry, ABAC ที่มี explicit deny + scope, Workflow Engine
ที่ versioned อยู่แล้ว, Notification Center ที่มี LINE OA/Telegram/Email/In-App
พร้อม retry และ Document/PDF Engine ที่เรนเดอร์ฝั่ง backend ด้วย FPDI
**SARABAN จึงต้องเป็นโมดูลที่เสียบเข้ากับของเหล่านี้ ไม่ใช่สร้างชั้นของตัวเอง**

---

## 0.1 Matrix ตามแบบฟอร์มมาตรฐาน

ตาม `CORE-COMPATIBILITY-MATRIX-TEMPLATE.md` — รายละเอียดเต็มของแต่ละแถวอยู่ในหัวข้อ 1–23 ด้านล่าง

| Capability | Existing | Decision | Required Change | Regression Risk | Evidence |
|---|---|---|---|---|---|
| Authentication/SSO/MFA | Sanctum 4.3.2 + `AuthController` + throttle 8 กลุ่ม · **ไม่มี SSO/OIDC · ไม่มี MFA** | **REUSE** (auth) + **EXTEND** (step-up re-auth) | เพิ่ม assurance level บน token + middleware `require.recent_auth` สำหรับ sign/void/publish (SoT §24) | **สูง** — กระทบ session ของทุกโมดูล | `backend/routes/api.php`, `app/Http/Controllers/AuthController.php`, `bootstrap/app.php` |
| Users | `users` (+`uuid`, `org_unit_id`, `is_active`, custom permissions) | **REUSE** | ไม่มี — SARABAN อ้าง `user_id` เท่านั้น | ต่ำ | `0001_01_01_000000_create_users_table.php`, `2026_08_13_100000` |
| Personnel | `personnel` + `personnel_types` + `user_profiles` + `PersonnelSyncService` | **REUSE** | ไม่มี — ห้ามสร้าง personnel master ซ้ำ | ต่ำ | `2026_08_16_010000_create_personnel_foundation.php` |
| Organization | `org_units` (ต้นไม้ + effective dating) + `department_org_unit_map` | **REUSE** | SARABAN config ผูก `org_unit_id` (series/policy รายหน่วยงาน) | ต่ำ | `2026_08_13_100000_create_core_data_foundation.php` |
| Position/Assignment | `personnel_assignments` (effective dating) + `org_unit_managers` (MANAGER/รักษาการ + scope) · **`position` เป็น string** | **REUSE** + **EXTEND** | เพิ่ม `positions` + `personnel_assignments.position_id` (nullable, backfill) | **กลาง** — additive แต่แตะตารางที่ leave/overtime ใช้ | `2026_08_13_100000`, `2026_08_14_110000_create_org_unit_manager_assignments.php` |
| RBAC/ABAC | Spatie 8.3 + `permission_denies` (explicit deny ชนะทุก allow) + `permission_scopes` 6 ระดับ + `PermissionEvaluator` + `OrgScopeResolver` + `EnsureAnyAbility` | **REUSE** | เพิ่ม namespace `saraban.*` + Policy ของ SARABAN · ห้ามสร้าง engine ใหม่ | กลาง — เพิ่มสิทธิ์กระทบ matrix test เดิม | `2026_08_13_130000_create_authorization_foundation.php`, `app/Services/PermissionEvaluator.php` |
| Module Registry | `ModuleRegistry`/`ModuleDefinition`/`ModuleContract` + `config/module_registry.php` + `EnsureModuleEnabled` | **REUSE** + **EXTEND** | เพิ่ม manifest `saraban` (order 30, deps `system`, pattern `api/saraban*`) — INT-02 ทำแล้ว | กลาง — `ModuleRegistryTest`/`ModuleToggleTest` | `app/Services/Modules/*`, `config/module_registry.php` |
| Audit | `audit_logs` (actor/effective_actor/before/after/ip/ua/correlation) + `AuditLogger` + `AttachRequestContext` | **REUSE** + **EXTEND** | เพิ่มกลไกกันแก้ (hash chain) — ปัจจุบัน append-oriented โดย convention เท่านั้น | ต่ำ (additive) | `2026_08_13_110000_create_central_audit_logs_table.php`, `app/Services/AuditLogger.php` |
| File Service | disk `private_uploads` + `StoredFile` + `SecureFileController`/`SecureFileAccessService` + allowlist 2 ชั้น (`config/uploads.php`) · **ไม่มี malware scan / quarantine** | **REUSE** + **EXTEND** + **MODIFY** | เพิ่มโปรไฟล์ `saraban_attachments` · เพิ่ม malware scan + quarantine ที่ Core · แปลง `canView()` เป็น resolver registry | **สูง** — กระทบไฟล์แนบใบลา/รูปลงเวลา/ลายเซ็น | `config/uploads.php`, `app/Services/SecureFileAccessService.php`, `app/Support/StoredFile.php` |
| Notification | Notification Center ครบ (types/templates/events/recipients/deliveries/preferences) + adapter In-App/Email/**LINE OA**/**Telegram** + retry | **REUSE** + **EXTEND** | ลงทะเบียน `notification_types` ของ SARABAN · เพิ่ม adapter Web Push/Mobile Push · เพิ่มสถานะ dead-letter | กลาง — `NotificationCenterTest` | `2026_08_15_220000_create_notification_center.php`, `app/Services/NotificationCenter/Adapters/*` |
| API | prefix `/api` (ไม่มี `/v1`) + `auth:sanctum` + `ability:` + `AttachRequestContext` + `SecurityHeaders` + JSON error model · **ไม่มี OpenAPI · ไม่มี idempotency** | **REUSE** + **CREATE** | ใช้ `/api/saraban/...` ตาม convention เดิม · สร้าง idempotency middleware (จำเป็นกับ numbering/signature) · สร้าง OpenAPI | **กลาง–สูง** — `ApiSurfaceContractTest` บังคับว่าทุก endpoint ต้องมีหน้าจอเรียก | `bootstrap/app.php`, `routes/api.php`, `tests/Feature/ApiSurfaceContractTest.php` |
| Redis/Queue | queue driver `database` + container `worker` + `scheduler` · **ไม่มี Redis** | **REUSE** | ไม่มี — ห้ามเพิ่ม infra เอง (ต้องเป็น ADR) | ต่ำ | `config/queue.php`, `docker-compose.yml` |
| Outbox/Webhook | **ไม่มี outbox แท้** (`notification_events` ใกล้เคียง) · **ไม่มี webhook** | **EXTEND** (outbox) + **CREATE** (webhook) | ยกระดับ `notification_events` เป็น outbox ที่เขียนใน transaction เดียวกับ business · สร้าง webhook + MCP gateway | กลาง | `2026_08_15_220000`, `app/Services/NotificationCenter/NotificationCenter.php` |
| Feature Flags | มีเฉพาะ **module toggle** (`config/modules.*.enabled` + env) — ไม่ใช่ feature flag | **CREATE** | สร้าง platform capability ใช้ซ้ำได้ (env/org/group/role/user) + audit · Feature Flag ≠ Permission (SoT §21) | ต่ำ | `config/module_registry.php`, `app/Services/Modules/ModuleRegistry.php` |
| Observability | health `/up` + `/api/ping` + `config/logging.php` · **ไม่มี metrics endpoint** | **REUSE** + **CREATE** | เพิ่ม metrics ของ SARABAN (backlog/SLA/PDF fail/notification fail/dead-letter) เพื่อรองรับ Command Center | ต่ำ | `bootstrap/app.php` (`health: '/up'`), `config/logging.php` |
| Backup/DR | `spatie/laravel-backup` 10.3.1 + `config/backup.php` · **ยังไม่พบหลักฐาน restore test** | **REUSE** + **EXTEND** | เพิ่ม restore test + DR + RPO/RTO evidence จริง (SoT §27, §31) | ต่ำ (เอกสาร/ปฏิบัติการ) | `composer.json`, `config/backup.php`, `docs/operations/` |
| CI/CD | `.github/workflows/ci.yml` 2 job · backend: `composer audit`→`pint`→`migrate` จากฐานว่าง→`test` · frontend: `lint`→`typecheck`→`vitest`→`npm audit`→`build`→ตรวจบัญชีทดสอบใน bundle | **REUSE** + **EXTEND** | เพิ่มขั้นตอนของ SARABAN ในไฟล์เดิม ห้ามสร้าง pipeline ที่สอง | กลาง — ทุก gate ต้องเขียวตลอด | `.github/workflows/ci.yml` |

---

## 1. Identity / Authentication

| # | Capability | ของเดิม | ตัดสิน | เหตุผล / Dependency / Risk |
|---|---|---|---|---|
| 1.1 | Authentication | `laravel/sanctum` 4.3.2 · `AuthController` · `routes/api.php` (`/login`, `/logout`, `/refresh-token`, `/user`) | **REUSE** | เป็น Source of Truth เดียว SARABAN ห้ามมี login แยกตาม §3 |
| 1.2 | Password reset | `/forgot-password`, `/reset-password` + `throttle:password` | **REUSE** | — |
| 1.3 | Rate limit / throttle | throttle groups: `public-api`, `login`, `password`, `password-change`, `token-refresh`, `verify`, `api`, `personal-data` | **REUSE + EXTEND** | ต้องเพิ่ม group สำหรับ endpoint ที่อ่อนไหวของ SARABAN (sign, verify, export) |
| 1.4 | SSO / OIDC / Google Workspace | **ไม่มี** | **CREATE (ภายหลัง · นอกขอบเขต SARABAN)** | §3 ระบุว่า "ถ้ามี" — ระบบนี้ยังไม่มี จึงไม่ใช่ blocker แต่ถ้าจะทำต้องทำที่ Core ไม่ใช่ใน SARABAN |
| 1.5 | MFA / Step-up Re-auth (§17) | **ไม่มี** | **EXTEND (Core)** | §17 บังคับ: sign / void / release number / publish policy ต้องมี recent assurance · **ต้อง extend Core Identity ไม่ใช่สร้างใน SARABAN** · Risk: สูง — กระทบ session model เดิม · ต้องมี regression กับทุกโมดูล |
| 1.6 | Session / device / token | Sanctum personal access tokens (`personal_access_tokens`) | **REUSE + EXTEND** | ต้องเก็บ `assurance_level` / `last_authenticated_at` เพื่อรองรับ 1.5 |

---

## 2. Personnel

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 2.1 | Personnel master | ตาราง `personnel` (`2026_08_16_010000_create_personnel_foundation.php`) · `App\Models\Personnel` · `PersonnelSyncService` | **REUSE** | เป็น Source of Truth ตาม §3 · SARABAN เก็บได้แค่ reference + snapshot |
| 2.2 | โปรไฟล์/ลายเซ็น/รูป | `user_profiles` (`signature_path`, `profile_photo_path`, `position`, `id_card_hash`) | **REUSE** | ลายเซ็นสำหรับ §16 ใช้ของเดิม ห้ามสร้างคลังลายเซ็นใหม่ |
| 2.3 | สถานะการปฏิบัติงาน | `personnel.status` + `users.is_active` | **REUSE** | ใช้ตัดสิน authority resolution |
| 2.4 | ประเภทบุคลากร | `personnel_types` | **REUSE** | — |

---

## 3. Organization

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 3.1 | โครงสร้างองค์กรแบบต้นไม้ | `org_units` (`parent_id`, `code`, `type`, `effective_from/to`, `is_active`) | **REUSE** | รองรับหลายระดับและมี effective dating แล้ว ตรงตาม §3 |
| 3.2 | Legacy department | `departments` + `department_org_unit_map` | **REUSE** | SARABAN ต้องอ้าง `org_unit_id` เท่านั้น **ห้ามอ้าง `department_id`** เพื่อไม่สืบทอดข้อจำกัดระดับเดียว |
| 3.3 | Organizational path | คำนวณจาก `org_units.parent_id` ผ่าน `OrgScopeResolver::expand()` | **REUSE** | — |
| 3.4 | SARABAN office config (เลขหนังสือรายหน่วยงาน, policy รายองค์กร) | — | **CREATE** | §3 อนุญาตให้สร้าง SARABAN-specific config ได้ แต่ต้องอ้าง `org_unit_id` ห้าม copy master |

---

## 4. Position / Assignment / Authority

| # | Capability | ของเดิม | ตัดสิน | เหตุผล / Risk |
|---|---|---|---|---|
| 4.1 | "ใครดำรงตำแหน่งใด หน่วยงานใด ณ วันใด" | `personnel_assignments` (`user_id`, `org_unit_id`, `personnel_type_id`, `position`, `effective_from/to`) | **REUSE** | ตอบคำถามของ §3 ได้แล้ว |
| 4.2 | หัวหน้าหน่วยงาน / รักษาการ | `org_unit_managers` (`assignment_type` = MANAGER/…, `scope`, `effective_from/to`) | **REUSE** | เป็นแหล่งของ "หัวหน้าฝ่าย/รักษาการ" จริง — ไม่ใช่ชื่อ role |
| 4.3 | Position เป็น entity | `personnel_assignments.position` เป็น **string ไม่ใช่ FK** | **EXTEND** | §4/§13 ต้องการ `position_id` เพื่อ resolve authority และทำ Authority Matrix ได้แม่น · แนวทาง: เพิ่มตาราง `positions` + `position_id` แบบ nullable แล้ว backfill จาก string · Risk: กลาง · Migration: additive |
| 4.4 | Delegation / มอบอำนาจ | `workflow_delegations` (delegator/delegate/definition/role scope/effective dating) + `user_delegations` (legacy) + `DelegationResolver` | **REUSE** | §7 บอกให้ SARABAN สร้าง delegation เองเฉพาะเมื่อ Core ไม่มี — **Core มีแล้ว** |
| 4.5 | Authority Matrix (§13) | — | **CREATE (เป็น Projection เท่านั้น)** | ต้องอ่านจาก 4.1–4.4 ห้ามเป็น Source of Truth ใหม่ |

---

## 5. Authorization / RBAC / ABAC

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 5.1 | RBAC | `spatie/laravel-permission` 8.3.0 · `permissions`, `roles`, `role_has_permissions`, `model_has_permissions` | **REUSE** | — |
| 5.2 | Explicit deny | `permission_denies` (ชนะทุก allow รวม Super Admin) · `PermissionEvaluator::isDenied()` | **REUSE** | ตรงกับ §4 "Default DENY" |
| 5.3 | Scope / ABAC | `permission_scopes` (SELF/UNIT/DESCENDANTS/ORGANIZATION/ASSIGNED/CUSTOM) · `OrgScopeResolver` | **REUSE** | ครอบคลุม scope ที่ §5 ต้องการเกือบทั้งหมด |
| 5.4 | Scope `EXPLICIT` (§5) | มี `CUSTOM` + `ASSIGNED` | **REUSE (ตั้งชื่อให้ตรงในเอกสาร)** | ไม่ต้องเพิ่ม enum ใหม่ — `CUSTOM` ทำหน้าที่เดียวกัน |
| 5.5 | Permission alias ข้ามรุ่น | `permission_aliases` | **REUSE** | SARABAN ใช้ชื่อ canonical ตั้งแต่ต้น จึงไม่ต้องสร้าง alias |
| 5.6 | Gate / middleware | `EnsureAnyAbility` (`ability:`), `PermissionMiddleware`, Policy ผ่าน `Gate::policy()` | **REUSE** | ทุก endpoint ของ SARABAN ต้องผ่านชั้นนี้ |
| 5.7 | Object-level authorization (IDOR) | `App\Policies\LeavePolicy` เป็นแบบอย่าง (มี Policy เดียวในระบบ) | **CREATE (Policy ของ SARABAN)** | §33 บังคับ object authorization · ผูกผ่าน `Gate::policy()` ใน module provider |
| 5.8 | Permission namespace `saraban.*` (§5) | ถูกลบทิ้งใน `2026_08_27_000000_drop_saraban_module.php` | **CREATE** | ต้องลงทะเบียนใหม่ผ่าน manifest — `ModuleRegistry` บังคับรูปแบบ `module.resource.action` อยู่แล้ว |

**ข้อควรระวัง:** `ModuleRegistry::normalize()` โยน exception ถ้าชื่อ permission ไม่ตรงรูป
`^saraban\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$` ดังนั้นชื่อใน §5 อย่าง
`saraban.document.create` ผ่าน แต่ `saraban.command_center.view` ก็ผ่าน
(สอง segment หลัง snake_case) — ตรวจแล้วว่าทุกชื่อใน §5 ผ่านกฎนี้

---

## 6. Module Registry

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 6.1 | Module manifest | `config/module_registry.php` + `config/modules.php` · `ModuleRegistry`, `ModuleDefinition`, `ModuleContract` | **REUSE + EXTEND** | เพิ่มรายการ `saraban` เข้า manifest — ห้ามสร้าง registry ใหม่ |
| 6.2 | เปิด/ปิดโมดูล | `config('modules.saraban.enabled')` + env · `EnsureModuleEnabled` middleware + `route_patterns` | **REUSE** | ปิดโมดูลแล้ว route/menu ต้องหายทั้งชุด |
| 6.3 | Module service provider | `App\Providers\Modules\*ModuleServiceProvider` | **CREATE (`SarabanModuleServiceProvider`)** | ตามแบบเดิม — ใช้ผูก Policy |
| 6.4 | Dependency graph | `validateDependencies()` + acyclic check | **REUSE** | `saraban` ขึ้นกับ `system` เท่านั้น (INT-02 — ศูนย์แจ้งเตือนเป็นโครงสร้างที่ไม่มีก็ทำงานได้) |

---

## 7. Menu / Navigation

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 7.1 | เมนูจากฐานข้อมูล | `menus` + `menu_permissions` · `MenuService::getUserMenuTree()` | **REUSE + EXTEND** | เมนู SARABAN ทั้ง 11 + Production+ 7 รายการเพิ่มผ่าน migration |
| 7.2 | Menu visibility จากสิทธิ์ | `MenuService` กรองด้วย `Gate` + `modules->isEnabled()` | **REUSE** | ตรงกับ §6 "ห้าม hard-code role ใน frontend" |
| 7.3 | Menu integrity guard | `tests/Feature/MenuIntegrityTest.php`, `2026_08_28_090000_resync_menus_with_application_routes.php` | **REUSE** | เมนูใหม่ต้องผ่านเทสต์นี้ |

---

## 8. Workflow Engine

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 8.1 | Dynamic workflow (§10) | `workflow_definitions` / `workflow_steps` / `workflow_transitions` (มี `conditions` เป็น JSON, `assignee_type` role/user/permission) · `WorkflowEngine` | **REUSE** | รองรับเส้นทาง A–E ของ §10 ได้โดยไม่ต้อง hard-code |
| 8.2 | Workflow versioning (§11) | `workflow_versions` (`status` draft/active/retired, `effective_from/to`, `published_by/at`) · `workflow_instances.workflow_version_id` (pinned) | **REUSE** | **§11 ถูกครบแล้ว** — instance ผูก version จึงไม่เปลี่ยน route กลางทาง |
| 8.3 | Task / action / audit trail | `workflow_tasks`, `workflow_actions` (เก็บ actor, effective_actor, delegated_by, ip, user_agent, correlation_id) | **REUSE** | ใช้เป็นหลักฐานของ §16 ได้บางส่วน |
| 8.4 | SLA / escalation | `workflow_steps.sla_hours`, `on_timeout`, `escalate_to_step_id`, `workflow_tasks.due_at` | **REUSE** | รองรับ §25 SLA backlog |
| 8.5 | Approver resolution | `ApproverResolverService`, `Assignee/`, `DelegationResolver` | **REUSE** | §12 บังคับให้ Simulation ใช้ resolver **ตัวเดียวกัน** กับ production |
| 8.6 | Workflow Simulation (§12) | `WorkflowShadowComparison` + `LeaveWorkflowShadow` เป็นแนวคิดใกล้เคียง (shadow run) แต่ไม่ใช่ read-only preview | **CREATE (บน resolver เดิม)** | ต้อง READ ONLY: ไม่สร้าง instance / ไม่จองเลข / ไม่ส่งแจ้งเตือน |
| 8.7 | Workflow provisioner ต่อโมดูล | `LeaveWorkflowProvisioner`, `OvertimeOrderWorkflowProvisioner`, `AttendanceCorrectionWorkflowProvisioner` | **CREATE (`SarabanWorkflowProvisioner`)** | ตามแบบเดิม |

---

## 9. Audit

| # | Capability | ของเดิม | ตัดสิน | เหตุผล / Gap |
|---|---|---|---|---|
| 9.1 | Central audit | `audit_logs` (actor, effective_actor, action, resource, before/after, reason, ip, ua, correlation_id) · `AuditLogger` | **REUSE** | §35 "ใช้ Central Audit เดิม" |
| 9.2 | Correlation id | `AttachRequestContext` middleware ตั้ง `request_id` | **REUSE** | |
| 9.3 | Immutability | ตารางเป็น append-oriented โดย convention แต่ **ไม่มี hash chain / DB trigger กันแก้** | **EXTEND** | §35 ต้องการ append-oriented/immutable · แนวทางที่กระทบน้อยสุด: เพิ่ม hash chain column + verification command · Risk: ต่ำ (additive) |
| 9.4 | Audit เฉพาะโมดูล | `leave_audit_logs`, `personal_data_access_logs` | **REUSE เป็นแบบอย่าง** | SARABAN Timeline (§35) ควรเป็น projection ที่อ่านได้จาก `audit_logs` + `workflow_actions` ไม่ใช่ตารางที่สาม |

---

## 10. File Service / Storage

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 10.1 | Private storage | disk `private_uploads` · `App\Support\StoredFile` (กัน path traversal + null byte) | **REUSE** | |
| 10.2 | Secure download | `SecureFileController` + `SecureFileAccessService` + directive `secure-src` ฝั่งหน้าเว็บ | **REUSE + EXTEND** | ต้องเพิ่ม branch สำหรับไฟล์แนบ/เอกสาร SARABAN — **ห้ามสร้าง endpoint ดาวน์โหลดใหม่** |
| 10.3 | Upload allowlist | `config/uploads.php` (mime magic bytes + extension + `never_allowed`) · `App\Support\UploadRules` | **REUSE + EXTEND** | เพิ่มโปรไฟล์ `saraban_attachments` ในไฟล์เดียวกัน — ไฟล์นี้เกิดขึ้นเพราะ allowlist เดิมหายไปพร้อมโมดูลสารบรรณ (SEC-023R) |
| 10.4 | Malware scan (§34) | **ไม่มี** | **CREATE (Core)** | §34 บังคับ · ควรเป็น pipeline กลางที่ทุกโมดูลใช้ ไม่ใช่ของ SARABAN · Risk: ต้องมี service ภายนอก (ClamAV) → กระทบ deployment §38 |
| 10.5 | Quarantine | **ไม่มี** | **CREATE (Core)** | คู่กับ 10.4 |

---

## 11. Notification

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 11.1 | Notification Center | `notification_types/templates/events/recipients/deliveries/preferences` · `NotificationCenter`, `NotificationDeliveryService` | **REUSE + EXTEND** | §20 "หาก Core มี ให้ EXTEND" — เพิ่ม `notification_types` ของ SARABAN ผ่าน migration |
| 11.2 | Channels | `InAppChannelAdapter`, `EmailChannelAdapter`, `LineOaChannelAdapter`, `TelegramChannelAdapter` + `LineGateway`, `TelegramGateway` | **REUSE** | §20 ต้องการ IN_APP/EMAIL/LINE_OA/TELEGRAM — **มีครบแล้วทั้ง 4** |
| 11.3 | Web Push / Mobile Push | **ไม่มี** | **CREATE (Core adapter)** | §20 ระบุ WEB_PUSH + MOBILE_PUSH_READY · ทำเป็น adapter ใหม่ใน `ChannelAdapterRegistry` ไม่ใช่ระบบใหม่ |
| 11.4 | Retry / dead letter | `notification_deliveries` (`attempt_count`, `max_attempts`, `next_attempt_at`, `last_error`) | **REUSE + EXTEND** | มี retry แล้ว แต่ยังไม่มีสถานะ dead-letter ที่แยกชัดสำหรับ §25 |
| 11.5 | Transactional Outbox (§20) | **ไม่มี outbox แท้** — `notification_events` ทำหน้าที่ใกล้เคียง (queued → …) | **EXTEND** | ยกระดับ `notification_events` เป็น outbox ที่เขียนใน transaction เดียวกับ business · ตรงกับ §20 "ห้าม rollback business เพราะ notification fail" |
| 11.6 | Preference รายบุคคล | `notification_preferences` | **REUSE** | |

---

## 12. Document / PDF Engine

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 12.1 | Template + versioning | `document_templates`, `document_template_versions` | **REUSE + EXTEND** | เป็นฐานของ §8 ได้บางส่วน แต่ §8 ต้องการ version ของ **เอกสาร** ไม่ใช่ของ **แม่แบบ** → ดู 12.7 |
| 12.2 | Backend PDF render | `PdfOverlayRenderer` (FPDI/FPDF, ฟอนต์ THSarabunNew) | **REUSE** | ตรงกับ §14 "ห้ามใช้ browser screenshot เป็น Final PDF" |
| 12.3 | พิกัดแบบ normalized | `CoordinateConverter` (PDF point จากมุมซ้ายบน) | **REUSE** | ตรงกับ §14 |
| 12.4 | Field mapping / binding | `document_field_definitions`, `document_field_mappings`, `document_binding_rules`, `document_data_sources` · `DataProviderRegistry` | **REUSE + EXTEND** | SARABAN ลงทะเบียนเป็น data provider เพิ่ม |
| 12.5 | ไฟล์ที่สร้างแล้ว | `document_generated_files` | **REUSE** | |
| 12.6 | QR + verification | `chillerlan/php-qrcode` · `leaves.verification_hash` · `/api/leaves/verify/{hash}` + `throttle:verify` | **REUSE เป็นแบบอย่าง** | §11 (INT-11) ทำแบบเดียวกันสำหรับหนังสือ |
| 12.7 | Document เป็น Primary Entity + Document Version (§8) | **ไม่มี** — ของเดิมเป็น "แม่แบบ + ไฟล์ที่ render" ไม่ใช่เอกสารที่มีวงจรชีวิต | **CREATE** | Domain ของ SARABAN โดยตรง (§7) |
| 12.8 | PDF Workspace / E-Kasian objects (§14–15) | **ไม่มี** | **CREATE** | Domain ของ SARABAN |
| 12.9 | Print calibration | `document_print_calibrations` | **REUSE** | |

---

## 13. Numbering

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 13.1 | เลขที่เอกสาร concurrency-safe | `LeaveDocumentNumberService` — `DB::transaction()` + `lockForUpdate()` + `firstOrCreate` (**ไม่ใช่ `SELECT MAX()+1`**) · `leave_document_sequences` | **REUSE เป็นแบบอย่าง (pattern) เท่านั้น** | ตรงตามข้อห้ามของ §9 แล้ว แต่รองรับแค่ prefix+ปี ไม่มี state machine |
| 13.2 | State machine AVAILABLE→…→VOID (§9) | **ไม่มี** (ของเดิมมีแค่ "ออกเลข") | **CREATE** | ต้องมี reserve/allocate/issue/release/cancel/void + unique constraint + concurrency tests |
| 13.3 | ปี พ.ศ. + annual reset | `LeaveDocumentSequence` มี `year` (พ.ศ.) · `ThaiDateService` · `fiscal_years` | **REUSE** | |
| 13.4 | Series รายหน่วยงาน | **ไม่มี** | **CREATE** | อ้าง `org_unit_id` ตาม §3 |

---

## 14. API Platform

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 14.1 | API prefix / convention | `/api/...` (ไม่มี `/v1`) ตั้งใน `bootstrap/app.php` | **REUSE (ใช้ convention เดิม)** | §21 อนุญาต "หรือ convention เดิม" → ใช้ **`/api/saraban/...`** เพื่อไม่แตกจากของเดิมและไม่ทำให้ `route_patterns` ของโมดูลอื่นเพี้ยน |
| 14.2 | Auth + ABAC บน API | `auth:sanctum` + `ability:` + Policy | **REUSE** | |
| 14.3 | Request id | `AttachRequestContext` | **REUSE** | |
| 14.4 | Error model | `$exceptions->shouldRenderJsonWhen(api/*)` | **REUSE** | |
| 14.5 | Security headers | `SecurityHeaders` middleware | **REUSE** | |
| 14.6 | API surface contract | `tests/Feature/ApiSurfaceContractTest.php` — endpoint ที่ไม่มีหน้าจอเรียกต้องมีเหตุผลกำกับ | **REUSE (ข้อบังคับ)** | **ทุก endpoint ของ SARABAN ต้องมีหน้าจอเรียก หรือขึ้นทะเบียนเหตุผล** มิฉะนั้นเทสต์แดง |
| 14.7 | Idempotency key | **ไม่มี** | **CREATE (Core middleware)** | §21 บังคับ · จำเป็นกับ numbering/signature ที่ห้ามทำซ้ำ |
| 14.8 | OpenAPI | **ไม่มี** | **CREATE** | §21 · Risk: ต่ำ |
| 14.9 | Webhook / MCP Gateway (§22) | **ไม่มี** | **CREATE** | INT-14 |

---

## 15. Queue / Cache / Scheduler / Infra

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 15.1 | Queue | driver `database` (`QUEUE_CONNECTION`) · container `worker` ใน `docker-compose.yml` | **REUSE** | §38 "ห้ามสร้าง infrastructure ใหม่โดยอัตโนมัติ" — **ไม่มี Redis ใน compose** จึงห้ามสมมติว่ามี |
| 15.2 | Scheduler | container `scheduler` · `routes/console.php` | **REUSE** | |
| 15.3 | Cache | `config/cache.php` (database/file) · `MenuService` ใช้ `Cache::remember` | **REUSE** | |
| 15.4 | Redis | **ไม่มีใน compose** | **ไม่ตัดสินในเฟสนี้** | ถ้า §37 ต้องการ queue latency metric จริงจัง ค่อยเสนอเป็น ADR แยก — เป็น decision ของเจ้าของระบบ |
| 15.5 | Web server / app | `nginx:1.27-alpine` + `e-le-backend` (PHP 8.4, Laravel 13.25) + `mysql:8.0` | **REUSE** | |

---

## 16. Observability / Backup / DR

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 16.1 | Health check | `health: '/up'` ใน `bootstrap/app.php` + `/api/ping` | **REUSE + EXTEND** | §37 ต้องเพิ่ม metric ของ SARABAN |
| 16.2 | Logging | `config/logging.php` | **REUSE** | |
| 16.3 | Metrics endpoint | **ไม่มี** | **CREATE** | §37 (INT-19 / Command Center §25) |
| 16.4 | Backup | `spatie/laravel-backup` 10.3.1 + `config/backup.php` | **REUSE** | §36 · ต้องเพิ่มหลักฐาน restore test จริง |
| 16.5 | Restore test / DR evidence | **ไม่พบหลักฐาน** (มี `docs/production/`, `docs/operations/` ต้องตรวจต่อใน INT-19) | **EXTEND** | §36 บังคับ evidence จริงก่อน GO |

---

## 17. Production+ Governance (§23–§31)

ทั้งหมดนี้ **ไม่มีของเดิมรองรับ** จึงเป็น **CREATE** ทั้งหมด แต่ทุกตัวต้องเป็น
projection/ส่วนต่อขยายของ Core ไม่ใช่ระบบคู่ขนาน

| # | Capability | ตัดสิน | ผูกกับ Core ใด |
|---|---|---|---|
| 17.1 | Policy Center (§23) | **CREATE** | ต่อยอดแนวคิด `settings` + `system_settings` + versioning แบบ `workflow_versions` |
| 17.2 | Exception Queue (§24) | **CREATE** | อ่านจาก `audit_logs`, `workflow_actions`, `notification_deliveries.last_error` |
| 17.3 | Operational Command Center (§25) | **CREATE** | projection ล้วน |
| 17.4 | Feature Flags (§26) | **CREATE** | **ไม่ใช่** `config/modules.*.enabled` (นั่นคือ module toggle) · Feature Flag ≠ Permission ตาม §26 |
| 17.5 | Document Comparison (§27) | **CREATE** | ใช้ `document_generated_files` hash เป็นฐาน |
| 17.6 | Case / Dossier (§28) | **CREATE** | |
| 17.7 | Redaction (§29) | **CREATE** | ใช้ `PdfOverlayRenderer` เป็นฐาน |
| 17.8 | Saved Views (§30) | **CREATE** | |
| 17.9 | Compliance Evidence Center (§31) | **CREATE** | ต่อจาก `docs/audits`, `docs/checkpoints`, `docs/uat` ที่มีอยู่ |

---

## 18. MIGRATE — ข้อมูลสารบรรณรุ่นเก่า

| # | รายการ | ตัดสิน | รายละเอียด |
|---|---|---|---|
| 18.1 | ตาราง `saraban_*` 14 ตาราง | **MIGRATE (ถ้ามีข้อมูล production)** | ถูก `dropIfExists` ใน `2026_08_27_000000_drop_saraban_module.php` ซึ่งเป็น migration **ทางเดียว (`down()` ไม่คืนข้อมูล)** · โค้ดรุ่นเก่ายังอ่านได้จาก git history ก่อน `cd34f41` และจาก worktree `.claude/worktrees/jolly-euclid-08076a/` · **Risk: สูงสุดในเอกสารนี้** — ถ้า production เคยมีหนังสือจริง ต้องกู้จาก backup ก่อน ไม่ใช่สร้าง schema ใหม่ทับ |

---

## 19. MODIFY — รายการเดียวที่ต้องแก้ของเดิม

| # | รายการ | เหตุผล | Regression scope |
|---|---|---|---|
| 19.1 | `SecureFileAccessService::canView()` | ตอนนี้เป็น if-chain ที่รู้จักเฉพาะ leave/attendance/profile/logo · การเพิ่ม SARABAN แบบ if-chain ต่อไปเรื่อย ๆ จะทำให้จุดตัดสินสิทธิ์ไฟล์บวมและเปราะ | ต้องเปลี่ยนเป็น registry ของ resolver ต่อโมดูล · **Regression: ทุกไฟล์แนบใบลา, รูปลงเวลา, ลายเซ็น, โลโก้** · ต้องมีเทสต์เดิม (`LeaveEvidenceAccessTest`, `AttendanceImageSecurityTest`) ผ่านครบก่อน |

---

## 20. รายการที่ห้ามสร้างซ้ำเด็ดขาด

สรุปจาก §2/§3 — ถ้าพบ PR ที่สร้างสิ่งเหล่านี้ ให้ปฏิเสธทันที:

- ตาราง user / personnel / organization / position ชุดใหม่
- login, session, token ของ SARABAN
- permission engine, role checker, scope resolver ตัวใหม่
- audit log กลางตัวที่สอง
- notification queue / channel adapter ที่ไม่ผ่าน `ChannelAdapterRegistry`
- endpoint ดาวน์โหลดไฟล์ที่ไม่ผ่าน `SecureFileController`
- workflow engine / versioning ตัวใหม่
- module registry / menu tree ตัวใหม่
- PDF renderer ฝั่ง browser สำหรับ Final PDF

---

## 21. CI/CD / Deployment

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 21.1 | Pipeline | `.github/workflows/ci.yml` · 2 job: `backend`, `frontend` · trigger ทั้ง push และ pull_request | **REUSE + EXTEND** | เพิ่มขั้นตอนของ SARABAN ในไฟล์เดิม ห้ามสร้าง pipeline ที่สอง |
| 21.2 | Backend gate | `composer audit` → `pint --test` → `migrate --force` **จาก MySQL 8 ที่ว่างเปล่า** → `document:install-fonts` → `php artisan test` | **REUSE (ข้อบังคับ)** | migration ของ SARABAN ต้องรันจากฐานว่างได้ทุกครั้ง — เป็นด่านที่จับ migration ที่ไม่ idempotent |
| 21.3 | Frontend gate | `lint` → `typecheck` (`vue-tsc`) → `vitest` → `npm audit --audit-level=high` → `build` → grep บัญชีทดสอบใน `dist/` (SEC-027) | **REUSE (ข้อบังคับ)** | จำกัดการเพิ่ม dependency ใหม่ของ SARABAN (ต้องไม่มี advisory ระดับ high) |
| 21.4 | Container | `docker-compose.yml`: `db`(mysql:8.0), `backend`, `nginx:1.27-alpine`, `worker`, `scheduler`, `frontend_build` · `backend/Dockerfile`, `frontend/Dockerfile`, `docker-entrypoint.sh` | **REUSE** | SoT §0 ห้าม rewrite · การเพิ่ม service ใหม่ (เช่น ClamAV, Redis) ต้องเป็น ADR แยก |
| 21.5 | Deployment evidence | `docs/deployment/`, `docs/production/` | **EXTEND** | SoT §31 ต้องการหลักฐานจริงก่อน GO |
| 21.6 | Environment guard | `App\Support\ProductionEnvironmentGuard` + `ProductionEnvironmentGuardTest` | **REUSE** | กันค่าตั้งอันตรายหลุดขึ้น production |

---

## 22. Trusted Time / Hash / Verification

SoT §10 (`trusted time`), §14 (`hash`), §12 (`public verification`)

| # | Capability | ของเดิม | ตัดสิน | เหตุผล |
|---|---|---|---|---|
| 22.1 | เวลาอ้างอิง | ใช้ `now()` ของแอป (timezone จาก `config/app.php`) · **ไม่มี trusted time source ภายนอก (NTP-signed / TSA)** | **CREATE (บาง ๆ)** | INT-04 · ทำเป็น `TrustedClock` service ที่ทุกจุดของ SARABAN เรียก เพื่อให้เปลี่ยนไป TSA ภายหลังได้โดยไม่ไล่แก้ · **ห้ามเรียก `now()` กระจายในโดเมน** |
| 22.2 | Hash ของเอกสาร | `leaves.verification_hash` (`2026_08_10_000800_add_verification_hash_to_leaves.php`) + `LeaveVerificationService` | **REUSE เป็นแบบอย่าง** | รูปแบบเดียวกันสำหรับหนังสือ (INT-12) |
| 22.3 | ตรวจสอบสาธารณะ | `GET /api/leaves/verify/{hash}` + `throttle:verify` + หน้า `VerifyLeaveView.vue` (route meta `bare` + `public`) | **REUSE เป็นแบบอย่าง** | ครบทั้ง 3 ชั้น (endpoint + throttle + หน้าจอไม่มี shell) |
| 22.4 | วันที่พุทธศักราช | `App\Services\ThaiDateService` + `fiscal_years` | **REUSE** | บังคับใช้กับเลขหนังสือ (SoT §9 Buddhist Era) |

---

## 23. Module Dependency Graph (ของเดิม)

ตรวจจาก `config/module_registry.php` — ต้องรู้ก่อนแทรก `saraban` เพื่อไม่ให้ `order` ชนกัน
และไม่ทำให้ `route_patterns` ของโมดูลเดิมถูกแย่งจับ

| module | code | order | dependencies | route_patterns |
|---|---|---|---|---|
| admin | `system` | 0 | — | — (ปิดไม่ได้ `can_disable=false`) |
| leave | `leave` | 10 | `system` | `api/leaves*`, `api/approvals*`, `api/admin/leaves*`, `api/reports/*` |
| attendance | `attendance` | 20 | `system` | `api/attendances*`, `api/admin/attendances*`, `api/admin/attendance-corrections*` |
| overtime | `overtime` | 25 | `system`, `attendance` | `api/overtime*`, `api/attendances/ot-*` |
| notification | `notification` | 40 | `system` | `api/notifications*`, `api/notification-preferences*` |
| reporting | `reporting` | 50 | `system` | `api/reporting*` |

**ที่ว่างสำหรับ SARABAN:** `order = 30` (ระหว่าง overtime กับ notification)
**dependencies:** `['system']` — แก้จากที่เคยระบุว่ารวม `notification` ด้วย ดูเหตุผลใน INT-02-REPORT.md
**route_patterns:** `['api/saraban*']` — ไม่ชนกับ pattern ใดข้างต้น (ตรวจแล้วทีละตัว)

`ModuleRegistry::moduleForPath()` เลือก pattern ที่ยาวที่สุดที่ตรงกัน ดังนั้น
`api/saraban*` จะไม่ไปแย่ง path ของโมดูลอื่น และไม่มีโมดูลอื่นแย่ง path ของ SARABAN

---

## 24. หมายเหตุการอ้างอิงมาตรา

เอกสารนี้ร่างครั้งแรกโดยอ้างเลขมาตราของ Integration Master Prompt ที่ผู้ใช้ส่งมา
เมื่อยืนยัน Single Source of Truth เป็น `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` แล้ว
เลขมาตราที่ต่างกันมีดังนี้ (เนื้อหาข้อกำหนดตรงกันทุกข้อ ไม่มีข้อใดขัดกัน):

| อ้างในเอกสารนี้ | มาตราที่ตรงกันใน SoT |
|---|---|
| §3 (Core Ownership) | SoT §3 + §4 |
| §5 (Permission namespace) | SoT §6 |
| §7 (SARABAN Domain) | SoT §5 |
| §9 (Numbering) | SoT §9 |
| §10–§12 (Workflow/Versioning/Simulation) | SoT §11 + §12 |
| §13 (Authority Matrix) | SoT §13 |
| §14–§16 (PDF/E-Kasian/Signature) | SoT §14 |
| §17 (Step-up Re-auth) | SoT §24 |
| §20 (Notification) | SoT §16 |
| §21 (API) | SoT §17 |
| §23 (Policy Center) | SoT §18 |
| §33–§35 (Security/File/Audit) | SoT §26 + §25 |
| §36–§38 (Backup/Observability/Deploy) | SoT §27 + §31 |
| §44 (Definition of Done) | SoT §32 |
