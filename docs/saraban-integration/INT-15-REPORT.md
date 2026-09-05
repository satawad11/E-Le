# INT-15 — API + WEBHOOK + OUTBOX + MCP-READY

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-15 (ROADMAP บรรทัดที่ 16)
**วันที่:** 2026-09-05
**สถานะ:** ✅ ผ่าน Definition of Done · **ปิด SEC-GAP-04 ที่ค้างมาตั้งแต่ INT-00**

---

## DONE

| ข้อบังคับ SoT §17 | สถานะ |
|---|---|
| ใช้ Core API conventions | ✅ ไม่สร้าง convention ใหม่ |
| `/api/v1/saraban/...` | ✅ **นิยามชุดเดียว ผูกสองที่** ไม่ใช่สำเนาสองชุด |
| reuse auth / ABAC | ✅ `auth:sanctum` + `ability:` + policy ชุดเดิมทั้งหมด |
| reuse errors | ✅ `shouldRenderJsonWhen` เดิม ไม่มี error format ใหม่ |
| reuse **correlation** | ✅ REUSE `AttachRequestContext` ที่มีอยู่แล้ว |
| reuse rate limit | ✅ `throttle:api` เดิม |
| **idempotency** | ✅ **SEC-GAP-04 ปิดแล้ว** |
| **OpenAPI** | ✅ `/api/v1/openapi.json` สร้างจากตาราง route จริง |
| **webhook** | ✅ พร้อม outbox / retry / dead-letter / ลายเซ็น HMAC |
| Service accounts / scopes **ห้าม bypass authority** | ✅ ขอบเขตโทเคนจำกัดได้ แต่ขยายไม่ได้ |
| MCP: ห้าม query DB ตรง | ✅ ทุกเส้นทางผ่าน Auth → ABAC → Service เดิม |

### 1. SEC-GAP-04 — กุญแจกันทำซ้ำ (ปิดแล้ว)

**ปัญหาจริงที่ปิด:** ผู้ใช้กดปุ่ม "ออกเลขหนังสือ" สองครั้งเพราะเน็ตช้า หรือระบบ
ภายนอกส่งซ้ำหลัง timeout — ทั้งสองกรณีทำให้เกิด **เลขหนังสือสองเลขสำหรับเรื่องเดียว**
ซึ่งเป็นความเสียหายที่แก้ย้อนหลังไม่ได้ในงานสารบรรณ

**กติกา**

| กรณี | ผลลัพธ์ |
|---|---|
| กุญแจเดิม + เนื้อเดิม + สำเร็จแล้ว | คืนผลเดิม พร้อม `Idempotent-Replay: true` **ไม่ทำงานซ้ำ** |
| กุญแจเดิม + เนื้อ**ต่าง** | 422 — ผู้เรียกใช้กุญแจผิด ไม่ใช่การส่งซ้ำ |
| กุญแจเดิม + ยังทำงานค้าง | 409 — คำขอซ้อนที่ยิงมาพร้อมกัน |
| คำขอเดิม**ล้มเหลว** | ปล่อยกุญแจคืน ลองใหม่ด้วยกุญแจเดิมได้ |
| ไม่ส่งกุญแจมา | ผ่านตามปกติ — **ไม่บังคับ** เพื่อไม่ให้ผู้เรียกเดิมพังในวันเปิดใช้ |

**สามการตัดสินใจที่สำคัญ**

1. **แข่งกันด้วย unique index ไม่ใช่ check-then-act** — สร้างแถวจองก่อนทำงาน
   ผู้แพ้ได้ `UniqueConstraintViolationException` แล้วไปอ่านแถวที่มีอยู่
   วิธี "เช็คก่อนแล้วค่อยเขียน" มีช่องว่างระหว่างสองคำสั่งที่คำขอพร้อมกันลอดได้
2. **กุญแจเป็นของแต่ละคน** (`unique(key, user_id)`) — ถ้า unique เฉพาะตัวกุญแจ
   ผู้ใช้คนหนึ่งจะทำให้คำขอของอีกคนถูกปฏิเสธได้ด้วยการเดากุญแจ
3. **บันทึกเฉพาะผลที่สำเร็จ (2xx)** — ถ้าจำผลล้มเหลวไว้ ผู้เรียกจะติดอยู่กับ
   ความล้มเหลวนั้นตลอดอายุกุญแจ 24 ชั่วโมง

### 2. พื้นผิว v1 — นิยามชุดเดียว ผูกสองที่

