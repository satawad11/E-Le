# DECISION LOG (REBUILD-2569)

> เริ่มชุดใหม่ 2026-10-18 · ADR ชุดเดิมดูได้จาก git tag `legacy-final` (`docs/DECISION-LOG.md`, `docs/20-DECISION-LOG.md`)

## ADR ชุดเดิมที่ยังมีผล (สาระคงเดิม)

| ADR เดิม | สาระ |
|---|---|
| ADR-001 | Modular Monolith |
| ADR-002 | Policy/Workflow มีรุ่นและวันที่มีผล |
| ADR-003, ADR-009 (attendance) | ข้อมูลจากเครื่องลงเวลาเป็น raw evidence แยกจากผลลงเวลา |
| ADR-010 (workflow) | workflow instance pin เวอร์ชันของตนเอง |
| ADR-010 (overtime) | OT แยกเวลาที่สั่ง เวลาจริง และเวลาที่รับรอง |
| ADR-011 | โมดูลส่ง event · Notification Center เป็นผู้ส่ง |
| ADR-012 | รายงานเป็นข้อมูลตาม scope · สูตรประเมินต้องได้รับอนุมัติ |
| ADR-013 | โมดูลประกาศส่วนขยาย · core ไม่ import โมดูลธุรกิจ |
| ADR-USER-GOV-01 | ไม่ลบบัญชีผู้ใช้ (ใช้สถานะ `separated`) |

ADR อื่นทั้งหมดของชุดเดิม **ยกเลิก** โดย ADR-R-001

---

## ADR-R-001 เข้าสู่โหมด REBUILD-2569 และยกเลิกกติกาชุดเดิม
2026-10-18 · เจ้าของระบบตัดสิน (D-1, D-3)
กติกา เอกสาร และ ADR ของเฟส LEGACY/PROD-00 ยกเลิกทั้งหมด ยกเว้นตารางด้านบน แทนด้วย `PROJECT_RULES.md` และ `docs/REBUILD-PLAN-2569.md`
เหตุผล: เปลี่ยนแบบจำลองสิทธิ์จาก role-centric เป็น assignment-centric 5 ระดับ ซึ่งขัดกับกติกาเดิมหลายข้อ

## ADR-R-002 ล้างข้อมูลและยุบ migration เป็นชุด baseline ใหม่
2026-10-18 · เจ้าของระบบตัดสิน (D-2)
ข้อมูลทั้งหมดของระบบเดิมถูกล้าง migration ~130 ไฟล์ยุบเป็นชุดใหม่ใน R-02
เงื่อนไขบังคับ: R-00 ต้องเสร็จก่อน (backup ที่ทดสอบ restore แล้ว + export ใบลา/ลงเวลาเป็นไฟล์ถาวร + ผู้บริหารลงนาม)
ผลที่ยอมรับ: ไม่มีข้อมูลเดิมให้ย้าย จึงไม่ต้องเขียน data migration

## ADR-R-003 ถอด spatie/laravel-permission ใช้ตารางสิทธิ์ของ core
2026-10-18
spatie ไม่รองรับ scope ต่อ (permission, แหล่งที่มา) วันหมดอายุ และกฎกลาง ระบบเดิมจึงต้องต่อตารางเสริมหลายชั้น (`permission_scopes`, `permission_denies`, `permission_aliases`) แบบจำลองใหม่ใน CORE-04 เก็บทุกอย่างในตารางเดียวกันและตัดสินที่ `PermissionEngine` จุดเดียว

## ADR-R-004 ระบบเดิมเปิดใช้ต่อจนโมดูลลาใหม่พร้อม (คำตอบ Q2 ชั่วคราว)
2026-10-18 · ใช้ข้อเสนอในแผนจนกว่าเจ้าของระบบจะยืนยัน
production เดิมทำงานต่อบน tag `legacy-final` ระหว่าง R-02–R-08 การล้างข้อมูลจริงทำตอนตัดระบบใน R-09 หลัง export รอบสุดท้าย

