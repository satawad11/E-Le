# เฟส 5 — บัญชีแยกประเภทวันลา audit ที่แก้ไม่ได้ กันส่งซ้ำ และปิดงวด

> แนวทางจากระบบธนาคาร ต้องเพิ่มตาราง — ต้องได้รับอนุมัติข้อยกเว้น Production Freeze
> ฐานข้อมูลที่ใช้จริงคือ MySQL 8 (ตาม docker-compose) ตัวอย่าง trigger จึงเขียนแบบ MySQL

## 5.1 ปัญหาปัจจุบัน

- `leave_balances` เก็บ `quota`, `used`, `reserved`, `carried_forward` เป็นตัวเลขที่ถูกบวกลบตรง ๆ ใน 13 จุด (`LeaveService`, `LeaveAdjustmentController`, `CarryForwardLeaves`, `ReconcileLeaveBalances` ฯลฯ)
- มีคำสั่ง `leave:reconcile-balances` ที่ **แก้ยอดให้ตรง** เมื่อเพี้ยน ซึ่งแสดงว่าเคยเพี้ยนจริง แต่หลังแก้แล้วไม่รู้ว่าเพี้ยนเพราะรายการใด
- `audit_logs`, `leave_audit_logs` แก้หรือลบได้ที่ระดับฐานข้อมูล
- ไม่มีการกันส่งคำขอซ้ำ (กดส่งใบลาสองครั้งตอนเน็ตช้า)

## 5.2 ตาราง `leave_ledger_entries` (เพิ่มได้อย่างเดียว)

| คอลัมน์ | ความหมาย |
|---|---|
| `id`, `uuid` | |
| `user_id`, `leave_type_id`, `period_year` | บัญชีที่ได้รับผล (คีย์เดียวกับ `leave_balances`) |
| `entry_type` | ดูตารางล่าง |
| `bucket` | `entitlement` (สิทธิ์) · `reserved` (จองไว้) · `used` (ใช้แล้ว) |
| `days` | บวก/ลบ ทศนิยม 2 ตำแหน่ง |
| `paid_days`, `unpaid_days` | แยกส่วนได้รับ/ไม่ได้รับเงินเดือน |
| `leave_id`, `leave_adjustment_id`, `leave_policy_id` | ต้นเหตุ |
| `reverses_entry_id` | รายการที่ถูกกลับ |
| `reason` | ข้อความภาษาไทย บังคับเมื่อ `ADJUST` |
| `actor_id`, `checker_id` | ผู้ทำ / ผู้ตรวจคนที่สอง (บังคับเมื่อ `ADJUST`) |
| `correlation_id` | เชื่อมกับ `audit_logs` |
| `created_at` | เวลาจากเซิร์ฟเวอร์ |

| entry_type | เกิดเมื่อ | bucket |
|---|---|---|
| `GRANT` | ต้นปีงบประมาณ / บรรจุใหม่ / นโยบายรุ่นใหม่ | entitlement + |
| `CARRY_FORWARD` | ปิดงวด ทบวันลาพักผ่อน | entitlement + |
| `RESERVE` | ยื่นใบลา | reserved + |
| `RELEASE` | ไม่อนุญาต / ยกเลิกก่อนอนุมัติ / ตัดใบลาเพราะลาคลอดคาบเกี่ยว | reserved − |
| `CONSUME` | อนุมัติ (RELEASE คู่กับ CONSUME ในธุรกรรมเดียว) | used + |
| `RESTORE` | ยกเลิกหลังอนุมัติ / ยกเลิกบางส่วน / เรียกตัวกลับ | used − |
| `CONVERT_OUT` / `CONVERT_IN` | แปลงประเภทการลา (ยกเลิกลาคลอด → ลากิจ) — คู่กันเสมอ | used −/+ |
| `ADJUST` | ปรับยอดด้วยมือ (ต้องมีผู้ตรวจคนที่สอง) | ตามที่ระบุ |
| `REVERSAL` | กลับรายการที่ผิด | ตรงข้ามรายการเดิม |

**หลักการ**
- ห้าม UPDATE/DELETE แถว ledger (trigger ระดับฐานข้อมูล) แก้ด้วย `REVERSAL` เท่านั้น
- `leave_balances` กลายเป็น **ยอดสรุป** ที่คำนวณจาก ledger ได้เสมอ: `คงเหลือ = Σentitlement − Σused − Σreserved`
- ทุกการเปลี่ยนยอดผ่าน `LeaveLedgerService::post()` ที่เดียว ใน `DB::transaction` และ `lockForUpdate` แถว `leave_balances` (เหมือนที่ทำอยู่)
- test สแกนโค้ด: ห้ามมีการเขียน `->used =`, `->reserved =`, `->quota =` นอก `LeaveLedgerService`

## 5.3 การกระทบยอดและการปิดงวด

- `leave:reconcile-balances` เปลี่ยนเป็น **ตรวจอย่างเดียว** รันทุกคืน เทียบ `leave_balances` กับผลรวม ledger และกับใบลาจริง ถ้าไม่ตรงให้แจ้งงานบุคลากรและผู้ดูแลระบบ พร้อมรายการที่ต่าง — **ไม่แก้เอง**
- การแก้ทำผ่าน `ADJUST` พร้อมเหตุผลและผู้ตรวจคนที่สอง
- ~~ปิดงวด~~ — **D-09 (18/10/2569): ยังไม่ปิดงวด** แก้ปีเก่าได้ แต่ทุกการแก้ต้องเป็นรายการใน ledger และ audit เท่านั้น ออกแบบตาราง `leave_period_closings` ไว้ก่อน (ปี ผู้ปิด วันที่ ยอดรวม checksum) เพื่อเปิดใช้ภายหลังโดยไม่ต้องรื้อ
- `CarryForwardLeaves` ทำงานเป็นส่วนหนึ่งของการปิดงวด และเขียน `CARRY_FORWARD`

