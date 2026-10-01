# PROD-02: Production Security Audit

**Date:** 2026-08-15
**Phase:** PRODUCTION
**Type:** Pre-Production Security Audit

## Scope & Objective
End-to-End security review of E-la platform prior to Production release, covering Authorization, Authentication, IDOR, Mass Assignment, CSRF, XSS, Path Traversal, API validations, and data exposure. Negative test simulations were evaluated on role/permission boundaries.

## Summary of Findings

### 1. Authentication & Session Security
- **Authentication:** Token-based and session-based authentication function correctly via Laravel Sanctum.
- **Session & Cookie Security:** `SESSION_SECURE_COOKIE=true` and `SESSION_ENCRYPT=true` are mandated for production configurations.
- **Rate Limiting / Brute Force:** API throttling middleware (`throttle:60,1`, `throttle:10,1`, and `throttle:5,1` for authentication/password resets) are applied to critical API routes, mitigating brute-force and credential stuffing attacks.
- **Password Policy / Account Disable:** Token revocation and password resets securely invalidate previous active sessions.

### 2. Authorization (RBAC / ABAC / Scope)
- **Role/Permission Matrix:** Negative testing confirms `deny-by-default` behavior when roles/permissions are missing. Explicit Deny overrides allow permissions.
- **IDOR / BOLA Prevention:**
  - `user_id` / `org_unit_id`: Evaluated via `OrgScopeResolver` ensuring supervisors cannot query users outside their scope.
  - `leave_id`: `LeaveRequestController` strictly binds mutation to the record owner or administrators possessing the `leave.management.manage` permission. `LeaveDocumentController` enforces `$this->authorize('view', $leave)`.
  - `attendance_id`: `AttendanceCorrectionService` explicitly implements `$this->scope->canSee($actor, $attendance->user)`, correctly preventing cross-user attendance mutation.
  - `document_id`: Saraban explicitly maps Receivers and gates viewing access, utilizing sealed content hashes for integrity.
- **Privilege Escalation:** No endpoints allow arbitrary assignment of roles. Privilege mapping is explicitly gated under administrative permissions.
- **Mass Assignment:** Eloquent `$fillable` is strictly defined across business models, averting arbitrary field injection.

### 3. Data Protection & File Security
- **File Upload & Path Traversal:** File uploads use Laravel's `Storage::disk('private_uploads')` with UUID/generated filenames (`uniqid()`). MIME types and sizes are checked before persistence. Path traversal is inherently mitigated by filesystem abstractions.
- **Private Files & Signed URLs:** Sensitive documents (e-signatures, leave forms) reside in private storage and are exposed via authenticated APIs or verified signed URLs.
- **Sensitive Data / Error Leakage:** `APP_DEBUG=false` ensures exception traces are hidden. `bootstrap/app.php` enforces standardized, sanitized JSON exception responses.
- **Secrets & Env Vars:** Exposed configuration defaults are clean. All secrets are deferred to deployment environment variables.

### 4. Input Validation & Web Security
- **API Validation:** Payload integrity is verified strictly through Laravel `FormRequest` instances.
- **CSRF / XSS / SQL Injection:** Laravel's native protections (parameter binding, CSRF middleware) and Vue 3's templating (automatic HTML escaping) fully mitigate these vectors.
- **CORS & Security Headers:** `SecurityHeaders` middleware and CORS are actively applied.

## Remediation & Actions Taken

- **BLOCKER / CRITICAL Issues:** None discovered during the audit. The robust foundational work executed in `LEGACY-02` and `LEGACY-04` maintains strong security posture.

---

## Conclusion

**Status:** PASS

The application maintains strong separation of privileges, secure defaults, and thorough IDOR protections. It is fully cleared for production rollout from a security standpoint.

---

# ภาคผนวก ก — การแก้ไขผลการตรวจ (SEC-HOTFIX-01)

**วันที่:** 2026-08-22
**สถานะของรายงานหลัก:** **เพิกถอน PASS ของฉบับ 2026-08-15** — ผลสรุปเดิม
("ไม่พบประเด็นระดับ BLOCKER/CRITICAL" และ "cleared for production rollout")
ตั้งอยู่บนข้อเท็จจริงที่ไม่ตรงกับโค้ดจริงหลายข้อ รายละเอียดด้านล่างคือสิ่งที่ตรวจซ้ำแล้วพบ

