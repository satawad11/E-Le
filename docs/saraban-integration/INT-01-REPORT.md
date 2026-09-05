# INT-01 — CORE CONTRACT MAPPING

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-01 (ROADMAP บรรทัดที่ 2)
**วันที่:** 2026-09-01
**Branch:** `chore/remove-saraban-module`
**สถานะ:** ✅ ผ่าน

---

## DONE

ตรึงสัญญาระหว่างโมดูลสารบรรณกับระบบกลางให้เป็นสิ่งที่ **บังคับได้ด้วยเทสต์** ไม่ใช่ข้อตกลงในเอกสาร

1. **`INT-01-CORE-CONTRACT.md`** — สัญญา 15 หมวด ระบุ API ที่แน่นอนของทุกความสามารถที่
   สารบรรณต้องเรียกจากระบบเดิม (identity, personnel, organization, position/assignment,
   delegation, authorization, module registry, menu, audit, workflow, notification,
   file/storage, document/PDF, API, time/hash)
2. **`SarabanCoreContractTest`** — ด่านบังคับสัญญาใน CI 5 กรณี
3. **ระบุจุดที่ต้องแก้ไฟล์ของ Core ไว้ล่วงหน้าครบทั้งหมด: มีเพียง 4 จุด** ทุกจุดเป็น additive
4. **บันทึก regression baseline** ก่อนแตะโค้ดใด ๆ

### ผลการทำ Contract Mapping — ข้อค้นพบที่เปลี่ยนแผน

| # | ข้อค้นพบ | ผลต่อแผน |
|---|---|---|
| 1 | **`ApproverResolverService` ไม่ใช่ resolver กลาง** — รับ `ApprovalStep`/`LeaveApproval` และ resolve หัวหน้าฝ่าย/ผอ./HR ตามกติกาของใบลาโดยเฉพาะ | INT-00 เคยระบุให้ REUSE ตัวนี้ · **แก้แล้ว**: สารบรรณต้องใช้ `AssigneeResolverRegistry` + `SubjectAssigneeResolver` แทน ซึ่งเป็นจุดต่อขยายที่ออกแบบไว้ให้โมดูลอื่นใช้จริง |
| 2 | `AssigneeResolverRegistry::RESOLVERS` เป็น `const` array ที่ต้องเพิ่มคลาสด้วยมือ | เป็น 1 ใน 4 จุดที่ต้องแก้ไฟล์ Core (INT-08) — ระบุไว้ในสัญญาแล้ว ไม่ใช่เรื่องที่จะไปเจอตอนเขียนโค้ด |
| 3 | Core พิสูจน์ความสามารถที่สารบรรณพึ่งพาไว้แล้วด้วยเทสต์ที่มีอยู่ (delegation 4 กรณี, pinned version, action log แก้/ลบไม่ได้, ขั้นเดียวกันอนุมัติซ้ำไม่ได้) | **ไม่เขียน characterization test ซ้ำ** — การเขียนซ้ำจะขัดหลัก REUSE เอง · อ้างอิงเป็นหลักฐานใน §3 ของสัญญาแทน |
| 4 | โมดูลสารบรรณรุ่นก่อนเคยมี `saraban_audit_logs` คู่ขนานกับ `audit_logs` กลางจริง | รายการตารางต้องห้ามในเทสต์ไม่ใช่ข้อกังวลเชิงทฤษฎี แต่กันความผิดพลาดที่เคยเกิดขึ้นแล้ว |

---

## CHANGED

**ไม่มีการเปลี่ยนแปลงพฤติกรรมของระบบเดิม** — ไม่แตะ production code, ไม่แตะ config,
ไม่แตะ schema · เพิ่มเฉพาะเทสต์ 1 ไฟล์ และเอกสาร 2 ไฟล์

---

## FILES

เพิ่มใหม่:

