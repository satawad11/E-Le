# LEGACY-06 — Time Foundation

**วันที่:** 15 สิงหาคม 2569 (2026-08-15)
**ขอบเขต:** LEGACY-06 ตาม `docs/LEGACY-MIGRATION-PLAN.md` (06-1 ถึง 06-4)
**ต่อจาก:** CORE CHECKPOINT (PASS · อนุมัติ 2026-08-14)
**ผล:** **LEGACY-06 = PASS**

---

## สรุปผลรายข้อ

| # | งาน | ผล |
|---|---|:---:|
| 06-1 | `work_schedules` + `work_schedule_assignments` แบบ effective-dated (รองรับรายวัน/กะ) | **PASS** |
| 06-2 | Backfill จาก `users.work_start_time` + `default_work_start_time` | **PASS** |
| 06-3 | grace period (เดิมสายทันทีที่เกิน 1 วินาที) | **PASS** |
| 06-4 | เชื่อม `holidays` + `work_calendar_exceptions` เข้ากับ engine (ไม่สร้างใหม่) | **PASS** |

### ความสามารถที่โจทย์กำหนด

| ต้องรองรับ | สถานะ | อยู่ที่ไหน |
|---|:---:|---|
| timezone Asia/Bangkok | ✅ | `work_schedules.timezone` · `TimeService::timezoneFor()` |
| work schedules | ✅ | `work_schedules` + `work_schedule_days` |
| shifts | ✅ | เวลาเริ่ม/สิ้นสุดรายวัน + `break_minutes` |
| holidays | ✅ | `holidays` (ของเดิม) ผ่าน `WorkingCalendar` |
| fiscal year | ✅ | `fiscal_years` (ของเดิม) ผ่าน `TimeService::fiscalYear*()` |
| working days | ✅ | `TimeService::isWorkingDay()` / `workingDaysBetween()` |
| working hours | ✅ | `TimeService::shiftWindow()` / `scheduledWorkMinutes()` |
| late calculation | ✅ | `TimeService::lateMinutes()` (มี grace) |
| absence calculation | ✅ | `TimeService::isAbsent()` |
| overnight shift | ✅ | `crosses_midnight` + `shiftWindow()` ขยับวันสิ้นสุด |
| effective-dated schedule | ✅ | `work_schedule_assignments.effective_from/to` |
| organization/person override | ✅ | ลำดับ person > org unit (ไล่ขึ้นหาแม่) > องค์กร > default |

---

## 1. สิ่งที่ตรวจพบก่อนสร้าง — และเหตุผลที่ต้องมีเฟสนี้

ตรวจโค้ดเดิมก่อนตามที่โจทย์กำหนด พบว่า **ตรรกะเรื่องเวลามีอยู่สองชุดที่ไม่รู้จักกัน**

| ที่อยู่ | รู้อะไร | ไม่รู้อะไร |
|---|---|---|
| `LeaveService::calculateTotalDays()` | วันหยุดราชการ · `work_calendar_exceptions` รายหน่วยงาน · เสาร์อาทิตย์ | ตารางเวลา · กะ · เวลาเริ่ม/เลิกงาน |
| `AttendanceController::checkIn()/checkOut()` | `users.work_start_time` รายบุคคล | **ไม่รู้จักวันหยุดเลย** · ไม่มีผ่อนผัน · ไม่มีกะ |

ผลที่ตามมาซึ่งวัดได้จริง:

**ระบบตอบคำถามว่า "วันนี้เป็นวันทำงานไหม" ได้ไม่เหมือนกันแล้วแต่ว่าใครถาม**
โมดูลลาตอบว่าวันแม่แห่งชาติเป็นวันหยุด แต่โมดูลลงเวลาจะนับว่า "มาสาย"
ถ้าพนักงานเข้ามาลงเวลาในวันนั้น เพราะมันเทียบเฉพาะเวลาเริ่มงานอย่างเดียว

ตรรกะเดิมของการมาสายคือเทียบสตริงเวลาตรง ๆ:

```php
if ($time->format('H:i:s') > $startTime->format('H:i:s')) {
    $status = 'Late';   // สายทันทีที่เกิน 1 วินาที · ไม่สนวันหยุด · ไม่สนกะ
}
```

---

