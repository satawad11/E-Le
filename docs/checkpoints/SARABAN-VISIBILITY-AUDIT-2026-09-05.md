# ตรวจ SARABAN: Registry → เมนู → Route → สิทธิ์ → UI/API

วันที่ 2026-09-05 · ตรวจ working tree ปัจจุบันและระบบ Docker ในเครื่อง · งานตรวจสอบ ไม่เปลี่ยนสิทธิ์/เมนู/ข้อมูลธุรกิจหรือ deploy

## ข้อสรุป

**สาเหตุหลักของเมนูที่หายคือข้อมูลเมนูในฐานข้อมูลไม่ตรงกับ MenuSeeder::TREE**: โค้ดมีเมนูลูก 13 รายการ แต่ฐานข้อมูลมี 6 รายการ ขาด 7 รายการ แม้หน้า Vue และ routes มีแล้ว บัญชีใน Chrome แสดงชื่อ “ทดสอบ ระบบ” ซึ่งตรงกับผู้ใช้ id=1 เพียงรายการเดียวในฐานข้อมูล ถือ Super Admin และ Gate อนุญาต SARABAN ครบ 32/32 ความสามารถ โมดูลเปิดอยู่ จึงไม่ใช่การขาด permission ของบัญชีนี้

หลักฐาน runtime: `saraban-audit-runtime-2026-09-05.json` ในโฟลเดอร์เดียวกัน บันทึก missing menus, effective permissions, flags และ route middleware โดยไม่อ่าน/บันทึก bearer token

## 1. Module Registry

- `backend/config/module_registry.php` ลงทะเบียน `saraban`, provider, namespace สิทธิ์ 32 รายการ, dependency `system`, enabled ผ่าน `MODULE_SARABAN_ENABLED` (default true)
- `ModuleStateResolver` ใช้ system setting `module_saraban_enabled` ทับค่า config เมื่อ boot; runtime `ModuleRegistry::isEnabled('saraban') = true`
- `SarabanModuleServiceProvider` ผูก DocumentPolicy และ DocumentCasePolicy แล้ว
- `route_patterns` ครอบ `api/saraban*` และ `api/v1/saraban*`; `EnsureModuleEnabled` อยู่ใน API middleware และปิด endpoint ด้วย 404
- **ข้อมูล manifest ล้าหลัง**: `routes`, `route_files`, `menus`, `workflow_definitions`, `reports`, `settings` ยังเป็น array ว่าง ทั้งที่มี implementation บางส่วนแล้ว คอมเมนต์ที่บอกว่ารอ UI ยังไม่ตรงกับสภาพปัจจุบัน
- ช่อง `menus=[]` ไม่ใช่ต้นเหตุโดยตรงของเมนูหาย: MenuService อ่านตาราง menus แล้วตรวจ module ของแต่ละแถว ไม่ได้สร้าง sidebar จาก array นี้

## 2–4. Menu registry / navigation / route / permission mapping

เส้นทางจริง: `AppMenuNav` ← `menuStore.fetchUserMenus()` ← `GET /api/user/menus` ← `MenuService` ← ตาราง `menus` + permission pivots

ทุกแถวต่อไปนี้มี route และ Vue component จริงแล้ว; “มี/ขาด” หมายถึงแถวเมนูในฐานข้อมูล ณ เวลาตรวจ