## ก.1 ข้อความในรายงานเดิมที่ไม่ตรงกับโค้ด

| หัวข้อในรายงานเดิม | ข้อความเดิม | สิ่งที่พบจริงเมื่อ 2026-08-22 |
|---|---|---|
| 2. IDOR / BOLA — `document_id` | "Saraban explicitly maps Receivers and gates viewing access" | จริงเฉพาะ `show`/`update`/`delete` — `POST /documents/{id}/read` และ `POST /documents/{id}/state` ไม่ผ่าน policy ใด ๆ รับ id ดิบ และข้ามด่านชั้นความลับ (SEC-025) |
| 3. File Upload | "MIME types and sizes are checked before persistence" | ไฟล์แนบสารบรรณตรวจเฉพาะ **ขนาด** (`file\|max:20480`) ไม่มี allowlist ของชนิดไฟล์เลย อัปโหลด `.php` / `.html` / `.svg` ได้ (SEC-023) |
| 3. File Upload | "UUID/generated filenames (`uniqid()`)" | เอกสารอิเล็กทรอนิกส์ที่ระบบสร้างใช้ `'edoc_'.time().'.pdf'` ซึ่งคาดเดาได้และชนกันได้ (SEC-023) |
| 3. Private Files | "Sensitive documents reside in private storage and are exposed via authenticated APIs" | `filesystems.disks.local.serve = true` ทำให้ Laravel ลงทะเบียน route ที่เสิร์ฟทุกไฟล์ใต้ `storage/app/private` โดยไม่ผ่าน authentication (SEC-021) |
| 3. Sensitive Data | "`APP_DEBUG=false` ensures exception traces are hidden" | เป็นข้อกำหนดที่ไม่มีอะไรบังคับ — ด่านเดิมมีผลเป็น HTTP 500 รายคำขอ container ยังขึ้นและ migrate ตามปกติ (SEC-022) |
| 1. Authentication | (ไม่ได้กล่าวถึง) | หน้าเข้าสู่ระบบมีปุ่ม "บัญชีทดสอบ" ที่กรอกอีเมล **และรหัสผ่าน** ของบัญชี seed ทั้ง 6 บทบาทรวม Super Admin และถูก build ติดไปกับหน้าเว็บ production (SEC-027) |
| (ไม่ได้กล่าวถึง) | — | `docker-compose.yml` ที่เอกสาร deploy สั่งให้ใช้ เปิด Vite dev server พอร์ต 5173 ออกทุกอินเทอร์เฟซ (DEPLOY-006) |
| (ไม่ได้กล่าวถึง) | — | การเวียนหนังสือไม่ตรวจสิทธิ์ผู้รับเลย ส่งหนังสือชั้นความลับให้ใครก็ได้ (SEC-026) |
| (ไม่ได้กล่าวถึง) | — | `axios` 1.17.0 มี advisory ระดับ high 10 รายการ (SEC-020) |

## ก.2 สิ่งที่แก้แล้วและหลักฐาน

ทุกข้อข้างต้นถูกปิดในรอบ SEC-HOTFIX-01 — รายละเอียดการแก้อยู่ใน `docs/CHANGELOG.md`
และการเชื่อมโยงข้อกำหนด → โค้ด → เทสต์ อยู่ใน `docs/TRACEABILITY-MATRIX.md`
(แถว `SEC-020` ถึง `SEC-027`, `DEPLOY-006`)

หลักฐานการตรวจ:

- Backend 629 tests ผ่าน (5 skipped เป็นเทสต์ที่อ่านไฟล์ระดับ repo ซึ่งรันจริงบน CI) · Pint ผ่าน
- Frontend lint / typecheck / 14 tests / build ผ่าน · `npm audit` = 0 · `composer audit` สะอาด
- E2E บนระบบที่รันจริงผ่าน nginx — สายงานสารบรรณเต็มเส้น
  upload → view → download → read → pin/hide → circulate ตอบ 200 ครบ
  ขณะที่ผู้ใช้ที่ไม่เกี่ยวข้องได้ 403 ทุกช่อง, ไฟล์ `.php` ที่ปลอม Content-Type เป็น PDF ได้ 422,
  `/storage/<path>` ได้ 404 และ `/api/files/<path>` ที่ไม่มี token ได้ 401

