# INT-04 — FILE / AUDIT / TRUSTED TIME

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-04 (ROADMAP บรรทัดที่ 5)
**วันที่:** 2026-09-03
**Branch:** `chore/remove-saraban-module`
**สถานะ:** ✅ ผ่าน Definition of Done ของเช็คพอยต์นี้

---

## DONE

ปิดช่องว่างด้านความปลอดภัยของ **ระบบกลาง** สองข้อจากสี่ข้อที่ INT-00 ระบุไว้
และวางตะเข็บเวลาที่งานสารบรรณต้องใช้ — **ทั้งหมดเป็นงาน Core ที่ทุกโมดูลได้ประโยชน์ร่วมกัน
ไม่ใช่ของเฉพาะสารบรรณ** ตาม SoT §4

| # | สิ่งที่ทำ | ช่องว่างที่ปิด | SoT |
|---|---|---|---|
| 1 | ด่านตรวจไวรัสไฟล์อัปโหลด (ClamAV) | **SEC-GAP-02** | §26 |
| 2 | โซ่แฮชของประวัติการดำเนินการ + คำสั่งตรวจ | **SEC-GAP-03** | §25 |
| 3 | `TrustedClock` — ตะเข็บเวลาเดียวของสารบรรณ | — | §10 |
| 4 | ชุด allowlist `saraban_attachments` | — | §26 |

### 1. ด่านตรวจไวรัส — จุดที่ตัดสินใจสำคัญ

**ปัญหาเดิม:** allowlist กลางตรวจได้เพียง "ชนิดไฟล์" กับ "ขนาด" ซึ่งกันไฟล์ที่รันได้
เมื่อเบราว์เซอร์เปิดได้ แต่กัน **เนื้อหาอันตรายในไฟล์ชนิดที่อนุญาต** ไม่ได้เลย —
PDF ที่ฝัง payload ไว้ผ่านทุกด่านเดิมได้อย่างสบาย

| การตัดสินใจ | เหตุผล |
|---|---|
| **ต่อด่านที่ `UploadRules::rulesFor()` ที่เดียว** | เป็นแหล่งความจริงเดียวของ allowlist อยู่แล้ว — **ทุกจุดที่รับไฟล์ได้ด่านนี้ทันทีโดยไม่ต้องแก้ controller สักตัว** (ใบลา, ลายเซ็น, รูปโปรไฟล์, ภาพลงเวลา, แม่แบบเอกสาร, ไฟล์แนบหนังสือ) |
| **fail closed — ตรวจไม่ได้ = ไม่รับไฟล์** | ถ้าตัวตรวจล่มแล้วยังรับไฟล์ ด่านนี้จะกลายเป็นเครื่องประดับที่ปิดได้ด้วยการทำให้ clamd ล่ม ซึ่งแย่กว่าไม่มีด่านเลยเพราะสร้างความมั่นใจผิด ๆ |
| **`INSTREAM` ส่งเนื้อไฟล์เข้าไปตรวจในหน่วยความจำ** | ไม่ต้องมี volume ที่สอง container เห็นร่วมกัน จึงไม่มีช่วงเวลาที่ไฟล์ซึ่งยังไม่ผ่านการตรวจไปวางอยู่บนพื้นที่จัดเก็บจริง |
| **ไม่บอกชื่อลายเซ็นไวรัสกับผู้ใช้** | รายละเอียดนั้นอยู่ใน log ของระบบแล้ว การบอกผู้ใช้ไม่ช่วยอะไรและเปิดเผยข้อมูลภายในโดยไม่จำเป็น |
| **`depends_on: service_started` ไม่ใช่ `service_healthy`** | รอบแรก clamav ใช้เวลาหลายนาทีดาวน์โหลดฐานลายเซ็น ถ้าผูกกับ healthy ทั้งระบบจะใช้ไม่ได้เลยระหว่างนั้น — ให้เฉพาะ "การอัปโหลดไฟล์" ล้มไปก่อนพร้อมข้อความบอกเหตุ |
| **`ProductionEnvironmentGuard` ไม่ยอมให้ปิดด่านบน production** | ค่านี้ปิดได้เพื่อพัฒนาในเครื่อง ถ้าหลุดไป production จะกลายเป็นการรับไฟล์โดยไม่ตรวจเนื้อหาโดยไม่มีอะไรส่งเสียง — ให้ container ไม่ขึ้นดีกว่าให้บริการทั้งที่ด่านปิด |

### 2. โซ่แฮชของประวัติ — ทำให้การแก้ย้อนหลัง "ตรวจจับได้"

