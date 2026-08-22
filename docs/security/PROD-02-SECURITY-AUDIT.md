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