| เมนู | URL | สิทธิ์ที่เมนูต้องการ | DB | UI / API หลัก |
|---|---|---|---|---|
| หนังสือเวียนถึงฉัน | /saraban/inbox | saraban.inbox.view | มี | InboxView → /inbox, รายฉบับ, acknowledge, progress |
| ทะเบียนหนังสือ | /saraban/documents | saraban.document.view | มี | DocumentsView → GET /documents |
| สร้างหนังสือ | /saraban/documents/new | saraban.document.create | มี | DocumentFormView → options, POST /documents |
| ทะเบียนรับหนังสือ | /saraban/incoming | saraban.document.view | มี | IncomingView → GET /incoming |
| ลงทะเบียนรับ | /saraban/incoming/new | saraban.incoming.register | มี | IncomingRegisterView → options, POST /incoming |
| จำลองเส้นทางเดินหนังสือ | /saraban/workflow/simulate | saraban.workflow.simulate | มี | WorkflowSimulationView → options, versions, simulate |
| ตารางอำนาจหน้าที่ | /saraban/authority | saraban.authority.view | **ขาด** | AuthorityMatrixView → GET /authority |
| ศูนย์นโยบายงานสารบรรณ | /saraban/policies | saraban.policy.manage | **ขาด** | PolicyCenterView → policies/history/draft/publish/rollback |
| ชุดเลขหนังสือ | /saraban/number-series | saraban.config.manage | **ขาด** | NumberSeriesView → GET/POST/PUT /number-series |
| ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด | /saraban/compliance | saraban.operations.view หรือ saraban.report.view | **ขาด** | ComplianceEvidenceView → GET /operations/compliance |
| รายงานทะเบียนงานสารบรรณ | /saraban/reports | saraban.report.view | **ขาด** | RegisterReportsView → /reports/register, numbers, distribution |
| สารบบเรื่อง | /saraban/cases | saraban.document.view | **ขาด** | CasesView → list/create/show/close |
| ศูนย์เฝ้าระวังการปฏิบัติการ | /saraban/operations | saraban.operations.view | **ขาด** | CommandCenterView → command-center/exceptions, actions, flags |

อ้างอิง `backend/database/seeders/MenuSeeder.php:228`, `frontend/src/router/index.js:116`, `backend/routes/api.php:466`

ข้อค้นพบเพิ่มเติม:

1. Migration `2026_09_04_110000_add_saraban_menus` รันแล้ว แต่การเพิ่ม TREE ภายหลังไม่ทำให้ migration เดิมรันใหม่ โดยโค้ด migration เดือนกันยายนที่ตรวจพบมีการเรียก MenuSeeder ที่จุดนี้เพียงแห่งเดียว สภาพ runtime จึงสอดคล้องกับการไม่ได้ sync เมนูหลังเพิ่มหน้าใหม่
2. MenuService cache ต่อผู้ใช้ + module fingerprint นาน 3,600 วินาที; การแก้ DB อย่างเดียวอาจยังเห็น cache เก่า แต่กรณีนี้ยืนยันว่าขาดแถวจริง จึงไม่ใช่เพียง cache
3. เมนูแม่ต้องมี document.view **หรือ** inbox.view ก่อนพิจารณาเมนูลูก ผู้มีเฉพาะ report.view / policy.manage / workflow.simulate อาจเสียเมนูลูกที่ตนมีสิทธิ์
4. ผู้มี `system.menu.manage` เห็นเมนูทั้งหมดของโมดูลที่เปิด แม้ไม่มีสิทธิ์ธุรกิจของแต่ละหน้า การเห็นเมนูจึงไม่รับรองว่า API จะผ่าน
5. เมนูสร้าง/รับ/จำลองใช้ create/register/simulate แต่หน้าเรียก `/documents/options` ซึ่งต้อง document.view เพิ่ม; ให้สิทธิ์เมนูอย่างเดียวจึงยังโหลดฟอร์มไม่ได้
6. เมนูนโยบายใช้ policy.manage แต่ GET policies/history ต้อง document.view; ผู้มีสิทธิ์อ่านอย่างเดียวไม่มีเมนูแม้ API อนุญาตอ่าน และผู้มี manage อย่างเดียวเปิดเมนูแล้วอาจโหลดไม่ได้
7. หน้า authority เรียกดูอำนาจตนเองซึ่ง API ยอมให้ผู้ล็อกอินอ่านได้ แต่เมนูต้อง authority.view ซึ่ง API ใช้จำกัดการดูผู้อื่น จึงซ่อนฟังก์ชันตรวจอำนาจตนเองจากผู้ใช้ทั่วไป

## 3. Route definitions เพิ่มเติม