## 5.4 การย้ายไปใช้ ledger

1. **ตั้งยอดยกมา:** สร้าง `GRANT` + `CONSUME` + `RESERVE` จากยอด `leave_balances` ปัจจุบันของทุกบัญชี โดยระบุ `reason` = "ยอดยกมาก่อนเปิดใช้บัญชีแยกประเภท"
2. **เขียนคู่ขนาน:** โค้ดเดิมยังแก้ `leave_balances` และเรียก `LeaveLedgerService` ไปพร้อมกัน การกระทบยอดทุกคืนต้องตรง 100% ต่อเนื่องอย่างน้อย 30 วัน
3. **สลับ:** ให้ `leave_balances` คำนวณจาก ledger เท่านั้น ลบโค้ดที่แก้ยอดตรง
4. ย้อนกลับได้ทุกขั้นก่อนขั้น 3 เพราะยอดเดิมยังอยู่

## 5.5 Audit log ที่แก้ไม่ได้

- เพิ่ม `audit_logs.prev_hash`, `audit_logs.row_hash` = SHA-256 ของ (ข้อมูลแถว + `prev_hash`) และทำเหมือนกันกับ `leave_audit_logs`, `saraban_audit_logs`
- trigger `BEFORE UPDATE` และ `BEFORE DELETE` ที่ `SIGNAL SQLSTATE '45000'` บนตาราง audit และ ledger
- ผู้ใช้ฐานข้อมูลของแอปไม่มีสิทธิ์ `DROP TRIGGER` (แยกบัญชี migration ออกจากบัญชีแอป — ต้องแก้ `docker-compose` และ `.env` ของ production)
- คำสั่ง `audit:verify-chain` ตรวจ hash chain ทุกคืน แจ้งเตือนเมื่อพบรอยแก้
- **ทำแล้ว (D-07 · 18/10/2569):** migration `2026_10_19_130000_make_audit_logs_append_only.php` สร้าง trigger กัน UPDATE/DELETE บน `audit_logs`, `leave_audit_logs`, `saraban_audit_logs`, `setting_audit_logs` และกัน UPDATE บน `leave_action_logs`, `personal_data_access_logs` (สองตารางนี้มี FK แบบ CASCADE จึงยังลบตามแถวต้นทางได้ — เปลี่ยนเป็น RESTRICT เป็นงานแยก) · ยอมให้ FK ที่ nullable เปลี่ยนเป็น NULL ได้เท่านั้น · ทดสอบแล้วบน SQLite (test) และ MySQL 8 (ฐานข้อมูลทดสอบแยก)
- ข้อควรรู้: trigger อ่านรายชื่อคอลัมน์ตอน migrate — **เพิ่มคอลัมน์ในตารางเหล่านี้ภายหลังต้องรัน migration สร้าง trigger ใหม่** (เรียก `up()` ซ้ำได้) · บน MySQL ที่เปิด binary log ผู้ใช้ที่รัน migration ต้องมีสิทธิ์สร้าง trigger (`TRIGGER` และ `SUPER` หรือ `log_bin_trust_function_creators=1`)
- ยังไม่ทำ: hash chain (`prev_hash`/`row_hash`) และ `audit:verify-chain`

## 5.6 กันส่งซ้ำ (Idempotency)

- frontend สร้าง `Idempotency-Key` (UUID) ต่อการกดปุ่มหนึ่งครั้ง ส่งกับคำขอที่เปลี่ยนข้อมูล: ยื่นลา อนุมัติ/ไม่อนุมัติ ยกเลิก ปรับยอด ลงเวลา
- ตาราง `idempotency_keys` (key, user_id, route, hash ของ request, response status, response body, หมดอายุ 24 ชั่วโมง)
- คำขอซ้ำ key เดิม → คืนผลเดิม / key เดิมแต่เนื้อหาต่าง → 422 "คำขอนี้ถูกส่งไปแล้วด้วยข้อมูลต่างกัน"
- ทำเป็น middleware เดียว ใช้ทุกโมดูล

## 5.7 Automated tests

- ทุกการเปลี่ยนสถานะใบลา (ยื่น อนุมัติ ไม่อนุมัติ ยกเลิก ยกเลิกบางส่วน เรียกตัวกลับ แปลงประเภท) สร้างรายการ ledger ถูกชนิดและผลรวมตรงกับยอด
- UPDATE/DELETE แถว ledger และ audit ล้มเหลวที่ระดับฐานข้อมูล
- แก้ข้อมูล audit โดยตรง (ปิด trigger ชั่วคราวใน test) → `audit:verify-chain` ตรวจพบ
- ส่งคำขอยื่นลาซ้ำด้วย key เดิมพร้อมกัน 2 คำขอ → ได้ใบลาใบเดียว
- `ADJUST` โดยไม่มีผู้ตรวจคนที่สอง หรือผู้ตรวจเป็นคนเดียวกัน → ปฏิเสธ
- หลังปิดงวด เขียนรายการลงปีเก่าไม่ได้

## Definition of Done เฟส 5

- [ ] กระทบยอดตรง 100% ต่อเนื่อง 30 วันก่อนสลับ
- [ ] ไม่มีโค้ดแก้ `leave_balances` ตรงนอก `LeaveLedgerService`
- [ ] รายงาน "ความเคลื่อนไหววันลา" รายบุคคลเป็นภาษาไทย อ่านได้ทั้งเจ้าตัวและงานบุคลากร และใช้ทำทะเบียนคุมวันลาได้