## ADR-R-005 ระเบียบราชการเป็นข้อมูลตั้งต้นที่มีรุ่น ไม่ใช่กฎที่ลบ (คำตอบ Q1 ชั่วคราว)
2026-10-18
ระเบียบการลาของข้าราชการ ระเบียบบริหารสถานศึกษา พ.ศ. 2569 และวันหยุดราชการ ย้ายเป็น seeder ของข้อมูลตั้งต้นที่มีรุ่นและวันที่มีผล ตรรกะคำนวณสิทธิ์การลา (`LeavePolicyService`, `LeaveBalanceService`, `LeaveEligibilityRuleService`) เก็บไว้และปรับให้ใช้ core ใหม่

## ADR-R-006 Workflow engine เป็น source of truth ทันที · resolver ตามระดับหน่วย
2026-10-18 · แทน ADR-009 (workflow shadow) เดิม
เมื่อไม่มีข้อมูลเดิม การเดินคู่ขนานไม่มีประโยชน์ ยกเลิก shadow และ `approval_steps` ขั้นอนุมัติอ้าง `head_of_level` แทนชื่อ role ไม่มี fallback (CORE-05)

## ADR-R-007 ชั้นความลับฟิลด์และการลดการเก็บข้อมูล
2026-10-18
ทุกฟิลด์บุคลากรมีชั้นใน `field_classifications` ฟิลด์ `sensitive` เข้ารหัส ไม่เก็บเชื้อชาติและศาสนาเว้นแต่มีเหตุตามกฎหมาย (CORE-02)

## ADR-R-008 MFA แบบ TOTP + รหัสสำรอง (คำตอบ Q6 ชั่วคราว)
2026-10-18 · เลือกไลบรารีและบันทึกเพิ่มใน R-05

## ADR-R-009 ระดับ L1–L4 มาจากประเภทหน่วย
2026-10-18
ความลึกของต้นไม้ไม่เท่ากันได้ ระดับอ่านจาก `org_unit_types.level` ชื่อประเภทหน่วย L4 ที่ใช้จริงรอคำตอบ Q4 (ค่าเริ่มต้น: กลุ่มงาน `subsection`, สาขางาน `program`)

## ADR-R-010 พักเทสต์ชุดเดิมไว้ที่ `tests/Legacy/` ระหว่าง R-02–R-08
2026-10-19 · R-02 · **เจ้าของระบบอนุมัติ 2026-10-19 (Q7)** — รายงานทุกเฟสระบุเทสต์ Legacy ที่ย้าย/ลบ · `tests/Legacy` ต้องว่างก่อนผ่าน R-09
การถอด spatie/ตารางที่ตัด และ deny-by-default ทำให้เทสต์ชุดเดิม 85 ไฟล์รันไม่ได้ (fixture สร้าง role/department/user_profile และคาดหวังให้ endpoint ทำงาน)
ไม่ลบและไม่แก้ assertion ให้ผ่าน: ย้ายไฟล์ไป `backend/tests/Legacy/` (นอก testsuite ปกติ) พร้อม README ที่ระบุเฟสที่ต้องเขียนใหม่ทีละไฟล์
กฎธุรกิจที่ยังมีผล (ระเบียบการลา, document engine, เวลา) ต้องพอร์ตเป็นเทสต์ใหม่ก่อนลบไฟล์เดิม
ทางเลือกที่ไม่เลือก: (ก) ปล่อยให้ CI แดง — ใช้ CI ตรวจงานใหม่ไม่ได้ (ข) ลบทิ้ง — เสียความรู้เรื่องกฎราชการ (ค) แก้เทสต์ให้ผ่านบนแบบจำลองเดิม — ขัดแผน