**ปัญหาเดิม:** `audit_logs` เป็น append-only ด้วย **ข้อตกลงในโค้ด** เท่านั้น
(`AuditLog::booted()` โยน exception ตอน update/delete) ซึ่งกันได้เฉพาะโค้ดของแอป —
ใครที่เข้าถึงฐานข้อมูลได้ยังแก้หรือลบแถวได้โดยไม่มีอะไรบอกได้เลยว่าประวัติถูกแก้
ซึ่งไม่พอสำหรับหลักฐานทางราชการ

แต่ละแถวเก็บแฮชของเนื้อหาตัวเอง **ผูกกับแฮชของแถวก่อนหน้า** การแก้แถวใดแถวหนึ่งทำให้
โซ่ตั้งแต่จุดนั้นไม่ตรงทันที และการลบแถวตรงกลางทำให้โซ่ขาด — ตรวจด้วย `php artisan audit:verify`

| การตัดสินใจ | เหตุผล |
|---|---|
| **ผูกโซ่ใน `AuditLog::creating` ไม่ใช่ใน `AuditLogger`** | มีหลายทางที่เขียนตารางนี้ (เช่นการเขียนคู่จาก `LeaveAuditLog`) ถ้าผูกที่ service ทางอื่นจะได้แถวไม่มีแฮช แล้วโซ่ขาดเองโดยไม่มีใครทำผิดเลย |
| **`lockForUpdate()` ตอนอ่านแถวสุดท้าย** | กันสองคำขอที่เขียนพร้อมกันอ่านแถวสุดท้ายตัวเดียวกัน ซึ่งจะทำให้โซ่แตกสองสายแล้วเตือนผิด |
| **แฮชจากเฉพาะฟิลด์ที่เป็นสาระของเหตุการณ์** | การเพิ่มคอลัมน์เชิงเทคนิคในอนาคตจะได้ไม่ทำให้โซ่ที่ลงไว้แล้วกลายเป็นไม่ตรง |
| **แถวเก่าที่ไม่มีแฮชไม่ถือว่าผิด** | migration เป็นการเพิ่มอย่างเดียว ระบบที่ใช้อยู่มีประวัติเดิมที่ไม่มีแฮช ถ้าตีว่าผิดปกติ คำสั่งตรวจจะแดงตลอดไปจนไม่มีใครเชื่อถือ · แต่เมื่อโซ่เริ่มแล้ว แถวที่ไม่มีแฮช **ถือว่าผิด** |

### 3. `TrustedClock`

งานสารบรรณผูกผลทางกฎหมายไว้กับเวลา — วันที่รับหนังสือ เวลาประทับตรารับ เวลาลงนาม
ล้วนเป็นหลักฐาน ถ้าโค้ดกระจายกันเรียก `now()` เอง จะไม่มีจุดใดที่เปลี่ยนไปใช้แหล่งเวลา
ที่รับรอง (TSA) ได้ภายหลังโดยไม่ต้องไล่แก้ทั้งระบบ

ตอนนี้ยังใช้นาฬิกาเครื่องเป็นแหล่งเวลา — สิ่งที่ได้ตั้งแต่วันแรกคือ **ตะเข็บเดียว**
ที่เปลี่ยนแหล่งเวลาได้ทีหลัง และบังคับด้วยด่านสัญญาแล้วตามที่ INT-01 §1.15 ตกลงไว้

---

## CHANGED

### สิ่งที่ **ไม่** ทำ และเหตุผล

**RISK-04 (`SecureFileAccessService` → resolver registry) — เลื่อนออกไปโดยตั้งใจ**

INT-00 เสนอให้ refactor เป็น resolver registry เพราะการต่อ if-branch ไปเรื่อย ๆ
จะทำให้จุดตัดสินสิทธิ์ไฟล์เปราะ แต่ตรวจตอนจะลงมือแล้วพบว่า:

1. **งานอีกงานหนึ่งเพิ่ง refactor ไฟล์นี้ไปแล้ว** — ตอนนี้ใช้ `PermissionEvaluator`
   และ `OrgScopeResolver` พร้อมตรวจขอบเขตหน่วยงาน ดีกว่าตอนที่สำรวจใน INT-00 มาก
2. **ยังไม่มีไฟล์ของสารบรรณให้เสิร์ฟเลย** — refactor ตอนนี้คือการเดาโครงสร้างล่วงหน้า
   โดยยังไม่มีผู้ใช้จริงตัวที่สอง
