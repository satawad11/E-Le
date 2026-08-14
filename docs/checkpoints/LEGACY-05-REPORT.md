# LEGACY-05 — Workflow Engine

**วันที่:** 14 สิงหาคม 2569 (2026-08-14)
**ขอบเขต:** LEGACY-05 ตาม `docs/LEGACY-MIGRATION-PLAN.md` (05-1 ถึง 05-7)
**ต่อจาก:** `LEGACY-04-FINAL-REPORT.md` (LEGACY-04 = PASS)
**ผล:** **LEGACY-05 = PASS** · Shadow ตรงกัน 100% · **ยังไม่ Cutover** (05-4 เป็นขั้นตอนแยกที่ต้องได้รับอนุมัติ)

---

## สรุปผลรายข้อ

| # | งาน | ผล |
|---|---|:---:|
| 05-1 | schema 8 ตาราง + comparison log | **PASS** |
| 05-2 | แปลง `approval_steps` เป็น Leave Workflow v1 | **PASS** |
| 05-3 | Shadow run + log ความต่าง | **PASS** — ตรงกัน 100% |
| 05-4 | Cutover เมื่อ shadow ตรงตามเวลาที่ตกลง | **ยังไม่ทำ** — ตามเจตนา ดูข้อ 11 |
| 05-5 | เตรียม Saraban เข้า engine เดียวกัน | **PASS** — draft เท่านั้น ไม่แตะสิทธิ์ |
| 05-6 | parallel / conditional / return / escalation / timeout / acting | **PASS บางส่วน** — ดูข้อ 12 |
| 05-7 | เก็บ `leave_approvals` + `approval_steps` เป็น read-only | **PASS** |

### กฎบังคับของเฟส

| กฎ | ผล |
|---|:---:|
| 1. ห้ามลบ `approval_steps` | ✅ 3 แถวเท่าเดิม |
| 2. ห้ามลบ `leave_approvals` | ✅ 6 แถวเท่าเดิม |
| 3. ห้ามลบ approval history | ✅ ไม่มี UPDATE/DELETE ใดถูกยิงใส่ |
| 4. ห้ามเปลี่ยนความหมายใบลาที่ค้าง | ✅ `leaves` ไม่ถูกเขียนเลยตลอดเฟส |
| 5. ห้าม Big Bang Cutover | ✅ ไม่ cutover |
| 6. Additive migration เท่านั้น | ✅ CREATE TABLE ล้วน |
| 7. Shadow ก่อน | ✅ ทำงานอยู่บน endpoint จริง |
| 8. เทียบผลเก่า/ใหม่ | ✅ 6/6 จุดบนข้อมูลจริง + 19 สถานะสังเคราะห์ |
| 9. mismatch = หยุด | ✅ **พบ 1 กรณี → แก้แล้ว** ดูข้อ 5 |
| 10. ห้ามเริ่ม LEGACY-06 | ✅ ไม่ได้เริ่ม |

---

## 1. Schema ที่เพิ่ม

migration `2026_08_14_200000_create_workflow_engine_foundation` — **CREATE TABLE ล้วน ไม่มี ALTER ของเดิม**

| ตาราง | หน้าที่ |
|---|---|
| `workflow_definitions` | นิยาม workflow หนึ่งชุด (leave.approval, saraban.document) |
| `workflow_versions` | เวอร์ชัน effective-dated · draft/active/retired |
| `workflow_steps` | ขั้นตอน + ผู้รับผิดชอบ + SLA + escalation target |
| `workflow_transitions` | เส้นทาง approve/reject/return/cancel/delegate/escalate/timeout |
| `workflow_instances` | เรื่องหนึ่งเรื่องที่กำลังเดินสาย · **pin `workflow_version_id`** |
| `workflow_tasks` | งานรอทำรายขั้น · แยกผู้กดจริงกับผู้ที่ควรเป็นเจ้าของงาน |
| `workflow_actions` | บันทึกการกระทำ **append-only** |
| `workflow_delegations` | มอบอำนาจระดับ workflow · อ้างกลับ `user_delegations` |
| `workflow_shadow_comparisons` | หลักฐานเทียบผลเก่า/ใหม่ (05-3) |

