# INT-14 — NOTIFICATION PROVIDERS

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-14 (ROADMAP บรรทัดที่ 15)
**วันที่:** 2026-09-05
**สถานะ:** ✅ ผ่าน Definition of Done

---

## DONE

SoT §16 บังคับสามอย่างที่ระบบเดิม **มีไม่ครบ**: ช่องทาง `WEB_PUSH`, **outbox** ที่กู้
งานที่หายได้ และ **dead-letter** ที่มองเห็นและสั่งส่งใหม่ได้

| ข้อบังคับ SoT §16 | ก่อน INT-14 | หลัง INT-14 |
|---|---|---|
| ใช้ Core Notification ถ้ามี | ✅ | ✅ ไม่มีชั้นแจ้งเตือนของสารบรรณเลย |
| IN_APP / EMAIL / LINE_OA / TELEGRAM | ✅ | ✅ ไม่แตะของเดิม |
| **WEB_PUSH** | ❌ | ✅ พร้อม service worker และการสมัครต่อเครื่อง |
| MOBILE_PUSH_READY | — | ✅ สัญญา adapter รองรับได้ทันที (ดูหมายเหตุ) |
| queue / retry | ✅ | ✅ ของเดิม |
| **outbox** | ⚠️ มีตาราง แต่ไม่มีตัวกวาด | ✅ `notifications:dispatch-outbox` ทุก 5 นาที |
| **dead-letter** | ❌ ค้างที่ `failed` เงียบ ๆ | ✅ `dead_lettered_at` + หน้าจัดการ + สั่งส่งใหม่ |
| templates / preferences | ✅ | ✅ เพิ่ม template ของช่องทางใหม่ครบทุกชนิด |
| **delivery tracking** | ⚠️ มีข้อมูล ไม่มีที่ดู | ✅ `channel-health` สรุปผลส่งจริง 24 ชม. |
| ธุรกรรมห้าม rollback เพราะ provider ล้ม | ✅ | ✅ INT-13 ตรึงไว้แล้ว |

### 1. ทำไมตารางเดลิเวอรีเดิม "ยังไม่ใช่ outbox"

ศูนย์แจ้งเตือนสร้างแถวแล้ว dispatch job ทันที ซึ่งใช้ได้ตราบใดที่ job ไม่หาย —
แต่ job หายได้จริงหลายทาง: worker ถูกฆ่าระหว่างทำงาน, Redis ถูกล้าง, deploy
ที่ตัดคิวทิ้ง, หรือ retry ที่ตั้งเวลาไว้ข้ามช่วงที่ระบบดับพอดี **แถวที่ค้างสถานะ
`pending`/`retrying` จะไม่มีวันถูกส่ง และไม่มีใครรู้ว่ามันมีอยู่**

ตัวกวาดเทียบ "แถวที่ควรถูกส่งไปแล้ว" กับ "แถวที่ถูกส่งจริง" ทุก 5 นาที
โดยข้ามแถวที่เพิ่งสร้าง (ค่าเริ่มต้น 5 นาที) เพื่อไม่ชนกับ job แรกที่กำลังจะทำงาน
ปลอดภัยเมื่อรันซ้ำ เพราะ `deliver()` ตรวจสถานะก่อนส่งอยู่แล้ว

### 2. dead-letter — จาก "ล้มเหลวแล้วเงียบ" เป็น "ล้มเหลวแล้วกู้ได้"

เดิมข้อความที่ครบจำนวนครั้งจะค้างที่ `failed` เฉย ๆ · ตอนนี้ประทับ `dead_lettered_at`
ทำให้ **ค้นเจอ นับได้ และสั่งส่งใหม่ได้** พร้อมบันทึก audit ว่าใครสั่ง
การสั่งซ้ำรีเซ็ตจำนวนครั้งเป็นศูนย์ เพราะเป็นการตัดสินใจของคนหลังแก้ต้นเหตุแล้ว
ไม่ใช่ retry อัตโนมัติของรอบเดิม

migration ย้อนหลังให้ข้อความที่ `failed` อยู่ก่อนแล้วปรากฏใน dead-letter ด้วย
ไม่งั้นของเก่าจะหายไปจากสายตาตลอดกาล

### 3. Web Push — ใช้ไลบรารีมาตรฐาน ไม่เขียนการเข้ารหัสเอง