3. การแก้ไฟล์ที่อีกงานหนึ่งกำลังทำอยู่ เสี่ยงชนกันโดยไม่จำเป็น

จะทำพร้อมกับตอนที่สารบรรณมีไฟล์ให้เสิร์ฟจริง ซึ่งเป็นจังหวะที่รู้แล้วว่า resolver
ต้องรับอะไรบ้าง — ดีกว่าออกแบบล่วงหน้าแล้วต้องมาแก้

### แก้ไฟล์เดิม 7 จุด (ทั้งหมดเป็นการเพิ่ม)

| ไฟล์ | การแก้ |
|---|---|
| `docker-compose.yml` | เพิ่ม service `clamav` + volume + env ให้ backend/worker/scheduler + `depends_on` |
| `config/uploads.php` | เพิ่มชุด `saraban_attachments` และหมวด `malware_scan` |
| `app/Support/UploadRules.php` | เพิ่ม `sarabanAttachment()` + ต่อด่านตรวจไวรัสท้ายทุกชุด |
| `app/Providers/AppServiceProvider.php` | ผูก `MalwareScanner` เข้า container |
| `app/Support/ProductionEnvironmentGuard.php` | เพิ่มข้อตรวจว่าปิดด่านบน production ไม่ได้ |
| `app/Models/AuditLog.php` | ผูกแถวใหม่เข้าโซ่แฮชตอน `creating` |
| `phpunit.xml` | ปิดด่านตรวจไวรัสในชุดทดสอบ (ทดสอบด้วยตัวปลอมแทน clamd จริง) |

### เทสต์เดิมที่ต้องแก้ 1 จุด

`UploadAllowlistTest::test_every_upload_rule_checks_both_content_and_extension`
ทำ `implode('|', $rules)` ซึ่งพังเมื่อชุดกฎมี object ปนมา — แก้เป็นรวมเฉพาะกฎแบบข้อความ
และเพิ่ม `saraban_attachments` เข้าไปในรายการที่ตรวจด้วย

---

## FILES

**เพิ่มใหม่ (9):**

```
backend/app/Contracts/Files/MalwareScanner.php
backend/app/Services/Files/ScanResult.php
backend/app/Services/Files/ClamAvScanner.php
backend/app/Rules/FreeOfMalware.php
backend/app/Services/TrustedClock.php
backend/app/Console/Commands/VerifyAuditChainCommand.php
backend/database/migrations/2026_09_03_120000_add_hash_chain_to_audit_logs.php
backend/tests/Feature/MalwareScanningTest.php
backend/tests/Feature/AuditChainAndTrustedTimeTest.php
```

**แก้ไข (9):** ตามตารางด้านบน + `tests/Feature/UploadAllowlistTest.php`
+ `tests/Feature/SarabanCoreContractTest.php` (เพิ่มกฎห้ามเรียก `now()` ตรง ๆ)

---

## MIGRATIONS

`2026_09_03_120000_add_hash_chain_to_audit_logs.php`

| ประเด็น | ผล |
|---|---|
| Backward compatible | ✅ **เพิ่มสองคอลัมน์แบบ nullable เท่านั้น** ไม่เขียนทับข้อมูลใด แถวเดิมยังอ่านได้ปกติ |
| ไม่ backfill ย้อนหลัง | ตั้งใจ — การไปคำนวณแฮชให้ประวัติเก่าย้อนหลัง จะเป็นการ "รับรอง" ข้อมูลที่ระบบไม่เคยเฝ้าดูมาก่อน ซึ่งเป็นการรับรองที่ไม่จริง |
| `down()` | ถอนคอลัมน์และ index ได้สะอาด |
| รันจากฐานว่างได้ | ✅ ยืนยันผ่าน `RefreshDatabase` ทุกเทสต์ + CI รัน `migrate --force` จาก MySQL 8 เปล่า |

---

## TESTS

