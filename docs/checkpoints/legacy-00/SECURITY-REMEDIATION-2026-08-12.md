# การแก้ไขช่องโหว่เร่งด่วน — 12 สิงหาคม 2569

> สืบเนื่องจากคำตอบ D-4 ที่ยืนยันว่าระบบเข้าถึงได้จากอินเทอร์เน็ต
> ดำเนินการนอกคิวของเฟส เพราะเป็นการรั่วไหลที่กำลังเกิดขึ้นจริง
>
> **ยังไม่ได้เริ่ม LEGACY-01 อย่างเป็นทางการ** — งานนี้คือการหยุดเลือดเท่านั้น

---

## สรุปสถานะช่องโหว่

| ID | ช่องโหว่ | ก่อน | หลัง |
|---|---|:---:|:---:|
| SEC-020 | `APP_KEY` รั่วผ่าน `phpinfo()` | 🔴 เปิด | ✅ **ปิด + หมุนกุญแจแล้ว** |
| SEC-010 | `test_db.php` มี DB credential / `phpinfo()` | 🔴 เปิด | ✅ ปิด |
| SEC-011 | `GET /fix-leaves` เขียน DB โดยไม่ยืนยันตัวตน | 🔴 เปิด | ✅ ปิด |
| SEC-001 | ไฟล์ส่วนบุคคลเปิดสาธารณะ (2 เส้นทาง) | 🔴 เปิด | ✅ **ปิดทั้งสองเส้นทาง** |
| SEC-012 | Token ไม่หมดอายุ | 🟠 เปิด | 🟠 เปิดอยู่ — token เดิมถูกเพิกถอนหมดแล้ว แต่ยังไม่ได้ตั้ง `expiration` |

---

## รอบที่ 1 — commit `f843b3c`

ลบไฟล์ debug 3 ไฟล์ (`public/info.php`, `test.php`, `test_db.php`) · ถอด route `GET /fix-leaves`
· ถอด route `GET /api/storage/{path}` (ตรวจแล้วว่าเป็น dead route ไม่มีโค้ดที่ไหนเรียก)

---

## รอบที่ 2 — หมุน `APP_KEY` และเข้ารหัสข้อมูลใหม่

### วิธีที่ใช้

ใช้กลไก `APP_PREVIOUS_KEYS` ของ Laravel ซึ่งจะถอดรหัสด้วย `APP_KEY` ปัจจุบันก่อน
แล้วไล่ลองกุญแจเก่าถ้าไม่สำเร็จ — ทำให้ re-key ได้โดยไม่มีช่วงที่ข้อมูลอ่านไม่ได้

```
1. สำรอง DB + storage + .env  (ตรวจ checksum แล้ว)
2. APP_KEY = กุญแจใหม่ , APP_PREVIOUS_KEYS = กุญแจเดิม
3. php artisan app:rekey-encrypted-data --dry-run   → ตรวจว่าถอดรหัสได้ครบ
4. php artisan app:rekey-encrypted-data             → เขียนจริง
5. ลบ APP_PREVIOUS_KEYS ออกจาก .env
6. ยืนยันว่าอ่านข้อมูลได้ด้วยกุญแจใหม่เพียงลำพัง
7. เพิกถอน token ทั้งหมด
```

### คำสั่งใหม่: `app:rekey-encrypted-data`

`app/Console/Commands/RekeyEncryptedDataCommand.php` — ครอบคลุม
`user_profiles.id_card_number`, `user_profiles.passport_number`, `users.phone`
และค่า secret ในตาราง `settings` (รูปแบบ `enc:`)

**จุดที่พลาดง่ายและคำสั่งนี้จัดการให้:** `user_profiles.id_card_number_hash`
คำนวณจาก `sha256(เลขบัตร + config('app.key'))` — **ผูกกับ `APP_KEY` โดยตรง**
ถ้าหมุนกุญแจโดยไม่คำนวณ hash ใหม่ **การเข้าสู่ระบบด้วยเลขบัตรประชาชนจะพังทั้งระบบ**
(`AuthController::login` ค้นผู้ใช้จาก hash นี้)

คำสั่งไม่พิมพ์ค่าที่ถอดรหัสได้ออกมาเลย เพื่อไม่ให้ข้อมูลอ่อนไหวไปอยู่ใน log

### ผลการดำเนินการ

| รายการ | ผล |
|---|---|
| `user_profiles` | 6/6 แถวเข้ารหัสใหม่ |
| `users.phone` | 6/7 แถว (1 แถวไม่มีเบอร์) |
| `settings` secret | 0 ค่า (ยังไม่เคยตั้ง LINE/Telegram/mail token) |
| ถอดรหัสล้มเหลว | **0** |