Web Push บังคับให้เข้ารหัส payload ด้วย **ECDH P-256 + HKDF + AES-128-GCM**
และเซ็น JWT ด้วย **ES256** · เขียนเองผิดจุดเดียวจะส่งไม่ถึงแบบเงียบ ๆ หรือแย่กว่านั้น
คือ "เข้ารหัส" ที่ไม่ปลอดภัยจริง

**หลักการ REUSE > CREATE ใช้กับไลบรารีภายนอกด้วย** จึงเพิ่ม `minishlink/web-push`
ซึ่งเป็นไลบรารีมาตรฐานของ PHP สำหรับงานนี้

> **ข้อแลกเปลี่ยนที่ต้องระบุ:** เป็น dependency ตัวที่สองของโครงการทั้งหมด
> และดึงแพ็กเกจย่อยมา 8 ตัว (jwt-library, pki-framework, httplug ฯลฯ)
> ผมชั่งกับทางเลือกคือเขียน crypto เอง ~120 บรรทัด แล้วเลือกไลบรารี
> เพราะการเขียน crypto เองผิดพลาดแบบเงียบได้ และไม่มีใครในทีมจะตรวจทานมันซ้ำ
> **ถ้าผู้ดูแลไม่ยอมรับ dependency นี้ ทางออกคือปิดช่องทาง `web_push`** —
> ทุกช่องทางเป็นอิสระต่อกัน ระบบที่เหลือทำงานได้ครบโดยไม่ต้องมีมัน

**การออกแบบที่ตั้งใจ**

- **หนึ่งแถว = หนึ่งเบราว์เซอร์บนหนึ่งเครื่อง** ไม่ใช่หนึ่งคน — คนหนึ่งเปิดที่เครื่องทำงาน
  แต่ปิดที่มือถือได้ ปุ่มบนหน้าจอจึงเขียนว่า "บนเครื่องนี้" เสมอ
- **ถึงเครื่องเดียวก็นับว่าสำเร็จ** — เครื่องที่ปิดทิ้งไว้ที่บ้านไม่ควรทำให้การแจ้งเตือน
  ที่ถึงมือถือในกระเป๋าแล้วถูกนับเป็นล้มเหลวแล้วไปกอง dead-letter
- **ปลายทางที่หมดอายุถูกลบทิ้งทันที** เมื่อ push service ตอบว่าหมดอายุ
  ไม่งั้นทุกการแจ้งเตือนจะพยายามส่งไปยังปลายทางที่ตายแล้วครบจำนวนครั้งเสมอ
- **กุญแจ VAPID อยู่ใน env ไม่ใช่ตาราง `settings`** ต่างจาก token ของ LINE/Telegram
  เพราะกุญแจส่วนตัวที่หลุดออกไปทำให้ปลอมการแจ้งเตือนถึงผู้ใช้ทุกคนได้
- **service worker ไม่ทำ cache ใด ๆ** — ระบบนี้ไม่ได้ออกแบบให้ทำงานออฟไลน์
  การแคชหน้าเว็บราชการโดยไม่ตั้งใจทำให้ผู้ใช้เห็นข้อมูลเก่าโดยไม่รู้ตัว

### 4. หมายเหตุเรื่อง MOBILE_PUSH_READY

SoT เขียนว่า **"MOBILE_PUSH_READY"** คือ *พร้อมรองรับ* ไม่ใช่ *ต้องมี* ·
ผมจึง **ไม่ใส่ชื่อช่องทาง `mobile_push` ที่ยังไม่มี adapter** เพราะจะสร้างแถว
สถานะ `skipped` ให้ผู้รับทุกคนทุกครั้งโดยไม่มีประโยชน์ · ความพร้อมพิสูจน์ด้วย
การที่ INT-14 เพิ่มช่องทางใหม่ทั้งช่องทางได้โดย **ไม่แตะ `NotificationCenter`
หรือ `NotificationDeliveryService` เลย** — เพิ่ม adapter หนึ่งคลาสและลงทะเบียน
หนึ่งบรรทัดเท่านั้น

---

## CHANGED