## 2. Schema ที่เพิ่ม (06-1)

migration `2026_08_15_100000_create_time_foundation` — **CREATE TABLE ล้วน**

| ตาราง | หน้าที่ |
|---|---|
| `work_schedules` | ตารางเวลาหนึ่งชุด · `timezone` · `grace_minutes` · `early_leave_grace_minutes` · `is_default` |
| `work_schedule_days` | เวลาทำงานรายวัน (0 = อาทิตย์ … 6 = เสาร์) · `is_working_day` · `start_time`/`end_time` · `break_minutes` · `crosses_midnight` |
| `work_schedule_assignments` | มอบหมายให้ผู้ใช้ / หน่วยงาน / ทั้งองค์กร แบบ effective-dated |

### สิ่งที่ **ไม่** สร้างใหม่

`TIME-CAL-001` ถูกประเมินไว้ว่า **PASS** อยู่แล้ว จึงนำของเดิมมาใช้ ไม่สร้างซ้ำ:

- `holidays` — วันหยุดราชการ (มีข้อมูลจริง 20 วันของปี 2569)
- `work_calendar_exceptions` — วันหยุด/วันทำงานชดเชย ระบุรายหน่วยงานได้
- `fiscal_years` — ปีงบประมาณ (5 แถว)

ทั้งสามถูก **เชื่อมเข้า engine** ผ่าน `WorkingCalendar` และ `TimeService` (06-4)

> **หมายเหตุการออกแบบ:** `day_of_week` ใช้เลขชุดเดียวกับ Carbon (0 = อาทิตย์)
> เพื่อไม่ต้องแปลงเลขไปมาแล้วผิด — เป็นชนิดบั๊กที่หายาก

---

## 3. บริการกลาง — โมดูลอื่นต้องเรียกที่นี่เท่านั้น

```
App\Services\Time\TimeService          ← ทางเข้าเดียวของโมดูลอื่น
├── WorkScheduleResolver               หาว่าใครใช้ตารางไหนในวันไหน
└── WorkingCalendar                    วันหยุด + exception รายหน่วยงาน
```

### API ที่เปิดให้เรียก

| กลุ่ม | method |
|---|---|
| เขตเวลา | `timezoneFor()` · `now()` · `today()` · `toUserZone()` |
| ตารางเวลา | `scheduleFor()` · `scheduleDayFor()` |
| วันทำงาน | `isWorkingDay()` · `isHoliday()` · `workingDaysBetween()` · `calendarDaysBetween()` |
| กะ | `shiftWindow()` · `scheduledWorkMinutes()` |
| สาย/ขาด | `lateMinutes()` · `earlyLeaveMinutes()` · `isAbsent()` |
| ปีงบประมาณ | `fiscalYear()` · `fiscalYearNumber()` · `fiscalYearRange()` · `crossesFiscalYear()` |

### จุดที่แก้ให้เรียกบริการกลาง

| ไฟล์ | เดิม | ตอนนี้ |
|---|---|---|
| `LeaveService::calculateTotalDays()` | คำนวณวันหยุด/เสาร์อาทิตย์/exception เอง ~25 บรรทัด | `TimeService::workingDaysBetween()` |
| `AttendanceController::checkIn()` | เทียบสตริงเวลากับ `work_start_time` | `TimeService::lateMinutes()` |
| `AttendanceController::checkOut()` | เทียบสตริงเวลากับ `work_end_time` | `TimeService::earlyLeaveMinutes()` |

**ตรวจแล้วว่าไม่มีตรรกะเวลาซ้ำเหลืออยู่:**

```
grep 'work_start_time|work_end_time|default_work_*'  นอก app/Services/Time  → เหลือแค่ comment + fillable ของ User
grep 'Holiday::|WorkCalendarException::|isWeekday()' นอก app/Services/Time  → เหลือแค่ WorkCalendarController (หน้า CRUD ไม่ใช่การคำนวณ)
```

---

## 4. การ Backfill (06-2)

`WorkScheduleSeeder` — แหล่งความจริงเดียว เรียกจากทั้ง migration และ seeder

ลำดับแหล่งข้อมูล:
1. setting `default_work_start_time` / `default_work_end_time`
2. setting `start_time` / `end_time` (ชื่อรุ่นเก่าที่ยังมีใน config)
3. เวลาราชการมาตรฐาน **08:30–16:30**