## ADR-R-011 baseline ของโมดูลเดิมสร้างจาก schema สุดท้ายจริง และย้ายข้อมูลตั้งต้นจาก migration เป็น seeder
2026-10-19 · R-02
รัน migration เดิมครบ 141 ไฟล์บนฐานข้อมูลทดสอบ (`eleave_r02_legacy`) แล้วแปลง information_schema เป็น Blueprint ด้วยสคริปต์ จึงได้รูปสุดท้ายตรงทุกคอลัมน์/index/FK แทนการถอดความด้วยมือ
ข้อมูลที่ migration เดิมเคยใส่ (ประเภทการลา นโยบายการลา ประเภท/แม่แบบการแจ้งเตือน) ส่งออกเป็น `database/seeders/data/*.json` และโหลดด้วย seeder · migration ชุดใหม่มีแต่ schema
ตรวจผลด้วยการเทียบ `mysqldump --no-data` ของสองฐาน: ต่างกันเฉพาะส่วนที่แผนสั่ง (รายการในรายงาน R-02)

## ADR-R-012 duty attributes เก็บใน `role_grants.attributes` · ช่องทางแจ้งเตือนอยู่ที่โมดูลแจ้งเตือน (คำตอบ Q9)
2026-10-19 · เจ้าของระบบตัดสิน
ไม่มีตาราง `grant_attributes` (แก้ PLAN §4.2 แล้ว) — attributes ของ functional grant เป็น json ตาม `attributes_schema` ของ template (CORE-04)
ค่าระบุผู้รับ LINE/Telegram ไม่อยู่ที่บัญชีผู้ใช้ เก็บในตาราง `notification_channels` ของโมดูลแจ้งเตือน สร้างใน R-08

## ADR-R-013 ล็อกอินด้วยอีเมลหรือรหัสบุคลากร · เลขประจำตัวประชาชนปิดเป็นค่าเริ่มต้น (คำตอบ Q10)
2026-10-19 · เจ้าของระบบตัดสิน
ตัวระบุหลักคืออีเมลหรือ `personnel.personnel_code` · การใช้เลขประจำตัวประชาชน (ค้นผ่าน `national_id_hash`) เปิดได้ด้วย `config('auth.allow_national_id_login')` (`AUTH_ALLOW_NATIONAL_ID_LOGIN`, ค่าเริ่มต้น false)
เหตุผล: เลขประจำตัวประชาชนเป็นข้อมูลชั้น sensitive และคาดเดาได้ ไม่ควรเป็นตัวระบุสำหรับเข้าสู่ระบบโดยปริยาย

## ADR-R-014 ตัวอ่าน XLSX/CSV ขนาดเล็กสำหรับนำเข้าข้อมูล แทนการเพิ่ม PhpSpreadsheet
2026-10-19 · R-03
ตัวนำเข้า (R03-5) อ่านเฉพาะค่าในแผ่นงาน ไม่ต้องการสูตรหรือรูปแบบ จึงใช้ `App\Support\Spreadsheet\SheetReader` ที่อ่าน `.xlsx` ด้วย ext-zip + XMLReader และ `.csv` ด้วย fgetcsv
ไม่เพิ่ม dependency ใหม่ในเฟส core · หมายเหตุ: โค้ดส่งออกเดิม (`LeaveExcelService`, `ReportExportService`) อ้าง PhpSpreadsheet ซึ่งไม่อยู่ใน composer.lock มาก่อน R-02 — ตัดสินใน R-08 ว่าจะเพิ่มแพ็กเกจหรือเขียนตัวเขียนเอง

