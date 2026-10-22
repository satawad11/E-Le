# R-05 บัญชีและความปลอดภัย — รายงาน checkpoint

> branch `rebuild/R-05` (จาก `rebuild/2569` หลัง merge PR #33) · 2026-10-19
> อ้างอิง: `docs/modules/CORE-03-AUTHENTICATION.md`, `docs/modules/CORE-04-RBAC-ABAC.md`, `TASKS.md` §R-05, ADR-R-013 (Q10), ADR-R-019 … ADR-R-022

## 1. สรุป

| งาน | ผล |
|---|---|
| R05-1 สถานะบัญชี + JML + `accounts:reconcile` | `AccountLifecycleService` ตามตาราง JML ของ CORE-03 ครบทุกแถว · event `PersonnelStatusChanged` (หลัง commit จาก `Personnel::updated` ทุกทาง) + listener `SyncAccountLifecycle` (Mover แจ้งเจ้าของบัญชี · engine ล้าง cache ตั้งแต่ R-04) · job รายวัน `accounts:reconcile` idempotent: JML ซ้ำทุกบุคลากร · ปลดล็อกที่ครบเวลา · ระงับบัญชีไม่เข้าใช้ 90 วัน · ปิด grant/override หมดอายุ · ปิดรอบทบทวนที่เลยกำหนด · `users.status_reason` บอกผู้ตั้งสถานะ — JML ไม่ปลดการระงับของผู้ดูแล (ADR-R-021) |
| R05-2 MFA TOTP | `pragmarx/google2fa` 9.1.0 (ADR-R-019) · ตั้งค่า/ยืนยัน/รหัสสำรอง 8 รหัสใช้ครั้งเดียว · กันใช้รหัสเดิมซ้ำ · `MfaPolicy` บังคับ L1–L3 (บริหาร/รักษาการที่มีผลวันนี้), ชุดสิทธิ์ที่ manifest ประกาศ `mfa` (งานบุคคล, ผู้ดูแลระบบ), break-glass · ไม่ออก token ก่อนผ่าน MFA · `EnsureMfaSatisfied` ตรวจทุก request (ADR-R-020) |
| R05-3 login events + ล็อก + อุปกรณ์ใหม่ | `login_events` ทุกผล (success, bad_password, locked, mfa_failed, new_device, blocked) · เลข 13 หลักถูกปิดบังใน log · ล็อก 30 นาทีหลังผิด 5 ครั้ง (นับรหัส MFA ผิดด้วย · ตอบ 423) · อีเมลแจ้งอุปกรณ์ใหม่ (`known_devices` เก็บ hash) · ใช้ break-glass แจ้ง ผอ. และผู้ตรวจสอบทุกครั้ง · บัญชีระบบห้าม interactive login · token ใช้ได้เฉพาะบัญชี active |
| R05-4 role grants + overrides API | `AccessGrantService` + `GrantController`: grant_ceiling (ตอนยื่นและตอนยืนยัน) · two_person ผ่าน `permission_change_requests` · maker_checker · not_own (ทั้งผู้มอบและผู้ยืนยัน) · วันหมดอายุบังคับ · มอบได้เฉพาะชุดสิทธิ์ functional · audit ใน `permission_audit_logs` (ADR-R-022) |
| R05-5 access reviews | `AccessReviewService` + `AccessReviewController`: ออกรอบ (role grant + override allow ที่มีผลทุกรายการ) · หัวหน้าหน่วยยืนยัน/ถอนตาม scope ของ `access.review.confirm` (not_own) · ปิดรอบ/เลยกำหนด → ถอนอัตโนมัติ |
| R05-6 หน้า 1 และหน้า 7 | `LoginView` (เข้าสู่ระบบ → MFA → ตั้ง MFA ครั้งแรก → รหัสสำรอง) · `AccountsView` `/access/accounts` (redirect จาก `/admin/users`) ตามต้นแบบผืนผ้าใบ "ระบบบุคลากร DMTC" |
| R05-7 เทสต์ + รายงาน | เทสต์ใหม่ 59 ข้อใน `tests/Feature/Access/` · รายงานนี้ |

## 2. ตรวจตามคำสั่งเฟส

| ข้อกำหนด | สถานะ | หลักฐาน |
|---|---|---|
| ทุก endpoint ใหม่ประกาศ `->permission()` จาก manifest โมดูล access และผ่าน `EnsureRouteUsesPermissionEngine` | ✅ | 26 route ที่ยืนยันตัวตนใช้ `access.self.security`, `access.account.view/manage`, `access.grant.manage`, `access.change.approve`, `access.review.confirm/manage` (`routes/api.php`) · ไม่เพิ่มอะไรใน `SELF_SERVICE` · endpoint ก่อนยืนยันตัวตน 5 ตัว (`/login`, `/login/mfa`, `/login/mfa/setup`, `/login/mfa/setup/confirm`, `/auth/options`) เป็นสาธารณะพร้อม throttle และอยู่ในรายการของ `RouteAuthorizationAuditTest` |
| หน้าเข้าสู่ระบบรับอีเมลหรือรหัสบุคลากร · ช่องเลขประจำตัวประชาชนแสดงเฉพาะเมื่อ `auth.allow_national_id_login` เปิด | ✅ | หน้าเว็บถาม `GET /api/auth/options` แล้วแสดงตัวเลือก "เลขประจำตัวประชาชน" เฉพาะเมื่อเปิด · server ปฏิเสธเลข 13 หลักเมื่อปิดอยู่แล้ว (`LoginSecurityTest::test_login_by_personnel_code_and_national_id_follows_adr_r013`) |
| หน้าบัญชีผู้ใช้แสดงข้อมูลตาม scope ที่ engine อนุญาต ไม่ซ่อนด้วย frontend อย่างเดียว | ✅ | รายการและจำนวนตามสถานะมาจาก `PermissionEngine::scopeQuery` (deny จำกัดหน่วยตัดทั้งรายการและจำนวน) · ปุ่ม "จัดการ" แสดงตาม `abilities` ที่ engine คำนวณต่อแถว และ endpoint จัดการตรวจซ้ำทุกครั้ง (`AccountsApiTest::test_list_is_filtered_by_the_engine_scope_including_unit_denies`, `test_read_only_viewers_see_the_list_without_manage_rights`) |
| เลือกไลบรารี TOTP + ADR | ✅ | ADR-R-019 |
| เทสต์: ทุกเหตุการณ์ JML | ✅ | `AccountLifecycleTest` 10 ข้อ: pending → บัญชีรอเปิด · active → เปิด + ลิงก์ตั้งรหัสผ่าน · ไม่มีอีเมล → รายงาน · ลาศึกษา/พักราชการ → ระงับ + ตัด token · กลับมา → เปิด · ระงับโดยผู้ดูแลไม่ถูกปลด · พ้นสภาพ → ปิดทุกอย่างและแจ้งงานบุคคล · ซ้ำได้ · กลับเข้ารับราชการได้รหัสผ่านใหม่ · Mover · ไม่เข้าใช้ 90 วัน · ปลดล็อก/ปิด grant หมดอายุ |
| เทสต์: MFA บังคับถูกกลุ่ม (L1–L3, งานบุคคล, ผู้ดูแลระบบ, break-glass) | ✅ | `MfaTest::test_mfa_is_required_for_l1_to_l3_hr_admin_and_break_glass_only` (9 persona + break-glass · L4, L5, ผู้ตรวจสอบไม่บังคับ) · `test_acting_l1_only_requires_mfa_during_the_order` |
| เทสต์: บัญชี separated ใช้ token เดิมไม่ได้ | ✅ | `AccountLifecycleTest::test_leaver_closes_everything_in_one_step_and_notifies_hr` (token ถูกลบ + 401 + login ไม่ได้) · `LoginSecurityTest::test_separated_account_cannot_use_an_old_token_even_if_the_row_survives` (แม้แถว token ยังอยู่) |
| เทสต์: ให้สิทธิ์เกินที่ตนมีไม่ได้ | ✅ | `AccessGrantApiTest::test_cannot_grant_more_than_one_holds`, `test_ceiling_respects_the_granters_unit_scope`, `test_requesters_ceiling_is_rechecked_when_the_request_is_confirmed` |
| เทสต์: สิทธิ์ความเสี่ยงสูงต้องผ่านคนที่สอง และผู้ยื่นยืนยันเองไม่ได้ | ✅ | `AccessGrantApiTest::test_high_risk_change_needs_a_second_person_and_the_requester_cannot_confirm_it`, `test_second_person_cannot_confirm_a_request_that_grants_themselves` |

## 3. ไฟล์

| กลุ่ม | ไฟล์ |
|---|---|
| ใหม่ — service | `app/Services/Access/{AccountLifecycleService, AuthenticationService, MfaPolicy, TotpService, DeviceRegistry, AccessGrantService, AccessReviewService, AccessRecipients, PermissionAudit}.php` |
| ใหม่ — HTTP | `app/Http/Controllers/Access/{AccountController, GrantController, AccessReviewController, SecurityController}.php` · `app/Http/Middleware/EnsureMfaSatisfied.php` |
| ใหม่ — อื่น | `app/Events/PersonnelStatusChanged.php` · `app/Listeners/SyncAccountLifecycle.php` · `app/Notifications/AccountNotice.php` · `app/Models/KnownDevice.php` · `app/Console/Commands/AccountsReconcileCommand.php` · migration `2026_10_19_200000_r05_account_security.php` |
| แก้ | `AuthController` (ตรรกะย้ายไป `AuthenticationService` · `/api/user` ส่ง `permission_names` จาก engine) · `User`, `LoginEvent`, `MfaCredential`, `Personnel` (event สถานะ), `AccessReviewItem` (AuthzResource) · `AppServiceProvider` (listener, Sanctum, limiter `mfa`) · `bootstrap/app.php` · `routes/api.php`, `routes/console.php` (แทน `access:expire-role-grants` ที่ไม่มีอยู่จริง) · `config/auth.php` · `ModuleRegistry` (template `mfa`) · manifest access (`access.self.security`, ชุดสิทธิ์ `access.self_service` L5), personnel/system (`mfa`) · `composer.json/lock` |
| ลบ | `app/Services/AccountSeparationService.php` (อ้างตารางที่ถูกตัดใน R-02 — แทนด้วย Leaver ใน `AccountLifecycleService`) |
| frontend | `views/LoginView.vue` (เขียนใหม่ · ลบรายชื่อบัญชีทดสอบและรหัสผ่านที่เคยแสดงบนหน้า) · `views/access/AccountsView.vue` · `components/auth/OtpInput.vue` · `stores/auth.js` (ขั้น MFA, รหัสอุปกรณ์) · `axios.js` (403 `mfa_required` → เข้าสู่ระบบใหม่) · `router/index.js` · `App.vue` (ลิงก์เมนู) |
| เอกสาร | ADR-R-019 … R-022 · `TASKS.md` (R05 ครบ, Q6 ปิด, Q11 ใหม่, R07-7, R08-leave-2) · `CHANGELOG.md` · `TRACEABILITY-MATRIX.md` (REQ-ACC-01…05) · `CORE-03` (นโยบายที่ทบทวนแล้ว) · `tests/Legacy/README.md` |

## 4. API ใหม่

| Method · URI | permission | หมายเหตุ |
|---|---|---|
| `GET /api/auth/options` | สาธารณะ | `allow_national_id_login`, จำนวนวันจดจำอุปกรณ์ |
| `POST /api/login` | สาธารณะ (throttle) | ตอบ token หรือ `{mfa_required, challenge, enrolled, reasons}` · ล็อก = 423 |
| `POST /api/login/mfa` · `/login/mfa/setup` · `/login/mfa/setup/confirm` | สาธารณะ (throttle + challenge 5 นาที) | ยืนยันรหัส/รหัสสำรอง · ตั้ง MFA ครั้งแรก → token + รหัสสำรอง |
| `GET /api/me/security` · `POST /api/me/password` · `POST /api/me/security/mfa/{setup,confirm,recovery-codes,disable}` · `DELETE /api/me/security/devices/{uuid}` | `access.self.security` (own) | ปิด MFA ไม่ได้ถ้าอยู่ในกลุ่มบังคับ · เปลี่ยนรหัสผ่านต้องใส่รหัสเดิมและตัด token ทั้งหมด |
| `GET /api/access/accounts` · `GET /api/access/accounts/{uuid}/access` | `access.account.view` | รายการตาม scopeQuery + จำนวนตามสถานะ + JML เดือนนี้ + ความคืบหน้าการทบทวน + นโยบาย |
| `POST /api/access/accounts/{uuid}/{suspend,reactivate,unlock,reset-mfa}` | `access.account.manage` | ต้องมีเหตุผล · not_own · audit |
| `POST /api/access/accounts/{uuid}/role-grants` · `/overrides` · `POST /api/access/role-grants/{uuid}/end` · `/overrides/{uuid}/end` · `POST /api/access/change-requests/{uuid}/cancel` | `access.grant.manage` | ความเสี่ยงสูง → 202 + คำขอ |
| `GET /api/access/change-requests` · `POST …/{uuid}/approve` · `…/reject` | `access.change.approve` | maker_checker (engine) + ผู้รับยืนยันเองไม่ได้ |
| `GET /api/access/reviews` · `GET …/{uuid}/items` | `access.review.confirm` หรือ `access.review.manage` | รายการกรองด้วย engine ต่อรายการ |
| `POST /api/access/reviews` · `…/{uuid}/close` | `access.review.manage` | |
| `POST /api/access/reviews/{uuid}/items/{id}/decide` | `access.review.confirm` | นอก scope 404 · ของตนเอง 403 |

## 5. เทสต์และผลตรวจ

| ชุด | ข้อ | ครอบคลุม |
|---|---|---|
| `Access/AccountLifecycleTest` | 10 | JML ทุกเหตุการณ์ · reconcile idempotent · ไม่เข้าใช้ 90 วัน · ปลดล็อก · grant หมดอายุ · audit ไม่มี hash รหัสผ่าน |
| `Access/MfaTest` | 10 | กลุ่มบังคับ · รักษาการเฉพาะช่วงคำสั่ง · ไม่มี token ก่อน MFA · ตั้ง MFA ครั้งแรก · กันรหัสซ้ำ · รหัสสำรองครั้งเดียว · เข้ากลุ่มระหว่างล็อกอิน → 403 · ผิด 5 ครั้งล็อก · ปิด MFA ไม่ได้เมื่อถูกบังคับ · เข้ารหัสที่ฐานข้อมูล |
| `Access/LoginSecurityTest` | 14 | login events · ล็อก 30 นาที · ล้างตัวนับ · ปิดบังเลข 13 หลัก · อุปกรณ์ใหม่/จดจำ 30 วัน · break-glass แจ้ง ผอ.+ผู้ตรวจสอบ (ไม่ซ้ำ) · บัญชีระบบ/ไม่ active · token บัญชี separated · refresh คง MFA · เปลี่ยน/รีเซ็ตรหัสผ่านตัด token · ลืมรหัสผ่านไม่บอกว่ามีบัญชี · ADR-R-013 · logout |
| `Access/AccessGrantApiTest` | 12 | grant_ceiling (สิทธิ์และหน่วย) · not_own · two_person · maker_checker · ผู้รับยืนยันเองไม่ได้ · เพดานตรวจซ้ำตอนยืนยัน · ถอนคำขอ · วันหมดอายุ/เลขคำสั่งบังคับ · deny มีผลทันที · positional มอบไม่ได้ · ถอนมีผลทันที · route ปฏิเสธผู้ไม่มีสิทธิ์ |
| `Access/AccessReviewTest` | 5 | รายการครบ · ยืนยันตาม scope และไม่ใช่ของตนเอง · ถอนมีผลทันที · เลยกำหนดถอนอัตโนมัติ · ผู้ออก/ปิดรอบ |
| `Access/AccountsApiTest` | 8 | รายการ+จำนวน+MFA+ที่มาสิทธิ์ · ผู้อ่านอย่างเดียว · scope ของ engine รวม deny จำกัดหน่วย · ระงับ/เปิด/ปลดล็อก/รีเซ็ต MFA · ไม่จัดการบัญชีตนเอง · ไม่มี endpoint ลบบัญชี · หน้าความปลอดภัยของตนเอง |
| แก้เทสต์เดิม | — | `Rebuild/CoreModelsTest` บัญชีล็อกตอบ 423 (เดิม 422) · `RouteAuthorizationAuditTest` เพิ่ม endpoint สาธารณะ 4 ตัว |

| ตรวจ | ผล |
|---|---|
| `php artisan test` | ✅ **278 tests, 1,711 assertions, 0 failures** ตอนส่งตรวจ (เทสต์ใหม่ R-05: 59 ข้อ / 393 assertions · หลังแก้ไขหลังตรวจดูข้อ 8) (R-04 จบที่ 219/1,264) |
| `vendor/bin/pint --test` | ✅ ผ่าน (658 ไฟล์) |
| `scripts/check-authz-patterns.sh` | ✅ ผ่าน |
| MySQL (`eleave_r02_new`, ไม่แตะฐานจริง) | ✅ migration ขึ้น → ถอย → ขึ้น · `modules:sync` เพิ่มชุดสิทธิ์ L5 `access.self_service` · `accounts:reconcile` รันได้ |
| frontend `vite build` | ✅ ผ่าน |
| ตรวจหน้าจอ | หน้า 1 (ขั้นรหัสผ่านและขั้น MFA) และหน้า 7 ตรวจด้วยเบราว์เซอร์กับ mock API (ไม่ใช้ฐานข้อมูลจริง) ที่ 1440×900 และ 375×812 — ไม่มีเลื่อนแนวนอนทั้งหน้า · กล่อง "จัดการ" อยู่กลางจอ (แก้ margin ที่ Tailwind reset ล้าง) |

## 6. เทสต์ใน `tests/Legacy` ที่ย้าย/ลบ (Q7)

| ไฟล์ | การจัดการ | เทสต์ใหม่ที่ครอบคลุมเจตนาเดิม |
|---|---|---|
| `Feature/AuthTest` | ลบ | `Access/LoginSecurityTest` (เข้าสู่ระบบ/รหัสผิด/ออกจากระบบ/token หมดอายุ) · `Rebuild/CoreModelsTest`, `Rebuild/DenyByDefaultTest` (`/api/user`, ไม่ยืนยันตัวตน 401) |
| `Feature/AuthenticationHardeningTest` | ลบ | `Access/LoginSecurityTest` (ล็อก 5 ครั้ง, refresh หมุน token, เปลี่ยน/รีเซ็ตรหัสผ่านตัด token, ลืมรหัสผ่านไม่บอกว่ามีบัญชี) · `Access/AccountsApiTest` (ระงับบัญชีตัด token) |
| `Feature/UserAccountGovernanceTest` | ลบ | `Access/AccountsApiTest` (ระงับมี audit และตัด API · จัดการบัญชีตนเองไม่ได้ · ไม่มี endpoint ลบบัญชี) · `Access/AccountLifecycleTest` (สร้างบัญชีมี audit ไม่มี hash) · `Access/AccessGrantApiTest` (เปลี่ยนสิทธิ์มี audit) · `Access/LoginSecurityTest` (เปลี่ยนรหัสผ่านต้องใส่รหัสเดิม) |
| `Feature/PersonnelApiAlignmentTest` | ลบ (อ้าง `AccountSeparationService` และ `/admin/users` เดิม) | `Access/MfaTest::test_user_outside_required_groups_logs_in_without_mfa` (payload บุคลากร) · `Access/AccountsApiTest` (รายการบัญชีพร้อมรหัสบุคลากร) |
| `Unit/UserTest` | ลบ (ชื่อ/แผนก/role ของ User เดิมถูกตัด — ชื่ออยู่ที่ Personnel) | `Rebuild/CoreModelsTest` (User ↔ Personnel) |
| `Feature/RateLimitBucketIsolationTest` | คงไว้ ย้ายเฟสเป็น R-08 leave | ทดสอบถังจำกัดอัตราของเอกสารใบลา/รายงานซึ่งยังเป็นโมดูลเดิม |

`tests/Legacy` เหลือไฟล์ `.php` 63 ไฟล์ (ก่อน R-05: 68)

## 7. ข้อจำกัด งานที่ส่งต่อ และสิ่งที่ต้องให้เจ้าของระบบตัดสิน

1. ~~Q11 การมอบชุดสิทธิ์ครั้งแรก~~ — ตัดสินแล้ว: `access:bootstrap` (ข้อ 8, ADR-R-023)
2. หน้า 7 อยู่ในแถบเมนูของระบบเดิม (ยังไม่ใช่แถบเมนูของต้นแบบ) จนกว่า R-06 จะเปลี่ยนโครงหน้าเป็นเมนูจาก `/api/me/menu` · ปุ่ม "สร้างบัญชีระบบ / สร้างบัญชีบุคคลภายนอก" และลิงก์ไปหน้า 8 ยังไม่แสดง (ต้องมี API สร้างบัญชีระบบและหน้ารายละเอียดใน R-07) · ปุ่ม "แก้ไขนโยบาย" ไม่แสดง (นโยบายอยู่ใน config)
3. หน้า 1 ไม่มีปุ่ม "เข้าสู่ระบบด้วยบัญชีอีเมลวิทยาลัย (SSO)" เพราะยังไม่มี SSO ในขอบเขต · "จดจำอุปกรณ์นี้ 30 วัน" ไม่ข้าม MFA (ADR-R-020)
4. หน้าจอคำขอเปลี่ยนสิทธิ์ ทบทวนสิทธิ์ และความปลอดภัยของฉัน (`/me/security`) — API พร้อม หน้าจออยู่ใน R07-4 และ R07-7 (ต้นแบบไม่มีหน้าเหล่านี้)
5. "ส่งงานค้างให้งานบุคคล" ของ Leaver = อีเมลแจ้งงานบุคคลพร้อมจำนวนคำขอแก้ไขและใบลาที่ค้าง · การโอนงานอนุมัติที่ค้างในสายเป็นงานของ workflow (`R08-leave-2`)
6. บัญชี break-glass และบัญชีระบบไม่มีตำแหน่งหลักจึงไม่ได้ `access.self.security` — break-glass ตั้ง MFA ผ่านขั้นตอนเข้าสู่ระบบ
7. อีเมลทุกฉบับส่งผ่านคิว (`AccountNotice` · ShouldQueue) — ต้องมี worker และ SMTP ที่ตั้งค่าแล้วจึงถึงผู้รับ
8. เทสต์ที่ใช้ `Sanctum::actingAs` ผ่านการตรวจ MFA ได้เพราะ `tests/TestCase` ตั้ง `auth.mfa.allow_transient_in_tests` อย่างชัดแจ้ง (มีผลเฉพาะ `runningUnitTests()`) — `MfaTest` ปิดค่านี้เพื่อตรวจการบังคับจริง

## 8. แก้ไขหลังตรวจ (ผลตรวจ PR #34)

| # | ประเด็น | การแก้ | เทสต์ |
|---|---|---|---|
| 1 | `EnsureMfaSatisfied` เป็น fail-open — token ที่ไม่ใช่ `PersonalAccessToken` ที่มีแถว (TransientToken จาก session ของ guard web) ผ่านโดยไม่ตรวจ MFA | fail-closed: ผู้ที่ MfaPolicy บังคับผ่านได้เฉพาะ token `session:mfa` ที่มีแถวจริง ทางอื่นทุกทางได้ 403 `mfa_required` · ข้อยกเว้นเฉพาะ `app()->runningUnitTests()` และ `config('auth.mfa.allow_transient_in_tests') === true` ที่เทสต์ตั้งเอง (ค่าใน `config/auth.php` เป็น false และไม่อ่านจาก env) · `tests/TestCase` ตั้งค่านี้ให้เทสต์สิทธิ์ที่ใช้ `Sanctum::actingAs` โดยชัดแจ้ง | `MfaTest::test_mfa_check_is_fail_closed_for_every_way_of_authenticating` — session ของ guard web 403 · token จำลองเมื่อปิดข้อยกเว้น 403 · token ที่ไม่ผ่าน MFA 403 · `session:mfa` จริง 200 · ผู้ไม่อยู่ในกลุ่มบังคับใช้ session ได้ |
| 2 | `resetMfa` ไม่แจ้งเจ้าของบัญชี | แจ้ง `AccountNotice` (`mfa_reset`) ทุกครั้งพร้อมเวลา ผู้ยื่น และผู้ยืนยัน · บันทึก `login_events` (`mfa_reset`) + audit · บัญชีที่ MfaPolicy บังคับ → `POST …/reset-mfa` ตอบ 202 และสร้าง `permission_change_requests` (diff `account.mfa_reset`) · ยืนยันด้วย `access.change.approve` ผู้ยื่นและเจ้าของบัญชียืนยันเองไม่ได้ · ตรวจซ้ำว่าผู้ยื่นยังมี `access.account.manage` | `AccountsApiTest::test_mfa_reset_of_an_ordinary_account_is_immediate_and_the_owner_is_told` · `test_mfa_reset_of_an_mfa_required_account_needs_a_second_person` |
| 3 | Q11 ตัดสินแล้ว | `php artisan access:bootstrap {template} {user} --order-number= --expires=` เฉพาะ `system.admin`/`personnel.hr_officer` เมื่อไม่มีผู้ถือที่มีผล · หมดอายุไม่เกิน 90 วัน · บัญชี active ไม่ใช่บัญชีระบบ · ล้าง MFA/token → ตั้ง MFA ตอนเข้าสู่ระบบครั้งแรก · `permission_audit_logs` (`role_grant.bootstrapped`) · แจ้ง L1 ทุกครั้ง · ไม่มี API/หน้าจอ · migration ให้ `role_grants.granted_by` เป็น null ได้ (null = คำสั่งนี้) · ADR-R-023 · ปิด Q11 ใน `TASKS.md` | `AccessBootstrapTest` 4 ข้อ — ใช้ได้ครั้งแรก (audit, แจ้ง ผอ., บังคับตั้ง MFA) และใช้ซ้ำถูกปฏิเสธ · มีผู้ถือที่มีผลถูกปฏิเสธ/ผู้ถือหมดอายุหรือบัญชีไม่ active ไม่กีดขวาง · ไม่มีเลขคำสั่ง/ไม่มีหรือเกิน 90 วัน/วันนี้/รูปแบบผิด/ชุดสิทธิ์อื่น/บัญชีไม่ active ถูกปฏิเสธ (90 วันพอดีผ่าน) |

ผลตรวจหลังแก้: `php artisan test` **285 tests, 1,764 assertions** ผ่าน · `pint --test` ผ่าน (661 ไฟล์) · `scripts/check-authz-patterns.sh` ผ่าน · migration ใหม่ ขึ้น → ถอย → ขึ้น บน MySQL scratch (`eleave_r02_new`) ผ่าน — `granted_by` เป็น null ได้และ FK ยังอยู่

**หยุดรอคำสั่ง**