```php
$sarabanRoutes = function () { /* ... นิยามครั้งเดียว ... */ };

Route::prefix('saraban')->group($sarabanRoutes);                        // หน้าเว็บของระบบ
Route::prefix('v1/saraban')->middleware('idempotent')->group($sarabanRoutes);  // ผู้เรียกภายนอก
```

**ไม่คัดลอกรายการ route มาวางซ้ำ** — ถ้าคัดลอก วันหนึ่งสองชุดจะไม่ตรงกันโดยไม่มีใคร
รู้ว่าชุดไหนคือของจริง · มีเทสต์เทียบรายการทั้งสองฝั่งให้ตรงกันเสมอ

เส้นทาง v1 บังคับ `idempotent` เพิ่ม เพราะผู้เรียกภายนอกคือฝ่ายที่ส่งซ้ำหลัง timeout
บ่อยที่สุด · `route_patterns` ของโมดูลครอบทั้งสองแบบ ปิดโมดูลแล้วปิดพร้อมกัน

### 3. MCP-ready — คำอธิบายที่ผิดไม่ได้

`/api/v1/openapi.json` **สร้างจากตาราง route จริง ไม่ใช่เอกสารที่เขียนมือ** ·
บอกว่าแต่ละ endpoint ต้องมีสิทธิ์อะไร และต้องยืนยันตัวตนซ้ำหรือไม่ โดยอ่านจาก
middleware จริงของ route นั้น

เอกสารที่เขียนมือจะไม่ตรงกับความจริงภายในไม่กี่เดือน และไม่มีใครรู้ว่าไม่ตรงตรงไหน
ตัวนี้ผิดไม่ได้เพราะอ่านจากสิ่งเดียวกับที่ระบบใช้ตัดสินจริง

### 4. Service accounts — ขอบเขตจำกัดได้ แต่ขยายไม่ได้

`EnsureAnyAbility` เดิมตัดสินด้วย `Gate` อย่างเดียว **ไม่ดูขอบเขตของโทเคนเลย** —
โทเคนที่ออกมาเพื่ออ่านทะเบียนหนังสือจึงออกเลขหนังสือได้ ถ้าเจ้าของบัญชีมีสิทธิ์นั้น

ตอนนี้ต้องผ่าน **ทั้งสองด่าน**: `Gate` (เจ้าของมีอำนาจไหม) และ `tokenCan`
(โทเคนใบนี้ถูกออกมาให้ทำสิ่งนี้ไหม) — ขอบเขตจึงจำกัดได้แต่ขยายไม่ได้ ตรงตาม SoT §17

> **ผลกระทบต่อของเดิม: ไม่มี** — โทเคนของผู้ใช้ทุกใบในระบบออกด้วยขอบเขต `*`
> (`AuthController`, `ProfileController`) จึงผ่านข้อนี้เสมอ · คำขอที่ไม่ได้มาจาก
> โทเคน (เซสชัน/ชุดทดสอบ) ไม่มีขอบเขตให้ตรวจ จึงข้ามไป · regression 917 เทสต์ยืนยัน

### 5. Webhook — ใช้กติกา outbox ชุดเดียวกับ INT-14

**บันทึกลง outbox ก่อนเสมอ แล้วค่อยส่ง** · ถ้าส่งตรงโดยไม่บันทึก เหตุการณ์ที่ส่ง
ไม่สำเร็จจะหายไปโดยไม่มีร่องรอย และไม่มีทางรู้ว่าปลายทางพลาดอะไรไปบ้าง

- **ลายเซ็น HMAC-SHA256 ครอบ `timestamp + body`** ไม่ใช่ครอบแค่ body —
  เวลาต้องอยู่ในข้อความที่ถูกเซ็นด้วย ไม่งั้นผู้โจมตีแก้เวลาแล้วส่งซ้ำได้ (replay)
- **บังคับ HTTPS** — payload เกี่ยวกับหนังสือราชการ ส่งผ่าน http ธรรมดาเท่ากับ
  เปิดเผยให้ทุกจุดที่ข้อมูลวิ่งผ่าน
- **รายชื่อเหตุการณ์เป็นรายการปิด** — สมัครรับชื่อที่พิมพ์ผิดไม่ได้ ไม่งั้นจะมีคน
  รอข้อความที่ไม่มีวันมาโดยระบบไม่เคยบอกว่าผิด