| ประเภท | รายการ |
|---|---|
| CREATE | `WebPushGateway`, `WebPushChannelAdapter`, `WebPushSubscription`, `WebPushSubscriptionController`, `NotificationOperationsController`, 2 artisan command, `sw-push.js`, `WebPushToggle.vue` |
| EXTEND | `NotificationCenter::CHANNELS`, `NotificationDeliveryService::recordFailure()`, `NotificationDelivery`, `NotificationModuleServiceProvider`, `config/services.php`, `routes/console.php`, `UserProfileView.vue` |
| REUSE | `ChannelAdapterRegistry`, `NotificationDeliveryService`, `TemplateRenderer`, `DeliverNotification`, `AuditLogger`, `ModuleRegistry`, `AppButton` |
| MODIFY Core | **ไม่มีการเปลี่ยนพฤติกรรมของช่องทางเดิม** — ของเดิมทั้ง 4 ช่องทางไม่ถูกแตะ |
| DEPENDENCY | **+`minishlink/web-push` ^11.0** (ดูข้อแลกเปลี่ยนในหัวข้อ DONE ข้อ 3) |

---

## FILES

**Backend**
- `database/migrations/2026_09_06_100000_create_web_push_and_dead_letter.php`
- `app/Models/NotificationCenter/WebPushSubscription.php`
- `app/Services/NotificationCenter/Providers/WebPushGateway.php`
- `app/Services/NotificationCenter/Adapters/WebPushChannelAdapter.php`
- `app/Http/Controllers/NotificationCenter/WebPushSubscriptionController.php`
- `app/Http/Controllers/NotificationCenter/NotificationOperationsController.php`
- `app/Console/Commands/DispatchNotificationOutboxCommand.php`
- `app/Console/Commands/GenerateVapidKeysCommand.php`
- `app/Services/NotificationCenter/NotificationCenter.php`, `NotificationDeliveryService.php` *(แก้ไข)*
- `app/Models/NotificationCenter/NotificationDelivery.php` *(แก้ไข)*
- `app/Providers/Modules/NotificationModuleServiceProvider.php`, `config/services.php`, `routes/api.php`, `routes/console.php` *(แก้ไข)*
- `tests/Feature/NotificationProvidersTest.php` · `tests/Feature/NotificationCenterTest.php` *(แก้ไข)*

**Frontend**
- `public/sw-push.js`
- `src/components/notifications/WebPushToggle.vue`
- `src/views/UserProfileView.vue` *(แก้ไข)*

---

## MIGRATIONS

`2026_09_06_100000_create_web_push_and_dead_letter.php`

| การเปลี่ยนแปลง | หมายเหตุ |
|---|---|
| สร้าง `web_push_subscriptions` | ตารางใหม่ล้วน · `endpoint` unique กันการสมัครซ้ำ |
| `notification_deliveries` +`dead_lettered_at` | nullable ไม่มี default ไม่แตะคอลัมน์เดิม |
| backfill แถวที่ `failed` อยู่แล้ว | ใช้ `COALESCE(last_attempt_at, updated_at)` — ของเก่าต้องไม่หายไปจากสายตา |
| เพิ่ม template ช่องทาง `web_push` | ให้ทุกชนิดการแจ้งเตือน · ข้ามชนิดที่มีอยู่แล้ว (idempotent) |

**Migration safe:** เพิ่มอย่างเดียว ไม่ลบ ไม่แก้ชนิดข้อมูลเดิม
`down()` ถอดครบทั้งสามส่วนตามลำดับที่ถูกต้อง

ตรวจ `migrate` จากฐานว่างบน MySQL 8 ผ่าน — **ใช้ฐานชั่วคราว `eleave_migcheck`
ไม่ใช่ฐานพัฒนา** (แก้พฤติกรรมที่ผิดพลาดไปในรอบ INT-13)

---

## TESTS

`tests/Feature/NotificationProvidersTest.php` — **17 tests / 41 assertions ผ่านทั้งหมด**

**ช่องทาง (10)** — ลงทะเบียนเป็นช่องทางของศูนย์กลางจริง · ทุกชนิดมี template ·
สมัคร/สมัครซ้ำ/ย้ายเจ้าของเครื่องที่ใช้ร่วมกัน · **ถอนแทนคนอื่นไม่ได้** ·
**กุญแจส่วนตัวไม่รั่วทาง API** · รายการเบราว์เซอร์ไม่คืนกุญแจหรือ endpoint ·
ผู้ที่ยังไม่เปิดรับถือว่ายังไม่ตั้งค่า · **ถึงเครื่องเดียวก็สำเร็จ**

