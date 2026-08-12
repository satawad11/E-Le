# LEGACY-01 — Stabilization Report

วันที่: 2026-08-12  
สถานะ: **WARNING — implementation และ automated gates ผ่านทั้งหมด แต่ยังมี 2 operational gates ที่ต้องใช้ข้อมูล/secret จากผู้ดูแลระบบ**  
ขอบเขต: LEGACY-01 เท่านั้น — **ไม่ได้เริ่ม LEGACY-02**

## Executive summary

LEGACY-01 ทำให้ baseline สามารถสำรอง/กู้คืนอัตโนมัติ, รัน worker/scheduler, ตรวจ CI, migrate จากฐาน MySQL 8 ว่าง, build frontend, ตรวจ production environment, trace request และใช้งานสถานะ `Voided` ได้จริง พร้อมแก้ null-role crash ใน LeaveController และลบไฟล์พัฒนาที่ไม่ควรอยู่ใน repository

Exit criteria เชิงเทคนิคผ่าน: Pint ผ่าน 289 ไฟล์, backend ผ่าน 130 tests / 351 assertions, fresh migration ผ่าน 94 migrations บน MySQL 8, frontend production build ผ่าน และ Composer audit เหลือ 0 advisory

ยังไม่ประกาศ PASS สมบูรณ์ เนื่องจาก application runtime ปัจจุบันยังใช้ MySQL `root` แบบไม่มีรหัสผ่าน และข้อมูลปีงบประมาณ/วันหยุด/แม่แบบเอกสารต้องใช้ข้อมูลที่องค์กรรับรอง ไม่ควรสร้างค่าจำลองแทนองค์กร

## ผลงานตามรายการ LEGACY-01

| รายการ | ผล | หลักฐาน |
|---|---|---|
| 01-0 Automated backup | PASS | ติดตั้ง `spatie/laravel-backup` 10.3.1, backup private disk, schedule 02:00, worker/scheduler ทำงาน |
| 01-1 Dangerous public files | PASS | ไม่พบ `info.php`, `test.php`, `test_db.php` ใน public |
| 01-2 `/fix-leaves` | PASS | ไม่พบ route implementation; เหลือเฉพาะ comment อธิบายการถอดออก |
| 01-3 DB credentials | **WARNING** | ตรวจพบ runtime ใช้ `root` และไม่มี password; ไม่สร้าง/เปิดเผย secret สมมติแทน deployment operator |
| 01-4 Dev artifacts | PASS | ลบ `debug.php`, `fix.php`, `fix_hash.php`, `seed_balances.php`, `sqlite_test.php`, `test_login.php`, `test_api_call.php`, `sync_test_accounts.php`, `eleave`, PHPUnit cache |
| 01-5 Tests green | PASS | 130 tests, 351 assertions, 174.21 วินาที |
| 01-6 CI | PASS (local verification) | เพิ่ม GitHub Actions: Pint + tests + MySQL 8 migration + frontend build |
| 01-7 Production env validation | PASS | หยุด bootstrap เมื่อ `APP_ENV=production` และ `APP_DEBUG=true`; 4 tests ผ่าน |
| 01-8 Structured logging / correlation ID | PASS | JSON log ไป stderr, safe incoming/generated `X-Request-ID`; 2 tests / 6 assertions ผ่าน |
| 01-9 LeaveController null role | PASS | ใช้ `leave.management.manage`; ผู้ใช้ไม่มี role ได้ 403 แทน 500; 2 regression tests ผ่าน |
| 01-10 `Voided` schema | PASS | forward-only migration: `VARCHAR(20)` + `leaves_status_check`; void + soft delete + audit ผ่าน 5 assertions |
| 01-11 Missing seed data | PARTIAL | MenuSeeder เติม 15 เมนู; ปีงบประมาณ/วันหยุด/แม่แบบคงว่างเพื่อรอข้อมูลรับรอง |

## Backup และ restore drill

- Backup archive: `backend/storage/backups/Laravel/2026-08-12-19-56-27.zip`
- ขนาด: 802,215 bytes (783.41 KB)
- เนื้อหา: database dump + `storage/app` บน private backup disk
- Integrity verification: ผ่าน
- Restore database แยก: `eleave_legacy01_restore`
- Restore สำเร็จ: 55 tables
- Row count เทียบตรง: users 7, leaves 3, leave_approvals 6, leave_balances 11, leave_policies 49, attendances 1, jobs 16 และตารางสำคัญอื่นที่ตรวจ
- Orphan leave-user: 0
- หลังตรวจสำเร็จ ลบเฉพาะ restore database และ temporary extraction แล้ว
- Queue ทดสอบ 16 jobs ถูกสำรอง/กู้คืนยืนยันก่อนล้าง; ปัจจุบัน queue = 0

## Migration verification

1. Backup และ restore drill ผ่านก่อนแก้ schema
2. รัน migration บนฐานใช้งานสำเร็จ
3. ตรวจ `leaves.status`: `varchar(20)`
4. ตรวจ constraint: `leaves_status_check` มีอยู่
5. สร้างฐาน MySQL 8 ว่าง `eleave_legacy01_ci`
6. รัน migration ตั้งแต่ศูนย์ครบ 94 rows
7. ตรวจปลายทางได้ `varchar(20)` และ CHECK constraint 1 รายการ
8. ลบเฉพาะฐานทดสอบหลัง verification

Migration ใหม่:

- `2026_08_12_210000_replace_leave_status_enum_with_checked_varchar.php`
- `down()` intentionally no-op เพราะเป็น forward-only; การย้อนกลับเป็น ENUM จะทำให้แถว `Voided` ใช้ไม่ได้

## CI และ quality gates