- **payload มีแค่ข้อเท็จจริงและตัวชี้** — ไม่ส่งสาระสำคัญ ไฟล์แนบ หรือรายชื่อผู้รับ
  ออกนอกระบบ · ปลายทางที่ต้องการรายละเอียดต้องเรียก API กลับเข้ามาด้วยสิทธิ์ของตน
- **ความลับสำหรับเซ็นแสดงครั้งเดียว** — หลังจากนั้นไม่มีเส้นทางใดอ่านได้

---

## CHANGED

| ประเภท | รายการ |
|---|---|
| CREATE | `EnsureIdempotentRequest`, `IdempotencyKey`, `WebhookDispatcher`, `WebhookSender`, `WebhookCatalogue`, `WebhookEndpoint`, `WebhookDelivery`, `DeliverWebhook`, `WebhookEndpointController`, `ApiDescriptorController`, 2 artisan command, `IntegrationsView.vue` |
| EXTEND | `EnsureAnyAbility` (+ขอบเขตโทเคน), `FinalizationService`, `DistributionService`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, `module_registry`, `MenuSeeder`, `router/index.js` |
| REUSE | `AttachRequestContext` (correlation), `AuditLogger`, `TrustedClock`, `auth:sanctum`, `ability:`, `throttle:api`, error handler เดิม, กติกา outbox/dead-letter ของ INT-14, `AppModal`/`AppInput`/`AppBadge`/`LoadingState`/`ErrorState`/`EmptyState` |
| MODIFY Core | `EnsureAnyAbility` — **Core change ที่ 5** · เพิ่มด่าน ไม่ลดด่าน และไม่กระทบโทเคนใบใดที่มีอยู่ |
| DEPENDENCY | **ไม่มีเพิ่ม** |

---

## FILES

**Backend**
- `database/migrations/2026_09_06_110000_create_idempotency_and_webhooks.php`
- `app/Http/Middleware/EnsureIdempotentRequest.php` · `app/Models/IdempotencyKey.php`
- `app/Services/Webhooks/{WebhookDispatcher,WebhookSender,WebhookCatalogue}.php`
- `app/Models/{WebhookEndpoint,WebhookDelivery}.php` · `app/Jobs/DeliverWebhook.php`
- `app/Http/Controllers/Webhooks/WebhookEndpointController.php`
- `app/Http/Controllers/Api/ApiDescriptorController.php`
- `app/Console/Commands/{DispatchWebhookOutboxCommand,PruneIdempotencyKeysCommand}.php`
- `app/Http/Middleware/EnsureAnyAbility.php` *(แก้ไข)*
- `app/Services/Saraban/{FinalizationService,DistributionService}.php` *(แก้ไข)*
- `bootstrap/app.php`, `routes/api.php`, `routes/console.php`, `config/module_registry.php`, `database/seeders/MenuSeeder.php` *(แก้ไข)*
- `tests/Feature/ApiIdempotencyAndWebhookTest.php`

**Frontend**
- `src/views/admin/IntegrationsView.vue` · `src/router/index.js` *(แก้ไข)*

---

## MIGRATIONS

`2026_09_06_110000_create_idempotency_and_webhooks.php` — สามตารางใหม่ล้วน

| ตาราง | หมายเหตุ |
|---|---|
| `idempotency_keys` | `unique(idempotency_key, user_id)` — กุญแจเป็นของแต่ละคน · `request_hash` เทียบเนื้อคำขอ · `expires_at` + คำสั่งล้างรายชั่วโมง |
| `webhook_endpoints` | `events` เป็น JSON · ว่าง = ไม่รับอะไรเลย ไม่ใช่รับทุกอย่าง · `secret` ซ่อนจากทุก response |
| `webhook_deliveries` | โครงเดียวกับ `notification_deliveries` รวม `dead_lettered_at` และ `correlation_id` |

**Migration safe:** สร้างตารางใหม่ล้วน ไม่แตะตารางหรือคอลัมน์เดิม ไม่มี backfill
`down()` ลบลูกก่อนพ่อ

ตรวจ `migrate` จากฐานว่างบน MySQL 8 ผ่าน (ฐานชั่วคราว `eleave_migcheck`)

---

## TESTS

`tests/Feature/ApiIdempotencyAndWebhookTest.php` — **18 tests / 47 assertions ผ่านทั้งหมด**

**พื้นผิว v1 (2)** — รายการ route ของสองพื้นผิว**ตรงกันทุกเส้นทาง** ·
คำอธิบายเครื่องอ่านรายงานสิทธิ์และ step-up ถูกต้องตาม middleware จริง

