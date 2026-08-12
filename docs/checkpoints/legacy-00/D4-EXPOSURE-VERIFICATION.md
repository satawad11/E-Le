# ผลตรวจการเปิดเผยจากภายนอก (สืบเนื่องจากคำตอบ D-4)

**วันที่:** 2026-08-12 · **ผู้ตรวจ:** AI Agent (Claude Code)
**เหตุที่ตรวจ:** ผู้ใช้ยืนยันว่า **ระบบเข้าถึงได้จากอินเทอร์เน็ต**
**วิธีตรวจ:** HTTP request ไปยัง instance ที่รันอยู่จริง (`http://localhost:8001` = `eleave_backend`) **โดยไม่ส่ง token ใด ๆ**
**ขอบเขต:** ตรวจระบบของผู้ใช้เองตามที่ได้รับมอบหมาย · เป็น GET/HEAD เท่านั้น · ไม่มีการ exploit ต่อยอด

---

## สรุป: ยืนยันว่าเปิดเผยจริง ไม่ใช่ทฤษฎี

| Endpoint | ผล | ความหมาย |
|---|---|---|
| `GET /info.php` | **200** (88,033 bytes) | `phpinfo()` — ดู §1 **รุนแรงที่สุด** |
| `GET /test_db.php` | **200** | สคริปต์ทดสอบที่มี DB credential ฝังอยู่ (`leave_user` / `secret`) |
| `GET /test.php` | **200** | ไฟล์ทดสอบ |
| `GET /api/storage/signatures/6a36b10d67692.png` | **200** (5,093 bytes, `image/png`) | **ลายเซ็นบุคลากรดาวน์โหลดได้โดยไม่ต้อง login** |
| `GET /storage/signatures/6a36b10d67692.png` | **200** (5,093 bytes) | ช่องทางที่ **สอง** ไปยังไฟล์เดียวกัน (ผ่าน symlink `public/storage`) |
| `GET /fix-leaves` | **200** | endpoint เขียนฐานข้อมูลที่ไม่ต้องยืนยันตัวตน — ดู §3 |
| `GET /api/leaves/1/pdf` | 401 | ต้อง login ก่อน (IDOR ยังจริง แต่ต้องมีบัญชีในระบบ) |
| `GET /api/saraban/documents/1` | 401 | เช่นเดียวกัน |

**ข้อแก้ไขจากเอกสารเดิม:** SEC-003 และ SEC-005 ต้องมี token ที่ใช้ได้ก่อน จึงเป็น
**"ผู้ใช้ที่ล็อกอินคนใดก็ได้"** ไม่ใช่ "ใครก็ได้บนอินเทอร์เน็ต" — ตรงกับที่ระบุไว้แล้วในเอกสารประเมิน

---

## 1. SEC-020 (ใหม่) — `APP_KEY` รั่วผ่าน `phpinfo()` · **CRITICAL สูงสุด**

### สิ่งที่ตรวจพบ

หน้า `http://<host>/info.php` แสดงตาราง Environment ของ PHP และ **มีค่า `APP_KEY` ในรูปแบบคีย์จริง
`base64:` ตามด้วย base64 ความยาวเต็ม** รวมถึง `DB_PASSWORD`, `DB_USERNAME`, `DB_DATABASE`,
`DB_HOST`, `MAIL_PASSWORD`

> ตรวจแบบ boolean เท่านั้น — **ไม่ได้ดึงค่า secret ออกมาอ่านหรือบันทึกไว้ที่ใด**

### สาเหตุ

`php artisan serve` ใช้ PHP built-in server ที่ boot Laravel ขึ้นมาก่อน ซึ่ง Dotenv จะโหลด `.env`
เข้า `$_ENV`/`getenv()` แล้ว จากนั้น router เดียวกันนี้เสิร์ฟไฟล์ `.php` ใน `public/` ต่อ —
`info.php` จึงเห็น environment ทั้งหมดที่ Laravel โหลดไว้

### ทำไมถึงร้ายแรงกว่าทุกข้อที่ประเมินไว้

`APP_KEY` คือกุญแจของ `Illuminate\Encryption\Encrypter` ที่ใช้กับ:

| สิ่งที่เข้ารหัสด้วย APP_KEY | ไฟล์อ้างอิง |
|---|---|
| `user_profiles.id_card_number` (เลขบัตรประชาชน) | `app/Models/UserProfile.php:62` cast `encrypted` |
| `user_profiles.passport_number` | `app/Models/UserProfile.php:63` |
| `users.phone` | `app/Models/User.php:22` |
| ค่า secret ในตาราง `settings` (LINE channel token, Telegram token, mail password) | `app/Services/SystemSettingService.php` |
| Signed URL / cookie signature ของ Laravel | framework |