| Gate | ผล |
|---|---|
| `vendor/bin/pint --test` | PASS — 289 files |
| `composer validate --strict --no-check-publish` | PASS |
| `composer audit` | PASS — 0 advisories |
| Targeted production guard | PASS — 4 tests |
| Targeted request context | PASS — 2 tests / 6 assertions |
| Targeted LeaveController null role | PASS — 2 tests |
| Targeted void flow | PASS — 1 test / 5 assertions |
| Targeted PDF after Dompdf update | PASS — 4 tests / 10 assertions |
| Full backend suite | PASS — 130 tests / 351 assertions |
| Fresh MySQL 8 migration | PASS — 94 migrations |
| Frontend `npm run build` | PASS — 877 modules, 59.22 วินาที |

หมายเหตุ: Pint พบ style debt เดิม 169 issues และจัดรูปแบบเชิงกลให้ทั้ง backend เพื่อให้ CI lint gate ผ่าน ไม่มี intentional logic change จากการ format และ full suite ผ่านหลัง format

## Dependency changes

- เพิ่ม `spatie/laravel-backup` 10.3.1
- อัปเดต `dompdf/dompdf` 3.1.5 → 3.1.6 เพื่อปิด 6 advisories (medium/low)
- จำกัด wildcard dependencies เป็น bounded constraints:
  - `chillerlan/php-qrcode ^6.0`
  - `setasign/fpdf ^1.9`
  - `setasign/fpdi ^2.6`
- `composer audit`: 0 advisory

## Runtime services

- `eleave_backend`: Up
- `eleave_worker`: Up; `queue:work --sleep=3 --tries=3 --timeout=120`
- `eleave_scheduler`: Up; `schedule:work`
- ใช้ named volume `backend_vendor` ร่วมกันเพื่อป้องกัน worker/scheduler เริ่มโดยไม่มี autoloader
- Scheduled backup: ทุกวัน 02:00 และ `withoutOverlapping()`

## API / behavior changes

- ไม่มี public write endpoint ใหม่
- ทุก API response ผ่าน middleware ใหม่จะมี `X-Request-ID`
- รับ caller request ID เฉพาะ `[A-Za-z0-9._-]` ยาวไม่เกิน 128; ค่าไม่ปลอดภัยถูกแทนด้วย UUID v4
- Leave update/delete ใช้ permission `leave.management.manage` แทนชื่อ role และ null-safe
- Endpoint void เดิมสามารถบันทึก `Voided`, soft delete และ audit ได้จริงหลัง migration

## ไฟล์หลักที่เปลี่ยน

- CI: `.github/workflows/ci.yml`
- Runtime: `docker-compose.yml`, `backend/routes/console.php`
- Backup: `backend/config/backup.php`, `backend/config/database.php`, `backend/config/filesystems.php`
- Observability: `backend/app/Http/Middleware/AttachRequestContext.php`, `backend/config/logging.php`, `backend/bootstrap/app.php`
- Production gate: `backend/app/Support/ProductionEnvironmentGuard.php`, `backend/app/Providers/AppServiceProvider.php`
- Leave stabilization: `backend/app/Http/Controllers/LeaveController.php`, migration `2026_08_12_210000...`
- Tests: `ProductionEnvironmentGuardTest`, `RequestContextTest`, `LeaveTest`, `GovernmentLeaveGovernanceTest`
- Delivery docs: `TASKS.md`, `docs/TRACEABILITY-MATRIX.md`, `docs/19-CHANGELOG.md`, `docs/20-DECISION-LOG.md`
- Pint จัดรูปแบบ PHP legacy ทั้ง backend 289 ไฟล์

## Operational gates ที่ยังต้องปิด

### GATE-01 — MySQL credential rotation

สถานะปัจจุบัน: application ใช้ `root` แบบไม่มี password บน local Compose volume เดิม

สิ่งที่ deployment operator ต้องทำใน maintenance window:

1. สร้าง secret จริงผ่าน secret manager/CI environment โดยไม่ commit ลง Git
2. สร้าง application user แบบ least privilege สำหรับ database `eleave`
3. เปลี่ยน backend/worker/scheduler ให้ใช้ application user เดียวกัน
4. ตั้ง root password และห้าม remote root
5. restart services แล้วรัน `migrate:status`, health check, backup และ smoke test
6. ตรวจว่าไม่มี credential ปรากฏใน repository/log/report

เหตุผลที่ไม่ทำอัตโนมัติ: ไม่มี secret ที่ผู้ดูแลอนุมัติ และการสร้างรหัสผ่านคงที่หรือเผยรหัสผ่านผ่าน command/output จะเพิ่มความเสี่ยงแทนการลดความเสี่ยง

### GATE-02 — Authoritative organization data

ยังไม่มีข้อมูลที่รับรองสำหรับ fiscal years, holidays และ document templates จึงไม่ seed ค่าจำลอง ข้อมูลเหล่านี้ต้องมาจากเจ้าของกระบวนงานและควรใช้ idempotent seeder/import หลังรับรอง

## Scope caveat

ก่อนเริ่ม LEGACY-01 working tree มีงาน Saraban ที่ยังไม่ commit และ conflict markers อยู่แล้ว ผู้ใช้อนุญาตให้ resolve เพื่อให้ baseline test รันได้ การ resolve รักษาการเปลี่ยนแปลงทั้งสองฝั่งและ full suite ผ่าน แต่ไฟล์เหล่านั้นไม่ถือเป็นการเริ่ม LEGACY-02

## Final verdict

**LEGACY-01 implementation: PASS**  
**LEGACY-01 operational completion: WARNING (GATE-01, GATE-02)**  
**LEGACY-02: NOT STARTED**

หยุดที่ checkpoint นี้ตามคำสั่ง