**outbox (2)** — กู้ข้อความที่ job หายได้ · ไม่กวาดซ้ำแถวที่เพิ่งสร้าง

**dead-letter (5)** — ครบจำนวนครั้งแล้วเข้า dead-letter · ยังไม่ครบไม่เข้า ·
ผู้ดูแลเห็นและสั่งส่งใหม่ได้พร้อม audit · **ผู้ใช้ทั่วไปเข้าไม่ได้ทั้งดูและสั่ง** ·
สรุปสุขภาพครบทุกช่องทางที่ลงทะเบียน

---

## SECURITY

| ประเด็น | การจัดการ |
|---|---|
| ดักรับการแจ้งเตือนของคนอื่น | ทุก endpoint ผูกกับ `$request->user()` — **ไม่มีพารามิเตอร์ที่ระบุผู้ใช้คนอื่นได้** และมีเทสต์ยืนยันว่าถอนของคนอื่นไม่ได้ |
| กุญแจส่วนตัว VAPID รั่ว | อยู่ใน env ไม่ใช่ฐานข้อมูล · ไม่มีเส้นทางใดส่งออก · มีเทสต์ตรึง |
| กุญแจของเบราว์เซอร์รั่วทาง API | `$hidden` บนโมเดลครอบ `p256dh_key`, `auth_token`, `endpoint` |
| เครื่องที่ใช้ร่วมกันเปลี่ยนคนใช้ | `updateOrCreate` บน endpoint ย้ายเจ้าของตามผู้เรียกล่าสุด — คนใหม่ไม่ได้รับข่าวของคนเก่า |
| ผู้ใช้ทั่วไปเข้าหน้า dead-letter | อยู่ใต้ `can:system.setting.manage` เดิม ไม่สร้าง namespace สิทธิ์ใหม่ |
| สั่งส่งใหม่โดยไม่มีร่องรอย | `notification.dead_letter.requeued` ลง audit กลาง (hash chain) |
| หัวเรื่องหนังสือลับหลุดออกนอกระบบ | `web_push` ไม่ได้อยู่ใน `default_channels` ของชนิดใด ผู้ใช้ต้องเปิดเอง |

---

## REGRESSION

```
php artisan test          →  899 passed, 8 skipped (3309 assertions), 0 failed
vendor/bin/pint --test    →  579 files, 0 style issues
migrate (MySQL 8)         →  ผ่านจากฐานว่าง (ฐานชั่วคราว eleave_migcheck)
Frontend: ESLint ✅ · vue-tsc ✅ · Vitest 76 tests ✅ · production build ✅
```

**สองจุดที่ด่านเดิมจับได้และแก้ที่ต้นเหตุ:**

1. `NotificationCenterTest` ตรึงจำนวน template ไว้ที่ 44 → เป็น 55 (11 ชนิด × 5 ช่องทาง)
   พร้อมคอมเมนต์อธิบายที่มาของตัวเลข ไม่ใช่แก้ตัวเลขเฉย ๆ
2. `dead_lettered_at` ไม่ถูกบันทึกเพราะไม่อยู่ใน `$fillable` ของโมเดล —
   เทสต์จับได้ทันทีว่าคอลัมน์ถูกสร้างแต่ค่าไม่เคยลง

---

## UI-QA

- `WebPushToggle` REUSE `AppButton` เดิม วางในการ์ด "การแจ้งเตือนของฉัน" ที่มีอยู่แล้ว
  ไม่สร้างหน้าใหม่
- ช่องทาง `web_push` ปรากฏในตารางตั้งค่าเดิมโดยอัตโนมัติ เพราะหน้านั้นอ่านรายการ
  ช่องทางจาก API — เพิ่มแค่ป้ายภาษาไทย "เบราว์เซอร์"
- **บอกเหตุผลตรง ๆ เมื่อใช้ไม่ได้** แทนการแสดงปุ่มที่กดแล้วพัง: เบราว์เซอร์ไม่รองรับ /
  ไม่ได้เปิดผ่าน HTTPS / ผู้ดูแลยังไม่ตั้งกุญแจ
