# LEGACY-10 — Notification Center

**วันที่:** 15 สิงหาคม 2569 (2026-08-15)

**ต่อจาก:** LEGACY-01 Queue Worker, LEGACY-04 Permission + Scope, LEGACY-07 Leave, LEGACY-08 Attendance, LEGACY-09 Overtime

**ผล:** **LEGACY-10 = PASS**

## 1. ผลลัพธ์

สร้าง Notification Center กลางแบบ additive โดย business module ส่งเพียง domain event และศูนย์กลางเป็นผู้เลือกผู้รับ, template, preference, channel, delivery status และ retry เอง

```text
Business event
  └─ notification_events
      └─ notification_recipients (read/unread)
          └─ notification_deliveries (channel/status/retry)
              └─ In-App | Email | LINE OA | Telegram adapter
```

ไม่มีการเปลี่ยน schema/approval workflow เดิม และไม่ลบตาราง `notifications` เดิม

## 2. Schema

Migration `2026_08_15_220000_create_notification_center.php` เพิ่ม:

| ตาราง | หน้าที่ |
|---|---|
| `notification_types` | รหัส event, module, default channels, mandatory flag |
| `notification_templates` | template แยก channel แบบ versioned/effective-dated |
| `notification_events` | event กลาง, actor, related resource, payload ที่ผ่าน sanitizer |
| `notification_recipients` | ผู้รับรายบุคคลและ read/unread |
| `notification_deliveries` | สถานะส่ง, attempt, next attempt, provider id/error |
| `notification_preferences` | เปิด/ปิด channel แบบ global หรือราย notification type |

Seed 10 types และ 40 templates: leave submitted/approval requested/approved/rejected/returned, attendance correction/late/early leave, OT approval และ saraban task

## 3. Channel และ retry

- `in_app`: สร้าง recipient ledger และอ่านผ่าน endpoint เดิม `/api/notifications`
- `email`: ส่งผ่าน Laravel Mail adapter
- `line_oa`: adapter ใช้ LINE Messaging API service เดียว; ถ้าไม่มี token หรือ `line_user_id` จะเป็น `skipped`
- `telegram`: adapter ใช้ Telegram service เดียว; ถ้าไม่มี token หรือ chat id จะเป็น `skipped`
- provider error ไม่ throw กลับไปยัง business transaction; delivery เปลี่ยนเป็น `retrying`
- exponential backoff 1, 2 นาที และจบที่ `failed` เมื่อครบ 3 attempts (dead-letter state ใน delivery ledger)
- channel หนึ่งล้มไม่ rollback event/recipient หรือ channel อื่น

`TelegramNotificationService` เดิมคงไว้เป็น compatibility proxy ไป `TelegramService`; ไม่มี provider implementation ซ้ำอีก

## 4. Permission, scope และข้อมูลอ่อนไหว

- notification list/read จำกัดด้วย `notification_recipients.user_id` จากผู้ใช้ที่ login; IDOR คืน 404
- mark-all เปลี่ยนเฉพาะของเจ้าของ รวม legacy notifications เดิม
- Attendance และ OT คัดผู้รับด้วย permission + org/personnel scope ก่อน emit
- Saraban คัดผู้รับด้วย permission/รายชื่อผู้รับหนังสือเวียนเดิม
- sanitizer ตัด password, token, secret, API key, national/citizen ID, เหตุผลลา, medical detail, attachment และ document content
- template ที่ seed เป็นข้อความสถานะขั้นต่ำ ไม่ใส่เหตุผลลา เนื้อหาเอกสาร หรือข้อมูลชีวมิติ
- การแก้ preference ถูกบันทึก central audit

## 5. Compatibility

- frontend contract เดิม `{id,type,data,read_at,created_at}` คงเดิม
- เพิ่ม route ที่ frontend เรียกอยู่แล้วแต่เดิมขาด: `PUT /api/notifications/{uuid}/read`
- `/api/notifications` dual-read ทั้ง Notification Center และ Laravel `notifications` เดิม จึงไม่ทำให้ประวัติหาย
- mark read/mark all รองรับทั้ง ledger ใหม่และข้อมูลเดิม
- endpoint/module workflow เดิมไม่เปลี่ยน
- business flows ที่ย้ายเข้า center: Leave request/status/governance, Attendance late/early/correction, OT order approval และ Saraban workflow/circular
- การทดสอบส่งจากหน้า Secure Settings ยังเรียก provider ตรงโดยเจตนา เพราะเป็น diagnostic endpoint ไม่ใช่ business notification

## 6. Queue backlog และ migration

- ก่อน cutover ตรวจฐานจริง: `jobs = 0`, `failed_jobs = 0` จึงไม่มีรายการย้อนหลังให้ตัดสินใจส่ง
- MySQL 8 migration ผ่าน: 10 types, 40 templates, 0 events/deliveries เริ่มต้น
- migration เป็น additive ล้วนและไม่ backfill/ลบข้อมูลเดิม
- DDL ใช้เวลาประมาณ **1 นาที 32 วินาที** ใน Docker environment นี้ จึงควรรันนอกช่วงใช้งานแม้ตารางใหม่ทั้งหมด

## 7. Tests

`NotificationCenterTest` ผ่าน **7 tests / 44 assertions** ครอบคลุม type/template, recipient dedup, sensitive filtering, In-App contract, IDOR, legacy compatibility, preferences, Email, missing config และ bounded retry

Regression เฉพาะ Leave/Attendance/OT/Saraban ผ่าน **63 tests / 154 assertions**

| Gate | ผล |
|---|---|
| MySQL 8 migration | PASS |
| Full backend suite | **442 passed / 1,140 assertions** |
| Pint | **PASS / 432 files** |
| Frontend production build | **PASS / 879 modules** |
| Direct provider scan | เหลือเฉพาะ adapters, provider services และ Secure Settings diagnostic |

## 8. จุดเสี่ยง/การใช้งานจริง

- ต้อง monitor worker, `retrying`, `failed` และ latency ของ provider หลัง deploy
- LINE OA/Telegram จะไม่ส่งจนกว่าจะมี credential และ recipient mapping; การไม่มี config ไม่ถือเป็น failure ของ business flow
- user preference API พร้อมแล้ว แต่ยังไม่มีหน้าจอจัดการละเอียดราย type; profile channel เดิมยังคงอยู่เพื่อ compatibility
- ยังไม่ลบ Notification classes/table เดิม เพื่อ rollback และอ่านประวัติเดิม
- การเพิ่ม escalation policy หรือ scheduled reminder เฉพาะเหตุการณ์ต้องมี requirement เรื่องผู้รับและ SLA ก่อน จึงยังไม่เดาเพิ่ม

## 9. สรุป

**PASS แล้วหยุด** — Notification Center เป็นทางส่ง business notification กลางแล้ว รองรับ In-App/Email จริง, เตรียม LINE OA/Telegram แบบ config-gated, มี preference, delivery ledger, retry/read scope และรักษาข้อมูล/response เดิม