## ADR-R-015 ชุดสิทธิ์ประกาศ denies ได้ (deny ชนะ) — ใช้เฉพาะหลักการข้อ 8 ของผู้ดูแลระบบ (แก้ไขหลังตรวจ R-04)
2026-10-19 · R-04 · **แก้ไขตามผลตรวจ R-04 ข้อ 2**
`template_permissions.effect` (allow/deny) — deny ในชุดสิทธิ์ตัดสิทธิ์จาก **ทุกแหล่ง** ของผู้ถือ จึงต้องใช้เฉพาะสิ่งที่ห้ามจริงโดยหลักการ
- ชุดสิทธิ์ผู้ดูแลระบบ (`system.admin`): deny `personnel.profile.reveal` **เพดาน own** (ตัดเฉพาะ scope ที่กว้างกว่าตนเอง — ผู้ดูแลระบบเปิดดูข้อมูลอ่อนไหวของตนเองได้ตาม PDPA มาตรา 30 แต่ของผู้อื่นไม่ได้) และ `*.*.approve`, `*.*.review` (ไม่อยู่ในสายอนุมัติ) — pattern ไม่ปฏิเสธสิทธิ์ที่ชุดสิทธิ์เดียวกันให้ไว้ (`access.change.approve` = งานผู้ดูแลคนที่สอง มี maker_checker — เจ้าของระบบยืนยันแล้ว) · ไม่ deny สิทธิ์ดูทั่วไป
- ชุดสิทธิ์ผู้ตรวจสอบ (`audit.auditor`): ไม่มี deny — ให้เฉพาะสิทธิ์อ่านของตน
- "—" ใน PLAN §8.3 = ชุดสิทธิ์นั้นไม่ให้สิทธิ์เพิ่ม ไม่ใช่ตัดสิทธิ์จากตำแหน่งอื่น (แก้คำอธิบายใต้ตาราง §8.3 แล้ว) · หัวหน้าแผนกที่เป็นผู้ตรวจสอบด้วยยังดูบุคลากรในแผนกตนได้
- deny มาจาก manifest เท่านั้น: `modules:sync` บังคับ deny ตาม manifest ทุกครั้งและลบ deny ที่เลิกประกาศ (ไม่ต้องใช้ `--reset-defaults`)
- ฉบับแรก (deny `personnel.*` เพดาน own และ `org.*` ทั้งหมด ให้ทั้งผู้ดูแลระบบและผู้ตรวจสอบ) ถูกยกเลิก เพราะตัดสิทธิ์ดูบุคลากรที่ได้จากตำแหน่งหัวหน้าไปด้วย
override deny ระบุหน่วยได้ = ตัด subtree นั้นออกจากทุกแหล่งของ permission นั้น ทั้ง `can()` และ `scopeQuery()` (รวม scope all) **ยกเว้น scope own** — เจ้าของข้อมูลเห็นข้อมูลของตนเองเสมอแม้สังกัดหน่วยที่ถูก deny

## ADR-R-016 deny by default ระดับ route แทนการปิดทั้งระบบ
2026-10-19 · R-04
route ที่ต้องยืนยันตัวตนผ่านได้เมื่อประกาศ `->permission()` ด้วยชื่อที่อยู่ใน manifest (ต่อ PermissionEngine) หรือเป็น self-service (logout, refresh-token, user, me/permissions, me/menu)
route ของโมดูลเดิมที่ยังตรวจแบบเก่ายังตอบ 403 จนย้ายใน R-08 — จึงถอด `DenyUntilPermissionEngine` ได้โดยไม่เปิด endpoint ที่ไม่ถาม engine
resource-level: controller เรียก `authorizeResource()` — นอก scope = 404 (ไม่บอกว่ามีอยู่) · ผิดกฎกลาง = 403

## ADR-R-017 ชั้นข้อมูลบุคลากรที่เห็นมาจาก permission แยก view / view_confidential / reveal
2026-10-19 · R-04 (ต่อจาก matrix ตั้งต้นของ R-03)
public เห็นเสมอ · internal ← `personnel.profile.view` · confidential ← `personnel.profile.view_confidential` · sensitive ← `personnel.profile.reveal` + เหตุผล + log
ค่าเริ่มต้น: L1 ทั้งวิทยาลัย · L2 subtree (รวมชั้นลับ) · L3/L4 subtree (ไม่รวมชั้นลับ) · L5 เฉพาะตนเอง · งานบุคคล (functional) ทั้งหมดรวม reveal

## ADR-R-018 ทะเบียน manifest ใหม่อยู่คู่กับทะเบียนโมดูลธุรกิจเดิมจนถึง R-08
2026-10-19 · R-04
`App\Authz\Manifest\ModuleRegistry` (manifest: สิทธิ์ ชุดสิทธิ์ เมนู) ใช้กับ core ทั้งหมด · `App\Services\Modules\ModuleRegistry` + `config/modules.php` ยังโหลด providers/route ของโมดูลลา ลงเวลา OT สารบรรณ แจ้งเตือน
R-08 ย้ายข้อมูลของแต่ละโมดูลเข้า manifest แล้วลบทะเบียนเดิม

