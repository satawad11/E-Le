# INT-01 — CORE CONTRACT

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**บังคับใช้โดย:** `backend/tests/Feature/SarabanCoreContractTest.php` (รันทุกครั้งใน CI)
**วันที่:** 2026-09-01

เอกสารนี้ตอบคำถามเดียว: **เมื่อโมดูลสารบรรณต้องการความสามารถหนึ่ง ต้องเรียกอะไรของระบบเดิม**
ทุกแถวระบุ API ที่แน่นอน ไม่ใช่คำแนะนำกว้าง ๆ — สิ่งที่ไม่อยู่ในเอกสารนี้ ห้ามสมมติเอาเอง

---

## 1. สัญญาหลัก 15 ข้อ

### 1.1 Identity / Authentication

| หัวข้อ | สัญญา |
|---|---|
| ผู้ใช้ปัจจุบัน | `$request->user()` / `auth()->user()` (guard `sanctum`) |
| การอ้างถึงผู้ใช้ | คอลัมน์ `user_id` → FK `users.id` เสมอ |
| **ห้าม** | `Auth::attempt()`, `Auth::login()`, `Hash::make()`, หน้า login ของตัวเอง, ตาราง session/token ของตัวเอง |
| Step-up re-auth | **ยังไม่มีใน Core** — จะเพิ่มที่ Core Identity ใน INT-11 · สารบรรณห้ามทำเอง |

### 1.2 Personnel

| หัวข้อ | สัญญา |
|---|---|
| ทะเบียนบุคลากร | `App\Models\Personnel` (`personnel.user_id` unique → `users.id`) |
| ประเภทบุคลากร | `personnel_types` |
| ลายเซ็น / รูป | `user_profiles.signature_path`, `profile_photo_path` (อ่านผ่าน `StoredFile` เท่านั้น) |
| Snapshot | เก็บ **สำเนาข้อความ** (ชื่อ/ตำแหน่ง/หน่วยงาน ณ เวลานั้น) ในตารางของสารบรรณได้ และ **ต้อง** เก็บ ตาม SoT §14 · แต่ยังต้องเก็บ `user_id` ควบคู่เสมอ |
| **ห้าม** | สร้าง personnel master, เขียนทับข้อมูลบุคลากรจากโมดูลสารบรรณ |

### 1.3 Organization

| หัวข้อ | สัญญา |
|---|---|
| หน่วยงาน | `org_units.id` (ต้นไม้ผ่าน `parent_id` + `effective_from`/`effective_to`) |
| การอ้างถึง | คอลัมน์ `org_unit_id` → FK `org_units.id` |
| **ห้าม** | `department_id` (บังคับด้วยเทสต์) — หน่วยงานระดับเดียวใช้กับสารบรรณไม่ได้ |
| Config รายหน่วยงาน | สร้างตารางของสารบรรณเองได้ (series เลข / policy) แต่ต้องอ้าง `org_unit_id` |

### 1.4 Position / Assignment / Acting

| หัวข้อ | สัญญา |
|---|---|
| ใครอยู่ตำแหน่งใด ณ วันใด | `personnel_assignments` (`user_id`, `org_unit_id`, `position`, `effective_from`, `effective_to`) |
| หัวหน้าหน่วยงาน / รักษาการ | `org_unit_managers` (`assignment_type`, `scope`, effective dating) — **นี่คือแหล่งความจริงของ "หัวหน้าฝ่าย/ผู้รักษาการ" ไม่ใช่ชื่อ role** |
| **ช่องว่างที่รู้อยู่** | `personnel_assignments.position` เป็น **string** ไม่ใช่ FK → Authority Matrix จะ resolve ตามตำแหน่งได้ไม่แม่น · แก้ที่ INT-03 ด้วยการเพิ่ม `positions` + `position_id` แบบ additive |

### 1.5 Delegation / มอบอำนาจ

| หัวข้อ | สัญญา |
|---|---|
| ผู้กดกำลังทำแทนใคร | `App\Services\Workflow\DelegationResolver::delegatorFor(User $actor, ?int $requiredRoleId, ?string $on, ?int $workflowDefinitionId): ?int` |
| การมอบอำนาจที่มีผลอยู่ | `DelegationResolver::activeDelegationsFor(User $actor, ?string $on): array` |
| แหล่งข้อมูล | `workflow_delegations` (+ `user_delegations` ที่เป็น legacy source of truth) |
| **ห้าม** | สร้างตาราง delegation ของสารบรรณ — SoT §5 อนุญาตเฉพาะกรณี Core ไม่มี ซึ่ง **Core มีแล้ว** |