**ที่รองรับตั้งแต่ schema:** sequential, parallel (`completion_policy`), conditional (`conditions`),
approve, reject, return, cancel, delegate, acting approver (`assignment_type`/`effective_actor_id`),
timeout (`sla_hours`, `on_timeout`), escalation (`escalate_to_step_id`)

**ที่เลือกใช้ `string` แทน `enum`:** เพื่อให้เพิ่มค่าใหม่ได้โดยไม่ต้อง ALTER TABLE
(ขัดหลัก additive) และเพื่อให้ชุดทดสอบบน SQLite ทำงานได้ — ค่าที่ยอมรับบังคับที่ชั้น application

> **หมายเหตุ:** ชื่อ index อัตโนมัติของ `workflow_transitions` ยาวเกิน 64 ตัวอักษรที่ MySQL รับได้
> จับได้ตอนรัน migration จริงบน MySQL 8 (ไม่ใช่ SQLite) จึงตั้งชื่อเองเป็น `workflow_transition_lookup`

---

## 2. Workflow v1

`LeaveWorkflowProvisioner::publishCurrentVersion()` แปลง `approval_steps` **ที่ใช้อยู่จริง**
เป็น definition `leave.approval` version 1 (status `active`)

| ขั้น | ชื่อ | บทบาทที่รับผิดชอบ |
|---:|---|---|
| 1 | ตรวจสอบโดยหัวหน้าบุคลากร | HR Head |
| 2 | พิจารณาโดยรองผู้อำนวยการ | Deputy Director |
| 3 | อนุมัติรอบสุดท้ายโดยผู้อำนวยการ | Director |

transitions 12 เส้น = 4 การกระทำ × 3 ขั้น (`approve`, `reject`, `return`, `cancel`)

**หลักการที่ยึด:** v1 คือ "สายอนุมัติที่ใช้อยู่วันนี้" ไม่ใช่สายอนุมัติในอุดมคติ
เพราะเป้าหมายของ shadow คือพิสูจน์ว่าผลเหมือนเดิมก่อนจะเปลี่ยนอะไร

**Version pinning** — `publishCurrentVersion()` เทียบลายนิ้วมือของสายอนุมัติ
ถ้าไม่เปลี่ยนจะคืนเวอร์ชันเดิม ถ้าเปลี่ยนจะออกเวอร์ชันใหม่และ retire ตัวเก่า
โดย **instance ที่รันอยู่ไม่ถูกแตะ** — มีเทสต์ยืนยันว่าใบลาที่ pin v1 ไว้ยังเดินครบ 3 ขั้น
แม้ v2 จะเหลือ 2 ขั้น

---

## 3. การ map ข้อมูลเดิม

| ของเดิม | อ่านไปเป็น | เขียนกลับ? |
|---|---|:---:|
| `approval_steps` | `workflow_steps` ของ v1 | **ไม่** |
| `leave_approvals` | `workflow_tasks` ของ shadow instance | **ไม่** |
| `leaves.approval_level` | เก็บใน `workflow_instances.meta` เพื่อการตรวจสอบ | **ไม่** |
| `user_delegations` | mirror เข้า `workflow_delegations` (`legacy_delegation_id`) | **ไม่** |

`backfill()` **reconstruct** สถานะจากประวัติจริง ไม่ได้ "เดินสายใหม่"
ใบลาที่ Pending อยู่จึงได้ instance ที่ชี้ขั้นเดียวกับ `approval_level` พอดี

### ตัวเลขบนฐานข้อมูลจริง

| | ก่อนเฟส | หลังเฟส |
|---|---:|---:|
| `approval_steps` | 3 | **3** |
| `leave_approvals` | 6 | **6** |
| `leaves` (รวม soft-deleted) | 3 | **3** |
| `workflow_instances` | – | 2 (shadow ทั้งหมด) |
| `workflow_tasks` | – | 6 |

**ไม่มีข้อมูลเดิมสูญหายหรือถูกแก้แม้แต่แถวเดียว**

---

## 4. ผล Shadow Comparison

```
+-----------------------+---------+---------+---------+
| checkpoint            | ทั้งหมด  | ตรงกัน   | ไม่ตรง   |
+-----------------------+---------+---------+---------+
| available_transitions |    2    |    2    |    0    |
| current_step          |    2    |    2    |    0    |
| next_approver         |    2    |    2    |    0    |
+-----------------------+---------+---------+---------+
รวม 6 จุด · ไม่ตรง 0 จุด
```

