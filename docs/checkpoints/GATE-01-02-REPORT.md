# GATE-01 / GATE-02 — ปิด operational gate ของ LEGACY-01

วันที่: 2026-08-12
สถานะ: **GATE-01 PASS · GATE-02 PARTIAL (ตามเจตนา) · เหลือ 1 เรื่องที่ agent ทำแทนไม่ได้**

`docs/checkpoints/LEGACY-01-REPORT.md` ประกาศ implementation PASS แต่ operational WARNING
เพราะเหลือ 2 gate เอกสารฉบับนี้บันทึกการปิด gate เหล่านั้น

---

## GATE-01 — หมุน MySQL credential · **PASS**

### ปัญหาเดิม

application เชื่อมฐานข้อมูลด้วย `root` แบบ **ไม่มีรหัสผ่าน** และ credential ถูก hardcode
อยู่ใน `docker-compose.yml` ซึ่ง commit เข้า git · นอกจากนี้ยังมีบัญชี `root@%` (remote root)
ที่ container ใด ๆ ในเครือข่าย docker เข้าถึงได้

รหัสผ่านชุดนี้ยังรั่วออกอินเทอร์เน็ตไปแล้วผ่าน `phpinfo()` (SEC-020)

### สิ่งที่ทำ

| ขั้น | รายละเอียด |
|---|---|
| 1 | สำรอง DB + `.env` + `docker-compose.yml` ก่อนเริ่ม (เก็บนอก git) |
| 2 | สร้างรหัสผ่านสุ่ม 32 ตัวอักษร 2 ชุดจาก `/dev/urandom` เขียนตรงลง `.env` ที่ root ของโปรเจกต์ (`chmod 600`, gitignore) — **ไม่เคยพิมพ์ค่าออกทาง output หรือ log** |
| 3 | สร้าง `eleave_app@%` พร้อมสิทธิ์เท่าที่จำเป็นบน schema `eleave` เท่านั้น |
| 4 | ตั้งรหัสผ่าน `root@localhost` |
| 5 | **ถอด `root@%` ออกทั้งบัญชี** (ไม่มี remote root อีกต่อไป) |
| 6 | เปลี่ยน `docker-compose.yml` ให้อ่านค่าจาก `${...}` แทน hardcode ทั้ง 3 service (backend/worker/scheduler) พร้อม `:?` เพื่อให้ compose ล้มทันทีถ้าลืมตั้ง `.env` |
| 7 | แก้ healthcheck ให้ใช้รหัสผ่าน (เดิมใช้ `-uroot` เปล่า ๆ ซึ่งจะพังหลังตั้งรหัส) |
| 8 | sync `backend/.env` ให้ตรงกัน |
| 9 | `docker compose up -d` แล้วตรวจทุกอย่าง |

### สิทธิ์ที่ให้ app user

```
GRANT USAGE ON *.* TO `eleave_app`@`%`
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER,
      LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, EVENT, TRIGGER
  ON `eleave`.* TO `eleave_app`@`%`
```

DDL (`CREATE/DROP/ALTER/INDEX/REFERENCES`) จำเป็นเพราะแอปรัน migration เอง
`LOCK TABLES/SHOW VIEW/TRIGGER/EVENT` จำเป็นสำหรับ `mysqldump` ของ `spatie/laravel-backup`
**ไม่มี** `GRANT OPTION`, ไม่มีสิทธิ์ระดับ global, เข้าถึง schema อื่นไม่ได้

### ผลตรวจ