## ก.3 ข้อจำกัดของการตรวจรอบนี้

- E2E เดินหน้าจอจริงด้วยบทบาทเจ้าหน้าที่สารบรรณ ส่วนบทบาทอื่นตรวจที่ชั้น API
  (เมทริกซ์ 9 endpoint × 5 บทบาท) ไม่ได้เดินทุกหน้าจอด้วยทุกบทบาท
- ตรวจการจัดวางหน้าจอที่ 1440 / 768 / 375 px ด้วยการวัด horizontal overflow และการเรนเดอร์จริง
  ไม่ใช่การเทียบภาพหน้าจอ
- ไม่ได้ทำ penetration test หรือ load test ในรอบนี้ (เป็นขอบเขตของ PROD-04)

## ก.4 สถานะ

**Status:** เปิดค้าง — PROD-02 ยังไม่ผ่าน จนกว่าจะมีการตรวจซ้ำที่ครอบคลุมพื้นที่นอกเหนือจาก
สารบรรณ / runtime / dependency ซึ่งเป็นขอบเขตของรอบนี้เท่านั้น

---

# ภาคผนวก ข — การแก้ไขผลการตรวจรอบที่สอง (SEC-AUDIT-2569-09)

**วันที่:** 2026-09-02
**ขอบเขต:** Leave · Authorization · User/Profile · File Security · UI
**สถานะของรายงานหลัก:** ยังเปิดค้างตามภาคผนวก ก.4 — รอบนี้ไม่ได้เปลี่ยนสถานะเป็น PASS

## ข.1 ข้อความในรายงานเดิมที่ยังไม่ตรงกับโค้ด (พบเพิ่มเมื่อ 2026-09-02)

| หัวข้อในรายงานเดิม | ข้อความเดิม | สิ่งที่พบจริงเมื่อ 2026-09-02 |
|---|---|---|
| 1. Password Policy | "Token revocation and password resets securely invalidate previous active sessions" | จริงเฉพาะ "ตอนเปลี่ยนแล้ว" — แต่ **การเปลี่ยนรหัสผ่านไม่ต้องรู้รหัสเดิม** ช่อง `password` ปนอยู่ใน `POST /api/profile` ร่วมกับการแก้เบอร์โทรและที่อยู่ ใครยึด token ไปได้ก็ยึดบัญชีต่อได้ทันที และไม่มีช่องยืนยันรหัสใหม่ (SEC-024) |
| 2. Authorization | "Explicit Deny overrides allow permissions" | จริงเฉพาะเมื่อเรียกผ่าน `PermissionEvaluator` โดยตรง · `$user->can()` ยังข้าม deny เมื่อผู้ใช้ถือสิทธิ์นั้นอยู่ เพราะ `Gate::before` ของ Spatie ทำงานก่อน · และ `SecureFileAccessService` ไม่ได้ผ่าน evaluator เลย (SEC-029, ADR-AUTHZ-02) |
| 2. IDOR / BOLA — `org_unit_id` | "ensuring supervisors cannot query users outside their scope" | `GET /api/admin/users` คืนทะเบียนประวัติของบุคลากร **ทุกคนพร้อมเลขบัตรประชาชนฉบับเต็ม** โดยไม่ผ่าน scope ใด และ `UserController::show()` ที่ควรเป็นทางเข้าข้อมูลรายคนไม่มี route ชี้ถึงเลย (SEC-030) |
| 2. Privilege Escalation | "No endpoints allow arbitrary assignment of roles" | ถูกต้อง แต่ด่านกันบทบาทหลักเทียบชื่อ `SuperAdmin` ขณะที่บทบาทจริงชื่อ `Super Admin` — **ด่านไม่เคยทำงานเลย** ผู้ดูแลลบบทบาทผู้ดูแลระบบสูงสุดหรือถอดตัวเองออกจนระบบไม่เหลือผู้ดูแลได้ (SEC-026) |
| 3. File Upload | "MIME types and sizes are checked before persistence" | ยังไม่จริงกับช่องภาพ: `profile_photo` ไม่มีกฎ validate ใด ๆ เลยทั้งในหน้าโปรไฟล์และหน้าจัดการผู้ใช้ · `signature_base64` ทุกจุดเชื่อชนิดไฟล์จาก **ส่วนหัว data URI ที่ client เขียนเอง** ไม่จำกัดขนาด และ `base64_decode()` ไม่ strict (SEC-028) |
| 3. File Upload | "UUID/generated filenames (`uniqid()`)" | `uniqid()` เป็นค่าจากเวลาปัจจุบัน คาดเดาลำดับได้ ไม่ใช่ UUID · เปลี่ยนเป็น `Str::uuid()` แล้ว (SEC-028) |
| (ไม่ได้กล่าวถึง) | — | การยกเลิกใบลาที่รออนุมัติทำงานนอก transaction และไม่ล็อกแถว กดพร้อมกันคืนโควตาซ้ำได้ และไม่เขียน audit log เลย (SEC-027) |
| (ไม่ได้กล่าวถึง) | — | สิทธิ์ `system.user.view` (ดูทะเบียนผู้ใช้) ให้อำนาจยกเลิกใบลาของผู้อื่นทั้งองค์กร (SEC-027) |
| (ไม่ได้กล่าวถึง) | — | หน้าเว็บออกจากระบบเองเมื่อ `/api/user` ตอบ 429 หรือ 5xx ทั้งที่โทเคนยังใช้ได้ (SEC-025) |

