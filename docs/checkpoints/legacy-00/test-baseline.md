# TEST & BUILD BASELINE — LEGACY-00

**วันที่:** 2026-08-12 · **Baseline tag:** `baseline/legacy-2026-08-12` (`6139212`)

## 1. Backend test suite

```
docker exec -w /var/www/html eleave_backend php artisan test
```

| รายการ | ผล |
|---|---|
| PHP | 8.4.24 |
| Laravel | 13.19.0 |
| PHPUnit | 12.x |
| Test database | SQLite `:memory:` (บังคับใน `phpunit.xml`) — **ไม่แตะ MySQL production** |
| **ผลรวม** | **86 passed · 0 failed · 0 skipped · 256 assertions** |
| เวลา | 173.25 วินาที |

### ผลรายไฟล์

| Test class | ผ่าน |
|---|---:|
| `Tests\Unit\ExampleTest` | 1 |
| `Tests\Unit\UserTest` | 7 |
| `Tests\Feature\ApprovalTest` | 5 |
| `Tests\Feature\AuthTest` | 5 |
| `Tests\Feature\ExampleTest` | 1 |
| `Tests\Feature\GovernmentLeaveGovernanceTest` | 4 |
| `Tests\Feature\LeaveCardPdfTest` | 1 |
| `Tests\Feature\LeaveEligibilityRulesTest` | 8 |
| `Tests\Feature\LeaveEntitlementRulesTest` | 8 |
| `Tests\Feature\LeaveExportTest` | 3 |
| `Tests\Feature\LeaveRegisterReportTest` | 3 |
| `Tests\Feature\LeaveTest` | 6 |
| `Tests\Feature\LeaveVerificationTest` | 6 |
| `Tests\Feature\PersonalDataProtectionTest` | 13 |
| `Tests\Feature\PromotionScreeningTest` | 7 |
| `Tests\Feature\Saraban\DocumentTest` | 1 |
| `Tests\Feature\SystemSettingsTest` | 6 |
| `Tests\Feature\ThaiGovernmentLeaveTypesTest` | 1 |
| **รวม** | **86** |

### ⚠️ แก้ข้อสรุปเดิม

`docs/LEGACY-SYSTEM-ASSESSMENT.md` §13 และ `docs/LEGACY-GAP-ANALYSIS.md` TEST-002 ระบุว่า
"พบ defect 42 รายการใน `.phpunit.result.cache` — น่าจะมีประมาณครึ่งหนึ่งของ suite ไม่ผ่าน"

**การรันจริงหักล้างข้อสรุปนั้น — test ผ่านทั้งหมด 86/86**

`.phpunit.result.cache` เป็นไฟล์แคชผลรันเก่าที่ตกค้างจากสภาพแวดล้อมอื่น (เขียนไว้ 2026-08-11 21:09
บนเครื่อง host ที่ PHP เวอร์ชันไม่ตรงกับที่ container ใช้) และการตีความค่าตัวเลข 7/8 ว่าเป็น
"failure/warning" นั้นไม่ถูกต้อง

**ผลต่อแผน:** งาน `01-5 ทำ test suite ให้เขียว` ใน LEGACY-01 **ไม่จำเป็นแล้ว** —
ระบบมีตาข่ายนิรภัยพร้อมสำหรับ refactor ตั้งแต่ต้น เปลี่ยนงานนั้นเป็น
"เพิ่ม CI ให้รัน suite ที่เขียวอยู่แล้วนี้อัตโนมัติ + ลบ `.phpunit.result.cache` ออกจาก repo" แทน

### ข้อจำกัดของ baseline นี้ (ยังคงเป็นช่องว่างจริง)

| ช่องว่าง | สถานะ |
|---|---|
| IDOR test | **ไม่มีเลย** — SEC-003/005/006 จึงลอดผ่านมาได้แม้ test เขียว 100% |
| Authorization matrix (role × scope × action) | ไม่มี |
| Attendance / OT test | **0 test** สำหรับ controller 1,137 บรรทัด |
| Saraban test | 1 test สำหรับ 9 controller |
| `LeaveGovernanceController::void()` | **ไม่มี test ครอบ** — จึงไม่มีใครจับได้ว่าฟีเจอร์นี้พังจาก enum |
| Migration test บน MySQL | ไม่มีใน CI (แต่ LEGACY-00 พิสูจน์ด้วยมือแล้วว่ารันผ่าน) |

> test ที่เขียว 100% แต่ไม่ครอบคลุม authorization คือเหตุผลว่าทำไมช่องโหว่ CRITICAL 6 รายการ
> ยังอยู่ในระบบ — ปริมาณ test ไม่ใช่ปัญหา **ขอบเขตของ test** คือปัญหา

## 2. Frontend build

```
docker exec -w /app eleave_frontend npm run build
```

| รายการ | ผล |
|---|---|
| Node / npm | v22.23.2 / 10.9.8 |
| Vite | 8.x (rolldown) |
| **Exit code** | **0 — สำเร็จ** |
| Error / Warning | ไม่มี |
| จำนวน chunk | ~60 ไฟล์ (code-split ตาม route) |
| Bundle ที่ใหญ่ที่สุด | `dist-vVyuq38W.js` 172 kB (gzip 59.5 kB), `quill` 164 kB, `index` 131 kB, `axios` 105 kB |

### ข้อสังเกต

- Build ใช้เวลานานผิดปกติ (vite รายงาน `built in 243m 3s`) โดย 80% ของเวลาอยู่ใน plugin `vite:vue`
  สาเหตุคือ bind mount ของ Docker Desktop บน Windows ช้ามาก — **ไม่ใช่ปัญหาของโค้ด**
  แต่กระทบเวลา CI/CD ในอนาคต ควรพิจารณาใช้ named volume หรือ build ใน container ที่ copy source เข้าไป
- `frontend/package.json` มี script แค่ `dev`, `build`, `preview` — **ไม่มี lint / test / typecheck**
  (ยืนยัน TEST-004 ตามเดิม)

## 3. คำสั่งที่ใช้สร้าง baseline นี้ (ทำซ้ำได้)

```bash
docker exec -w /var/www/html eleave_backend php artisan test
```

```bash
docker exec -w /app eleave_frontend npm run build
```
