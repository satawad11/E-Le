# INT-09 — WORKFLOW VERSIONING + SIMULATION

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-09 (ROADMAP บรรทัดที่ 10)
**วันที่:** 2026-09-04
**สถานะ:** ✅ ผ่าน Definition of Done

---

## DONE

การจำลองเส้นทางเดินหนังสือ และการยืนยันว่าการกำหนดเวอร์ชันสายงานทำงานจริงกับสารบรรณ

| ข้อบังคับ SoT §12 | สถานะ | บังคับที่ไหน |
|---|---|---|
| Version: DRAFT / PUBLISHED / RETIRED | ✅ **REUSE** — engine เดิมมี `draft`/`active`/`retired` อยู่แล้ว | `workflow_versions.status` |
| instance pin version | ✅ **REUSE** + พิสูจน์ว่าใช้ได้กับสารบรรณจริง | `workflow_instances.workflow_version_id` |
| **การจำลองเป็น READ ONLY** | ✅ | `WorkflowSimulationService` + เทสต์นับแถวก่อน/หลัง 8 ตาราง |
| **ใช้ Production Resolver เดียวกัน** | ✅ | เรียก `AuthorityResolver` ตัวเดียวกับที่ `SarabanAssigneeResolver` ใช้ |
| resolve ตามเวลา | ✅ | พารามิเตอร์ `as_of` ส่งต่อถึง `holdersFor()` |
| ห้ามสร้าง instance/number/notification | ✅ | เทสต์ยืนยันทั้ง 8 ตาราง |
| แสดง unresolved reason | ✅ | `unresolved_reason` รายขั้น + `warnings` รวม |

### สถานะผลการจำลอง 4 ค่า

| สถานะ | ความหมาย |
|---|---|
| `VALID` | ทุกขั้นมีผู้มีอำนาจครบ |
| `VALID_WITH_WARNINGS` | เดินได้แต่มีข้อควรระวัง |
| `UNRESOLVED_AUTHORITY` | **มีขั้นที่ไม่มีผู้ใดมีอำนาจ** — หนังสือจะไปค้างที่ขั้นนั้น |
| `INVALID` | จำลองไม่ได้ (เช่น ไม่พบหน่วยงาน หรือยังไม่มีสายงานที่มีผลบังคับใช้) |

### ประโยชน์จริงของการจำลอง

**รู้ล่วงหน้าว่าหนังสือจะไปค้างที่ขั้นไหน ก่อนที่จะมีหนังสือจริงไปค้าง** —
ถ้าหน่วยงานหนึ่งยังไม่มีผู้ถือสิทธิ์ลงนาม การจำลองจะบอกทันทีพร้อมชื่อสิทธิ์ที่ขาด
แทนที่จะรู้ตอนมีหนังสือด่วนค้างอยู่แล้ว

**ทดลองเวอร์ชันร่างก่อนเผยแพร่** — เลือกเวอร์ชันที่ยังเป็น `draft` มาจำลองได้
เพื่อดูว่าถ้าเผยแพร่แล้วเส้นทางจะเปลี่ยนไปอย่างไร โดยเรื่องจริงยังเดินบนเวอร์ชันเดิม

---

## CHANGED

**ไม่แตะไฟล์ Core เลยในเช็คพอยต์นี้** — การกำหนดเวอร์ชันเป็นความสามารถที่ engine
เดิมมีอยู่แล้วครบ งานของ INT-09 คือ **พิสูจน์ว่าใช้ได้จริงกับสารบรรณ** ไม่ใช่สร้างเพิ่ม

แก้ไฟล์ของสารบรรณเอง: `routes/api.php` (+2), `MenuSeeder` (+1 เมนู),
`router/index.js` (+1 route)

---

## FILES

**เพิ่มใหม่ (4):**

```
backend/app/Services/Saraban/WorkflowSimulationService.php
backend/app/Http/Controllers/Saraban/WorkflowSimulationController.php
backend/tests/Feature/SarabanWorkflowSimulationTest.php
frontend/src/views/saraban/WorkflowSimulationView.vue
```

---

## MIGRATIONS

**ไม่มี** — INT-09 ไม่ต้องเปลี่ยน schema เลย เพราะโครงสร้างการกำหนดเวอร์ชัน
มีอยู่ครบแล้วตั้งแต่ `2026_08_14_200000_create_workflow_engine_foundation`

เป็นตัวอย่างที่ดีของหลัก REUSE — ข้อกำหนดทั้งหมวดของ SoT ผ่านได้โดยไม่เพิ่มตารางใด

---

## TESTS

`SarabanWorkflowSimulationTest` — **9 กรณี / 43 assertions**

| กลุ่ม | ครอบคลุม |
|---|---|
| **READ ONLY** | **จำลอง 3 ครั้งแล้วนับแถวใน 8 ตาราง (instance, task, action, number, sequence, document, notification, audit) — ต้องเท่าเดิมทุกตาราง** |
| resolver เดียวกัน | ผู้ที่จำลองบอกว่ามีอำนาจ = ผู้ที่ทำได้จริง · คนต่างหน่วยงานไม่ถูกเสนอชื่อ |
| unresolved | บอกว่าขั้นไหนไม่มีผู้มีอำนาจ พร้อมชื่อสิทธิ์ที่ขาด · หน่วยงานไม่มีอยู่ = `INVALID` ไม่ใช่ error |
| **versioning** | **เรื่องที่เดินอยู่คง version เดิมเมื่อเผยแพร่เวอร์ชันใหม่** และยังเดินตามผังเดิม · จำลองเวอร์ชันร่างได้โดยเรื่องจริงไม่เปลี่ยน |
| สิทธิ์ | ต้องมี `saraban.workflow.simulate` แยกจากสิทธิ์เดินเรื่องจริง |

