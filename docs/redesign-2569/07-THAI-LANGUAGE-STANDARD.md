# มาตรฐานการแสดงผลภาษาไทย 100%

> ใช้กับทุกส่วนที่ผู้ใช้เห็น: หน้าจอ ข้อความ error ข้อความ validation สถานะ ประวัติการดำเนินการ แจ้งเตือน (ในระบบ / LINE / Telegram / อีเมล) PDF CSV/Excel และหน้า error ของเซิร์ฟเวอร์
> ไม่เปลี่ยน schema — ไม่ขัด Production Freeze ทำคู่ขนานกับเฟสอื่นได้ (PROJECT_RULES ข้อ 13)

## 1. กฎ

| # | กฎ | ตัวอย่าง |
|---|---|---|
| T1 | ข้อความที่ผู้ใช้เห็นต้องเป็นภาษาไทย | "Check In" → "ลงเวลาเข้างาน" |
| T2 | **ห้ามแสดงรหัสภายใน** (สถานะ ชนิดการกระทำ ชื่อสิทธิ์ ชื่อคีย์) ให้แปลงเป็นป้ายภาษาไทยเสมอ | `partial_cancellation_requested` → "ขอยกเลิกวันลาบางส่วน" |
| T3 | ชื่อเฉพาะที่ไม่มีคำไทยใช้ทับศัพท์ได้: ชื่อผลิตภัณฑ์ (LINE, Telegram) และชื่อแบบอักษร (TH Sarabun New) | — |
| T4 | ศัพท์เทคนิคในหน้าตั้งค่าใช้คำไทยนำหน้า และวงเล็บคำอังกฤษได้เฉพาะเมื่อผู้ดูแลต้องใช้เทียบกับเอกสารผู้ให้บริการ | "พอร์ตเซิร์ฟเวอร์อีเมล (SMTP Port)" |
| T5 | วันที่เป็นพุทธศักราช รูปแบบ "17 ต.ค. 2569" บนหน้าจอ และ "17 ตุลาคม พ.ศ. 2569" ในเอกสารราชการ | ใช้ `ThaiDateFormatter` ที่มีอยู่ |
| T6 | ตัวเลขบนหน้าจอใช้เลขอารบิก ตัวเลขในแบบฟอร์มราชการเป็นไปตามแม่แบบของแต่ละแบบฟอร์ม | — |
| T7 | ข้อความ error บอก **สาเหตุและสิ่งที่ผู้ใช้ทำต่อได้** ไม่ใช้คำกำกวม และไม่เปิดเผยรายละเอียดระบบ | "ต้องยื่นลาล่วงหน้าอย่างน้อย 60 วัน (ระเบียบการลา ข้อ…) หากมีเหตุจำเป็นให้แจ้งงานบุคลากร" |
| T8 | ชื่อฟิลด์ในข้อความ validation ใช้ชื่อไทยที่ตรงกับป้ายบนหน้าจอ | "กรุณาระบุวันที่เริ่มลา" ไม่ใช่ "The start date field is required." |
| T9 | log ภายใน ข้อความ commit และชื่อในโค้ดเป็นภาษาอังกฤษได้ (ไม่ใช่สิ่งที่ผู้ใช้เห็น) | — |

## 2. สิ่งที่พบ (ตรวจ 2026-10-17)