| การตรวจ | ผล |
|---|---|
| `root` ไม่มีรหัสผ่าน | ถูกปฏิเสธ ✓ |
| `root` ผ่าน TCP (remote) | ถูกปฏิเสธ — บัญชีถูกถอดแล้ว ✓ |
| `root@localhost` (เส้นทางกู้คืนผ่าน `docker exec`) | ใช้ได้ ✓ |
| app user อ่าน `mysql.user` | ถูกปฏิเสธ ✓ |
| app user เห็น schema | เฉพาะ `eleave`, `information_schema`, `performance_schema` ✓ |
| `SELECT CURRENT_USER()` จากแอป | `eleave_app@%` ✓ |
| `php artisan migrate:status` | อ่านได้ครบ ✓ |
| `GET /api/ping` | 200 ✓ |
| container ทั้งหมด | `eleave_db` healthy · backend/worker/scheduler ขึ้นปกติ ✓ |
| `php artisan backup:run --only-db` | **Backup completed + verified** ✓ (จุดที่เสี่ยงพังที่สุดจากการลดสิทธิ์) |
| `php artisan test` | **130 passed / 351 assertions** ✓ |

### บัญชีที่เหลือในระบบ

```
eleave_app@%
mysql.infoschema@localhost
mysql.session@localhost
mysql.sys@localhost
root@localhost
```

### ที่เก็บ secret

`/.env` ที่ root ของโปรเจกต์ — `chmod 600`, อยู่ใน `.gitignore` และ docker compose อ่านอัตโนมัติ

> ⚠️ ไฟล์นี้คือ **สำเนาเดียว** ของรหัสผ่าน · ถ้าหายต้องตั้งใหม่ผ่าน `root@localhost`
> ควรสำรองเข้า password manager ขององค์กร และ **ห้ามส่งผ่านช่องทางที่ไม่เข้ารหัส**

### ข้อจำกัดที่ยังเหลือ

`docker-compose.yml` เก็บ `.env` ไว้บนเครื่องเดียวกับแอป ซึ่งดีกว่าเดิมมากแต่ยังไม่ใช่
secret manager จริง — ถ้าจะขึ้น production เต็มรูปแบบควรย้ายไปใช้ Docker secrets
หรือ secret manager ขององค์กรตาม `docs/05-SECURITY-STANDARDS.md`

---

## GATE-02 — ข้อมูลองค์กรที่ได้รับการรับรอง · **PARTIAL ตามเจตนา**

รายงาน LEGACY-01 ระบุ 3 ชุดข้อมูลที่ยังว่าง ผมแยกออกเป็น 2 กลุ่มตามว่า
**เป็นมาตรฐานระดับประเทศ** หรือ **เป็นข้อมูลเฉพาะของสถานศึกษา**

### ทำแล้ว — `fiscal_years`

ปีงบประมาณไทยคือ **1 ตุลาคม – 30 กันยายน** และเรียกชื่อตามปี พ.ศ. ที่สิ้นสุด
เป็นมาตรฐานระดับประเทศ ไม่ใช่ทางเลือกของสถานศึกษา จึงสร้างได้โดยไม่ต้องรอการรับรอง

เพิ่ม `database/seeders/FiscalYearSeeder.php` (idempotent) และลงทะเบียนใน `DatabaseSeeder`

| ปี | เริ่ม | สิ้นสุด | ปัจจุบัน |
|---|---|---|:---:|
| 2567 | 2023-10-01 | 2024-09-30 | |
| 2568 | 2024-10-01 | 2025-09-30 | |
| **2569** | **2025-10-01** | **2026-09-30** | **YES** |
| 2570 | 2026-10-01 | 2027-09-30 | |
| 2571 | 2027-10-01 | 2028-09-30 | |

ตรวจแล้ว: `FiscalYear::current()` คืน 2569 · มีแถว `is_current` เพียง 1 แถว ·
วันนี้อยู่ในช่วงของปีปัจจุบันจริง · รันซ้ำแล้วยังคง 5 แถว (idempotent) · test 130/130 ผ่าน

**เหตุผลที่สำคัญ:** `fiscal_years` ว่างทำให้ `FiscalYear::current()` คืน `null`
ซึ่งกระทบการคำนวณสิทธิ์วันลาและการออกเลขที่หนังสือ — เป็น bug ที่พบตั้งแต่ LEGACY-00

### ยังไม่ทำโดยตั้งใจ — `holidays` และ `document_templates`