- Runtime ลงทะเบียน 146 route records ที่ชื่อ URI มี saraban: API ชุดป้องกัน 72 เส้นทาง × 2 prefix และ public verify/QR 2 เส้นทาง (GET รวม HEAD อยู่ใน record เดียว)
- ทั้งสอง prefix ใช้ closure นิยามเดียวกันใน routes/api.php; `/api/v1/saraban` เพิ่ม idempotent middleware
- Frontend มี `/saraban/...` 15 route records และ `/verify-saraban/:hash` อีก 1 รวม 16 โดย detail/create ใช้ DocumentFormView ร่วมกัน (15 Vue page files)
- detail `/saraban/documents/:id` และ workspace `/saraban/documents/:id/workspace` เข้าโดยลิงก์ในหน้า ไม่ใช่เมนูหลัก; public verify ใช้ bare/public layout
- Saved views และ authority/self ไม่ได้ใส่ ability middleware ทุกเส้นทางโดยเจตนา มี auth และตรวจ ownership/target ใน controller จึงไม่สรุปว่าทุก route ที่ไม่มี ability เป็นช่องโหว่

## 5. ABAC และ Policy

ส่วนที่มีจริง:

- DocumentPolicy ตรวจ ownership, recipient, org scope และสถานะเอกสาร; create ตรวจ authority ของ owner_org_unit_id ใน controller
- OrgScopeResolver รองรับ SELF/UNIT/DESCENDANTS/ORGANIZATION/ASSIGNED/CUSTOM และการเป็นหัวหน้า/ผู้รักษาการตาม effective date
- AuthorityResolver รวม permission, หน่วยงาน, manager assignment และ delegation; workflow actions ใช้ engine ตรวจผู้รับมอบหมายและ transition เพิ่ม
- InboxController จำกัดผู้รับเป็นผู้เรียก; การให้ inbox.view ไม่ให้ค้นทะเบียนทั้งหน่วยงาน
- PolicyCenter มี version/draft/publish/rollback/audit จริง แต่ catalogue ปัจจุบันครอบ layout, distribution, numbering เท่านั้น ยังไม่ครบขอบเขตนโยบายตาม master §18

ช่องว่างที่ยังพบจาก static review (ไม่ได้ทดลองโจมตีข้อมูลจริง):

- **Explicit deny มีความเสี่ยงถูก Gate ของ Spatie ข้าม**: runtime new engine=true แต่ `permission.register_permission_check_method=true`; Spatie before callback คืน true เมื่อถือ permission ก่อน callback ของแอปมีโอกาสตัดสิน deny ข้อนี้เป็นข้อค้าง ADR-AUTHZ-02 อยู่แล้ว ไม่ได้แก้ในงานนี้
- **Case read scope ยังไม่ครบ**: CaseController::index ไม่มี org filter และ DocumentCasePolicy::view ตรวจเพียง document.view; show กรองเอกสารข้างใน แต่ metadata/จำนวนหนังสือของสารบบยังเปิดกว้าง ส่วน update อนุญาต null owner org เป็นส่วนกลาง ขัดกับการตีความ deny-by-default ที่ต้องทบทวน
- DocumentPolicy ไม่มีเงื่อนไข confidentiality/clearance แม้มี field ชั้นความลับในข้อมูล; ยังไม่พบ policy กลาง confidentiality/download/print ที่บังคับตาม master §18 ในเส้นทางที่ตรวจ
- Policy ราย resource อนุญาต Super Admin ผ่าน Gate::before แต่ list queries ยังใช้ OrgScopeResolver แยก จึงไม่ควรตีความ 32/32 permissions ว่าเห็นทุก record: หน้า authority ที่เปิดจริงแสดงขอบเขต 1 หน่วยงาน
- ข้อเดิม legal hold/disposal race ยังพบใน RetentionService: ตรวจ hold ก่อน transaction และไม่พบล็อกเอกสารร่วมกับ placeHold; ยังไม่ได้พิสูจน์ concurrency รอบนี้
- Regression scope เดิมสำหรับ case mutation/disposal ผ่านใน suite รอบนี้แล้ว แต่ไม่ได้พิสูจน์ว่า case metadata และ race ที่กล่าวข้างต้นถูกปิด

## 6. Feature flags

| Flag | Runtime | ผล |
|---|---|---|
| saraban.webhooks | true | อนุญาตช่องทาง webhook เมื่อผ่านเงื่อนไขอื่น |
| saraban.distribution.all_authorized | true | เปิดตัวเลือกเวียนถึงผู้มีสิทธิ์ทั้งหมด |
| system.read_only | false | ไม่อยู่ในโหมดอ่านอย่างเดียว |
| notification.web_push | true | เปิดช่องทาง Web Push ตามการตั้งค่าอื่น |

