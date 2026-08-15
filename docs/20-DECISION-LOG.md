# DECISION LOG

## ADR-009 Workflow engine เดินคู่ขนานก่อน ไม่สลับทันที

ตัดสินเมื่อ 2026-08-14 (LEGACY-05)

Workflow engine ใหม่ถูกติดตั้งในโหมด shadow: คำนวณขั้นตอนปัจจุบัน ผู้อนุมัติถัดไป
และการกระทำที่ทำได้ **คู่ขนาน** กับตรรกะเดิม แล้วบันทึกผลเทียบลง
`workflow_shadow_comparisons` โดยผลของตรรกะเดิมยังเป็น source of truth ทั้งหมด

`approval_steps` และ `leave_approvals` คงไว้ในฐานะข้อมูลอ่านอย่างเดียว ห้ามลบ

เหตุผล: การสลับทันทีทำให้ข้อบกพร่องของ engine ไปโผล่ที่ผู้ใช้จริง เฟสนี้พิสูจน์
คุณค่าของการตัดสินใจนี้แล้ว — shadow จับได้ว่าใบลาที่ยกเลิกก่อนมีใครอนุมัติจะกลับมา
โผล่ในคิวรออนุมัติ ซึ่งจะกลายเป็นปัญหาหน้างานทันทีถ้า cutover แบบ big bang

เงื่อนไข cutover (05-4): ผลเทียบต้องตรง 100% จาก**การอนุมัติจริง**ตามจำนวนที่ตกลงกัน
ไม่ใช่จากการ replay ย้อนหลังอย่างเดียว

## ADR-010 Workflow instance pin เวอร์ชันของตนเอง

ตัดสินเมื่อ 2026-08-14 (LEGACY-05)

ทุก `workflow_instances` เก็บ `workflow_version_id` ที่ pin ไว้ตอนเริ่ม
การแก้สายอนุมัติจะ**ออกเวอร์ชันใหม่**และ retire เวอร์ชันเดิม โดยไม่แตะ instance
ที่กำลังรันอยู่ ตาม `docs/07-WORKFLOW-ENGINE.md` และ PROJECT_RULES ข้อ 7

ปัญหาที่แก้: เดิม `approval_steps` เป็นตารางเดียวระดับ global ไม่มี version
การแก้สายอนุมัติวันนี้จึงเปลี่ยนความหมายของใบลาที่ค้างอยู่ในระบบทันที

`workflow_actions` เป็น append-only บังคับที่ชั้นโมเดล — ห้าม update/delete

## ADR-006 ตำแหน่งหัวหน้าหน่วยงานเป็นการมอบหมาย ไม่ใช่ชื่อบทบาท

ตัดสินเมื่อ 2026-08-14 (ปิด LEGACY-04)

ระบบต้องรองรับหัวหน้าแผนก หัวหน้างาน หัวหน้าฝ่าย และผู้รักษาการ **ห้ามสร้าง role
ชื่อ "หัวหน้าแผนก" เพื่อใช้เป็น business logic** เพราะชื่อ role ผู้ดูแลแก้ได้จากหน้าจอ
และ LEGACY-04 พิสูจน์แล้วว่าชื่อ role ที่โค้ดเช็คกับชื่อที่มีจริงในฐานข้อมูลไม่ตรงกัน
ตรรกะที่ผูกกับชื่อจึงกลายเป็น dead code โดยไม่มีใครรู้

เก็บเป็น assignment ใน `org_unit_managers` ผูกกับ `org_units` รองรับ effective date
และผู้รักษาการ/ผู้รับมอบอำนาจผ่าน `assignment_type` ขอบเขตเริ่มต้นเป็น `UNIT`
และเป็น `DESCENDANTS` เมื่อหน่วยงานมีหน่วยงานลูก

`OrgScopeResolver` ตัดสินตามลำดับ: แถวใน `permission_scopes` ที่ผู้ดูแลกำหนดเอง >
การมอบหมายที่มีผล ณ วันนี้ > ค่าเริ่มต้นของจุดเรียก ทำให้เพิ่มตารางนี้ได้โดยไม่กระทบใคร
เมื่อยังไม่มีการมอบหมาย

## ADR-007 ขอบเขตข้อมูลของผู้บริหารและหัวหน้าหน่วยงาน

ตัดสินเมื่อ 2026-08-14 (ปิด LEGACY-04)

| ตำแหน่ง | ขอบเขตเริ่มต้น |
|---|---|
| ผู้อำนวยการ | `ORGANIZATION` |
| รองผู้อำนวยการ | `DESCENDANTS` ของหน่วยงาน/สายงานที่ได้รับมอบหมาย |
| HR | ตาม permission + scope ที่ได้รับ |
| หัวหน้าแผนก/หน่วยงาน | `UNIT` หรือ `DESCENDANTS` |
| ผู้ดูแลระบบสูงสุด | ทั้งระบบ แต่ explicit DENY ต้อง override ได้ |