| # | ที่พบ | ผล | การแก้ |
|---|---|---|---|
| L1 | `APP_LOCALE` ตั้งเป็น `th` แต่ **ไม่มีโฟลเดอร์ `lang/th`** ทดสอบ `__('validation.required')` ได้ "The x field is required." | ข้อความ validation ที่ไม่ได้เขียนเองเป็นภาษาอังกฤษทั้งหมด — มี 48 ไฟล์ที่เรียก validate แต่มีข้อความเองแค่ 2 ไฟล์ และ frontend (`errorHandler.js`) ต่อข้อความ 422 ไปแสดงตรง ๆ | เพิ่ม `lang/th/validation.php`, `auth.php`, `passwords.php`, `pagination.php` และ `attributes` ชื่อฟิลด์ไทยทุกฟิลด์ที่ใช้ในฟอร์ม |
| L2 | `.env.example` ยังตั้ง `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en` | เครื่องใหม่หรือ production ที่สร้างจาก example จะเป็นอังกฤษ | ตั้ง `th` ทั้งคู่ และให้ `app:verify-environment` ตรวจว่า locale เป็น `th` |
| L3 | `bootstrap/app.php` ไม่ได้กำหนดข้อความ error ของ API | 401/403/404/419/429/500 ใช้ข้อความ Laravel ("Unauthenticated.", "This action is unauthorized.", "Too Many Attempts.") — frontend แปลงบางส่วน แต่ 403/404 ส่งต่อข้อความจาก server ก่อน | ทำ renderer กลางใน `withExceptions` คืนข้อความไทยตามสถานะ และไม่เปิดเผยรายละเอียดข้อผิดพลาด |
| L4 | `WorkflowTimeline.vue:139` และ `saraban/DocumentDetailView.vue:191` แสดง `log.action` / `step.action` ตรง ๆ | ผู้ใช้เห็นรหัส เช่น `partial_cancellation_requested` | API ส่ง `action_label` ภาษาไทย หรือแปลงผ่านตารางป้ายกลาง |
| L5 | ข้อความอังกฤษในหน้าจอ: `CheckInView.vue` (Check In / Check Out), `AlertList.vue` (Close), `WorkflowManagementView.vue` (Snapshot), `UsersManagementView.vue` (Fax, Telegram Chat ID), `TelegramSettingsView.vue` (Mail Port, Mail Username, Mail Password, Mail Encryption, None, Mail From Address, Mail From Name), `UserProfileView.vue` (Telegram Chat ID) | ไม่ตรง T1/T4 | แปลตาม T4 เช่น "ชื่อผู้ใช้เซิร์ฟเวอร์อีเมล", "การเข้ารหัส: ไม่มี / TLS / SSL", "รหัสแชต Telegram" |
| L6 | `components/HelloWorld.vue` (แม่แบบตั้งต้นของ Vite) ยังอยู่ | ข้อความอังกฤษ ไม่ได้ใช้งาน | ลบไฟล์ |
| L7 | ป้ายสถานะมีที่ frontend (`statusHelper.js`) แต่ backend ส่งรหัสอังกฤษ (`Pending`, `Approved`) ใน API, CSV และข้อความแจ้งเตือนบางจุด | CSV/แจ้งเตือนอาจแสดงรหัส | ตารางป้ายกลางฝั่ง backend (ดูข้อ 3) และ CSV ใช้ป้ายไทยเสมอ |
| L8 | คำสั่ง artisan 2 ไฟล์มีคำอธิบายอังกฤษ | ผู้ดูแลเห็นใน `php artisan list` | แปลคำอธิบาย (ไม่บังคับตาม T9 แต่ผู้ดูแลระบบใช้งานจริง) |

ตัวเลขข้างบนมาจากการค้นด้วยรูปแบบข้อความ อาจมีจุดที่ค้นไม่เจอ (เช่น ข้อความที่ประกอบจากตัวแปร) — ตรวจครบด้วยเครื่องมือในข้อ 4

### ความคืบหน้า (2026-10-17)

| รายการ | สถานะ |
|---|---|
| L1 | ทำแล้ว — `lang/th/validation.php` (ครบทุกกฎของ Laravel + ชื่อฟิลด์ไทยทุกฟิลด์ที่ controller ตรวจ), `auth.php`, `passwords.php`, `pagination.php`, `th.json` |
| L2 | ทำแล้ว — ค่าเริ่มต้น `APP_LOCALE` / `APP_FALLBACK_LOCALE` = `th` ทั้งใน `config/app.php` และ `.env.example` |
| L3 | ทำแล้ว — `App\Support\ThaiApiErrorResponse` แทนข้อความ error ภาษาอังกฤษของ API ด้วยข้อความไทยตามรหัสสถานะ (คงข้อความไทยที่โค้ดเขียนเอง) และไม่เปิดเผยชื่อ model ในข้อความ 404 อีก |
| L4 | ทำแล้ว — `action_label` ใน `LeaveActionLog` และ `DocumentWorkflowStep` แสดงในไทม์ไลน์ของใบลาและหนังสือสารบรรณ |
| L5 | ทำแล้ว — แปลข้อความในหน้าจอราว 95 จุดใน 29 ไฟล์ |
| L6 | ทำแล้ว — ลบ `HelloWorld.vue` |
| L7, L8 | ยังไม่ทำ |
| เครื่องมือตรวจ | ทำแล้ว — `npm run check:thai` ใน CI ล้มเมื่อพบข้อความอังกฤษใหม่ ค้างใน `frontend/scripts/thai-baseline.json` 6 รายการ (ตัวอย่างรหัสในหน้าผู้ดูแลระบบ) |