```
backend/tests/Feature/SarabanCoreContractTest.php     (เทสต์บังคับสัญญา)
docs/saraban-integration/INT-01-CORE-CONTRACT.md      (ตัวสัญญา)
docs/saraban-integration/INT-01-REPORT.md             (ฉบับนี้)
```

**ไม่ได้แตะไฟล์ใดของระบบเดิมเลย** — ยืนยันด้วย `git status`

> **หมายเหตุ:** working tree มีงานค้างของงานอื่นอยู่ก่อนแล้ว (SEC-028 upload rules,
> `SystemRoleGuard`, `ChangePasswordRequest`, `app/Services/Files/`, เทสต์ frontend ใหม่ ฯลฯ)
> งาน INT-01 **ไม่ได้แตะไฟล์เหล่านั้นเลย** และผลการรันเทสต์ด้านล่างวัดบน working tree
> ที่มีงานค้างเหล่านั้นรวมอยู่ด้วย

---

## MIGRATIONS

ไม่มี — INT-01 ยังไม่แตะ schema (schema ของสารบรรณเริ่มที่ INT-03)

---

## TESTS

**เพิ่มใหม่:** `backend/tests/Feature/SarabanCoreContractTest.php` — 5 กรณี / 10 assertion

| เทสต์ | บังคับอะไร | ผล |
|---|---|---|
| `core_tables_that_saraban_binds_to_exist` | ตารางกลาง 26 ตารางที่สารบรรณเกาะต้องไม่หาย/ไม่เปลี่ยนชื่อ | ✅ |
| `core_services_expose_the_entry_points_saraban_binds_to` | เมธอดของ 12 คลาสกลางที่สารบรรณจะเรียกต้องยังอยู่ | ✅ |
| `saraban_does_not_create_a_second_core_master_table` | ห้ามสร้างทะเบียนหลักซ้ำ 21 ชื่อ | ✅ |
| `saraban_code_does_not_reimplement_core_capabilities` | ห้าม 8 รูปแบบในโค้ดสารบรรณ | ✅ |
| `the_contract_scanner_actually_detects_violations` | พิสูจน์ว่าตัวสแกนจับได้จริง | ✅ |

**เหตุผลที่มีเทสต์ตัวสุดท้าย:** ตอน INT-01 ยังไม่มีไฟล์ของสารบรรณให้สแกน สองเทสต์ก่อนหน้า
จึงผ่านแบบ "ไม่มีอะไรให้ตรวจ" ซึ่งเป็นด่านหลอก — เทสต์ตัวสุดท้ายป้อนโค้ดผิดกติกาเข้าไปตรง ๆ
เพื่อพิสูจน์ว่าตัวสแกนทำงาน **และ** ป้อนโค้ดที่ถูกกติกาเข้าไปเพื่อพิสูจน์ว่าไม่จับผิดคนบริสุทธิ์

**Style:** `vendor/bin/pint --test` ผ่าน

---

## SECURITY

INT-01 ไม่ได้เพิ่มพื้นผิวการโจมตีใหม่ (ไม่มี endpoint, ไม่มี schema, ไม่มี dependency)
แต่ **ตรึงเส้นแบ่งความปลอดภัยไว้ล่วงหน้า** ซึ่งเป็นการป้องกันที่ถูกจังหวะที่สุด:

| กติกาที่บังคับได้แล้ว | ป้องกันอะไร |
|---|---|
| ห้าม `Auth::attempt` / `Hash::make` ในโค้ดสารบรรณ | ทางเข้าระบบชุดที่สองที่ไม่ผ่าน throttle/policy ของ Core |
| ห้ามเขียน `audit_logs` ตรง ๆ / ห้ามตาราง audit ของตัวเอง | ประวัติกระจายสองที่จนตอบไม่ได้ว่าอะไรคือความจริง |
| ห้าม `hasRole()` | สิทธิ์ที่ผูกกับชื่อบทบาทซึ่งเปลี่ยนได้และไม่มี explicit deny |
| ห้าม `department_id` | ขอบเขตการมองเห็นที่ตื้นกว่าโครงสร้างองค์กรจริง |
| ห้าม `Mail::` / อีเมลฝังในโค้ด | ช่องทางส่งออกที่เลี่ยง preference/retry/audit ของ Notification Center |
| ห้ามแก้ทะเบียนหลักของ Core | โมดูลธุรกิจเขียนทับข้อมูล identity/องค์กร |