ห้าม hard-code ชื่อบุคคลหรือชื่อฝ่ายในโค้ดเพื่อบังคับขอบเขตเหล่านี้ — ทุกค่าเป็นข้อมูล
ใน `permission_scopes` และ `org_unit_managers`

## ADR-008 การจัดการสิทธิ์และการตั้งค่าระบบเป็นสิทธิ์เฉพาะของผู้ดูแลระบบ

ตัดสินเมื่อ 2026-08-14 (ปิด LEGACY-04)

`edit_users` คือสิทธิ์แก้ไข**ข้อมูล**ผู้ใช้ ไม่ใช่สิทธิ์**มอบ**สิทธิ์ เดิม route
กลุ่มเดียวกันยอมรับทั้งสองอย่าง ทำให้ผู้ถือ `edit_users` มอบ permission ใดก็ได้ให้ตนเอง
หรือสร้าง role ใหม่แล้วผูก permission เข้าไปได้ จึงแยกเป็น
`admin.users.manage_permissions` และ `system.menu.manage`

ในทำนองเดียวกัน `saraban.setting.manage` ต้องเปิดได้เฉพาะค่าตั้งค่าของงานสารบรรณ
จึงย้ายไปที่ `/api/saraban/settings` แทนการอยู่รวมกับ `/api/admin/settings`
ซึ่งมีค่า SMTP, โทเคน Telegram/LINE และการล้างค่าลับของทั้งระบบ

## ADR-005 Access token lifetime and rotation

กำหนด access token เริ่มต้น 480 นาทีผ่าน `SANCTUM_EXPIRATION` และหมุน token เดิมเมื่อ refresh โดย revoke token ทั้งหมดเมื่อเปลี่ยนรหัสผ่านหรือระงับบัญชี ค่า production บังคับ session encryption และ secure cookie ผ่าน Docker environment defaults ส่วนสภาพแวดล้อม local ปรับค่าได้ใน `.env`

## ADR-004 Leave status uses checked varchar

เลือกเปลี่ยน `leaves.status` จาก MySQL ENUM เป็น `VARCHAR(20)` พร้อม CHECK constraint เพื่อเพิ่ม `Voided` แบบ forward-only โดยไม่แก้ migration เก่าและไม่ทำลายประวัติเอกสารที่ void แล้ว

## ADR-001 Modular Monolith
เลือก Modular Monolith เพื่อความง่ายในการพัฒนา/ดูแลระยะแรก แต่บังคับ domain boundary เพื่อรองรับการแยก service ในอนาคต.

## ADR-002 Policy/Workflow versioning
กฎและ workflow ต้อง versioned/effective-dated เพื่อรักษาความถูกต้องของรายการย้อนหลัง.

## ADR-003 Vendor attendance as raw source
ระบบผู้ผลิตเครื่องสแกนส่ง raw events เข้าระบบกลาง; Attendance Engine ของระบบกลางเป็นผู้ตัดสินสถานะตามตาราง/นโยบาย.

## ADR-009 Attendance raw evidence, reconciliation, and corrections

ตัดสินเมื่อ 2026-08-15 (LEGACY-08)

ทุกแหล่ง `manual`, `web`, `device`, `import` ส่ง event แบบ vendor-neutral เข้า
`attendance_raw_events` ก่อนแปลงเป็นผลรายวันใน `attendances` โดยใช้
`event_uuid` และ `(attendance_source_id, dedup_key)` เป็น idempotency boundary
เหตุการณ์ดิบแก้ payload/เวลา/ตัวตนไม่ได้ แต่ service อัปเดต processing metadata ได้
เพื่อรองรับ retry และ reconciliation โดยไม่ทำลายหลักฐานต้นทาง

ยังคง `unique(user_id, date)` บน `attendances` จนกว่า pipeline จริงจาก vendor
จะผ่านการ pilot/reconciliation ตามแผน การแก้ผล attendance ด้วยคนต้องสร้าง
`attendance_corrections` และผ่าน Workflow Engine ก่อน apply พร้อม central audit
ห้าม controller หรือ connector แก้ raw event หรือผลย้อนหลังโดยตรง

Connector ใช้ `AttendanceConnector` และ marker `BiometricConnector` เท่านั้นในเฟสนี้
ห้ามเพิ่ม SDK/protocol/identifier ของยี่ห้อใดจนกว่าจะตอบ Open Question #1/#2.

## ADR-010 Overtime has three independent time truths

ตัดสินเมื่อ 2026-08-15 (LEGACY-09)

OT แยกเป็น `ot_orders` (คำสั่งและเวลาที่อนุมัติ), `ot_assignments`
(ผู้ได้รับมอบหมาย), และ `ot_time_records` (เวลาปฏิบัติจริงและเวลาที่รับรอง)
ห้ามใช้ค่าใดค่าหนึ่งแทนอีกค่า เพราะ approved time, actual time และ certified time
เป็นคนละข้อเท็จจริงและอาจไม่เท่ากันโดยชอบด้วยกระบวนการ