**พบระหว่างตรวจ:** `saraban/DocumentDetailView.vue` มีบรรทัด `import { ICONS } ...` อยู่ในแม่แบบแทนที่จะอยู่ใน `<script>` ข้อความนี้จึงแสดงบนหน้าจอ และไอคอน 4 จุดในหน้านั้นอ้าง `ICONS` ที่ไม่ได้ import — แก้แล้ว

## 3. ตารางป้ายกลาง

- backend: `app/Support/Labels/` หนึ่งคลาสต่อชุดรหัส (`LeaveStatusLabel`, `LeaveActionLabel`, `ApprovalActionKindLabel`, `LedgerEntryTypeLabel`, `PermissionLabel`, `ScopeLabel` …) แต่ละคลาสมี `label(string $code): string` และ **ต้องครอบคลุมทุกรหัส** — test วนทุกค่าคงที่/ทุกค่าในฐานข้อมูลแล้วต้องได้ข้อความไทยที่ไม่ใช่รหัสเดิม
- API resource ส่งทั้ง `status` (รหัส ใช้ในตรรกะ) และ `status_label` (ไทย ใช้แสดงผล)
- frontend: `statusHelper.js` อ่าน `*_label` จาก API เป็นหลัก ตารางในไฟล์เหลือไว้เป็นค่าสำรองเท่านั้น
- ชื่อสิทธิ์ใช้ `permissions.description` ภาษาไทย (มีคอลัมน์ใน Spatie หรือเพิ่มใน manifest ของโมดูล) หน้าจัดการบทบาทไม่แสดงชื่อสิทธิ์ดิบ

## 4. เครื่องมือตรวจอัตโนมัติ (ใส่ใน CI)

| ตรวจ | วิธี |
|---|---|
| ข้อความอังกฤษในแม่แบบ Vue | สคริปต์ `frontend/scripts/check-thai.mjs` แยก text node และ attribute `placeholder`, `title`, `aria-label`, `label` ของทุก `.vue` แล้วรายงานคำที่เป็นอักษรละติน นอกรายการยกเว้น (`.thai-allowlist.json`: LINE, Telegram, PDF, CSV, OT, QR, TLS, SSL, ชื่อแบบอักษร) — CI ล้มเมื่อพบรายการใหม่ |
| ข้อความ validation | test วนทุก rule ที่ใช้ใน `app/` แล้ว `__('validation.<rule>')` ต้องไม่ขึ้นต้นด้วย "The " |
| error ของ API | test ยิง 401/403/404/419/422/429/500 แล้วข้อความต้องเป็นภาษาไทย |
| ป้ายรหัส | test ครอบคลุมทุกรหัสในข้อ 3 |
| PDF/CSV | test สร้างเอกสารตัวอย่างของทุกแบบฟอร์มแล้วตรวจว่าไม่มีรหัสสถานะดิบในเนื้อหา |
| แจ้งเตือน | test สร้างแจ้งเตือนทุกชนิดใน `NotificationCenter` แล้วหัวเรื่องและเนื้อหาเป็นภาษาไทย |

## 5. ลำดับการทำ

1. L1 + L2 + L3 (กระทบทุกหน้าจอ แก้ที่เดียวได้ผลมากที่สุด)
2. ตารางป้ายกลาง (ข้อ 3) + L4 + L7
3. L5 + L6 + L8
4. เปิดเครื่องมือตรวจในข้อ 4 ใน CI ให้ล้มเมื่อพบรายการใหม่

## Definition of Done

- [ ] เครื่องมือตรวจในข้อ 4 ผ่านทั้งหมดใน CI
- [ ] ทดลองใช้งานจริงทุกเมนูด้วยบัญชีแต่ละบทบาท ไม่พบข้อความอังกฤษหรือรหัสดิบ (บันทึกภาพหน้าจอใน checkpoint)
- [ ] เอกสารนี้อัปเดตรายการยกเว้นทุกครั้งที่เพิ่ม