ไม่มี DB override ของทั้งสี่ flag; ใช้ default ของ FeatureFlags catalogue ค่าเหล่านี้ไม่ใช่ตัวกรองเมนู SARABAN รายหน้า และไม่ให้สิทธิ์แก่ผู้ใช้เพิ่ม

## 7. Frontend guards

- router.beforeEach ตรวจ login และ meta.permission/meta.permissions ของ route ที่กำหนด แต่ SARABAN routes กำหนดเฉพาะ requiresAuth โดยเจตนา จึงเปิด URL ตรงได้แล้วให้ backend ตัดสิน
- ไม่พบ route guard ที่โหลด module state/feature flags เพื่อกันเปิด shell ของ SARABAN ที่ปิด; backend 404 เป็นด่านจริง
- ปุ่มบางงานใช้ v-can เช่น create/sign/distribute และ server ส่ง workflow transitions; บางปุ่มตรวจแค่สถานะ เช่น canSubmit ใน DocumentFormView จึงอาจแสดงปุ่มแล้วถูก API ปฏิเสธ
- Router ข้าม meta permission เมื่อ authStore.isAdmin ซึ่งนิยามเป็น system.setting.manage ไม่ใช่ Super Admin โดยตรง (ประเด็น UX ข้ามโมดูล; backend ยังต้องตรวจ)
- CommandCenterView ซ่อน actions ด้วย operations.retry ขณะที่ API ยอม exception.manage ด้วย ทำให้บาง permission combination ใช้ API ได้แต่ปุ่มไม่แสดง

## 8–9. UI implemented / backend ยังไม่ผูก frontend

ตารางเมนูข้างต้นยืนยันว่ามีไฟล์ component และ API calls จริง ไม่ใช่ placeholder; ทะเบียนหนังสือใช้ direction filter รวมรับ/ส่ง/ภายใน ไม่ได้มีหน้าส่งและภายในแยกตามชื่อใน master

การทำงานที่ผูกแล้ว: draft/create/update/submit, incoming register, workflow transition/history, simulation, annotations/stamp/render, signing พร้อม step-up, send distribution, inbox acknowledgement/progress, policy CRUD/publish, reports, number series, command center

| ความสามารถ API ที่มีแล้ว | สถานะการผูก UI จากการค้น frontend/src ทั้งหมด |
|---|---|
| POST documents/{id}/finalize | **ไม่พบ call**; SignDocumentModal เรียกเพียง /sign จึงไม่มีปุ่มออกฉบับสมบูรณ์พร้อม QR ใน flow นี้ |
| POST documents/{id}/endorse | **ไม่พบ call**; handwriting ใน modal ลงนามไม่ใช่การเรียก endpoint เกษียน |
| GET documents/{id}/signatures | **ไม่พบ call** แยก; public verify แสดง signatures ที่มากับ verify response ได้ |
| GET documents/{id}/distributions | **ไม่พบ call** รายฉบับ; มีส่ง POST และรายงาน distribution รวม |
| GET documents/{id}/compare | **ไม่พบ call** |
| GET/POST documents/{id}/redactions | **ไม่พบ call** |
| saved-views CRUD | **ไม่พบ call** |
| retention / holds / release / due / approve / dispose | **ไม่พบ call** |
| cases attach/detach documents | **ไม่พบ call**; CasesView มี create/list/show/close เท่านั้น |
| authority/holders | **ไม่พบ call**; หน้าปัจจุบันแสดง authority ของตน |
| incoming/duplicates | **ไม่พบ call** preflight; backend store ยังอาจตรวจซ้ำเอง จึงไม่สรุปว่าไม่มี duplicate protection |
| number-series DELETE | **ไม่พบ call**; UI ใช้ PUT เปิด/ปิดแทน |

สิทธิ์ที่มีใน catalogue ไม่รับรองว่ามี feature ครบ: `saraban.distribution.create` (ร่างรายการเวียน) มีคอมเมนต์ route ว่ายังไม่มี; ไม่พบหน้าจัดการ/เผยแพร่ workflow สารบรรณโดยเฉพาะ, คิวรอตรวจ/รอลงนามแยก หรือหน้าควบคุมเลข reserve/release/cancel ใน frontend ที่ตรวจ รายงาน register มีหน้าอ่าน แต่ไม่พบ flow ส่งออกที่ใช้ saraban.report.export