- ข้อความบนปุ่มระบุ "บนเครื่องนี้" เสมอ เพื่อไม่ให้เข้าใจผิดว่าเป็นสวิตช์รวมของบัญชี

**ข้อจำกัดที่ต้องระบุ:** ไม่ได้ทดสอบการรับ push จริงบนเบราว์เซอร์ เพราะต้องเข้าสู่ระบบ
(กรอกรหัสผ่าน) และต้องเปิดผ่าน HTTPS · **ผู้ดูแลระบบต้องทดสอบเองตามขั้นตอนนี้**

1. `php artisan notifications:generate-vapid-keys` แล้วใส่สองบรรทัดลง `.env`
2. `php artisan config:clear`
3. เปิดหน้าโปรไฟล์ผ่าน HTTPS → กด "เปิดบนเครื่องนี้" → อนุญาตในเบราว์เซอร์
4. เปิดช่อง "เบราว์เซอร์" ของเหตุการณ์ที่ต้องการในตารางด้านล่าง
5. ทดสอบด้วยการเวียนหนังสือถึงตนเอง (INT-13)

---

## EVIDENCE

- ผลรัน `php artisan test`, `pint --test`, `migrate` บนฐานว่าง และ gates ฝั่งหน้าเว็บ
- `php artisan notifications:generate-vapid-keys` รันได้จริงและออกคู่กุญแจ ES256
- `SarabanCoreContractTest` ยังผ่าน = สารบรรณยังไม่มีชั้นแจ้งเตือนของตัวเอง
- เทสต์ outbox จำลองกรณี job หายจริง (แถวค้าง `pending` + `Queue::fake()`)
  แล้วยืนยันว่าตัวกวาดผลักเข้าคิวใหม่ — ไม่ได้ทดสอบแค่ว่าคำสั่งรันผ่าน

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-38 **(ใหม่)** | dependency ใหม่ `minishlink/web-push` (+8 แพ็กเกจย่อย) | กลาง | ยอมรับโดยชั่งกับการเขียน crypto เอง · **ถอนได้โดยปิดช่องทาง `web_push`** โดยไม่กระทบส่วนอื่น |
| RISK-39 **(ใหม่)** | Web Push ต้องมี HTTPS — ใช้ไม่ได้บน `http://localhost:8001` ที่ทีมใช้พัฒนา | ต่ำ | หน้าจอบอกเหตุผลตรง ๆ · ต้องทดสอบบน staging ที่มี TLS |
| RISK-40 **(ใหม่)** | ตัวกวาด outbox พึ่ง scheduler — ถ้า `schedule:run` ไม่ทำงาน ช่องนี้กลับมาเปิด | กลาง | ควรเฝ้าระวัง scheduler ในศูนย์ปฏิบัติการที่ **INT-17** |
| RISK-41 **(ใหม่)** | dead-letter ยังไม่มีหน้าจอ มีแต่ API | ต่ำ | จะรวมเข้าศูนย์เฝ้าระวังการปฏิบัติการที่ **INT-17** (SoT §20) |
| RISK-34 | ต้องมอบหมาย `saraban.inbox.view` ตอนติดตั้ง | กลาง | ยกมาจาก INT-13 → INT-22 |
| RISK-37 | `migrate` บน MySQL ยังไม่อยู่ใน CI | กลาง | ยกมาจาก INT-13 → INT-20 |
| RISK-21 / 22 / 29 / 30 / 32 / 33 / 35 / 36 | ยกมาจากเช็คพอยต์ก่อนหน้า | — | ไม่มีการเปลี่ยนแปลง |
| SEC-GAP-04 | ยังไม่มี idempotency key | กลาง | → **INT-15 (เช็คพอยต์ถัดไป)** |

**ไม่มี blocker สำหรับ INT-15**

---

## NEXT

**→ INT-15 — API + Webhook + Outbox + MCP-ready**

1. `/api/v1/saraban/...` ตาม SoT §17 พร้อม correlation id และ error contract เดิม
2. **idempotency key** — ปิด SEC-GAP-04 ที่ค้างมาตั้งแต่ INT-00
3. webhook ขาออกพร้อม outbox/retry — **REUSE รูปแบบเดียวกับ INT-14**
4. service account / scope ที่ **ห้าม bypass authority** (SoT §17)