**ผลคือ: การเข้ารหัสข้อมูลอ่อนไหวทั้งหมดในระบบไม่มีผลบังคับอีกต่อไป** ถ้าผู้ไม่หวังดีได้ทั้ง
`APP_KEY` (จาก `info.php`) และเนื้อหาฐานข้อมูล ก็ถอดรหัสเลขบัตรประชาชนได้ทันที

นอกจากนี้ `info.php` ยังเปิดเผย `DB_*` ที่ตั้งไว้เป็น container env — ซึ่งตรงกับที่พบว่า
MySQL ใช้ `root` แบบไม่มีรหัสผ่าน (SEC-015)

### ข้อควรระวังเรื่องการหมุนกุญแจ

**ห้ามเปลี่ยน `APP_KEY` เฉย ๆ** — ข้อมูลที่เข้ารหัสไว้แล้วจะถอดไม่ได้อีกเลย ต้องทำตามลำดับ:

1. ปิดการเข้าถึง `info.php` ก่อน (หยุดเลือด)
2. Backup (มีแล้วจาก LEGACY-00 และ restore ได้จริง)
3. ถอดรหัสข้อมูลด้วยกุญแจเดิม → เข้ารหัสใหม่ด้วยกุญแจใหม่ (repo มี `app/Console/Commands/EncryptSensitiveDataCommand.php` อยู่แล้ว ต้องตรวจว่ารองรับการ re-key หรือไม่)
4. หมุน `APP_KEY`
5. หมุน secret อื่นทั้งหมดที่รั่วไปพร้อมกัน: DB password, LINE token, Telegram token, mail password
6. เพิกถอน `personal_access_tokens` ทั้ง 9 รายการ (ผู้ใช้ต้อง login ใหม่)

> **บริบทที่ช่วยได้มาก:** ระบบยังไม่ได้ใช้งานจริง — มีเพียง 7 ผู้ใช้ทดสอบ และ `user_profiles` 6 แถว
> การ re-key จึงมีต้นทุนต่ำมาก **ถ้าทำตอนนี้**

---

## 2. ลายเซ็นบุคลากรเปิดสาธารณะ — ยืนยัน SEC-001 ด้วยหลักฐานจริง

ดาวน์โหลดสำเร็จโดยไม่ต้อง login ผ่าน **สองเส้นทางแยกกัน**:

- `GET /api/storage/{path}` — route ที่ประกาศไว้นอก `auth:sanctum` ใน `routes/api.php:263-273`
- `GET /storage/{path}` — symlink `backend/public/storage -> storage/app/public` ที่ `php artisan serve` เสิร์ฟตรง

**การปิดต้องทำทั้งสองทาง** — ลบเฉพาะ route ใน `api.php` ยังไม่พอ

ไฟล์ที่เข้าถึงได้ตอนนี้: ลายเซ็น 11 ไฟล์ · ภาพเซลฟี่ลงเวลา (ถ้ามี) · ชื่อไฟล์ลายเซ็นเป็น `uniqid()`
ซึ่งเดาลำดับได้ง่ายเพราะอิงเวลา

---

## 3. `/fix-leaves` — ต้องรายงานตรง ๆ

Endpoint นี้ตอบ **200** โดยไม่ต้องยืนยันตัวตน และ **การ probe ของผมทำให้โค้ดในนั้นทำงานจริง**

**ผลกระทบ: ไม่มี — ตรวจสอบยืนยันแล้ว**

โค้ดวน `Leave::whereNull('document_number')` ซึ่งปัจจุบันไม่มีแถวไหนเข้าเงื่อนไข (ใบลาทั้ง 3 ใบ
มีเลขที่หนังสือครบแล้ว) จึงเป็น no-op

ตรวจยืนยันหลัง probe:

| id | `HEX(document_number)` | `updated_at` |
|---:|---|---|
| 1 | `E0B8A5E0B89B2E2D323536392D3030303031` | 2026-08-09 21:44:30 |
| 2 | `E0B8A5E0B89B2E2D323536392D3030303032` | 2026-08-10 23:21:04 |
| 3 | `E0B8A5E0B89B2E2D323536392D3030303033` | 2026-08-11 22:38:05 |

**ตรงกับ baseline ทุกค่า `updated_at` ไม่ขยับ** → ไม่มีการเขียนเกิดขึ้น

**บทเรียน:** ผมควรใช้วิธีตรวจที่ไม่ทำให้ endpoint ทำงาน (เช่นดูจาก route list) แทนการยิง HTTP
ไปยัง endpoint ที่รู้อยู่แล้วว่าเขียนฐานข้อมูล — บันทึกไว้เพื่อไม่ทำซ้ำ