**ช่องว่างที่ยังเปิดอยู่ (ตามแผน ไม่ใช่ของค้าง):** SEC-GAP-01 ถึง 04 จาก INT-00
(step-up re-auth · malware scan · audit immutability · idempotency) — กำหนดไว้ที่
INT-04, INT-11, INT-15 และสัญญาระบุชัดว่า **ต้องแก้ที่ Core ไม่ใช่ในสารบรรณ**

---

## REGRESSION

| รอบ | ผล |
|---|---|
| **Baseline (ก่อนแตะอะไร)** | ✅ **701 passed · 8 skipped · 0 failed** · 2457 assertions · 87.52s · exit 0 |
| หลังเพิ่ม INT-01 (รอบแรก) | ⚠️ 719 passed · 8 skipped · **2 failed** ที่ `SecureFileAuthorizationTest` |
| **หลังเพิ่ม INT-01 (รอบยืนยัน)** | ✅ **721 passed · 8 skipped · 0 failed** · 2495 assertions · 69.30s · exit 0 |

ขอบเขต regression: ทุกโมดูลเดิม (`leave`, `attendance`, `overtime`, `notification`,
`reporting`) และระบบกลาง — รันเต็มชุด ไม่ได้รันเฉพาะเทสต์ที่เกี่ยวข้อง

### เรื่องที่ต้องบันทึกไว้ — 2 กรณีที่ล้มในรอบแรก

รอบแรกมี 2 กรณีล้มที่ `tests/Feature/SecureFileAuthorizationTest.php`
**ตรวจแล้วว่าไม่ได้เกิดจาก INT-01** และไม่ใช่ regression จริง:

1. INT-01 ไม่ได้แตะไฟล์นั้นเลย (`git status` ยืนยันว่างานนี้เพิ่มเฉพาะ
   `SarabanCoreContractTest.php` และ `docs/saraban-integration/`)
2. ไฟล์ดังกล่าวมี timestamp การแก้ไข **ระหว่างที่ชุดเทสต์กำลังรันอยู่** — เป็นงานค้าง
   ของอีกงานหนึ่งที่ทำอยู่พร้อมกันใน working tree เดียวกัน
3. รันไฟล์นั้นแยกเดี่ยวหลังไฟล์นิ่งแล้ว → ✅ 8 passed
4. รันเต็มชุดซ้ำหลังไฟล์นิ่งแล้ว → ✅ 721 passed, 0 failed

จำนวนเทสต์ที่เพิ่มจาก 701 → 721 มาจากสองทาง: INT-01 เพิ่ม 5 กรณี
และงานค้างของอีกงานหนึ่งเพิ่มอีก 15 กรณีระหว่างช่วงเวลาเดียวกัน

---

## UI-QA

**ไม่มีการเปลี่ยนแปลงหน้าเว็บใน INT-01** จึงไม่มีอะไรให้เทียบ

หน้าเว็บของสารบรรณเริ่มที่ INT-02 (เมนู) และต้องเทียบกับ `UI-COMPATIBILITY-MATRIX.md`
ซึ่งกำหนด baseline ไว้แล้ว

---

## EVIDENCE