## ADR-R-019 ไลบรารี TOTP: pragmarx/google2fa
2026-10-19 · R-05 · R05-2
ใช้ `pragmarx/google2fa` 9.1.0 (พึ่ง `paragonie/constant_time_encoding` 3.1.3 ตัวเดียว) · QR ใช้ `chillerlan/php-qrcode` ที่มีอยู่แล้ว ไม่เพิ่ม dependency
เหตุผล: ตรง RFC 6238 ใช้ได้กับ Google/Microsoft Authenticator · เป็นตัวที่ Laravel Fortify ใช้ · ดูแลต่อเนื่อง · เทียบรหัสแบบ constant-time · มี `verifyKeyNewer` กันใช้รหัสเดิมซ้ำ
ทางเลือกที่ไม่เลือก: `spomky-labs/otphp` (dependency มากกว่า ความสามารถที่ต้องใช้เท่ากัน) · เขียนเอง (โค้ดเข้ารหัส ไม่ควรเขียนเองต่างจาก ADR-R-014)
ค่าที่ใช้: secret 32 ตัวอักษร base32 (160 bit) เข้ารหัสที่ฐานข้อมูล · ยอมคลาดเวลา ±1 ช่วง (30 วินาที) · บันทึก timestep ที่ใช้แล้ว · รหัสสำรอง 8 รหัส × 10 ตัวอักษร เก็บเป็น HMAC-SHA256 (กุญแจ APP_KEY) ในคอลัมน์ที่เข้ารหัส ใช้ได้รหัสละครั้ง
คำถาม Q6 (MFA แบบ TOTP) ดำเนินตามคำสั่งเฟส R-05 (TOTP + รหัสสำรอง)

## ADR-R-020 MFA บังคับทุก request · "จดจำอุปกรณ์" ไม่ข้าม MFA · อายุเซสชัน 8 ชั่วโมง
2026-10-19 · R-05 · R05-2, R05-3
- กลุ่มที่ต้องใช้ MFA (`App\Services\Access\MfaPolicy`): ผู้มี assignment บริหาร/รักษาการที่มีผลวันนี้ในหน่วยระดับ `auth.mfa.levels` (L1–L3) · ผู้ถือชุดสิทธิ์ที่ manifest ประกาศ `'mfa' => true` จากแหล่งใดก็ได้ที่ engine นับ (ตอนนี้ `personnel.hr_officer`, `system.admin`) · บัญชี break-glass — โมดูลใหม่ที่มีชุดสิทธิ์อ่อนไหวประกาศ `mfa` ใน manifest ได้โดยไม่แก้โค้ด
- ไม่ออก token จนกว่าจะผ่าน MFA · ยังไม่เคยตั้ง MFA แต่ถูกบังคับ → ตั้งผ่าน challenge (อายุ 5 นาที ผิดได้ 5 ครั้ง) ก่อนจึงได้ token · รหัส MFA ผิดนับรวมกับรหัสผ่านผิดเพื่อล็อกบัญชี
- token ที่ผ่าน MFA ชื่อ `session:mfa` · middleware `EnsureMfaSatisfied` ตรวจทุก request: ผู้ที่เพิ่งเข้ากลุ่มบังคับระหว่างล็อกอินอยู่ได้ 403 `mfa_required` จนกว่าจะเข้าสู่ระบบใหม่ · หมุน token คงสถานะ MFA
- "จดจำอุปกรณ์นี้ 30 วัน" ในต้นแบบหน้า 1 = อุปกรณ์ถือว่ารู้จัก 30 วัน (ไม่ส่งอีเมลอุปกรณ์ใหม่) **ไม่ข้าม MFA** (Security > UI preference) · ไม่ติ๊ก = รู้จัก 12 ชั่วโมง · เก็บเฉพาะ hash ของรหัสอุปกรณ์
- ทบทวน ADR-005: อายุ token คง 8 ชั่วโมง (`sanctum.expiration` 480) ตรงกับนโยบายในต้นแบบหน้า 7 · บัญชีถูกล็อกตอบ HTTP 423 พร้อมเวลาปลดล็อก
- token ใช้ได้เฉพาะบัญชีที่ active (`Sanctum::authenticateAccessTokensUsing`) แม้ token ยังไม่ถูกลบ
- **แก้ไขหลังตรวจ R-05 ข้อ 1 — fail-closed:** ผู้ที่ต้องใช้ MFA ผ่านได้เฉพาะ token `session:mfa` ที่มีแถวจริง · ทางยืนยันตัวตนอื่นทุกทาง (TransientToken จาก session ของ guard web, token ที่ไม่ผ่าน MFA) ได้ 403 `mfa_required` · ข้อยกเว้นเดียวคือ `runningUnitTests()` + `auth.mfa.allow_transient_in_tests` ที่เทสต์ตั้งเอง (ค่าไม่อ่านจาก env · ค่าเริ่มต้น false)