### 🔴 ข้อจำกัดที่ต้องอ่านก่อนตัดสินใจ cutover

**ฐานข้อมูลจริงมีใบลาเพียง 3 ใบ และถูก soft delete ทั้งหมด**
(`deleted_at` ตั้งแต่ 9–11 ส.ค.) ใบที่ 1 ไม่มี `leave_approvals` เลย
เทียบได้จริงจึงเหลือ **2 ใบ = 6 จุด**

ตัวเลข "ตรงกัน 100%" ข้างบนจึงมาจากฐานข้อมูลที่แทบไม่มีข้อมูล **ห้ามใช้ตัวเลขนี้
อย่างเดียวเป็นเหตุผลในการ cutover** หลักฐานที่มีน้ำหนักจริงคือชุดสถานะสังเคราะห์ในข้อ 9
และ shadow ที่ติดตั้งบน endpoint จริงแล้วซึ่งจะสะสมหลักฐานเมื่อมีการใช้งานจริง

**เกณฑ์ที่ควรใช้แทน:** shadow ต้องตรง 100% จากการอนุมัติจริงจำนวนหนึ่งที่ตกลงกัน
ตรวจได้ทุกเมื่อด้วย `php artisan workflow:shadow-report`

---

## 5. Mismatch ที่พบ

พบ **1 กรณี** — และเป็นเหตุผลทั้งหมดที่เฟสนี้ต้องมี shadow mode

### ใบลาที่ถูกยกเลิกก่อนมีใครอนุมัติ กลับมาโผล่ในคิวรออนุมัติ

พบจาก `workflow:shadow-replay` บนใบลา **ใบที่ 3 ของฐานข้อมูลจริง**

```
MISMATCH leave#3 [current_step]   legacy={"step_order":null} engine={"step_order":1}
MISMATCH leave#3 [next_approver]  legacy={"approver_ids":[]} engine={"approver_ids":[5]}
```

**สาเหตุ:** ใบลาที่ยกเลิกก่อนใครอนุมัติจะมี `leave_approvals` เป็น `Pending` ครบทุกแถว
ตรรกะเดิมตอบว่า "ไม่มีขั้นปัจจุบัน" เพราะดู**สถานะใบลา**ก่อน
ส่วน engine รุ่นแรกตอบว่า "อยู่ขั้นที่ 1" เพราะดูแต่ `workflow_tasks`

**ผลถ้า cutover ตอนนั้น:** ใบลาที่ยกเลิกไปแล้วจะกลับมาอยู่ในคิวรออนุมัติของ HR

**แก้ที่สองจุด:**
1. `WorkflowEngine::currentTask()` — instance ที่จบแล้วไม่มีขั้นปัจจุบัน แม้จะมี task ค้าง
2. `LeaveWorkflowProvisioner::backfill()` — ปิด task ที่ค้างเป็น `skipped` เมื่อเรื่องจบแล้ว

**ล็อกไว้ด้วยเทสต์:** `test_a_leave_cancelled_before_any_approval_has_no_current_step`

หลังแก้: replay ได้ 6/6 ตรงกัน

> พบเพิ่มระหว่างทาง (ไม่ใช่ mismatch แต่เป็น bug ของเครื่องมือเอง):
> `workflow:shadow-report` เคยรายงานตัวเลขผิด เพราะ select `SUM(...) as matched`
> แล้วโมเดล cast `matched` เป็น boolean ทำให้ผลรวม 2 กลายเป็น 1
> แก้โดยใช้ query builder ดิบและเปลี่ยนชื่อ alias — ถ้าไม่จับได้ รายงานจะบอกว่า
> "ไม่ตรง" ทั้งที่ตรง และทำให้ตัดสินใจผิด

---

## 6. Delegation

`DelegationResolver` ให้ผลตรงกับ `GovernmentApprovalController::authorizeStep()` ทุกกรณี:

1. ถือ `leave.management.manage` → ผ่าน (ไม่นับเป็นการรับมอบอำนาจ)
2. ถือบทบาทของขั้นตอน → ผ่านในนามตนเอง
3. รับมอบอำนาจจากผู้ที่ถือบทบาทนั้น → **ผ่านในนามผู้มอบ**