**idempotency (6)** — คืนผลเดิมโดย**ตัวนับฝั่ง server ไม่เพิ่ม** ·
เนื้อต่างถูกปฏิเสธ · กุญแจแยกตามผู้ใช้ · คำขอที่ล้มเหลวปล่อยกุญแจคืน ·
คำขอที่ไม่ส่งกุญแจไม่ถูกแตะ · กุญแจผิดรูปแบบถูกปฏิเสธไม่ใช่เพิกเฉย

**ขอบเขตโทเคน (2)** — โทเคนแคบเข้าไม่ถึงสิ่งที่อยู่นอกขอบเขต ·
ขอบเขตให้อำนาจที่เจ้าของไม่มีไม่ได้

**webhook (8)** — เขียน outbox ก่อนส่ง · ปลายทางได้เฉพาะที่สมัคร ·
**ลายเซ็นครอบ timestamp+body จริง** · ลองใหม่แล้วเข้า dead-letter ·
ความลับแสดงครั้งเดียว · ปฏิเสธ http · ปฏิเสธชื่อเหตุการณ์ที่ไม่รู้จัก ·
ผู้ใช้ทั่วไปจัดการไม่ได้

---

## SECURITY

| ประเด็น | การจัดการ |
|---|---|
| ทำงานซ้ำจากคำขอเดิม | SEC-GAP-04 ปิดแล้ว — กติกาครบ 5 กรณีตามตารางด้านบน |
| แย่งกุญแจของคนอื่น | unique ผูกกับ `user_id` — เดากุญแจถูกก็ไม่กระทบคนอื่น |
| โทเคนบัญชีบริการทำเกินขอบเขต | ต้องผ่าน `Gate` **และ** `tokenCan` ทั้งคู่ |
| ปลอม webhook | HMAC-SHA256 ครอบ `timestamp + body` — กัน replay ด้วย |
| ดักอ่าน payload ระหว่างทาง | บังคับ `url:https` |
| ข้อมูลหนังสือรั่วออกนอกระบบ | payload มีแค่ข้อเท็จจริงและตัวชี้ · ไม่มีสาระสำคัญ ไฟล์แนบ หรือรายชื่อผู้รับ |
| ความลับสำหรับเซ็นรั่ว | `$hidden` บนโมเดล · แสดงครั้งเดียวตอนสร้าง · มีเทสต์ตรึง |
| เปิดทางข้อมูลออกโดยผู้ไม่มีอำนาจ | อยู่ใต้ `can:system.setting.manage` · ทุกการเปลี่ยนแปลงลง audit |
| ตาราง `idempotency_keys` โตไม่จำกัด | `idempotency:prune` รายชั่วโมง |

---

## REGRESSION

```
php artisan test          →  917 passed, 8 skipped (3356 assertions), 0 failed
vendor/bin/pint --test    →  594 files, 0 style issues
migrate (MySQL 8)         →  ผ่านจากฐานว่าง (ฐานชั่วคราว eleave_migcheck)
Frontend: ESLint ✅ · vue-tsc ✅ · Vitest 76 tests ✅ · production build ✅
```

**regression ผ่านทั้งหมดโดยไม่ต้องแก้เทสต์เดิมแม้แต่ข้อเดียว** — รวมถึง
`RouteAuthorizationAuditTest` (v1 อยู่ใต้ `auth:sanctum` ครบ) และ
`SarabanModuleRegistrationTest` (route pattern ใหม่ไม่ชนกับโมดูลอื่น)

**หมายเหตุ:** ตอนรัน `pint` ผมเผลอรวม `bootstrap/` ทั้งโฟลเดอร์ ทำให้ไฟล์ที่ระบบ
สร้างเองใน `bootstrap/cache/` ถูกจัดรูปแบบ · แก้แล้วด้วย `php artisan package:discover`
และไฟล์เหล่านั้นอยู่ใน `.gitignore` อยู่แล้ว จึงไม่มีผลต่อ repository

---

## UI-QA

- `IntegrationsView` REUSE `AppModal`, `AppInput`, `AppBadge`, `AppButton`,
  `LoadingState`/`ErrorState`/`EmptyState` เดิมทั้งหมด
- อยู่ใต้เมนู "ตั้งค่าระบบ" เดิม พร้อมสิทธิ์ `system.setting.manage`
  (`MenuIntegrityTest` ยืนยันว่า `route_path` ตรงกับ router จริง)