| หลักฐาน | ที่มา |
|---|---|
| สัญญาฉบับเต็ม | `docs/saraban-integration/INT-01-CORE-CONTRACT.md` |
| ด่านบังคับสัญญา | `backend/tests/Feature/SarabanCoreContractTest.php` |
| ผลรันด่าน | `php artisan test --filter=SarabanCoreContractTest` → 5 passed (10 assertions), 1.09s |
| ผล Pint | `vendor/bin/pint --test tests/Feature/SarabanCoreContractTest.php` → PASS |
| Baseline regression | `php artisan test` → 701 passed, 8 skipped, 87.52s, exit 0 |
| ขอบเขตไฟล์ที่แตะ | `git status --short` → มีเพียง `backend/tests/Feature/SarabanCoreContractTest.php` และ `docs/saraban-integration/` |
| จุดต่อขยาย workflow | `backend/app/Services/Workflow/Assignee/{AssigneeResolverRegistry,SubjectAssigneeResolver}.php` |
| หลักฐานว่า `ApproverResolverService` ผูกกับใบลา | ลายเซ็นเมธอด `resolveEligibleApproverIds(User $requester, ApprovalStep\|LeaveApproval\|array $step, ...)` |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-01 | ข้อมูลหนังสือของสารบรรณรุ่นเก่าอาจสูญไปแล้ว | **สูง** | **ยังเปิด — ต้องตอบก่อน INT-03** |
| RISK-03 | `personnel_assignments.position` เป็น string → Authority Matrix ไม่แม่น | กลาง | ระบุไว้ในสัญญา §1.4 · แก้ที่ INT-03 |
| RISK-09 **(ใหม่)** | เทสต์สัญญาจับด้วย regex อาจ **จับผิดคนบริสุทธิ์** ในอนาคต (เช่น คำว่า `department_id` ที่ปรากฏในคอมเมนต์อธิบายว่าทำไมถึงห้ามใช้) | ต่ำ | รับได้ — ข้อความ assertion บอกเหตุผลและไฟล์ที่ผิดเสมอ · แก้ได้ด้วยการปรับ pattern พร้อมเหตุผลกำกับ ไม่ใช่ถอดกฎทิ้ง |
| RISK-10 **(ใหม่)** | working tree มีงานค้างของงานอื่นจำนวนมาก — ถ้างานนั้น revert หรือชนกัน baseline ที่บันทึกไว้จะไม่ตรง | ต่ำ | บันทึกไว้ชัดเจนแล้วในหัวข้อ FILES ว่า baseline วัดบน tree ที่มีงานค้างรวมอยู่ |

**ไม่มี blocker สำหรับ INT-02**

---

## NEXT

**→ INT-02 — Module Registry + Permission Namespace**

1. เพิ่ม manifest `saraban` ใน `config/module_registry.php`
   (`code: saraban` · `order: 30` · `dependencies: ['system','notification']` ·
   `route_patterns: ['api/saraban*']`) + `SarabanModuleServiceProvider`
2. ลงทะเบียน permission namespace ตาม SoT §6 ทั้ง 13 กลุ่ม
   (`saraban.document.*`, `incoming`, `number`, `workflow`, `endorse`, `signature`,
   `distribution`, `report`, `policy`, `authority`, `exception`, `operations`, `feature_flags`)
   ผ่าน migration + `PermissionCatalogSeeder` — **ทุกชื่อต้องผ่านกฎ `saraban.<resource>.<action>`**
   ของ `ModuleRegistry::normalize()` มิฉะนั้นแอป boot ไม่ขึ้น
3. เพิ่มเมนูตาม SoT §7 ผ่าน migration (`menus.module = 'saraban'` + `menu_permissions`)
   โดยยังไม่มีหน้าจอปลายทาง → **ต้องตรวจก่อนว่า `MenuIntegrityTest` ยอมรับเมนูที่ยังไม่มี route หรือไม่**
   ถ้าไม่ยอม ให้เลื่อนการเพิ่มเมนูไปพร้อมหน้าจอจริง แทนการผ่อนกฎของเทสต์
4. Regression เต็มชุด + ยืนยันว่าปิดโมดูลแล้ว route/เมนูหายจริง (`ModuleToggleTest`)