ผลบนฐานข้อมูลจริง (ระบบยังไม่เคยตั้งค่าใดเลย จึงใช้ข้อ 3):

```
standard-office-hours | tz=Asia/Bangkok | grace=0 | default=Y
   dow=0 work=n            ← อาทิตย์
   dow=1..5 work=Y 08:30:00-16:30:00
   dow=6 work=n            ← เสาร์
assignments: 1 (ระดับองค์กร · ไม่มีวันสิ้นสุด)
```

**ไม่แตะ `users.work_start_time` / `work_end_time`** — คงไว้เป็นข้อมูลอ่านอย่างเดียว
ตามหลักการเดียวกับที่ LEGACY-05 คงตาราง approval เดิมไว้ ผู้ใช้ที่ตั้งเวลาเฉพาะตัวไว้
จะได้ตารางส่วนบุคคลพร้อมการมอบหมาย (ปัจจุบันยังไม่มีผู้ใช้รายใดตั้งไว้ — 0/7 คน)

> **หมายเหตุ:** 08:30–16:30 เป็น **ข้อมูล** ใน seeder ไม่ใช่ค่าคงที่ในตรรกะ
> ผู้ดูแลแก้ได้จากตารางเวลาโดยไม่ต้องแตะโค้ด

---

## 5. Grace Period (06-3)

`work_schedules.grace_minutes` และ `early_leave_grace_minutes`

**ตั้งค่าเริ่มต้นเป็น 0 โดยตั้งใจ** เพื่อรักษาพฤติกรรมเดิมของระบบไว้ก่อน
ผู้ดูแลปรับเพิ่มเองได้ — การเปลี่ยนเกณฑ์การมาสายของทั้งองค์กรเป็นการตัดสินใจ
เชิงนโยบาย ไม่ใช่สิ่งที่ควรเปลี่ยนให้เงียบ ๆ ระหว่าง refactor

มีเทสต์ยืนยันว่าเมื่อตั้ง grace = 15 นาที: มา 08:45 = ไม่สาย · มา 08:50 = สาย 5 นาที

---

## 6. ผลการทดสอบ

| รายการ | ผล |
|---|---|
| `php artisan test` (ชุดเต็ม) | ✅ **372 passed / 952 assertions · 0 failed** |
| Time foundation tests | ✅ **40 tests / 81 assertions** |
| `vendor/bin/pint --test` | ✅ **PASS 373 ไฟล์** |
| `npm run build` | ✅ built in 24.43s |
| stack (nginx) | ✅ `/api/ping` → 200 |

| | ก่อนเฟส | หลังเฟส |
|---|---:|---:|
| test | 332 (871 assertions) | **372 (952 assertions)** |

### เทสต์ที่เพิ่ม (40 รายการ)

| ไฟล์ | จำนวน | ครอบอะไร |
|---|---:|---|
| `TimeServiceTest` | 34 | วันทำงาน · วันหยุด · เสาร์อาทิตย์ · ข้ามวัน · ข้ามปีงบ · override · timezone · effective date · สาย/ขาด |
| `WorkScheduleBackfillTest` | 6 | ผลของ backfill · ไม่แตะคอลัมน์เดิม · idempotency |

### ครอบตามที่โจทย์กำหนด

| ต้องมี test | test |
|---|---|
| วันทำงาน | `test_a_weekday_with_a_schedule_is_a_working_day`, `test_working_days_between_skips_weekends_and_holidays` |
| วันหยุด | `test_a_public_holiday_is_not_a_working_day`, `test_an_inactive_holiday_is_ignored`, `test_a_department_exception_overrides_a_public_holiday`, `test_an_exception_can_turn_a_normal_weekday_into_a_holiday` |
| เสาร์อาทิตย์ | `test_saturday_and_sunday_are_not_working_days`, `test_a_schedule_may_declare_saturday_a_working_day` |
| ข้ามวัน | `test_an_overnight_shift_ends_on_the_next_day`, `test_checking_out_after_midnight_on_a_night_shift_is_not_early_leave`, `test_a_day_shift_does_not_cross_midnight` |
| ข้ามปีงบประมาณ | `test_a_range_that_spans_the_fiscal_year_boundary_is_detected`, `test_the_fiscal_year_number_is_the_gregorian_year_it_ends_in`, `test_the_fiscal_year_falls_back_when_no_row_covers_the_date` |
| schedule override | `test_a_personal_schedule_overrides_the_organisation_default`, `test_an_org_unit_schedule_applies_to_its_members`, `test_a_child_unit_inherits_the_parent_unit_schedule`, `test_a_personal_schedule_beats_the_org_unit_schedule` |
| timezone | `test_the_default_timezone_is_bangkok`, `test_a_schedule_may_declare_its_own_timezone`, `test_a_utc_timestamp_is_interpreted_in_the_local_zone`, `test_converting_a_moment_into_the_user_zone` |

