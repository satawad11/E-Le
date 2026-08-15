# LEGACY-08 — Attendance Integration

**วันที่:** 15 สิงหาคม 2569 (2026-08-15)  
**ต่อจาก:** LEGACY-06 Time Foundation และ LEGACY-07 Leave Modernization  
**ผล:** **LEGACY-08 = PASS**

## 1. ขอบเขตและข้อยกเว้นที่ตั้งใจ

ทำ attendance foundation ที่ไม่ผูกกับเครื่องสแกนยี่ห้อใด รองรับ source type
`manual`, `web`, `device`, `import` และใช้เขตเวลา `Asia/Bangkok` เป็นค่าเริ่มต้น

ไม่ได้สร้าง connector vendor จริง เพราะยังไม่มีคำตอบเรื่องยี่ห้อ/protocol และ
external personnel identifier ตาม Open Question #1/#2 ของ migration plan
งานเฟสนี้จึงเตรียม `AttendanceConnector` และ `BiometricConnector` contracts เท่านั้น
ตามคำสั่งที่ห้ามสร้าง integration โดยไม่มี requirement

## 2. Schema แบบ additive

Migration: `2026_08_15_200000_create_attendance_integration_foundation.php`

| ตาราง | หน้าที่ |
|---|---|
| `attendance_sources` | registry ของ manual/web/device/import, timezone, config, health, cursor |
| `devices` | external device ต่อ source, location, org unit, timezone, metadata |
| `attendance_identity_maps` | external person id ต่อ source ↔ `users.id` |
| `attendance_raw_events` | หลักฐาน event ดิบ, UUID, dedup key, UTC instant, payload, processing result |
| `attendances` | ผลสรุปรายคน/วันเดิม — ไม่ลบ/rename/ปลด unique |
| `attendance_corrections` | คำขอแก้ผล พร้อม before/proposed/reason/decision/apply timestamps |

ไม่เปลี่ยน schema เดิมของ `attendances` และยังคง `unique(user_id, date)` ตาม 08-7

## 3. Raw event integrity และ deduplication

- `event_uuid` unique ทั้งระบบ
- `(attendance_source_id, dedup_key)` unique ต่อ source
- duplicate คืน event เดิมและ reconcile ซ้ำได้โดยไม่สร้างผลเพิ่ม
- payload, event type, timestamp, source, device และ identity แก้ไม่ได้หลังสร้าง
- อนุญาตเฉพาะ processing metadata: `attendance_id`, `processed_at`, `processing_error`
- เก็บ instant เป็น UTC และแปลงด้วย timezone ของ event/source ก่อนหา work date

## 4. Reconciliation service

`AttendanceReconciliationService` เป็นผู้แปลง raw event ไป `attendances` ภายใน
transaction และ lock แถวที่เกี่ยวข้อง รองรับ:

- check-in มาก่อนหรือ checkout มาก่อน (out-of-order)
- event ซ้ำจาก UUID หรือ dedup key
- กะข้ามคืน โดย checkout หลังเที่ยงคืนกลับไป work date ก่อนหน้า
- missing checkout โดยเก็บ partial attendance ที่ valid
- identity mapping เมื่อ event จาก device/import ยังไม่มี `user_id` โดยตรง

Web check-in/out เดิมยังใช้ URI และ JSON response เดิม แต่เขียน raw event ก่อน
reconcile เป็นผลรายวัน

## 5. Authorization, scope และ correction workflow

- POST check-in/check-out บังคับ `attendance.checkin` โดยตรง
- today endpoint ยังยอมรับ permission เดิม `attendance.checkin|attendance.view_self`
- list/export/monthly/yearly/OT summary ใช้ `AttendanceScopeService` ร่วมกับ
  `OrgScopeResolver` เพื่อจำกัดข้อมูลบุคลากรตาม permission scope
- correction route ใช้ UUID binding และตรวจทั้ง permission + org scope กัน IDOR
- correction ไม่แก้ attendance ทันที: สร้าง non-shadow workflow instance
  `attendance.correction` แล้วผู้ถือ `attendance.view_all` ที่อยู่ใน scope ต้อง approve
- audit action ครบ: requested, rejected, applied และ generic workflow action

## 6. Compatibility และข้อมูลเดิม

- endpoint check-in/out/today/report/export เดิมไม่เปลี่ยน URI
- frontend caller เดิมไม่ต้องแก้
- response success/error ของ check-in/out เดิมคงอยู่
- `attendances` ยังเป็น source ของ report/UI เดิม
- MySQL 8 ก่อน migration: count `2`, sum(id) `3`, sum(user_id) `3`
- หลัง migration: count `2`, sum(id) `3`, sum(user_id) `3`
- ตารางใหม่เริ่มต้นว่าง จึงไม่มีการเขียนทับข้อมูลย้อนหลัง

## 7. Verification

| การตรวจ | ผล |
|---|---:|
| duplicate dedup key | PASS |
| duplicate event UUID | PASS |
| raw payload immutable | PASS |
| out-of-order checkout/check-in | PASS |
| web check-in/check-out + permission | PASS |
| overnight shift | PASS |
| missing checkout | PASS |
| manual correction workflow + audit | PASS |
| organization permission scope | PASS |
| Attendance regression subset | 44 tests / 94 assertions PASS |
| Full backend regression | **428 tests / 1056 assertions PASS** |
| Laravel Pint | **400 files PASS** |
| Frontend production build | **879 modules PASS** |
| MySQL 8 migration + row reconciliation | **PASS** |

## 8. ความเสี่ยงคงเหลือและเงื่อนไขก่อนเชื่อมอุปกรณ์จริง

1. ต้องทราบ vendor/model, transport (REST/webhook/read-only DB/SFTP/file) และ SLA
2. ต้องตกลง external personnel identifier และกระบวนการจัดการ identity ที่ map ไม่ได้
3. ต้องทำ department pilot, connector retry/cursor/health monitoring และ vendor reconciliation report
4. ห้ามปลด `unique(user_id, date)` จนกว่า pilot และ reconciliation จะยืนยันผลตรง

### ข้อมูลที่ได้รับเพิ่มเติมเมื่อเริ่ม LEGACY-09

- ผู้ผลิต: **ZKTeco**
- personnel identifier mapping: **เลขบัตรประชาชน**
- protocol: ระบุว่า “มาตรฐาน” แต่ยังต้องยืนยันรุ่นอุปกรณ์และ transport/API ที่แน่นอน

ข้อมูลสองข้อแรกปิด vendor/identifier decision ได้บางส่วน แต่ยังไม่เพียงพอให้เลือก
SDK หรือ protocol implementation อย่างปลอดภัย จึงยังไม่มี connector code ใน LEGACY-09

ข้อเหล่านี้ไม่ใช่ FAIL ของขอบเขตนี้ เพราะคำสั่งกำหนดชัดว่าให้เตรียม interface
และยังไม่สร้าง integration ที่ไม่มี requirement

## 9. สรุป

LEGACY-08 ตามขอบเขตที่ได้รับมอบหมาย **PASS**: raw evidence แยกจาก result,
dedup/immutability/reconciliation/workflow correction/permission/scope/audit ครบ
ข้อมูล attendance เดิมไม่เปลี่ยน และไม่มี logic ผูกกับผู้ผลิตรายใด