| ไฟล์ | จำนวน | ครอบคลุม |
|---|---|---|
| `MalwareScanningTest` | **7 / 19 assertions** | ไฟล์สะอาดผ่าน · ไฟล์ติดไวรัสถูกปฏิเสธโดยไม่เปิดเผยชื่อลายเซ็น · **ตัวตรวจล่มแล้วต้องไม่ปล่อยผ่าน** · ทุกชุด allowlist มีด่านต่อท้าย · ปิดด่านได้เพื่อพัฒนาในเครื่อง · production ไม่ยอมขึ้นเมื่อด่านปิด · ชุดสารบรรณตรวจครบสองชั้น |
| `AuditChainAndTrustedTimeTest` | **7 / 21 assertions** | ทุกแถวต่อกันเป็นโซ่ · โซ่สะอาดตรวจผ่าน · **แก้ข้อมูลตรงในฐานข้อมูลถูกจับได้** · **ลบแถวกลางถูกจับได้** · ประวัติเก่าก่อนเริ่มโซ่ไม่ถูกตีว่าผิด · โมเดลยังห้าม update/delete · ตรึงเวลาได้โดยไม่กระทบทั้งระบบ |
| `SarabanCoreContractTest` (+2 assertions) | — | เพิ่มกฎ **ห้ามเรียก `now()` ตรง ๆ ในโดเมนสารบรรณ** ตามที่ INT-01 §1.15 ตกลงไว้ |
| `UploadAllowlistTest` (แก้ + ขยาย) | — | ครอบชุด `saraban_attachments` ด้วย |

---

## SECURITY

| ประเด็น | ก่อน INT-04 | หลัง INT-04 |
|---|---|---|
| เนื้อหาอันตรายในไฟล์ชนิดที่อนุญาต | ผ่านได้ทุกด่าน | ถูกตรวจทุกไฟล์ ทุกจุดที่รับไฟล์ |
| ตัวตรวจล่ม | — | **ไม่รับไฟล์** (fail closed) พร้อมข้อความบอกเหตุ |
| ปิดด่านบน production | — | container ไม่ขึ้น |
| แก้ประวัติย้อนหลังผ่านฐานข้อมูล | ทำได้เงียบ ๆ | ตรวจจับได้ด้วย `audit:verify` |
| ลบประวัติตรงกลาง | ทำได้เงียบ ๆ | โซ่ขาด ตรวจจับได้ |
| เวลาที่เป็นหลักฐาน | กระจายเรียก `now()` | มีตะเข็บเดียว เปลี่ยนไป TSA ได้ภายหลัง |
| ข้อมูลรั่วผ่านข้อความ error | — | ชื่อลายเซ็นไวรัสอยู่ใน log เท่านั้น ไม่บอกผู้ใช้ |

**ช่องว่างที่ยังเปิดอยู่ (ตามแผน):** SEC-GAP-01 (step-up re-auth → INT-11) ·
SEC-GAP-04 (idempotency → INT-15)

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-03 | 750 passed · 8 skipped · 0 failed |
| **หลัง INT-04** | ✅ **764 passed · 8 skipped · 0 failed** · 2761 assertions · 71.73s · exit 0 |
| Pint (`--test app tests database config`) | ✅ 503 files ผ่าน |
| `docker compose config` | ✅ ไม่มีข้อผิดพลาดทางไวยากรณ์ |
| Frontend | ไม่ได้แตะหน้าเว็บใน INT-04 — สถานะเดิม 64 passed |

**ขอบเขต:** รันเต็มชุด · จุดที่เสี่ยงที่สุดคือการต่อกฎใหม่เข้า `UploadRules` ซึ่งกระทบ
**ทุกเส้นทางที่รับไฟล์ของทุกโมดูล** — `UploadAllowlistTest`, `LeaveEvidenceAccessTest`,
`AttendanceImageSecurityTest`, `DocumentTemplateVersionTest`, `ProfilePasswordChangeTest`
เขียวทั้งหมด

---

## UI-QA

**ไม่มีการเปลี่ยนแปลงหน้าเว็บใน INT-04** — เป็นชั้นโครงสร้างพื้นฐานล้วน

ผลที่ผู้ใช้จะเห็นคือข้อความ error ภาษาไทยเมื่ออัปโหลดไฟล์ไม่ผ่าน ซึ่งแสดงผ่าน
กลไก error เดิมของหน้าเว็บ (`errorHandler.js` → `alertStore`) ไม่ต้องแก้หน้าจอใด