### การยืนยันหลังลบ `APP_PREVIOUS_KEYS`

```
APP_PREVIOUS_KEYS ที่ config เห็น: 0 รายการ
user_profiles.id_card_number : ถอดรหัสสำเร็จ 6 / ล้มเหลว 0
id_card_number_hash          : ตรง 6 / ไม่ตรง 0
users.phone                  : ถอดรหัสสำเร็จ 6 / ล้มเหลว 0
จำลอง login ด้วยเลขบัตร      : หา user เจอ (ใช้งานได้)
```

ciphertext และ hash เปลี่ยนค่าทั้งหมด (ตรวจด้วย MD5 รวมก่อน/หลัง) → ยืนยันว่าเขียนใหม่จริง

### เพิกถอน token

Sanctum เก็บ token เป็น `sha256` **ไม่ได้ผูกกับ `APP_KEY`** จึงยังใช้ได้หลังหมุนกุญแจ
ต้องลบเอง — ลบแล้ว **9 → 0 รายการ** ยืนยันว่า `GET /api/user` ด้วย token เดิมได้ 401

> **ผู้ใช้ทุกคนต้องเข้าสู่ระบบใหม่**

---

## รอบที่ 3 — ปิด `/storage/{path}` ให้จบ

### สิ่งที่ทำให้ต่างจากรอบแรก

รอบแรกผมไม่ปิดเส้นทางนี้เพราะการลบ symlink จะทำให้ PDF ฝังลายเซ็นและตราครุฑไม่ได้
(มีโค้ด 16 จุดอ่านผ่าน `public_path('storage/...')`) รอบนี้แก้ที่รากของปัญหาแทน

### การออกแบบ

เพิ่ม disk ใหม่ `private_uploads` (root = `storage/app/private/uploads`, visibility private)
**โดยคงรูปแบบค่าที่เก็บใน DB ไว้เหมือนเดิมทุกประการ** (`/storage/<relative>`)
จึง **ไม่ต้อง backfill ฐานข้อมูลเลย** — ลดความเสี่ยงลงมาก

| ส่วน | การเปลี่ยนแปลง |
|---|---|
| `config/filesystems.php` | เพิ่ม disk `private_uploads` |
| `app/Support/StoredFile.php` (ใหม่) | จุดเดียวที่รู้วิธีแปลง `/storage/x.png` → path จริง พร้อมกัน path traversal และ null byte |
| `app/Http/Controllers/SecureFileController.php` (ใหม่) | เสิร์ฟไฟล์ผ่าน `GET /api/files/{path}` ใต้ `auth:sanctum` พร้อม `Cache-Control: private, no-store` และ `X-Content-Type-Options: nosniff` |
| Writer 12 ไฟล์ | `disk('public')` → `disk('private_uploads')` |
| Reader 16 จุด | `public_path(str_replace(...))` → `\App\Support\StoredFile::path(...)` |
| ไฟล์บนดิสก์ | ย้าย `storage/app/public/*` → `storage/app/private/uploads/*` |
| symlink | ลบ `backend/public/storage` |
| Frontend | เพิ่ม `utils/secureFile.js` + directive `v-secure-src` · เปลี่ยน `<img :src>` 7 จุด และลิงก์ดาวน์โหลด 2 จุด ให้ดึงผ่าน endpoint ที่ยืนยันตัวตน (blob + object URL เพราะ `<img src>` แนบ Authorization header ไม่ได้) |

### ผลกระทบต่อข้อมูล: ไม่มี

ตรวจทุกคอลัมน์ที่อาจอ้างถึงไฟล์ — **ไม่มีแถวใดอ้างถึงเลย**

| คอลัมน์ | แถวที่อ้างถึงไฟล์ |
|---|---:|
| `user_profiles.signature_path` | 0 |
| `user_profiles.profile_photo_path` | 0 |
| `leaves.signature_path` | 0 |
| `leaves.attachment` | 0 |
| `leave_approvals.signature_path` | 0 |
| `attendances.check_in_image` | 0 |
| `settings` (โลโก้) | 0 |

ลายเซ็น 11 ไฟล์ที่มีอยู่เป็น **ไฟล์กำพร้าจากการทดสอบ** ไม่มีอะไรชี้ถึง

---

## ผลการตรวจสอบทั้งหมด

### การเข้าถึงจากภายนอก (ไม่ส่ง token)

| Endpoint | เริ่มต้น | หลังรอบ 1 | หลังรอบ 3 |
|---|:---:|:---:|:---:|
| `/info.php` | 200 | 404 | 404 |
| `/test.php` | 200 | 404 | 404 |
| `/test_db.php` | 200 | 404 | 404 |
| `/fix-leaves` | 200 | 404 | 404 |
| `/api/storage/{path}` | **200** | 404 | 404 |
| `/storage/{path}` | **200** | **200** | **403** ✅ |
| `/api/files/{path}` | — | — | **401** ✅ |
| `/api/ping` | 200 | 200 | 200 ✅ |