## 10. Role ปัจจุบันและ role mapping ใน DB

- บัญชีที่ browser แสดง: **ทดสอบ ระบบ**; DB matched id=1, role **Super Admin**, effective Gate SARABAN **32/32 true** ควรเห็นเมนูลูกทั้งหมด 13 รายการเมื่อ DB sync ครบ
- ตรวจ role_permissions ปัจจุบัน: Employee, HR Head, Deputy Director, Director, Document Officer มี SARABAN เพียง inbox.view; Super Admin มีทั้ง 32 รายการ
- การที่ Document Officer ไม่มี register/create/stamp หรือ Director ไม่มี sign หมายถึง **สิทธิ์จาก role ยังไม่พร้อมสำหรับหน้าที่เหล่านั้น**; ต้องตรวจ assignment/สิทธิ์รายบุคคลประกอบก่อนตัดสินรายคน ไม่ได้ให้สิทธิ์เพิ่มอัตโนมัติจากชื่อ role
- ไม่ควรแก้เมนูหายของ Super Admin ด้วยการแจก permission ซ้ำ เพราะบัญชีนี้ผ่านครบอยู่แล้ว

## การตรวจสอบและข้อจำกัด

- `docker exec eleave_backend php artisan test --compact`: **1,039 passed, 8 skipped, 3,825 assertions**, 131.98 วินาที
- `vendor/bin/pint --test`: **PASS 657 files**
- Frontend `npm run lint`, `npm run typecheck`, `npm test`, `npm run build -- --outDir /tmp/saraban-audit-dist`: ผ่าน; **81 tests / 9 files**; build 5.15 วินาที สร้าง output ใน /tmp ของ frontend container ไม่ทับ dist ที่ nginx ใช้อยู่
- เก็บผลคำสั่งใน `saraban-audit-backend-2026-09-05.log`, `saraban-audit-pint-2026-09-05.log`, `saraban-audit-frontend-2026-09-05.log`
- Browser read-only: เห็น sidebar เดิม 6 เมนูและบัญชีปัจจุบัน; เปิด `/saraban/policies` และ `/saraban/authority` ในแท็บตรวจแยก โหลดข้อมูลจริงสำเร็จแม้ไม่มีเมนู อีก 5 หน้าที่หายตรวจจาก source + runtime route registration ไม่ได้ทำ browser UAT ทุกหน้า
- tests ใช้ SQLite :memory: ตาม phpunit.xml ไม่รัน migration กับ DB ธุรกิจ; ผลผ่านไม่ครอบคลุม missing menu ใน DB ที่ติดตั้งแล้ว และไม่ได้หักล้าง static findings ที่ไม่มี regression test
- ไม่ได้เปลี่ยน source code, migrations, API, role grants, feature flags หรือ cache เพื่อซ่อนปัญหา; ไม่มี architectural decision ใหม่

## ลำดับแก้ที่เสนอ

1. เพิ่มเมนู SARABAN ที่ขาด 7 แถวและ permission mapping แบบจำกัดขอบเขต พร้อม invalidate menu cache แล้วตรวจ 13 เมนูบนบัญชีปัจจุบัน ไม่ควรใช้ MenuSeeder ทั้งระบบโดยไม่ตรวจผลกระทบ เพราะ seeder ลบแถวที่ไม่อยู่ใน TREE และ Cache::flush ทั้งระบบ
2. ทำ menu/API permission matrix ให้สอดคล้อง: parent gating, options dependencies, policy read, authority/self และ exception actions
3. ปิดช่องว่าง ABAC/explicit deny และเพิ่ม regression สำหรับกรณีที่ระบุ ก่อนถือว่าโมดูลผ่านด้านความปลอดภัยครบ
4. ผูก UI ที่ขาดตาม flow งานจริง เริ่ม finalize/เกษียน/ติดตามการเวียน และจัด role + scope ตามหน้าที่ที่อนุมัติ
5. เติม manifest metadata และเพิ่มตรวจ upgrade/runtime DB menu drift เพื่อไม่ให้ fresh-seed tests ผ่านแต่ระบบใช้งานจริงยังไม่มีเมนู