### 1.6 Authorization (RBAC / ABAC)

| หัวข้อ | สัญญา |
|---|---|
| ตรวจสิทธิ์ | `$user->can('saraban.document.create')` / `Gate::allows()` → วิ่งผ่าน `PermissionEvaluator` (deny > allow) |
| ตรวจที่ route | middleware `ability:saraban.document.read` (`App\Http\Middleware\EnsureAnyAbility`) |
| ขอบเขต (ABAC) | `App\Services\OrgScopeResolver::visibleOrgUnitIds(User $user, string $ability, string $default): ?array` — คืน `null` = ไม่จำกัด |
| Object-level | `Gate::policy(SarabanDocument::class, SarabanDocumentPolicy::class)` ผูกใน `SarabanModuleServiceProvider` |
| ลำดับการตัดสิน | explicit deny → scoped allow → role allow → **default DENY** |
| **ห้าม** | `hasRole()` (บังคับด้วยเทสต์), ตัดสินสิทธิ์จาก id ผู้ใช้, ตรวจเฉพาะฝั่งหน้าเว็บ |

### 1.7 Module Registry

| หัวข้อ | สัญญา |
|---|---|
| ลงทะเบียน | เพิ่มรายการใน `backend/config/module_registry.php` |
| ค่าที่ตกลงไว้ | `code: 'saraban'` · `order: 30` · `dependencies: ['system']` · `route_patterns: ['api/saraban*']` |
| Provider | `App\Providers\Modules\SarabanModuleServiceProvider` — **ยังไม่สร้างใน INT-02** เพราะยังไม่มี model/policy ให้ผูก จะสร้างพร้อม Policy ตัวแรก · `providers` ยังเป็น `[]` เหมือน `overtime`/`reporting` |
| รูปแบบชื่อสิทธิ์ | `ModuleRegistry::normalize()` บังคับ `saraban.<resource>.<action>` — ผิดรูปแล้ว boot ไม่ขึ้น |
| ปิดโมดูล | `EnsureModuleEnabled` ใช้ `route_patterns` ตัด route ให้เอง — ไม่ต้องเขียน guard เอง |

### 1.8 Menu

| หัวข้อ | สัญญา |
|---|---|
| เพิ่มเมนู | migration เพิ่มแถวใน `menus` (`module = 'saraban'`) + `menu_permissions` |
| การแสดงผล | `App\Services\MenuService::getUserMenuTree()` กรองด้วย Gate + module enabled ให้เอง |
| **ห้าม** | ผูกเมนูกับชื่อ role, hard-code เมนูในหน้าเว็บ |

### 1.9 Audit

| หัวข้อ | สัญญา |
|---|---|
| บันทึก | `App\Services\AuditLogger::log(string $action, Model\|string $resource, $resourceId, ?array $before, ?array $after, ?string $reason, ?int $actorId)` |
| ตารางเดียว | `audit_logs` — correlation id มาจาก `AttachRequestContext` อัตโนมัติ |
| Timeline ของสารบรรณ | เป็น **projection** ที่อ่านจาก `audit_logs` + `workflow_actions` |
| **ห้าม** | `saraban_audit_logs` หรือเขียน `DB::table('audit_logs')` ตรง ๆ (บังคับด้วยเทสต์) |

### 1.10 Workflow

| หัวข้อ | สัญญา |
|---|---|
| เริ่มเรื่อง | `WorkflowEngine::startOnVersion(WorkflowVersion $version, Model $subject, ?int $initiatedBy, bool $shadow, array $meta)` |
| ทำ action | `WorkflowEngine::perform(WorkflowInstance $instance, string $action, User $actor, ?string $comment, array $context)` |
| สิ่งที่ทำได้ตอนนี้ | `WorkflowEngine::availableTransitions($instance, $user)` · `authorize()` · `currentStep()` |
| **จุดต่อขยายของสารบรรณ** | สร้าง `SarabanAssigneeResolver implements App\Services\Workflow\Assignee\SubjectAssigneeResolver` แล้วเพิ่มคลาสใน `AssigneeResolverRegistry::RESOLVERS` — **นี่คือจุดเดียวที่ต้องแก้ไฟล์ของ Core** |
| Versioning | มีครบแล้ว: `workflow_versions.status` (draft/active/retired) + `workflow_instances.workflow_version_id` (pinned) |
| **ห้ามใช้** | `ApproverResolverService` — ผูกกับ `ApprovalStep`/`LeaveApproval` ของโมดูลลาโดยตรง **ไม่ใช่ resolver กลาง** |