## ADR-R-021 สถานะบัญชีมีเจ้าของ (`users.status_reason`) · JML จากสถานะบุคลากร
2026-10-19 · R-05 · R05-1
- `status_reason`: `personnel:<สถานะ>` (JML) · `manual` (ผู้ดูแล) · `inactive_90_days` · `failed_logins` — JML และ `accounts:reconcile` คืนสถานะเฉพาะที่ตนตั้ง (`personnel:*`) การระงับโดยผู้ดูแลหรือเพราะไม่เข้าใช้ต้องให้ผู้ดูแลเปิด
- Joiner ทำงานเมื่อสถานะบุคลากรเปลี่ยน (event `PersonnelStatusChanged` จาก `Personnel::updated` หลัง commit) และใน job รายวัน · ไม่สร้างบัญชีตอนสร้างแถวบุคลากร (ตัวนำเข้า R-03 ไม่สร้างบัญชี — job รายวันสร้างให้) · ต้องมี `email_work` ไม่มีให้รายงาน `missing_email`
- ชื่อผู้ใช้ = `personnel_code` · รหัสผ่านสุ่มที่ไม่มีใครรู้ + ลิงก์ตั้งรหัสผ่าน (broker เดียวกับลืมรหัสผ่าน) — ไม่มีรหัสผ่านเริ่มต้นส่งทางใดเลย · กลับเข้ารับราชการหลังพ้นสภาพได้รหัสผ่านสุ่มใหม่ + ลิงก์
- Leaver: ปิด assignment ที่ยังมีผล ณ วันพ้นสภาพ (ผ่าน `AssignmentService::end`) · ถอน role grant/override · ยกเลิกคำขอเปลี่ยนสิทธิ์ค้าง · ตัด token · แจ้งงานบุคคลพร้อมจำนวนงานค้าง — การส่งงานอนุมัติที่ค้างในสายให้ผู้อื่นเป็นงานของ workflow (R08-leave-2)
- บัญชีไม่เข้าใช้ 90 วันเฉพาะบัญชีบุคลากร (ไม่รวม break-glass และบัญชีระบบ)
- `AccountSeparationService` เดิม (อ้างตารางที่ถูกตัดใน R-02) ถูกลบ

