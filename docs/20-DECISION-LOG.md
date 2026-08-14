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
