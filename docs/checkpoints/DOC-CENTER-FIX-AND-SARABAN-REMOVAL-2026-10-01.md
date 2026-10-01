# ปิดข้อค้นพบศูนย์จัดการเอกสาร และถอดโมดูลสารบรรณ V1.2

วันที่: 2026-10-01 · branch `chore/remove-saraban-module`

ขอบเขต: แผนที่ผู้ใช้อนุมัติ — ปิด DC-01..DC-06 จาก `DOCUMENT-CENTER-REVIEW-2026-09-19.md`,
ย้ายศูนย์ปฏิบัติการออกจากสารบรรณ, ถอดโมดูลสารบรรณ V1.2 และ drop ตารางหลังสำรอง
การตัดสินใจของเจ้าของระบบ: DC-02 ใช้ safe subset renderer · ถอดสารบรรณ · drop ตารางหลัง backup · คงศูนย์ปฏิบัติการเป็นของระบบ

## งานและหลักฐาน

| ลำดับ | เรื่อง | Commit | เทสต์ใหม่/ที่ปรับ |
|---|---|---|---|
| 0 | งานลาค้าง 2026-09-20 + เทสต์ล้มเองตอนขึ้นปีงบ (12 ข้อ) + PHPStan 2 ข้อ | `0356c03` | `TeamCalendarPrivacyTest`, trait `FreezesClockMidFiscalYear` |
| 1 | DC-01 IDOR เอกสารที่ออกแล้ว | `743b243` | `GeneratedDocumentAuthorizationTest` 8 เคส |
| 2 | DC-02 Blade รันโค้ดจากแม่แบบ | `8f165d6` | `SafeTemplateRendererTest` 19, `TemplateContentSecurityTest` 7 |
| 3 | DC-03 ประวัติกติกาผูกแม่แบบ/การเผยแพร่เวอร์ชัน | `719cd96` | `DocumentTemplateHistoryTest` 5 |
| 4 | DC-04..06 + ปุ่มตามสิทธิ์ + โหลดรายส่วน | `2c46618` | `DocumentCenterListingTest` 5, `documentCenterView.test.js` 6 |
| 5 | ศูนย์ปฏิบัติการ → `system.*` | `7d922a3` | `OperationsPermissionMigrationTest` 3, `MenuDriftTest` |
| 6 | ถอดสารบรรณ V1.2 | `12e46a5` | `SarabanRemovalTest` 5, `StepUpAuthenticationTest` 8, `ComplianceEvidenceTest` 9 |

## ผลตรวจรอบสุดท้าย (HEAD ของ branch)

| ด่าน | ผล |
|---|---|
| Backend PHPUnit (SQLite in-memory, container `e-le-backend`, ไม่มี network) | 1,096 passed / 0 failed / 3,746 assertions |
| PHPStan/Larastan level 5 | ไม่มี error (baseline ตัดรายการของไฟล์ที่ลบและหนี้ที่แก้แล้ว) |
| Pint | ผ่าน 637 files |
| Frontend ESLint / vue-tsc | ผ่าน |
| Frontend Vitest | 135 passed / 21 files |
| Vite build | ผ่าน |

จำนวนเทสต์ลดจาก 1,385 (Phase 0) เพราะลบเทสต์เฉพาะสารบรรณ 27 ไฟล์ (backend) และ 13 ไฟล์ (frontend)
เทสต์ที่ป้องกันกลไกของระบบที่อยู่ในไฟล์เหล่านั้นถูกย้ายออกมาก่อนลบ

## สิ่งที่ต้องทำเองบน production

1. สำรองฐานข้อมูลและไฟล์ (`php artisan backup:run` หรือ `docker/scripts/backup.sh`)
2. `php artisan migrate` — ถ้าตารางงานจริงของสารบรรณมีข้อมูล migration จะหยุดพร้อมรายชื่อตาราง
   ให้ตั้ง `SARABAN_DROP_CONFIRMED=true` หลังตรวจ backup แล้วรันใหม่
3. ไฟล์ PDF ของหนังสือใน `private_uploads` (โฟลเดอร์ของสารบรรณ) ไม่ถูกลบ — ลบเองหลังยืนยันว่า backup กู้ได้
4. ตรวจ PROD-02 ซ้ำบน production

## ข้อจำกัด

- รันบน SQLite ตาม `phpunit.xml`; ไม่ได้ทดสอบ migration drop บนสำเนาฐาน MySQL ที่มีข้อมูลจริง (ไม่มีฐานดังกล่าวในงานนี้ ·
  ฐาน dev `eleave_db` ของเครื่องอยู่ในสถานะ restart วนจาก MySQL crash ก่อนเริ่มงาน และไม่ได้แตะ)
- ไม่ได้ตรวจหน้าจอผ่านเบราว์เซอร์จริงหรือเทียบ PDF ด้วยสายตา
- แคตตาล็อก webhook ว่าง — สร้างปลายทาง webhook ใหม่ไม่ได้จนกว่าจะมีโมดูลประกาศเหตุการณ์
- ด่าน `step_up` ไม่มี route ใดใช้แล้ว (เดิมใช้เฉพาะการลงนามหนังสือ)
- `LeaveEvidenceManager.vue` ยังไม่ได้จัดรูปแบบ (cosmetic) — เลื่อนเพราะเสี่ยงทำคอมโพเนนต์ที่ทำงานอยู่พังโดยไม่มีประโยชน์ด้านการทำงาน

ไม่มีการ deploy และไม่ได้เรียก mutation บนฐานข้อมูลใช้งานจริง