บันทึกครบตามที่เฟสกำหนด — `completed_by` (ผู้กดจริง), `effective_actor_id`
(ผู้ที่ควรเป็นเจ้าของงาน), `delegated_by_id` (ผู้มอบ), และช่วงเวลาที่ตรวจแล้ว

**ตรวจ effective date ทุกครั้ง** มีเทสต์ครอบ 3 กรณี: หมดอายุแล้ว, ยังไม่ถึงกำหนด,
และผู้มอบไม่ได้ถือบทบาทที่ขั้นตอนต้องการ — ทั้งสามกรณีต้องอนุมัติไม่ได้

`user_delegations` **ยังเป็น source of truth** — `workflow_delegations` ที่มี
`legacy_delegation_id` คือภาพสะท้อน ไม่ใช่ข้อมูลคู่ขนานที่แข่งกัน (บทเรียนจาก DB-RISK-009)
ปัจจุบันฐานข้อมูลมี 0 แถวทั้งสองฝั่ง

**ที่เพิ่มจากของเดิม:** จำกัดขอบเขตการมอบอำนาจได้ (`workflow_definition_id`, `scope_role_id`)
เมื่อไม่ระบุ = พฤติกรรมเท่าเดิมทุกประการ

---

## 7. Saraban

**สถานะ: เตรียมไว้เท่านั้น — ยังไม่เปิดใช้ และไม่แตะสิทธิ์ใด ๆ**

`SarabanWorkflowProvisioner::publishDraft()` บันทึกสายงานที่ `DocumentWorkflowService`
ใช้อยู่จริงเป็น definition `saraban.document` version 1 **status `draft`**
(`activeVersion()` จะไม่คืนเวอร์ชัน draft จึงไม่มีทางรับ instance ได้)

```
draft ──▶ pending_review ──▶ pending_approval ──▶ approved_pending_signature ──▶ signed
            └──reject──▶ draft   └──reject──▶ draft
```

ขั้นตอนผูกกับ permission ที่คุมอยู่จริงวันนี้ — `saraban.document.create` /
`.register` / `.approve` / `.sign` ตามที่ LEGACY-04 ตั้งไว้ **ไม่มีการเปลี่ยนสิทธิ์**

มีเทสต์ยืนยันสองข้อ:
- สายงานสารบรรณต้องยังเป็น draft และไม่มี instance ใด ๆ
- การเตรียมสายงานต้องไม่ทำให้ permission หรือการแจกสิทธิ์เปลี่ยนแม้แต่แถวเดียว

**เหตุผลที่ยังไม่เปิดใช้:** LEGACY-04 ปิดงานโดยยังไม่ได้ให้สิทธิ์
`saraban.document.approve` / `.sign` แก่ ผอ./รอง ผอ. (รอ decision) ถ้าเดินสายนี้จริงตอนนี้
จะไม่มีผู้อนุมัติในสายเลยนอกจาก Super Admin

---

## 8. Audit

ทุกการกระทำบันทึกสองที่: `workflow_actions` (append-only) และ central audit log

| ที่เฟสกำหนด | คอลัมน์ |
|---|---|
| actor | `actor_id` |
| effective actor | `effective_actor_id` |
| workflow | ผ่าน `workflow_instance_id` → version → definition |
| instance | `workflow_instance_id` |
| action | `action` |
| from step / to step | `from_step_id` / `to_step_id` |
| reason/comment | `comment` |
| timestamp | `created_at` |
| request/correlation id | `correlation_id` |

**append-only บังคับที่ชั้นโมเดล** — `WorkflowAction` โยน exception เมื่อมีการ
`update()` หรือ `delete()` มีเทสต์ยืนยันทั้งสองทาง

ประวัติเดิม (`leave_audit_logs`, `leave_action_logs`, `audit_logs`) ไม่ถูกแตะ

> ข้อจำกัดที่บันทึกไว้: `AuditLogger::log()` ตั้ง `effective_actor_id = actor_id` เสมอ
> ตัว effective actor ที่แท้จริงจึงถูกเก็บใน payload `after` แทน
> ถ้าจะให้คอลัมน์นั้นถูกต้องต้องขยาย signature ของ `AuditLogger` ซึ่งกระทบผู้เรียกทุกจุด
> — เก็บเป็นงานแยก ไม่ทำในเฟสนี้

---

## 9. ผลการทดสอบ