- **ความลับสำหรับเซ็นแสดงเป็นกล่องเตือนสีเหลืองที่เด่นและคัดลอกได้ทันที**
  เพราะแสดงครั้งเดียวและกู้ไม่ได้ — ไม่ใช่ข้อความเล็ก ๆ ที่ผู้ใช้กดผ่านไป
- สถานะการส่งใช้ป้ายสีพร้อมข้อความไทยเสมอ ไม่พึ่งสีอย่างเดียว
- ตารางครอบ `overflow-x-auto` ตาม `UI-COMPATIBILITY-MATRIX`

**ข้อจำกัดที่ต้องระบุ:** ไม่ได้ทำ visual QA ผ่านเบราว์เซอร์จริง (ต้องกรอกรหัสผ่าน)
· **ผู้ดูแลระบบควรตรวจที่** `http://localhost:8001/admin/integrations`

---

## EVIDENCE

- ผลรัน `php artisan test`, `pint --test`, `migrate` บนฐานว่าง และ gates ฝั่งหน้าเว็บ
- `php artisan route:list --path=v1` แสดง endpoint ครบทั้งชุดพร้อม `/api/v1/openapi.json`
- เทสต์ idempotency วัดด้วย **ตัวนับฝั่ง server** ไม่ใช่แค่เทียบ response —
  เป็นหลักฐานว่างานจริงไม่ถูกทำซ้ำ ไม่ใช่แค่คืนค่าเหมือนเดิม
- เทสต์ลายเซ็นคำนวณ HMAC ซ้ำจาก request จริงที่ถูกส่งออก ไม่ได้ตรวจแค่ว่ามี header

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| ~~SEC-GAP-04~~ | ไม่มี idempotency key | — | ✅ **ปิด** |
| RISK-42 **(ใหม่)** | `idempotent` บังคับเฉพาะเส้นทาง `v1` — หน้าเว็บของระบบยังกดซ้ำได้ | กลาง | หน้าเว็บป้องกันด้วยปุ่ม `:disabled` ระหว่างส่งอยู่แล้ว · การเปิด `idempotent` ให้เส้นทางเดิมต้องให้หน้าเว็บส่งกุญแจมาด้วย ซึ่งเป็นงาน UI ที่ควรทำพร้อมกันทั้งระบบ ไม่ใช่ทีละหน้า |
| RISK-43 **(ใหม่)** | ยังไม่มี "บัญชีบริการ" เป็นชนิดผู้ใช้แยก — โทเคนขอบเขตแคบยังผูกกับบัญชีคน | กลาง | ด่านขอบเขตทำงานแล้ว แต่การเพิกถอนสิทธิ์ยังผูกกับคน · ควรมีชนิดบัญชีแยกที่ **INT-16/17** พร้อม Authority Matrix |
| RISK-44 **(ใหม่)** | ยังไม่มีหน้าจอออกโทเคนขอบเขตแคบ — ต้องออกด้วย tinker | ต่ำ | ตามหลังจาก RISK-43 |
| RISK-40 | outbox พึ่ง scheduler | กลาง | ยกมาจาก INT-14 → INT-17 · **ตอนนี้กระทบทั้งการแจ้งเตือนและ webhook** |
| RISK-38 | dependency `minishlink/web-push` | กลาง | ยกมาจาก INT-14 |
| RISK-34 / 37 / 39 / 41 / 21 / 22 / 29 / 30 / 32 / 33 / 35 / 36 | ยกมาจากเช็คพอยต์ก่อนหน้า | — | ไม่เปลี่ยนแปลง |

**ไม่มี blocker สำหรับ INT-16**

---

## NEXT

**→ INT-16 — Policy Center + Authority Matrix**

1. นโยบายกลางแบบมีเวอร์ชัน (SoT §18) — numbering / workflow / signature /
   confidentiality / distribution / SLA / retention ฯลฯ · **versioned / published /
   rollback / audit**
2. Authority Matrix (SoT §6) — ตารางอำนาจหน้าที่ที่ดูได้จากหน้าจอ
3. ปิด **RISK-21** (ตั้งชุดเลขหนังสือ), **RISK-26** (ไม่มีหน้าจอจัดการเวอร์ชันสายงาน),
   **RISK-32** (ตำแหน่ง QR ต่อประเภทหนังสือ)
4. การเผยแพร่นโยบายเป็น sensitive action → ต้องผ่าน `step_up` (SoT §24)