---

## 4-A · ผลการแก้ไข (ดำเนินการแล้ว 2026-08-12 · commit `f843b3c`)

ผู้ใช้สั่ง "แก้ไขเลย" · ทำเป็น hotfix commit เดียว revert ได้ด้วย `git revert f843b3c`

### ยิงซ้ำหลังแก้ (ไม่ส่ง token)

| Endpoint | ก่อน | หลัง |
|---|:---:|:---:|
| `GET /info.php` | 200 | **404** ✅ |
| `GET /test.php` | 200 | **404** ✅ |
| `GET /test_db.php` | 200 | **404** ✅ |
| `GET /fix-leaves` | 200 | **404** ✅ |
| `GET /api/storage/{path}` | 200 | **404** ✅ |
| `GET /storage/{path}` | 200 | **200** ⚠️ ยังเปิดอยู่ — ดูเหตุผลด้านล่าง |
| `GET /api/ping` (ตรวจว่าระบบยังทำงาน) | 200 | 200 ✅ |

### สิ่งที่แก้

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `backend/public/info.php` | ลบ — ต้นเหตุ SEC-020 |
| `backend/public/test_db.php` | ลบ — มี DB credential ฝังในไฟล์ |
| `backend/public/test.php` | ลบ |
| `backend/routes/web.php` | ถอด route `GET /fix-leaves` (SEC-011) แทนด้วยคอมเมนต์ชี้ไป `php artisan leaves:fix-document-numbers` |
| `backend/routes/api.php` | ถอด route `GET /api/storage/{path}` (SEC-001 บางส่วน) |

รวม 5 ไฟล์ · +13 / −64 บรรทัด · **ไม่แตะ business logic, leave policy, workflow, schema หรือข้อมูล**

### การตรวจสอบ

- `php artisan test` → **86/86 ผ่าน (256 assertions)** เท่าเดิมกับ baseline
- ตรวจก่อนลบ route `/api/storage/{path}` ว่า **ไม่มีโค้ดฝั่งใดเรียกเลย** (`grep -rn "api/storage"` ทั้ง frontend และ backend = ไม่พบ) — frontend ใช้ `/storage/{path}` ผ่าน symlink คนละเส้นทาง

### ⚠️ สิ่งที่ยังไม่ได้ปิด และเหตุผล

`GET /storage/{path}` ผ่าน symlink `backend/public/storage` **ยังคืน 200**

ตอนขออนุมัติผมเสนอว่าจะลบ symlink ด้วย โดยระบุผลกระทบว่า "รูปลายเซ็นจะไม่แสดงในหน้าจอ"
**ซึ่งประเมินไว้ต่ำเกินจริง** — ตรวจละเอียดก่อนลงมือแล้วพบว่ามีโค้ด **10+ จุด** อ่านไฟล์ผ่าน
`public_path(str_replace('/storage/', 'storage/', $path))` ได้แก่:

| ที่ | จุดที่ใช้ |
|---|---|
| `app/Http/Controllers/LeaveController.php` | บรรทัด 661, 679, 786, 804 — ฝังลายเซ็นผู้อนุมัติลง PDF ใบลา |
| `app/Http/Controllers/Api/Saraban/DocumentController.php` | 143, 269 — ตราครุฑในหนังสือราชการ |
| `resources/views/pdf/leave_hajj.blade.php` · `leave_spouse` · `leave_study` | ลายเซ็นผู้ลา |
| `resources/views/pdf/official_travel.blade.php` · `attendance_report.blade.php` · `reports/attendance_*_preview.blade.php` | โลโก้/ตราครุฑ |

การลบ symlink จะทำให้ **PDF ทุกชนิดฝังลายเซ็นและตราครุฑไม่ได้** ซึ่งเป็น regression ที่กว้างกว่า
ที่ผมแจ้งไว้ตอนขออนุมัติ และมี test ครอบอยู่ (`LeaveExportTest`, `LeaveCardPdfTest`)

**การแก้ที่ถูกต้อง** = ย้ายไฟล์ไป private + สร้าง endpoint ดาวน์โหลดที่ตรวจสิทธิ์ + แก้ทั้ง 10+ จุด
ให้อ่านผ่าน `storage_path('app/public/...')` แทน — ซึ่งคือขอบเขตของ **LEGACY-02 §02-A** ที่วางแผน
ไว้พร้อม test อยู่แล้ว ไม่ควรทำแบบเร่งด่วนโดยไม่มี test รองรับ