| รายการ | ผล |
|---|---|
| `php artisan test` (ชุดเต็ม) | ✅ **320 passed / 832 assertions · 0 failed** |
| Workflow targeted tests | ✅ 52 tests / 139 assertions |
| Authorization matrix | ✅ ผ่าน |
| IDOR tests | ✅ ผ่าน |
| `vendor/bin/pint --test` | ✅ **PASS 359 ไฟล์** |
| `npm run build` | ✅ ผ่าน |
| migration บน MySQL 8 (ฐานว่าง) | ✅ ดูข้อ 10 |

### เทสต์ที่เพิ่ม (52 รายการ)

| ไฟล์ | จำนวน | ครอบอะไร |
|---|---:|---|
| `WorkflowEngineTest` | 24 | sequential, reject, return, delegate, expired/future delegation, version pinning, duplicate/concurrent action, audit completeness, append-only |
| `LeaveWorkflowShadowParityTest` | 14 | เทียบ engine กับตรรกะเดิมใน 19 สถานะ + regression ของ mismatch ที่พบ |
| `WorkflowAuthorizationTest` | 14 | authorization ต่อ action, IDOR, ใบลา Pending เดินต่อได้, shadow ไม่รบกวนของจริง, Saraban ยังไม่เปิด |

### รายการที่เฟสกำหนดให้มีก่อน cutover

| ต้องมี | มีแล้ว |
|---|:---:|
| sequential approval | ✅ |
| reject | ✅ |
| return | ✅ |
| delegate | ✅ |
| expired delegation | ✅ |
| workflow version pinning | ✅ |
| definition เปลี่ยนแต่ instance เก่าไม่เปลี่ยน | ✅ |
| Pending leave เดิมเดินต่อได้ | ✅ (ผ่าน HTTP จริงครบ 3 ขั้น) |
| authorization ต่อ workflow action | ✅ |
| IDOR | ✅ |
| duplicate action / concurrent approval | ✅ (`lockForUpdate` + เทสต์ 2 ผู้อนุมัติบทบาทเดียวกัน) |
| audit completeness | ✅ |

---

## 10. ผล Migration

- รันบนฐานข้อมูล dev (MySQL 8) สำเร็จ — additive ล้วน
- **ข้อมูลเดิมครบถ้วน:** `approval_steps` 3 · `leave_approvals` 6 · `leaves` 3 (เท่าก่อนเฟส)
- `leaves` ไม่ถูก UPDATE เลยตลอดเฟส — สถานะและ `approval_level` เดิมทุกใบ
- migration รันซ้ำได้ (`publishCurrentVersion` และ `backfill` เป็น idempotent)

---

## 11. ความพร้อมในการ Rollback

| จะย้อน | วิธี | ต้องแตะฐานข้อมูล? |
|---|---|:---:|
| ปิด shadow ทันที | `WORKFLOW_SHADOW_MODE=false` | ไม่ |
| ถอด engine ทั้งชุด | `php artisan migrate:rollback` (down() ลบ 9 ตารางที่สร้างใหม่) | เฉพาะตารางใหม่ |
| ถอดโค้ด | `git revert` ช่วง commit ของเฟสนี้ | ไม่ |

**ความเสี่ยงในการ rollback ต่ำผิดปกติ** เพราะ engine ยังไม่ได้ตัดสินอะไรเลย
ตรรกะเดิมยังเป็น source of truth ทั้งหมด การถอด engine ออกจึงไม่กระทบใบลาใด ๆ

### สถานะ Cutover (05-4)

**ยังไม่ทำ และไม่ควรทำจากหลักฐานชุดนี้**

`config/workflow.php` มีสวิตช์ `engine_is_source_of_truth` เตรียมไว้แล้ว
(ค่าเริ่มต้น `false` และ **ยังไม่มีโค้ดส่วนใดอ่านค่านี้**) การ cutover ต้องมี:

1. shadow ตรง 100% จากการอนุมัติจริงจำนวนที่ตกลงกัน (ไม่ใช่จาก replay)
2. การอนุมัติจริงเกิดขึ้นบ้าง — ตอนนี้ฐานข้อมูลมีใบลา 3 ใบและถูกลบหมด
3. การอนุมัติเมื่ออนุมัติเสร็จ ต้องเขียนผลลง `leave_approvals` ต่อไป (dual-write)
4. คำสั่งอนุมัติ