### 1.11 Notification

| หัวข้อ | สัญญา |
|---|---|
| ส่ง | `App\Services\NotificationCenter\NotificationCenter::emit(...)` — ทางเข้าเดียว |
| ลงทะเบียนชนิด | migration เพิ่มแถว `notification_types` (`module = 'saraban'`) + `notification_templates` |
| ช่องทางที่มีแล้ว | In-App, Email, **LINE OA**, **Telegram** (`ChannelAdapterRegistry`) |
| ที่ยังไม่มี | Web Push / Mobile Push → เพิ่มเป็น adapter ใหม่ที่ Core (INT-14) |
| **ห้าม** | `Mail::send()` (บังคับด้วยเทสต์), queue/retry ของตัวเอง, rollback business transaction เพราะส่งไม่สำเร็จ |

### 1.12 File / Storage

| หัวข้อ | สัญญา |
|---|---|
| เก็บไฟล์ | `Storage::disk('private_uploads')` — เข้าถึง path ผ่าน `App\Support\StoredFile::relative()/path()/exists()` |
| ตรวจไฟล์อัปโหลด | `App\Support\UploadRules::attachment()` / `documentTemplate()` — allowlist กลางที่ `config/uploads.php` |
| ให้ผู้ใช้ดาวน์โหลด | เส้นทางเดียว: `GET /api/files/{path}` (`SecureFileController`) → ต่อสิทธิ์ที่ `SecureFileAccessService::canView()` |
| หน้าเว็บ | directive `v-secure-src` เท่านั้น ห้ามใส่ URL ไฟล์ตรง ๆ |
| **ห้าม** | endpoint ดาวน์โหลดของตัวเอง, เขียน `mimes:` เอง, วางไฟล์บน disk สาธารณะ |
| ที่ยังไม่มี | malware scan + quarantine → เพิ่มที่ Core ใน INT-04 |

### 1.13 Document / PDF

| หัวข้อ | สัญญา |
|---|---|
| เรนเดอร์ PDF | `App\Services\DocumentEngine\PdfOverlayRenderer::render(DocumentTemplateVersion $version, array $values, array $calibration): string` |
| พิกัด | `App\Services\DocumentEngine\CoordinateConverter` — PDF point วัดจากมุมซ้ายบน |
| **ห้าม** | ใช้ภาพจากเบราว์เซอร์เป็น Final PDF (SoT §14) |

### 1.14 API

| หัวข้อ | สัญญา |
|---|---|
| เส้นทาง | `/api/saraban/...` (convention เดิมไม่มี `/v1`) |
| Middleware | `auth:sanctum` + `throttle:api` + `ability:` |
| correlation id | `AttachRequestContext` ให้อัตโนมัติ |
| Error | JSON อัตโนมัติสำหรับ `api/*` |
| **ข้อบังคับ** | ทุก endpoint ต้องมีหน้าเว็บเรียกจริง มิฉะนั้น `ApiSurfaceContractTest` แดง |
| ที่ยังไม่มี | idempotency key + OpenAPI → INT-15 |

### 1.15 Time / Hash

| หัวข้อ | สัญญา |
|---|---|
| วันที่พุทธศักราช | `App\Services\ThaiDateService` + `fiscal_years` |
| Trusted time | **ยังไม่มี** — INT-04 จะสร้าง `TrustedClock` เป็น seam เดียวของสารบรรณ · **ตั้งแต่ INT-04 เป็นต้นไป โดเมนของสารบรรณห้ามเรียก `now()` ตรง ๆ** (จะเพิ่มเข้าเทสต์สัญญาเมื่อ `TrustedClock` มีจริง) |
| Hash + ตรวจสอบสาธารณะ | เดินตามแบบของใบลา: `verification_hash` + `GET /api/.../verify/{hash}` + `throttle:verify` + หน้า route meta `bare`+`public` |

---

## 2. สิ่งที่ต้องแก้ไฟล์ของ Core (มีเพียง 4 จุด)