## ADR-R-022 มอบสิทธิ์ผ่าน API: two_person ตามเนื้อหา · เพดานตรวจสองครั้ง · ทุก endpoint ผ่าน engine
2026-10-19 · R-05 · R05-4, R05-5
- two_person ตัดสินจากสิ่งที่ให้ (`requiresSecondPerson` ของทุก permission ในชุดสิทธิ์/override) → `permission_change_requests` · สิทธิ์ความเสี่ยงต่ำมีผลทันที · การถอนสิทธิ์และ deny ลดอำนาจจึงมีผลทันที
- grant_ceiling ตรวจกับผู้ยื่นทั้งตอนยื่นและตอนยืนยัน · ผู้ยืนยันต้องถือ `access.change.approve` ไม่ใช่ผู้ยื่น (maker_checker) และไม่ใช่ผู้รับสิทธิ์ (not_own)
- **แก้ไขหลังตรวจ R-05 ข้อ 2:** รีเซ็ต MFA ของบัญชีที่ MfaPolicy บังคับต้องผ่าน `permission_change_requests` (diff `account.mfa_reset` · ผู้ยื่นและเจ้าของบัญชียืนยันเองไม่ได้ · ตรวจซ้ำว่าผู้ยื่นยังมี `access.account.manage`) · บัญชีอื่นมีผลทันที · ทุกครั้งแจ้งเจ้าของบัญชี (เวลา ผู้ยื่น ผู้ยืนยัน) และบันทึก `login_events` (`mfa_reset`) + audit
- role grant มอบได้เฉพาะชุดสิทธิ์ functional · ชุดสิทธิ์ positional มาจากคำสั่งแต่งตั้งเท่านั้น · ทุกการเปลี่ยนบันทึก `permission_audit_logs`
- endpoint ความปลอดภัยบัญชีของตนเอง (`/api/me/security`, `/api/me/password`) ประกาศ `access.self.security` (own) จากชุดสิทธิ์ `access.self_service` ที่ทุกตำแหน่งหลักได้ (L5) — ทุก endpoint ที่ยืนยันตัวตนผ่าน engine · endpoint ก่อนยืนยันตัวตน (`/login`, `/login/mfa*`, `/auth/options`) เป็นสาธารณะพร้อม throttle
- ข้อจำกัดที่รู้: บัญชีที่ไม่มีตำแหน่งหลัก (break-glass, บัญชีระบบ) ไม่ได้ `access.self.security` — ตั้ง MFA ผ่านขั้นตอนเข้าสู่ระบบแทน · การมอบชุดสิทธิ์แรกสุด (ผู้ดูแลระบบคนแรก, งานบุคคล) ติดเพดาน — แก้ด้วย ADR-R-023 (Q11)

## ADR-R-023 ตั้งผู้ถือชุดสิทธิ์คนแรกด้วย `php artisan access:bootstrap` (คำตอบ Q11)
2026-10-22 · R-05 แก้ไขหลังตรวจ · เจ้าของระบบตัดสิน
grant_ceiling ทำให้ระบบที่ยังไม่มีผู้ถือ `system.admin` / `personnel.hr_officer` มอบชุดสิทธิ์ทั้งสองผ่าน API ไม่ได้ จึงมีคำสั่งบนเซิร์ฟเวอร์ทางเดียว:
- ใช้ได้เฉพาะสองชุดสิทธิ์นี้ และเฉพาะเมื่อไม่มีผู้ถือที่ยังมีผล (ไม่ถอน ไม่หมดอายุ บัญชี active) — มีแล้วปฏิเสธ ต้องมอบผ่าน API ตามปกติ (grant_ceiling + คนที่สอง)
- บังคับ `--order-number` และ `--expires` (หลังวันนี้ ไม่เกิน 90 วัน) · บัญชีเป้าหมายต้อง active และไม่ใช่บัญชีระบบ
- ล้าง MFA และ token ของบัญชีเป้าหมาย → เข้าสู่ระบบครั้งแรกต้องตั้ง MFA (สองชุดสิทธิ์นี้บังคับ MFA อยู่แล้ว)
- `role_grants.granted_by` = null (migration `r05_role_grants_granted_by_nullable` — null หมายถึงคำสั่งนี้เท่านั้น ไม่บันทึกผู้มอบที่ไม่ได้มอบจริง) · `permission_audit_logs` action `role_grant.bootstrapped` (ผู้สั่ง = เซิร์ฟเวอร์ · host · ผู้ใช้ระบบปฏิบัติการ) · แจ้งผู้ดำรงตำแหน่ง L1 ทุกครั้ง (ไม่มี L1 = แสดงคำเตือน)
- ไม่มี API หรือหน้าจอสำหรับงานนี้