**เทสต์ที่สำคัญที่สุดของเฟสนี้:**
`test_changing_the_schedule_does_not_rewrite_history` — พิสูจน์ว่าการแก้ตารางเวลาวันนี้
ไม่เปลี่ยนผลการคำนวณของวันที่ผ่านมาแล้ว (คนที่เคยสาย 30 นาทีเมื่อวานยังสาย 30 นาที
แม้จะเปลี่ยนเวลาเข้างานขององค์กรวันนี้)

---

## 7. ตรวจกับข้อมูลจริง

รันบนฐานข้อมูล `eleave` ที่มีวันหยุดราชการปี 2569 จริง 20 วัน:

```
2026-08-11 (อังคาร)    working = YES
2026-08-12 (พุธ)       working = NO   ← วันเฉลิมพระชนมพรรษาฯ และวันแม่แห่งชาติ
2026-08-13 (พฤหัสบดี)  working = YES
2026-08-15 (เสาร์)     working = NO
2026-08-16 (อาทิตย์)   working = NO

มาลงเวลา 08:45 วันที่ 13  → สาย 15 นาที
มาลงเวลา 10:00 วันหยุด 12 → สาย 0 นาที   ← ของเดิมจะนับว่าสาย 90 นาที
วันทำงาน 10–16 ส.ค.       → 4 วัน
ปีงบประมาณของ 2026-08-12  → 2026
```

---

## 8. Rollback

| จะย้อน | วิธี |
|---|---|
| ถอนตารางเวลาทั้งชุด | `php artisan migrate:rollback` — ลบ 3 ตารางที่สร้างใหม่ |
| กลับไปใช้ค่าเดิมรายบุคคล | `users.work_start_time` / `work_end_time` **ยังอยู่ครบ ไม่ถูกแตะ** |
| โค้ด | `git revert` ช่วง commit ของเฟสนี้ |

ความเสี่ยงต่ำเพราะข้อมูลต้นทางเดิมยังอยู่ทั้งหมด และตารางที่เพิ่มเป็น additive ล้วน

---

## 9. ความเสี่ยงที่เหลือ

| เรื่อง | รายละเอียด |
|---|---|
| ยังไม่มีหน้าจอจัดการตารางเวลา | ตั้งค่าผ่านฐานข้อมูล/seeder เท่านั้น — เป็นฟีเจอร์ใหม่ อยู่นอกขอบเขต 06-1..06-4 |
| `break_minutes` ยังไม่ถูกใช้ในการคำนวณสาย | ใช้เฉพาะใน `scheduledWorkMinutes()` · การหักพักกลางวันออกจากชั่วโมงทำงานจริงเป็นเรื่องของ LEGACY-08 |
| OT ยังไม่ผูกกับตารางเวลา | `attendances.ot_*` ยังคำนวณแบบเดิม — เป็นขอบเขตของ LEGACY-09 |
| `isAbsent()` ยังไม่มีใครเรียก | เตรียม API ไว้ให้ LEGACY-08 ใช้ · ไม่ได้เปลี่ยนพฤติกรรมปัจจุบัน |
| grace = 0 | รักษาพฤติกรรมเดิม · **เจ้าของระบบยืนยันให้คงไว้ที่ 0** เมื่อ 2026-08-15 (ดูข้อ 11) |
| กะข้ามวันกับการลงเวลา | `shiftWindow()` รองรับแล้ว แต่ `attendances.date` ยังผูกกับวันที่ลงเวลาจริง — **ยังไม่ตัดสินใจ** ว่าการลงเวลาตี 2 นับเป็นของวันไหน · เป็นเงื่อนไขก่อนเปิดใช้กะดึก (ดูข้อ 11) |