---

## SECURITY

| ประเด็น | ผล |
|---|---|
| สิทธิ์แยกต่างหาก | ✅ `saraban.workflow.simulate` — การเห็นว่า "ใครมีอำนาจในขั้นใด" เป็นข้อมูลเชิงโครงสร้างอำนาจขององค์กร ไม่ควรผูกกับสิทธิ์เดินเรื่องธรรมดา |
| ผลข้างเคียง | ✅ ไม่มีเลย — พิสูจน์ด้วยการนับแถว 8 ตาราง |
| ข้อมูลที่เปิดเผย | คืนเฉพาะ **ชื่อและ id** ของผู้มีอำนาจ ไม่มีข้อมูลส่วนบุคคลอื่น |
| ขอบเขต | หน่วยงานที่เลือกจำลองได้มาจาก `options` ซึ่งจำกัดตามอำนาจของผู้ใช้อยู่แล้ว (RISK-20) |

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-08 | 814 passed · 0 failed |
| **หลัง INT-09** | ✅ **823 passed · 8 skipped · 0 failed** · 2983 assertions |
| Frontend | ✅ 70 passed · ESLint ✅ · vue-tsc ✅ |
| Pint | ✅ 538 files |

**ผ่านตั้งแต่รอบแรก**

---

## UI-QA

หน้าจอ "จำลองเส้นทางเดินหนังสือ" ใช้ `AppBadge`, `AppButton`, `AppInput`, `AppSelect`,
`EmptyState` เดิม **ไม่สร้าง component ใหม่**

| องค์ประกอบ | การออกแบบ |
|---|---|
| ผลการจำลอง | แสดงเป็นการ์ดรายขั้น เรียงตามลำดับ พร้อมเลขขั้นในวงกลม |
| ขั้นที่หาผู้มีอำนาจไม่ได้ | `AppBadge variant="danger"` + ข้อความบอกเหตุเป็นภาษาไทย |
| ชื่อสิทธิ์ที่ขั้นต้องการ | แสดงด้วย `font-mono` ขนาดเล็ก เพราะเป็นค่าเชิงเทคนิคที่ผู้ดูแลต้องเอาไปตั้งสิทธิ์ |
| คำเตือนรวม | กล่องสีเหลืองแบบเดียวกับที่ใช้ในทะเบียนรับ (INT-07) เพื่อความสม่ำเสมอ |

หน้าจอบอกชัดว่า **"การจำลองไม่สร้างเรื่อง ไม่ออกเลข และไม่ส่งการแจ้งเตือนใด ๆ"**
เพื่อให้ผู้ใช้กล้าทดลองโดยไม่กลัวว่าจะทำอะไรเสียหาย

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| **READ ONLY** | `test_simulating_leaves_no_trace_in_the_system` — นับ 8 ตาราง ก่อน/หลังจำลอง 3 ครั้ง |
| resolver เดียวกัน | `test_the_simulation_reports_the_same_people_who_may_really_act` |
| pinned version | `test_a_document_already_in_progress_keeps_its_pinned_version` |
| จำลองร่างได้ | `test_a_draft_version_can_be_simulated_before_it_is_published` |
| สิทธิ์แยก | `test_simulation_requires_its_own_permission` |
| เทสต์เช็คพอยต์ | **9 passed (43 assertions)** |
| Regression | **823 passed / 0 failed** · frontend **70 passed** |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-26 **(ใหม่)** | ยังไม่มีหน้าจอ **จัดการเวอร์ชันสายงาน** (สร้างร่าง / เผยแพร่ / เลิกใช้) — ตอนนี้ทำได้ผ่านฐานข้อมูลเท่านั้น | กลาง | ตั้งใจ — SoT วางหน้าจอกำกับดูแลไว้ที่ **INT-16 (Policy Center)** ซึ่งเป็นที่ที่การเผยแพร่ต้องมี versioning + audit + rollback ครบ · การทำหน้าจอเผยแพร่ก่อนมีกลไกเหล่านั้นจะได้ปุ่มที่เปลี่ยนสายงานได้โดยไม่มีร่องรอย |
| RISK-25 (INT-08) | สายงาน v1 มี 2 ขั้น | ต่ำ | ยังเปิด — เพิ่มขั้นได้ด้วยเวอร์ชันใหม่ ซึ่ง INT-09 พิสูจน์แล้วว่าไม่กระทบเรื่องที่เดินอยู่ |
| RISK-21 | ชุดเลขหนังสือส่งต้องตั้งค่าก่อนลงนาม | ต่ำ | ยังเปิด — จะมีหน้าตั้งค่าที่ INT-16 |

**ไม่มี blocker สำหรับ INT-10**

---

## NEXT

**→ INT-10 — PDF Workspace**

เป็นเช็คพอยต์ที่หนักที่สุดฝั่งหน้าเว็บ

1. ตัวแสดง PDF ในเบราว์เซอร์ — **dependency ใหม่ตัวเดียวของทั้งโครงการ**
   ตามที่ `UI-COMPATIBILITY-MATRIX.md` §8 ระบุ (ต้องผ่าน `npm audit --audit-level=high`)
2. `PdfWorkspace` — zoom / เลื่อนหน้า / ลาก / ปรับขนาด · พิกัดเป็น normalized/PDF point
   ให้ตรงกับ `CoordinateConverter` ของ Document Engine เดิม
3. `ReceiveStampOverlay` — วางตราประทับรับจากข้อมูลที่ INT-07 บันทึกไว้แล้ว
4. **Final PDF ต้องเรนเดอร์ที่ backend เสมอ** ด้วย `PdfOverlayRenderer` เดิม —
   ห้ามใช้ภาพจากเบราว์เซอร์ (SoT §14)