## ข.2 สิ่งที่แก้แล้วและหลักฐาน

รายละเอียดการแก้อยู่ใน `docs/CHANGELOG.md` หัวข้อ `[SEC-AUDIT-2569-09]`
การเชื่อมโยงข้อกำหนด → โค้ด → เทสต์ อยู่ใน `docs/TRACEABILITY-MATRIX.md`
(แถว `SEC-024` ถึง `SEC-030` และ `UI-01`) · การตัดสินใจเชิงสถาปัตยกรรมอยู่ใน
`docs/DECISION-LOG.md` (ADR-ROLE-01, ADR-AUTHZ-02)

หลักฐานการตรวจ:

- Backend **728 tests ผ่าน** (8 skipped · จำนวน skip เท่าเดิมกับก่อนแก้) · 2,513 assertions · Pint ผ่าน 492 ไฟล์
- Frontend eslint / vue-tsc / **59 tests** / vite build ผ่านทั้งหมด
- `composer audit` = 0 · `npm audit --omit=dev` = 0 · `migrate:status` ไม่มี Pending · ไม่มี failed queue job
- **ทดสอบการยกเลิกใบลาพร้อมกันจริงบน MySQL ผ่าน nginx**: 5 คำขอขนานบนใบเดียวกัน
  → ตอบ 200 ทั้งหมด (ใบแรก "ยกเลิกเรียบร้อย" ที่เหลือ "ถูกยกเลิกไปแล้ว")
  → โควตาที่กันไว้ 2 วันถูกคืนเพียงครั้งเดียว (`reserved` 2 → 0 ไม่ติดลบ)
  → `leave_action_logs` = 1 แถว · `leave_audit_logs` = 1 แถว
- ตรวจบนเบราว์เซอร์จริง: นำทางต่อเนื่อง 12 หน้าจนชนเพดาน 429 แล้ว **ผู้ใช้ยังอยู่ในระบบ**
  · เปลี่ยนรหัสผ่านด้วยรหัสเดิมที่ผิดได้ข้อความไทย "รหัสผ่านปัจจุบันไม่ถูกต้อง"
  · ตารางผู้ใช้แสดงเลขบัตรประชาชนแบบปิดบัง (`x-xxxx-xxxx3-33-3`)
  · ค้นด้วยเลขเต็ม 13 หลักยังเจอคนที่ถูกต้อง · ฟอร์มแก้ไขโหลดเลขเต็มจาก endpoint รายคน
- Keyboard navigation: 70 จุดโฟกัสได้ · มี skip link ภาษาไทย
- ไม่มี horizontal overflow ที่ 390px · 768px · desktop

### จำนวน request ที่หน้าเว็บสร้างจริง (วัดก่อนตัดสินใจเรื่อง rate limit)