---

## 10. สรุป: **LEGACY-06 = PASS**

- schema ครบตามที่กำหนด · additive ล้วน · ไม่แตะข้อมูลเดิม
- นำ `holidays` / `work_calendar_exceptions` / `fiscal_years` ของเดิมมาใช้ ไม่สร้างซ้ำ
- **ตรรกะเวลาที่เคยกระจายสองที่ รวมเหลือบริการกลางเดียว** และตรวจแล้วว่าไม่มีที่ไหนคำนวณเองอีก
- แก้ข้อบกพร่องจริง: ระบบเคยนับ "มาสาย" ในวันหยุดราชการได้
- test 372/372 · Pint 373 ไฟล์ · build ผ่าน

> **ข้อสังเกตสำหรับผู้ตรวจ:** ค่าที่แท้จริงของเฟสนี้ไม่ใช่ตารางสามตาราง แต่คือการที่
> คำถาม "วันนี้เป็นวันทำงานไหม" และ "คนนี้สายกี่นาที" มีคำตอบเดียวทั้งระบบ
> ก่อนหน้านี้โมดูลลากับโมดูลลงเวลาตอบคนละอย่างโดยไม่มีใครรู้

---

## หยุดที่นี่ — รอคำสั่งก่อนเริ่ม LEGACY-07

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-15 | PASS |
| ผู้ตรวจทาน | เจ้าของระบบ (satawad11) | 2026-08-15 | ☑ **อนุมัติ** |

---

## 11. สิ่งที่การอนุมัติครั้งนี้ครอบคลุม

เจ้าของระบบอนุมัติเมื่อ 2026-08-15 ว่า "ตามที่แนะนำ" — ตีความและบันทึกไว้ดังนี้
เพื่อไม่ให้เข้าใจคลาดเคลื่อนภายหลัง

### ✅ ยืนตามค่าที่ตั้งไว้ (ไม่มีการเปลี่ยนแปลงโค้ดเพิ่ม)

| เรื่อง | ค่าที่ยืนยัน | เหตุผล |
|---|---|---|
| `grace_minutes` | **0** | รักษาพฤติกรรมเดิมของระบบ · ปรับได้ภายหลังโดยแก้ข้อมูลในตารางเวลา ไม่ต้องแตะโค้ด |
| `early_leave_grace_minutes` | **0** | เหตุผลเดียวกัน |
| เวลาราชการเริ่มต้น | **08:30–16:30** จันทร์–ศุกร์ | เป็นค่ามาตรฐานราชการ · ระบบยังไม่เคยตั้ง `default_work_start_time` มาก่อน · เก็บเป็น**ข้อมูล**ใน seeder ไม่ใช่ค่าคงที่ในตรรกะ |

### ⚠️ ยังไม่ถือว่าตัดสินใจแล้ว

**กะข้ามวันกับการผูกวันที่ของการลงเวลา** — ข้อนี้ผมระบุไว้ชัดว่า *ไม่ได้ให้ข้อเสนอแนะ*
จึงไม่มีสิ่งที่จะ "อนุมัติตามที่แนะนำ" ได้

`TimeService::shiftWindow()` รองรับกะข้ามวันแล้วในระดับการคำนวณ แต่
`attendances.date` ยังผูกกับวันที่ที่ผู้ใช้กดลงเวลาจริง **การเปิดใช้กะดึกจริง
ต้องตัดสินก่อนว่าการลงเวลาตอนตี 2 นับเป็นของวันไหน** (วันที่กะเริ่ม หรือวันตามปฏิทิน)

ตราบใดที่ยังไม่มีกะข้ามวันถูกใช้งานจริง ข้อนี้ไม่กระทบระบบ — เป็นเงื่อนไข
ก่อนเปิดใช้กะดึก ไม่ใช่ข้อค้างของ LEGACY-06

### สถานะหลังอนุมัติ

เปิดให้เริ่ม **LEGACY-07 Leave Modernization** ได้
สิ่งที่ยังห้ามทำจนกว่าจะมีคำสั่งแยก: **Workflow Cutover (05-4)**