| ข้อมูล | เหตุผลที่ไม่สร้างเอง |
|---|---|
| `holidays` | วันหยุดราชการประกาศตามมติคณะรัฐมนตรีรายปี และสถานศึกษายังมีวันหยุดเฉพาะของตนเอง (วันสถาปนา ปิดภาคเรียน ฯลฯ) การเดาจะทำให้ **คำนวณจำนวนวันลาผิด** ซึ่งกระทบสิทธิ์ของบุคลากรโดยตรง |
| `document_templates` | แม่แบบหนังสือราชการต้องตรงกับฟอร์มจริงของวิทยาลัย รวมถึงตราครุฑ หัวกระดาษ และผู้ลงนาม การสร้างแม่แบบสมมติแล้วให้พิมพ์ออกไปใช้จริงเป็นความเสี่ยงเชิงเอกสารราชการ |

**สิ่งที่ต้องการจากเจ้าของกระบวนงาน:**

1. ปฏิทินวันหยุดของวิทยาลัยปีงบประมาณ 2569 (และ 2570 ถ้ามีแล้ว)
2. ไฟล์แม่แบบหนังสือราชการที่ใช้จริง พร้อมระบุว่าแบบไหนใช้กับการลาประเภทใด

เมื่อได้ข้อมูลแล้วจะทำ import แบบ idempotent เหมือน `FiscalYearSeeder`

> หมายเหตุ: ระบบยังใช้งานได้โดยไม่มีข้อมูลสองชุดนี้ — การคำนวณวันลาจะไม่ตัดวันหยุด
> (นับวันหยุดเป็นวันลา ซึ่งเสียเปรียบผู้ลา) และการพิมพ์ฟอร์มจะ fallback ไปใช้ blade template เดิม

---

## เรื่องที่ agent ทำแทนไม่ได้ — ปิด port forward ที่ router

`docker-compose.yml` เปิด `8001:8000` (backend) และ `5173:5173` (frontend) บน `0.0.0.0`
ส่วน `db` ผูกกับ `127.0.0.1` อยู่แล้วจึงไม่ออกอินเทอร์เน็ต

การที่ระบบเข้าถึงได้จากอินเทอร์เน็ตเกิดจาก **การ forward port ที่ router/firewall** ซึ่งอยู่
นอกเครื่องนี้ — ผมไม่มีสิทธิ์เข้าถึงอุปกรณ์เครือข่าย

**ทางเลือกที่ทำได้จากฝั่งนี้** (ยังไม่ได้ทำ เพราะจะตัดการเข้าถึงจากภายนอกทั้งหมดทันที):

เปลี่ยน binding ใน `docker-compose.yml` เป็น
```
- "127.0.0.1:8001:8000"
- "127.0.0.1:5173:5173"
```
ผลคือเข้าถึงได้เฉพาะจากเครื่องที่รัน docker เท่านั้น · **ผู้ใช้ระยะไกลทุกคนจะเข้าระบบไม่ได้**
จึงควรทำก็ต่อเมื่อยืนยันแล้วว่าไม่มีผู้ใช้จริงที่พึ่งการเข้าถึงจากภายนอก

---

## สรุปสถานะหลังปิด gate

| เรื่อง | สถานะ |
|---|---|
| LEGACY-01 implementation | PASS |
| GATE-01 credential rotation | **PASS** |
| GATE-02 fiscal years | **PASS** |
| GATE-02 holidays / document templates | รอข้อมูลจากเจ้าของกระบวนงาน |
| ปิด port forward | รอผู้ดูแลระบบ |
| LEGACY-02 | ยังไม่เริ่ม |

## Rollback

| จะย้อน | วิธี |
|---|---|
| credential | `docs/checkpoints/legacy-00/backups/docker-compose-pre-gate01.yml` + `env-pre-gate01-2026-08-12.backup` แล้ว `ALTER USER` กลับผ่าน `root@localhost` |
| ฐานข้อมูล | `docs/checkpoints/legacy-00/backups/eleave-pre-gate01-2026-08-12.sql` |
| ปีงบประมาณ | ลบแถวใน `fiscal_years` หรือแก้ `is_current` ผ่านหน้า admin ที่มีอยู่แล้ว |