ทุกจุดเป็น **additive** และไม่เปลี่ยนพฤติกรรมเดิม

| # | ไฟล์ | การแก้ | Checkpoint |
|---|---|---|---|
| 1 | `backend/config/module_registry.php` | เพิ่มรายการ `saraban` | INT-02 |
| 2 | `backend/app/Services/Workflow/Assignee/AssigneeResolverRegistry.php` | เพิ่ม `SarabanAssigneeResolver::class` ใน `RESOLVERS` | INT-08 |
| 3 | `backend/app/Services/SecureFileAccessService.php` | รองรับไฟล์ของสารบรรณ (ทำเป็น resolver registry — ดู MODIFY 19.1 ของ core matrix) | INT-04 |
| 4 | `backend/config/uploads.php` | เพิ่มโปรไฟล์ `saraban_attachments` | INT-04 |

**นอกเหนือจาก 4 จุดนี้ ห้ามแก้ไฟล์ของ Core หรือของโมดูลเดิม** — ถือเป็น unrelated refactor

---

## 3. ความสามารถของ Core ที่พิสูจน์แล้วด้วยเทสต์ที่มีอยู่

INT-01 **ไม่เขียนเทสต์ characterization ซ้ำ** เพราะระบบเดิมพิสูจน์ไว้แล้ว
(การเขียนซ้ำจะขัดหลัก REUSE เอง) — อ้างอิงเป็นหลักฐานแทน:

| ความสามารถที่สารบรรณพึ่งพา | เทสต์ที่พิสูจน์ไว้แล้ว |
|---|---|
| ผู้รับมอบอำนาจทำแทนได้ / หมดอายุแล้วทำไม่ได้ / ยังไม่ถึงกำหนดทำไม่ได้ / ผู้มอบไม่มีบทบาทก็ไม่ได้ | `Tests\Feature\Workflow\WorkflowEngineTest` (4 กรณี) |
| เรื่องที่กำลังเดินอยู่คง pinned version เดิมเมื่อสายงานเปลี่ยน | `WorkflowEngineTest` |
| ประวัติการกระทำแก้ไม่ได้ / ลบไม่ได้ | `WorkflowEngineTest` |
| ขั้นเดียวกันอนุมัติซ้ำไม่ได้ / ผู้อนุมัติสองคนดันขั้นเดียวกันพร้อมกันไม่ได้ | `WorkflowEngineTest` |
| explicit deny ชนะทุก allow · alias ชื่อสิทธิ์ข้ามรุ่น | `PermissionEvaluatorTest` |
| ขอบเขตหน่วยงาน 6 ระดับ | `OrgScopeResolverTest` |
| audit กลางบันทึกครบ | `CentralAuditLogTest` |
| เมนูสอดคล้องกับ route และสิทธิ์ | `MenuIntegrityTest` |
| เปิด/ปิดโมดูลแล้ว route หายจริง | `ModuleToggleTest` |
| ไฟล์แนบเข้าถึงได้เฉพาะผู้มีสิทธิ์ | `LeaveEvidenceAccessTest`, `AttendanceImageSecurityTest` |

---

## 4. การบังคับใช้สัญญา

`backend/tests/Feature/SarabanCoreContractTest.php` — 5 กรณี:

| เทสต์ | บังคับอะไร |
|---|---|
| `core_tables_that_saraban_binds_to_exist` | ตารางกลาง 26 ตารางที่สารบรรณเกาะต้องไม่หาย/ไม่เปลี่ยนชื่อ |
| `core_services_expose_the_entry_points_saraban_binds_to` | เมธอด 12 คลาสที่สารบรรณจะเรียกต้องยังอยู่ |
| `saraban_does_not_create_a_second_core_master_table` | ห้ามสร้างทะเบียนหลักซ้ำ 21 ชื่อ (รวม `saraban_audit_logs` ที่เคยมีจริงในรุ่นก่อน) |
| `saraban_code_does_not_reimplement_core_capabilities` | ห้าม 8 รูปแบบในโค้ด (auth เอง, hash รหัสผ่าน, เขียน audit ตรง, แก้ทะเบียนหลัก, `hasRole()`, `department_id`, `Mail::`, อีเมลฝังในโค้ด) |
| `the_contract_scanner_actually_detects_violations` | พิสูจน์ว่าตัวสแกนทำงานจริง ไม่ใช่ผ่านเพราะยังไม่มีไฟล์ให้ตรวจ |