ข้อความที่ผู้ใช้เห็น:
- ไฟล์มีปัญหา → *"ไฟล์นี้ไม่ผ่านการตรวจความปลอดภัย จึงไม่สามารถอัปโหลดได้"*
- ตัวตรวจยังไม่พร้อม → *"ระบบตรวจไวรัสยังไม่พร้อมใช้งาน จึงยังรับไฟล์ไม่ได้ กรุณาลองใหม่อีกครั้งหรือแจ้งผู้ดูแลระบบ"*

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| service ขึ้นจริง | `docker compose up -d clamav` → `eleave_clamav` Started · `clamdcheck.sh` ผ่าน · `docker compose config` ผ่าน |
| **ตัวตรวจจริงทำงาน (ไม่ใช่แค่ตัวปลอมในเทสต์)** | รัน `ClamAvScanner` จริงกับ clamd จริง: PDF สะอาด → `clean=true` · **ไฟล์ EICAR → `clean=false, signature=Eicar-Test-Signature`** · ชี้ไปโฮสต์ที่ไม่มีอยู่ → `clean=false, available=false` พร้อม log เตือน |
| ด่านต่อให้ทุกชุด allowlist | `test_every_upload_allowlist_carries_the_scan_rule` (6 ชุด) |
| fail closed | `test_an_unavailable_scanner_blocks_the_upload_instead_of_letting_it_through` |
| production ปิดด่านไม่ได้ | `test_production_refuses_to_start_with_the_scanner_switched_off` |
| แก้ประวัติแล้วจับได้ | `test_editing_history_straight_in_the_database_is_detected` (แก้ผ่าน `DB::table()` เลี่ยงโมเดล) |
| ลบประวัติแล้วจับได้ | `test_deleting_a_row_from_the_middle_breaks_the_chain_and_is_detected` |
| เทสต์เช็คพอยต์ | `MalwareScanningTest` 7 passed · `AuditChainAndTrustedTimeTest` 7 passed |
| Regression | `php artisan test` → **764 passed, 8 skipped, 0 failed** |
| Style | `pint --test` → 503 files PASS |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| ~~SEC-GAP-02~~ | ไม่มี malware scan | — | ✅ **ปิด** |
| ~~SEC-GAP-03~~ | audit แก้ย้อนหลังไม่รู้ | — | ✅ **ปิด** |
| RISK-04 | `SecureFileAccessService` เป็น if-chain | ต่ำลง | **เลื่อนโดยตั้งใจ** — งานอีกงานเพิ่ง refactor ให้ดีขึ้นแล้ว และยังไม่มีไฟล์สารบรรณให้เสิร์ฟ · ทำพร้อมตอนมีไฟล์จริง |
| RISK-15 **(ใหม่)** | clamav ใช้ RAM ~1–2 GB และรอบแรกต้องดาวน์โหลดฐานลายเซ็นหลายร้อยเมกะไบต์ | กลาง | ต้องเผื่อทรัพยากรบนเครื่อง production · ฐานลายเซ็นเก็บใน volume จึงไม่โหลดใหม่ทุกรีสตาร์ต · **ต้องมี `freshclam` อัปเดตฐานสม่ำเสมอ** ซึ่ง image ทำให้เองอยู่แล้ว |
| RISK-16 **(ใหม่)** | ชุดทดสอบปิดด่านตรวจไวรัสไว้ (`phpunit.xml`) CI จึงไม่ได้ทดสอบกับ clamd จริง | ต่ำ | ตัวกฎถูกทดสอบครบด้วยตัวปลอม ส่วน `ClamAvScanner` เองทดสอบด้วยไฟล์ EICAR ตอน deploy · การผูก CI กับ container ภายนอกจะทำให้ CI เปราะโดยไม่ได้ความมั่นใจเพิ่มมาก |
| RISK-17 **(ใหม่)** | โซ่แฮชใช้ `lockForUpdate()` ทุกครั้งที่เขียน audit — ระบบที่เขียน audit ถี่มากอาจช้าลง | ต่ำ | ปริมาณ audit ของระบบนี้ไม่สูง · ถ้าเป็นปัญหาในอนาคต ทางแก้คือแยกโซ่ตามชนิดทรัพยากร ไม่ใช่ถอด lock ออก |

**ไม่มี blocker สำหรับ INT-05**

---

## NEXT

**→ INT-05 — Document Core**

1. พฤติกรรมของเอกสาร: สร้าง / แก้ไข / เสนอ พร้อม endpoint และหน้าจอ (ต้องมาคู่กันตาม `ApiSurfaceContractTest`)
2. **เมนูสารบรรณตัวแรก** — ปลดล็อกเทสต์ `test_saraban_declares_no_menu_until_its_screens_exist`
   ที่ล็อกไว้ตั้งแต่ INT-02 พร้อมกับหน้าจอจริง
3. Policy + `SarabanModuleServiceProvider` (Object-level authorization ตาม SoT §6)
4. ไฟล์แนบหนังสือ → เป็นจังหวะที่ทำ RISK-04 (resolver registry ของ `SecureFileAccessService`)
5. UI ต้องเดินตาม `UI-COMPATIBILITY-MATRIX.md` — ใช้ shell, UI kit, layout convention เดิมทั้งหมด