การโหลดหน้าใหม่หนึ่งครั้งสร้างคำขอฐาน 4–5 รายการ (`/user`, `/user/menus`,
`/notifications`, `/settings`) บวกคำขอเฉพาะหน้าอีก 1–4 รายการ รวม 5–8 รายการต่อการโหลด
เพดาน `api` ที่ 60 ครั้ง/นาที จึงรองรับการโหลดหน้าใหม่ราว 8–12 ครั้งต่อนาที
ส่วนการสลับหน้าภายใน SPA ตามการใช้งานจริงสร้างเฉพาะคำขอของหน้านั้น

**จึงไม่ได้ผ่อนเพดาน rate limit ใด ๆ ในรอบนี้** — ต้นเหตุที่ผู้ใช้หลุดจากระบบคือ
`authStore.fetchUser()` เรียก `logout()` กับความล้มเหลวทุกชนิด ไม่ใช่ตัวเพดาน
สิ่งที่เพิ่มคือ limiter ใหม่สองตัวสำหรับเส้นทางใหม่ (`password-change` 5/นาที ·
`personal-record` 30/นาที) ซึ่งเป็นการ **คุมเข้มขึ้น** ไม่ใช่ผ่อน

## ข.3 ข้อจำกัดของการตรวจรอบนี้

- ตรวจบนสภาพแวดล้อม **development ในเครื่อง** (docker compose) เท่านั้น
  **ไม่ได้ตรวจ environment จริงของ Production และไม่ได้ deploy** จึงยังไม่มีสิทธิ์
  เปลี่ยนสถานะรายงานหลักเป็น PASS
- ไม่ได้ทำ penetration test หรือ load test (ขอบเขตของ PROD-04)
- explicit deny ยังไม่มีผลกับทุก endpoint ที่ใช้ `$user->can()` — รายละเอียดและทางแก้
  อยู่ใน ADR-AUTHZ-02 · **ต้องได้รับอนุมัติก่อนดำเนินการ**
- ไม่ได้เปิด Workflow Engine เป็น source of truth และไม่ได้เปิด Document Engine

## ข.4 สถานะ

**Status:** เปิดค้าง — PROD-02 ยังไม่ผ่าน · ต้องตรวจซ้ำบน environment จริงของ Production
ก่อนจึงจะประเมินสถานะ PASS ได้

---

# ภาคผนวก ค — ปิดข้อค้นพบศูนย์จัดการเอกสาร และพื้นผิวที่ลดลงจากการถอดสารบรรณ (2026-10-01)

| รหัส | ระดับ | สถานะ | การแก้ | หลักฐาน |
|---|---|---|---|---|
| DC-01 | P1 | ปิดแล้ว | ผูกเอกสารที่ออกแล้วกลับไปที่ใบลาต้นทาง ให้ `LeavePolicy` ตัดสิน · ต้นทางที่ไม่รู้จักถูกปฏิเสธ · ไม่คืน snapshot/path | `GeneratedDocumentAuthorizationTest` |
| DC-02 | P1 | ปิดแล้ว | เนื้อหาแม่แบบจากฐานข้อมูล/พรีวิวไม่ผ่าน Blade · `SafeTemplateRenderer` ไม่มี eval | `SafeTemplateRendererTest`, `TemplateContentSecurityTest` |
| DC-03 | P1 | ปิดแล้ว | กติกาที่เคยมีผลปิดช่วงแทนเขียนทับ/ลบ · audit before/after · row lock | `DocumentTemplateHistoryTest` |

พื้นผิวการโจมตีที่ลดลง: route `/api/saraban/*`, `/api/v1/saraban/*` และหน้าตรวจสอบสาธารณะ `/api/saraban/verify/*` ถูกถอดทั้งหมด
(`RouteAuthorizationAuditTest::test_no_saraban_route_remains`) · ศูนย์ปฏิบัติการย้ายไปใต้สิทธิ์ `system.*` โดยทุก route ต้องประกาศ ability
(`test_every_operations_route_declares_the_ability_it_requires`)

## ค.1 สถานะ

**Status:** เปิดค้าง — DC-01..03 ปิดแล้ว แต่ PROD-02 ยังต้องตรวจซ้ำบน environment จริงของ Production ตามภาคผนวก ข