---

## 12. ความเสี่ยงที่เหลือ

| เรื่อง | รายละเอียด |
|---|---|
| **หลักฐาน shadow บนข้อมูลจริงน้อยมาก** | ใบลา 2 ใบ = 6 จุด ทั้งหมดถูก soft delete · หลักฐานหลักมาจากสถานะสังเคราะห์ · **นี่คือความเสี่ยงอันดับหนึ่งของการ cutover** |
| `parallel` / `conditional` | schema และคอลัมน์พร้อม แต่ **engine ยังไม่ประเมิน** `conditions` และยังไม่รวมผล `completion_policy = all` · Leave v1 ใช้ sequential ล้วนจึงยังไม่กระทบ |
| `timeout` / `escalation` | คอลัมน์พร้อม (`sla_hours`, `on_timeout`, `escalate_to_step_id`) และ `due_at` ถูกตั้งตอนสร้าง task แต่**ยังไม่มี scheduler ที่ทำงานตามกำหนด** |
| `escalate_to_step_id` ไม่มี FK | เป็น self-reference ที่ต้องเพิ่มแยก · ยังไม่ใช้งานจึงยังไม่ผูก constraint |
| `return` ยังไม่มี endpoint | สายรองรับแล้วและมีเทสต์ แต่ไม่มี API ให้เรียก — ของเดิมไม่มีการกระทำนี้ จึงไม่ถือเป็น regression |
| การยกเลิกใบลา (`approveCancellation`) | ยังใช้ `approval_level` + `ApprovalStep` ตรง ๆ ใน `LeaveController` และ **ยังไม่ถูกครอบด้วย shadow** — สายที่สองที่ต้องรวมเข้า engine ก่อน cutover |
| `ApprovalController` | ยังมีตรรกะเดินสายซ้ำอยู่อีกชุด (ถูก route ของ `GovernmentApprovalController` ทับอยู่) · ควรลบตอน cutover |
| `AuditLogger.effective_actor_id` | ตั้งเท่ากับ actor เสมอ (ดูข้อ 8) |
| Saraban | ยังไม่เชื่อม และรอ decision เรื่องสิทธิ์อนุมัติ/ลงนามของ ผอ./รอง ผอ. ที่ค้างจาก LEGACY-04 |

---

## 13. สรุป: **LEGACY-05 = PASS**

- schema ครบ 8 ตารางตามที่กำหนด + comparison log · additive ล้วน
- Leave Workflow v1 แปลงจากสายอนุมัติจริง · pin version ที่ instance
- Shadow mode ทำงานบน endpoint จริงแล้ว · ผลเทียบตรงกัน 100%
- **จับ mismatch ได้จริง 1 กรณีจากข้อมูลจริง และแก้ก่อนที่จะกลายเป็นปัญหา**
- ข้อมูลเดิมไม่สูญหายหรือถูกแก้แม้แต่แถวเดียว · ใบลา Pending เดินต่อได้ครบสาย
- test 320/320 · Pint 359 ไฟล์ · build ผ่าน

> **ข้อสังเกตสำหรับผู้ตรวจ:** ค่าที่แท้จริงของเฟสนี้ไม่ใช่ตาราง 9 ตาราง แต่คือการที่
> shadow mode จับได้ว่าใบลาที่ยกเลิกแล้วจะกลับมาโผล่ในคิวรออนุมัติ — ข้อบกพร่องที่
> ถ้า cutover แบบ big bang จะไปโผล่ที่หน้าจอผู้ใช้จริง
>
> ในทางกลับกัน ผู้ตรวจควรระวังตัวเลข "ตรงกัน 100%" ในข้อ 4 เพราะมาจากใบลาเพียง 2 ใบ
> ที่ถูกลบไปแล้วทั้งคู่ — **ยังไม่ใช่หลักฐานที่พอสำหรับ cutover**

---

## หยุดที่นี่ — รอคำสั่งก่อนเริ่ม LEGACY-06

## Sign-off

| บทบาท | ชื่อ | วันที่ | ผล |
|---|---|---|---|
| ผู้จัดทำ | AI Agent (Claude Code) | 2026-08-14 | PASS (ยังไม่ Cutover) |
| ผู้ตรวจทาน | | | ☐ อนุมัติ ☐ ขอแก้ไข |