### endpoint ใหม่ (ส่ง token ที่ถูกต้อง)

| คำขอ | ผล |
|---|---|
| `GET /api/files/signatures/6a36b10d67692.png` | **200** · 5,093 bytes · `image/png` · sha256 ตรงกับต้นฉบับ ✅ |
| `GET /api/files/../../../.env` | **404** (กัน traversal ได้) ✅ |
| `GET /api/files/signatures/does-not-exist.png` | 404 ✅ |

### Regression

| การทดสอบ | ผล |
|---|---|
| `php artisan test` หลังรอบ 1 | 86/86 ผ่าน |
| `php artisan test` หลัง re-key | 86/86 ผ่าน |
| `php artisan test` หลังย้าย storage | **86/86 ผ่าน (256 assertions)** |
| `php -l` ทุกไฟล์ที่แก้ | ไม่มี syntax error |
| `npm run build` | ผ่าน |
| `StoredFile::relative()` กับ input อันตราย (`../`, `%2f`, ว่าง) | คืน `null` ทุกกรณี |

---

## สิ่งที่ยังเหลือ

| # | เรื่อง | ใครทำ |
|---|---|---|
| 1 | **ปิด port forward ที่ router** — ยังควรทำเป็น defence in depth | **ผู้ใช้** |
| 2 | **`APP_KEY` เก่าที่รั่วไปแล้ว** — ข้อมูลถูกเข้ารหัสใหม่หมดแล้ว กุญแจเก่าจึงใช้กับข้อมูลปัจจุบันไม่ได้ **แต่ถ้ามีใครเคย dump ฐานข้อมูลชุดเก่าไว้ระหว่างที่ `info.php` เปิดอยู่ เขาถอดรหัสชุดนั้นได้** — ประเมินว่าความเสี่ยงจำกัด เพราะข้อมูลจริงมีเพียง 6 โปรไฟล์ทดสอบ | รับทราบ |
| 3 | **รหัสผ่าน MySQL** (`root` ไม่มีรหัส) รั่วไปกับ `phpinfo()` ด้วย — แต่ port ผูกกับ `127.0.0.1` เท่านั้น ไม่ได้เปิดออกอินเทอร์เน็ต | LEGACY-01 (01-3) |
| 4 | **`sanctum.expiration`** ยังเป็น `null` (token ไม่หมดอายุ) | LEGACY-02 (02-C1) — รอคำตอบว่าจะตั้งกี่นาที |
| 5 | **`/api/files/{path}` ยังไม่มี ABAC scope รายไฟล์** — ผู้ใช้ที่ล็อกอินแล้วยังเปิดไฟล์ของผู้อื่นได้ถ้ารู้ path (คอมเมนต์กำกับไว้ในคลาสแล้ว) | LEGACY-02 (02-B) |
| 6 | ช่องโหว่ IDOR ที่เหลือ (SEC-003/004/005/006) และ e-signature (SEC-007/008) | LEGACY-02 |

> ข้อ 5 เป็นการลดความรุนแรงลง **ไม่ใช่ปิดสนิท**: จากเดิม "ใครก็ได้บนอินเทอร์เน็ต"
> เหลือ "ผู้ใช้ที่มีบัญชีในระบบ" ซึ่งเป็นระดับเดียวกับ SEC-003/005 ที่เหลืออยู่
> การปิดสนิทต้องมี Policy รายทรัพยากรใน LEGACY-02

---

## Rollback

| จะย้อน | วิธี |
|---|---|
| โค้ดทั้งหมด | `git revert` commit ของรอบ 1 และ 3 |
| ไฟล์กลับไป public disk | `mv storage/app/private/uploads/* storage/app/public/` แล้วสร้าง symlink ใหม่ `php artisan storage:link` |
| ฐานข้อมูลก่อน re-key | `docs/checkpoints/legacy-00/backups/eleave-pre-rekey-2026-08-12.sql` |
| `APP_KEY` เดิม | `docs/checkpoints/legacy-00/backups/env-pre-rekey-2026-08-12.backup` และ `backend/.env.bak-rekey` |

> ⚠️ การย้อน DB ต้องย้อน `APP_KEY` พร้อมกันเสมอ มิฉะนั้นข้อมูลที่เข้ารหัสจะถอดไม่ได้
> ไฟล์สำรองทั้งหมดอยู่นอก git (gitignore) — **ต้องเก็บ `.env` สำรองไว้ในที่ปลอดภัย เพราะมีกุญแจเก่า**