คำสั่งต้องผ่าน Workflow Engine ก่อนมอบหมาย การลงเวลาจริงต้องมี assignment
ที่อนุมัติและอยู่ในช่วงเวลา การรับรองทำภายหลังโดยผู้ถือสิทธิ์ภายใน org scope
และทุก mutation สำคัญเข้า central audit

คอลัมน์ `attendances.ot_*` คงไว้เป็นข้อมูล legacy แบบอ่านอย่างเดียวเพื่อ rollback
และ compatibility แต่ source of truth ใหม่คือโดเมน OT เท่านั้น ห้ามสร้าง rate,
amount หรือการคำนวณค่าตอบแทนจนกว่าจะมีหลักเกณฑ์ที่อนุมัติและ versioned.

## ADR-011 Business modules emit; Notification Center delivers

ตัดสินใจเมื่อ 2026-08-15 (LEGACY-10)

โมดูล Leave, Attendance, Overtime และ Saraban มีหน้าที่เลือก event, related resource และผู้รับภายใต้ permission/scope แล้ว emit เข้า Notification Center เท่านั้น ห้ามเรียก Email, LINE OA หรือ Telegram provider จาก business flow โดยตรง

Notification Center เป็นเจ้าของ notification type, versioned template, user preference, recipient/read state, delivery status และ retry แยก channel ความล้มเหลวของ provider ต้องเกิดหลัง business transaction และห้าม rollback ธุรกรรมหลัก

payload และ template ใช้ข้อมูลขั้นต่ำ ตัด credential, รหัสประชาชน, เหตุผลลา, medical detail, attachment และเนื้อหาเอกสารออกโดยปริยาย ประวัติใน Laravel `notifications` เดิมคงไว้และ dual-read ระหว่างช่วง compatibility; ยังไม่ลบหรือ backfill จนกว่าจะพิสูจน์การส่งจริงครบหนึ่งรอบการใช้งาน

## ADR-012 Reporting datasets are scoped facts; evaluation formulas require approval

ตัดสินใจเมื่อ 2026-08-15 (LEGACY-11)

รายงานทุกชนิดต้องสร้างจาก dataset ที่ผ่าน permission และ organization scope ก่อน และ export ทุก format ต้องใช้ dataset เดียวกับ JSON response เพื่อไม่ให้เกิดช่องทางส่งออกข้อมูลเกิน scope. Government Reports เดิมคง URL และ response contract ไว้ แต่ query ต้องเคารพ explicit permission scope.

Evaluation layer เก็บและส่งคืนเฉพาะข้อเท็จจริง เช่น วันลา การมาสาย การขาดงาน และเวลา OT ที่อนุมัติ/ปฏิบัติจริง/รับรอง ห้ามสร้าง score, weight, threshold, ranking หรือ pass/fail จนกว่าองค์กรจะกำหนดหลักเกณฑ์และอนุมัติ version ที่ใช้บังคับอย่างชัดเจน.

## ADR-013 Modules declare extensions; Core never imports business modules

ตัดสินใจเมื่อ 2026-08-15 (LEGACY-12)

โมดูลใหม่ต้องลงทะเบียนผ่าน `ModuleContract`/`ModuleRegistry` และประกาศ routes, menus, permissions, policies, workflows, settings, notifications, reports, audit events, version, dependencies และ enabled state. Permission ใหม่ใช้ `module.resource.action`; ชื่อเดิมที่ไม่ตรงเป็น compatibility exception เท่านั้น.

Core ให้ contracts และ platform services แต่ห้าม import Leave, Attendance, Overtime, Saraban หรือโมดูลธุรกิจในอนาคต. Module provider เป็นผู้ผูก policy/listener/adapter ของ domain. การปิดโมดูลเป็น deployment switch แบบ transitive: routes ตอบ 404, route files ไม่โหลดและ menus ไม่แสดง แต่ container adapters ยังคง resolve ได้เพื่อไม่ทำให้งาน queue/in-flight พัง. เมนูเป็นเพียง presentation และไม่ให้สิทธิ์ backend.

## ADR-014 PROD-00 Architecture Freeze

ตัดสินใจเมื่อ 2026-08-15 (PROD-00)

สถานะของระบบเข้าสู่ Production Phase อย่างเป็นทางการ สถาปัตยกรรม เทคโนโลยี (Laravel, Vue 3, MySQL 8, Tailwind, Docker) และโครงสร้างหลักจะถูก Freeze หรือล็อคไว้ ไม่มีการปรับเปลี่ยนหรือเพิ่มเครื่องมือใหม่ (Dependency versions pinned) นอกจากมีเหตุผลด้านความปลอดภัยร้ายแรงหรือ Critical Bug Fixes เท่านั้น โครงสร้างฐานข้อมูลจะถูก Freeze เพื่อป้องกันผลกระทบต่อ UAT และ Production Data.