**มาตรการชั่วคราวจนกว่าจะถึง LEGACY-02:** ปิด port forward ที่ router (ข้อ 1 ในตารางด้านล่าง)

### 🔴 ยังต้องทำต่อ — `APP_KEY` ที่รั่วไปแล้วยังใช้ได้อยู่

การลบ `info.php` หยุด**การรั่วครั้งใหม่** แต่ **ไม่ได้ทำให้กุญแจที่รั่วออกไปแล้วใช้ไม่ได้**
ถ้ามีใครเคยเปิดหน้านั้นระหว่างที่เปิดอยู่ เขายังถอดรหัสข้อมูลได้

ต้อง re-key ตามลำดับใน §1 — **ยังไม่ได้ทำ** เพราะเป็นงานหลายขั้นที่ต้องวางแผนและมี downtime

---

## 4. มาตรการหยุดเลือดที่เสนอ (สถานะ ณ 2026-08-12)

เรียงตามผลลัพธ์ต่อความเสี่ยงต่อหน่วยเวลา

| ลำดับ | มาตรการ | สถานะ | ผลต่อผู้ใช้ |
|---|---|---|---|
| **1** | **ตัดการเข้าถึงจากอินเทอร์เน็ตชั่วคราว** (ปิด port forward / firewall ที่ router) | ⏳ **รอผู้ใช้ทำ** — ผมเข้าถึงอุปกรณ์เครือข่ายไม่ได้ · **ยังเป็นมาตรการที่ได้ผลที่สุด** เพราะปิด `/storage/{path}` ที่ยังเหลืออยู่ด้วย | ระบบใช้ได้เฉพาะวงใน |
| **2** | ลบ `backend/public/info.php`, `test.php`, `test_db.php` | ✅ **ทำแล้ว** `f843b3c` | ไม่มี |
| **3** | ลบ route `GET /fix-leaves` | ✅ **ทำแล้ว** `f843b3c` | ไม่มี |
| **4a** | ลบ route `GET /api/storage/{path}` | ✅ **ทำแล้ว** `f843b3c` | ไม่มี (เป็น dead route) |
| **4b** | ปิด `GET /storage/{path}` (symlink) | ⛔ **ไม่ทำในรอบนี้** — จะทำให้ PDF ฝังลายเซ็น/ตราครุฑไม่ได้ (10+ จุดอ่านผ่าน `public_path`) → เลื่อนไป LEGACY-02 §02-A พร้อม test | — |
| **5** | Re-key `APP_KEY` + หมุน secret ทั้งหมด + เพิกถอน `personal_access_tokens` 9 รายการ | ⏳ **ยังไม่ทำ** — ต้องวางแผนและมี downtime · **จำเป็น เพราะกุญแจที่รั่วไปแล้วยังใช้ได้** | ทุกคนต้อง login ใหม่ |

> ข้อ 2–4 อยู่ในแผน LEGACY-01/02 อยู่แล้ว (01-1, 01-2, 02-A5) — ไม่ใช่งานใหม่
> แต่ **ควรทำทันทีแยกจากคิวของเฟส** เพราะเป็นการรั่วไหลที่กำลังเกิดขึ้นจริง

---

## 5. บันทึกการตัดสินใจของผู้ใช้ (2026-08-12)

| # | เรื่อง | คำตอบ | สถานะ |
|---|---|---|---|
| D-1 | Push baseline ขึ้น origin | **อนุญาต** | ✅ ทำแล้ว — `603b838` + tag `baseline/legacy-2026-08-12` อยู่บน `origin/main` (repo เป็น private) |
| D-2 | งานแจ้งเตือนค้างในคิว 16 รายการ | ตามที่แนะนำ | ⏳ **รอทำใน LEGACY-01** — แนะนำ: **ล้างทิ้ง** โดย export เก็บไว้ก่อน เพราะเป็นการแจ้งเตือนของใบลาทดสอบที่ถูกลบไปหมดแล้ว การส่งย้อนหลังจะทำให้ผู้อนุมัติได้รับแจ้งเตือนถึงใบลาที่ไม่มีอยู่จริง |
| D-3 | วิธีแก้ `leaves.status` | ตามที่แนะนำ = **(A)** | ⏳ **รอทำใน LEGACY-01/03** — แปลง `enum` → `varchar(20)` + `CHECK` constraint พร้อมเขียน test ครอบ `void()` ก่อนแก้ |
| D-4 | ระบบเข้าถึงได้จากอินเทอร์เน็ตหรือไม่ | **เข้าถึงได้จากอินเทอร์เน็ต** | 🔴 นำไปสู่ผลตรวจในเอกสารฉบับนี้ |
