# แผนปรับระบบ E-la เป็นแพลตฟอร์มบุคลากรและสิทธิ์ 5 ระดับ (REBUILD-2569)

> **สถานะ: อนุมัติให้ดำเนินการ (2026-10-18)** · R-01 เสร็จ · คำถาม Q1–Q6 ใช้ข้อเสนอชั่วคราวตาม ADR-R-004/005/008/009 จนกว่าจะยืนยัน
> เอกสารนี้แทนที่ทิศทางเดิมใน `CURRENT_PHASE.md`, `PROJECT_RULES.md`, `docs/ACCESS-GOVERNANCE-2569.md`
> และ `docs/ORG-POSITION-ACCESS-MAPPING.md` เมื่อได้รับอนุมัติ
> ต้นแบบหน้าจอ 12 หน้า: ผืนผ้าใบ "ระบบบุคลากร DMTC" (Claude Design)

---

## 1. สิ่งที่ตัดสินแล้ว (จากเจ้าของระบบ 2026-10-18)

| # | เรื่อง | ผล |
|---|---|---|
| D-1 | ขอบเขตการเปลี่ยน | ทั้ง **เอกสารกติกา** และ **โค้ด** ปรับเป็นแนวทางใหม่ทั้งหมด |
| D-2 | ข้อมูลเดิม | **ล้างข้อมูลทั้งหมดและเริ่มใหม่** (ใบลา ลงเวลา OT สารบรรณ บัญชี บทบาท) |
| D-3 | ลำดับงาน | อนุมัติแผนนี้ก่อน แล้วจึงแก้โค้ดทีละเฟส |
| D-4 | Stack | คง Laravel + Vue 3 + MySQL 8 + Tailwind + Docker (ADR 2026-08-12) — เปลี่ยนแบบ ไม่เปลี่ยนเทคโนโลยี |

### 1.1 ขอบเขตของคำว่า "กฎเดิม" ที่ลบ

**ลบ:** กฎและเงื่อนไขของ *ระบบเดิม* ได้แก่ บทบาท สิทธิ์ สายอนุมัติ ข้อห้ามของเฟส LEGACY/PROD-00
ตรรกะที่ผูกกับชื่อ role, fallback, Super Admin bypass, สิทธิ์รายบุคคลแบบไม่มีวันหมดอายุ

**ไม่ลบ แต่ย้ายเป็นข้อมูลตั้งค่าที่มีรุ่น:** *ระเบียบราชการ* ที่ระบบต้องปฏิบัติตาม
(ระเบียบการลาของข้าราชการ, ระเบียบบริหารสถานศึกษา พ.ศ. 2569, วันหยุดราชการ)
เพราะเป็นกฎหมาย ไม่ใช่เงื่อนไขของระบบ → ดูคำถาม Q1

---

## 2. หลักการใหม่ (แทน `PROJECT_RULES.md`)

1. **บุคลากร ≠ บัญชีผู้ใช้** ข้อมูลบุคลากรเป็นแหล่งความจริงที่ขับเคลื่อนบัญชีและสิทธิ์อัตโนมัติ
2. **สิทธิ์ผูกกับการดำรงตำแหน่ง (assignment) ไม่ผูกกับคน** คนเดียวมีได้หลาย assignment พร้อมกัน
3. **สิทธิ์ = คู่ (permission, scope)** ต่อ assignment แล้ว union กัน ห้ามรวมแบบ "ระดับสูงสุด + scope กว้างสุด"
4. **Deny by default · Deny ชนะเสมอ** ตรวจที่ server ทุกครั้ง การซ่อนเมนูเป็นแค่ UX
5. **Permission มาจาก manifest ของโมดูลเท่านั้น** ผู้ดูแลจัดการได้แค่การจับคู่ ไม่สร้าง permission จากหน้าจอ
6. **กฎกลางบังคับทุกโมดูล** ไม่ใช้อำนาจกับข้อมูลตนเอง · Maker–Checker · ให้สิทธิ์ไม่เกินที่ตนมีและภายในขอบเขตตน
7. **สายอนุมัติเดินตามต้นไม้องค์กรตาม "ประเภทหน่วย"** ไม่นับชั้น ไม่มี fallback ไปหาตามชื่อ role ไม่พบผู้อนุมัติ = เข้าคิวงานบุคคล
8. **ไม่มีบัญชีใดข้ามการตรวจสิทธิ์** ผู้ดูแลระบบไม่เป็นผู้อนุมัติ และไม่เห็นข้อมูลอ่อนไหว
9. **ทุกสิทธิ์พิเศษมีที่มา (เลขคำสั่ง) ผู้อนุมัติ และวันหมดอายุ**
10. **ข้อมูลมีวันที่มีผล ไม่เขียนทับ** ตำแหน่ง สังกัด สถานะ บันทึกเป็นแถวใหม่
11. **ฟิลด์มีชั้นความลับ 4 ชั้น** สาธารณะ / ภายใน / ลับ / อ่อนไหว (PDPA) การเปิดดูชั้นอ่อนไหวต้องระบุเหตุผลและถูกบันทึก
12. **Audit แบบเพิ่มได้อย่างเดียว** แยก log การเข้าถึงข้อมูล การเปลี่ยนสิทธิ์ และการเข้าสู่ระบบ
13. UI ภาษาไทย 100% · วันที่แสดง พ.ศ. เก็บ ค.ศ. · UUID ใน URL · รองรับมือถือ
14. ห้าม hard-code ชื่อหน่วย ตำแหน่ง ประเภทบุคลากร สายอนุมัติ หรือสิทธิ์การลา

---

## 3. แบบจำลองเป้าหมาย

### 3.1 โครงสร้าง 5 ระดับ

| ระดับ | ประเภทหน่วย (`org_unit_types.code`) | ตำแหน่งหัวหน้า | Scope ของหัวหน้า |
|---|---|---|---|
| L1 | `institution` | ผู้อำนวยการ | ทั้งวิทยาลัย |
| L2 | `division` | รองผู้อำนวยการ (มอบหมายได้หลายฝ่าย) | subtree ของฝ่าย |
| L3 | `section` / `department` | หัวหน้างาน / หัวหน้าแผนกวิชา | subtree ของงาน/แผนก |
| L4 | `subsection` / `program` | หัวหน้ากลุ่มงาน / หัวหน้าสาขางาน | subtree ของกลุ่ม/สาขา |
| L5 | — | บุคลากร | ตนเอง |

- ระดับและประเภทหน่วยเป็น **ข้อมูล** ความลึกของต้นไม้ไม่เท่ากันได้ (หน่วยที่ไม่มี L4 ข้ามได้)
- เก็บ `path` (materialized path) เพื่อ query subtree ในคำสั่งเดียว

### 3.2 Assignment 3 ประเภท + รักษาการ

| `kind` | ความหมาย | ได้อะไร |
|---|---|---|
| `primary` | ตำแหน่งหลัก (สังกัดจริง) — มีได้ 1 ต่อคน ณ เวลาหนึ่ง | role ตามระดับ L5 · ใช้สำหรับสายอนุมัติเมื่อยื่นเอง · นับอัตรากำลัง |
| `management` | ตำแหน่งในสายบังคับบัญชา L1–L4 | positional role + bundle ที่แนบกับตำแหน่ง |
| `duty` | หน้าที่มอบหมาย (เจ้าหน้าที่ประจำงาน/ครูช่วยงาน) | functional role + duty attributes |
| `acting` | รักษาการ / ทำการแทน | สิทธิ์ของตำแหน่งต้นทางเฉพาะช่วงคำสั่ง |

ทุกแถวมี `order_number`, `order_date`, `effective_from`, `effective_to`, `ended_by`, `end_reason`

### 3.3 ที่มาของสิทธิ์

```
สิทธิ์ที่มีผล = ⋃ (permission, scope) จากทุก assignment ที่มีผล ณ วันนั้น
              ∪ functional role grants (มีคำสั่ง ผู้อนุมัติ วันหมดอายุ)
              ± overrides (allow/deny, มีวันหมดอายุ, deny ชนะ)
              → ผ่านกฎกลาง → field masking → audit
```

Scope: `own` · `unit` · `subtree` · `all` · `assigned` (รายการที่ได้รับมอบ)
Duty attributes (ABAC): `personnel_type_ids`, `org_unit_ids`, `amount_limit` ฯลฯ ประกาศโดยโมดูล

### 3.4 Manifest ของโมดูล

ทุกโมดูลมี `backend/app/Modules/<Code>/manifest.php` ประกาศ:

```php
return [
  'code' => 'leave', 'name' => 'ระบบการลา', 'version' => '2.0.0',
  'features' => [
    'request' => ['name' => 'ยื่นใบลา', 'actions' => [
        'create' => ['name' => 'ยื่นใบลา', 'scopes' => ['own']],
        'view'   => ['name' => 'ดูใบลา'],
        'cancel' => ['name' => 'ยกเลิกใบลา', 'requires' => ['request.view']],
    ]],
    'approval' => ['name' => 'พิจารณาอนุมัติ', 'actions' => [
        'approve' => ['name' => 'อนุมัติ/ไม่อนุมัติ', 'requires' => ['request.view'],
                      'risk' => 'medium', 'rules' => ['not_own']],
    ]],
    'settings' => ['name' => 'ตั้งค่าประเภทการลา', 'actions' => [
        'manage' => ['name' => 'แก้ไขประเภทและโควตา', 'risk' => 'high', 'scopes' => ['all']],
    ]],
  ],
  'templates' => [ /* ชุดสิทธิ์สำเร็จรูป */ ],
  'defaults'  => ['L1-L4' => ['staff', 'approver'], 'L5' => ['staff']],
  'menus' => [...], 'workflows' => [...], 'notifications' => [...], 'audit_events' => [...],
];
```

คำสั่ง `php artisan modules:sync` อ่าน manifest → สร้าง permission (`leave.approval.approve`), template, เมนู
การเปลี่ยนสิทธิ์ความเสี่ยงสูงต้องมีผู้ดูแลคนที่สองยืนยัน (`permission_change_requests`)

### 3.5 ชั้นความลับของฟิลด์

ตาราง `field_classifications (entity, field, class)` + Resource transformer ที่ปิดบังตามชั้นและสิทธิ์
การเปิดดูชั้นอ่อนไหว: `POST /api/personnel/{uuid}/reveal` พร้อมเหตุผล → บันทึก `sensitive_access_logs` → คืนค่าเฉพาะฟิลด์

### 3.6 บัญชีผู้ใช้

สถานะ `pending → active ⇄ locked / suspended → separated` · Joiner–Mover–Leaver อัตโนมัติจากข้อมูลบุคลากร (job รายวัน + event)
MFA (TOTP + รหัสสำรอง) บังคับ L1–L3, งานบุคคล, ผู้ดูแลระบบ · ทบทวนสิทธิ์พิเศษรายภาคเรียน · Permission Simulator (`explain()`)

---

## 4. รายการ ลบ / แทนที่ / เก็บ

### 4.1 เอกสาร

| การจัดการ | ไฟล์ |
|---|---|
| **ลบ** (ประวัติยังอยู่ใน git) | `CORE_CHECKPOINT_REPORT.md`, `LEGACY-UPGRADE-PROMPT.md`, `docs/LEGACY-*.md`, `docs/ACCESS-GOVERNANCE-2569.md`, `docs/ORG-POSITION-ACCESS-MAPPING.md`, `docs/checkpoints/*` (53 ไฟล์), `docs/existing-system-audit.md`, `docs/audits/*` |
| **เขียนใหม่** | `PROJECT_RULES.md` (ตามหัวข้อ 2), `CURRENT_PHASE.md`, `TASKS.md`, `START-HERE.md`, `AGENTS.md` (hard gates ใหม่), `docs/00-MASTER-BLUEPRINT.md`, `docs/06-RBAC-ABAC.md`, `docs/08-MODULE-STANDARDS.md` + `MODULE-EXTENSION-GUIDE.md` (manifest), `docs/TRACEABILITY-MATRIX.md`, `docs/REQUIREMENTS-MATRIX.md`, `docs/DECISION-LOG.md` (เริ่ม ADR ชุดใหม่ อ้างอิง ADR เดิมเป็น superseded) |
| **ทบทวนแล้วเก็บ** | `03-CODING-STANDARDS`, `04-DATABASE-STANDARDS`, `05-SECURITY-STANDARDS`, `07-WORKFLOW-ENGINE`, `09-UI-UX-STANDARDS`, `10-TESTING-STANDARDS`, `11-API-STANDARDS`, `12-AUDIT-LOGGING`, `13-NOTIFICATION`, `14-DOCUMENT-PDF`, `15-INTEGRATION`, `16-DEPLOYMENT`, `17-BACKUP-DR`, `18-DEFINITION-OF-DONE`, `THAI_GOVERNMENT_LEAVE_ADMIN_GUIDE.md` |

### 4.2 ฐานข้อมูล

ข้อมูลถูกล้างทั้งหมด (D-2) จึง **ยุบ migration เดิม ~130 ไฟล์เป็นชุด baseline ใหม่** แทนการทำ additive

| การจัดการ | ตาราง |
|---|---|
| **ลบ** | `departments`, `department_org_unit_map`, `roles` (เดิม), ตาราง spatie (`permissions`, `roles`, `model_has_*`, `role_has_permissions`), `permission_aliases`, `permission_denies`, `permission_scopes`, `users.custom_permissions`, `user_role_grants`, `org_unit_members`, `org_unit_managers`, `personnel_assignments`, `user_profiles`, `approval_steps`, `workflow_shadow_comparisons`, `menus` (สร้างจาก manifest แทน) |
| **สร้างใหม่ (core)** | `org_unit_types`, `org_units` (+`path`, `level`), `positions`, `position_groups`, `personnel_types`, `personnel`, `personnel_profiles` (เข้ารหัสฟิลด์อ่อนไหว), `personnel_educations`, `personnel_trainings`, `personnel_licenses`, `personnel_documents`, `personnel_change_requests`, `assignments`, `field_classifications`, `sensitive_access_logs` |
| **สร้างใหม่ (สิทธิ์)** | `modules`, `module_features`, `permissions`, `permission_templates`, `template_permissions` (scope), `position_templates`, `role_grants` (functional, duty attributes ใน `attributes` json — ADR-R-012), `permission_overrides`, `permission_change_requests`, `access_reviews`, `access_review_items` |
| **สร้างใหม่ (บัญชี)** | `users` (สถานะ, `separated_at`, `last_login_at`), `mfa_credentials`, `login_events` |
| **เก็บโครงสร้าง ล้างข้อมูล** | ตารางโมดูลลา ลงเวลา OT สารบรรณ แจ้งเตือน document engine workflow engine, `fiscal_years`, `holidays`, `audit_logs` |

### 4.3 โค้ด backend

| การจัดการ | สิ่งที่พบ |
|---|---|
| **ลบ** | `Gate::before` ใน `AppServiceProvider:84` · แพ็กเกจ `spatie/laravel-permission` (11 ไฟล์ที่ใช้) · role-name literals ในตรรกะ 24 จุด · `LeaveWorkflowShadow` และโหมด shadow (ไม่มีข้อมูลเดิมให้เทียบ → engine เป็น source of truth ทันที) · seeders: `RolesAndPermissionsSeeder`, `ModulePermissionSeeder`, `OperationalPermissionSeeder`, `SarabanPermissionSeeder`, `MockDataSeeder`, `MockLeaveSeeder`, `UpdateUserNamesSeeder`, `OrgUnitBackfillSeeder` |
| **เขียนใหม่** | `PermissionEvaluator` → `Authz\PermissionEngine` (`can`, `scopeQuery`, `explain`) · `OrgScopeResolver` → ใช้ `path` · `ApproverResolverService` → เดินต้นไม้ตามประเภทหน่วย ไม่มี fallback · `RoleGrantService`, `AccessReviewService`, `AccountSeparationService` → ตามโมเดลใหม่ · `config/module_registry.php` → manifest รายโมดูล + `ModuleRegistry` · `MenuService` → สร้างจาก manifest + สิทธิ์ |
| **ปรับให้ใช้ core ใหม่** | Leave, Attendance, Overtime, Saraban, Notification, Document Engine, Workflow Engine — เปลี่ยนทุกจุดตรวจสิทธิ์ให้เรียก `PermissionEngine` และทุกสายอนุมัติให้ใช้ resolver ใหม่ |
| **เก็บ** | ตรรกะระเบียบการลา (`LeavePolicyService`, `LeaveBalanceService`, `LeaveEligibilityRuleService`), `ThaiDateService`, document engine, notification adapters (LINE/Telegram/Email) |

### 4.4 โค้ด frontend (ผังเมนูละเอียดดูหัวข้อ 8)

| การจัดการ | หน้า |
|---|---|
| **เขียนใหม่ตามต้นแบบ** | `LoginView` (+MFA) · `UsersManagementView` → บัญชีผู้ใช้ + รายละเอียด + Simulator · `RolesManagementView` → บทบาทและสิทธิ์ · ใหม่: ตั้งค่าสิทธิ์รายโมดูล · `OrgStructureView` + `DepartmentsManagementView` → โครงสร้าง 5 ระดับ · `PersonnelAssignmentsView` + `PositionsManagementView` + `DelegationsManagementView` → แท็บตำแหน่งในประวัติบุคลากร · ใหม่: รายชื่อบุคลากร, ประวัติบุคลากร, คำขอแก้ไขรอตรวจ, บันทึกการใช้งาน |
| **ลบ** | `MenusManagementView` (เมนูมาจาก manifest), `LeaveGovernanceView` (รวมเข้าหน้าตั้งค่าสิทธิ์) |
| **ปรับ** | หน้าของโมดูลลา ลงเวลา OT สารบรรณ: ใช้ directive/composable สิทธิ์ใหม่ (`v-can`, `useCan`) |

---

## 5. เฟสการพัฒนา

แต่ละเฟสทำบน branch แยก มี test และรายงาน checkpoint เมื่อผ่าน Go/No-Go จึงเริ่มเฟสถัดไป

| เฟส | งาน | Go/No-Go | ประมาณ |
|---|---|---|---|
| **R-00 สำรองและปิดระบบเดิม** | backup ฐานข้อมูล + ไฟล์ + export ใบลา/ลงเวลาเป็น PDF/CSV เก็บถาวร · tag `legacy-final` · แจ้งผู้ใช้ | มีไฟล์สำรองที่กู้คืนได้จริง (ทดสอบ restore) และผู้บริหารลงนามรับทราบการล้างข้อมูล | 1 สัปดาห์ |
| **R-01 กติกาใหม่** | ลบ/เขียนเอกสารตามหัวข้อ 4.1 · ADR ชุดใหม่ | เอกสารกติกาครบ อ่านแล้วสั่งงาน agent ได้ | 1 สัปดาห์ |
| **R-02 Schema baseline** | ยุบ migration · ตาราง core/สิทธิ์/บัญชี · ลบ spatie · seeder ข้อมูลตั้งต้น (ประเภทหน่วย ประเภทบุคลากร ระเบียบการลา วันหยุด) | `migrate:fresh --seed` ผ่าน · ไม่มีการอ้างตารางที่ลบ | 2 สัปดาห์ |
| **R-03 Core องค์กรและบุคลากร** | ต้นไม้ 5 ระดับ, ตำแหน่ง, assignment 4 ประเภท, ข้อมูลบุคลากรครบหมวด, field classification, นำเข้าจาก Excel | นำเข้าข้อมูลจริงของวิทยาลัยได้ครบ | 3 สัปดาห์ |
| **R-04 Permission Engine** | manifest + `modules:sync`, engine, scope query, กฎกลาง, overrides, cache + invalidation, `explain()` · **ชุดเทสต์สร้างจาก Permission Matrix** | เทสต์สิทธิ์ผ่านทุกเคสรวมคนหลายตำแหน่งข้ามฝ่าย, รักษาการ, IDOR | 3–4 สัปดาห์ |
| **R-05 บัญชีและความปลอดภัย** | สถานะบัญชี, JML อัตโนมัติ, MFA, login events, ทบทวนสิทธิ์ | ทดสอบ JML ครบทุกเหตุการณ์ · MFA บังคับถูกกลุ่ม | 2 สัปดาห์ |
| **R-06 หน้าจอบุคลากร** | หน้า 1–6 ตามต้นแบบ, คำขอแก้ไข + Maker–Checker, เปิดดูพร้อมเหตุผล, รายงาน | UAT กับงานบุคคลผ่าน | 3 สัปดาห์ |
| **R-07 หน้าจอสิทธิ์และ audit** | หน้า 7–12 ตามต้นแบบ, การยืนยันสองคนสำหรับสิทธิ์เสี่ยงสูง | ผู้ดูแลตั้งค่าโมดูลได้โดยไม่แก้โค้ด | 2–3 สัปดาห์ |
| **R-08 ย้ายโมดูลเดิมเข้า core ใหม่** | ลา → ลงเวลา → OT → สารบรรณ → แจ้งเตือน ทีละโมดูล: manifest, ตรวจสิทธิ์, สายอนุมัติใหม่ | grep ไม่พบชื่อ role ในตรรกะ · เทสต์โมดูลผ่าน · dry-run สายอนุมัติทุกคน × ทุกประเภทลา | 4–5 สัปดาห์ |
| **R-09 UAT และเปิดใช้** | ตั้งค่าข้อมูลจริง, ทดสอบเจาะระบบ, ตรวจ PDPA, อบรม, นำร่อง | ไม่มีปัญหาระดับ critical/high | 3 สัปดาห์ |
| **รวม** | | | **ราว 24–27 สัปดาห์ (5.5–6.5 เดือน)** |

R-06 กับ R-07 ทำคู่ขนานได้หากมีนักพัฒนาสองคน (ลดได้ราว 3 สัปดาห์)

> **เทียบทางเลือก:** การปิดช่องว่างบนระบบเดิมโดยไม่ล้างข้อมูล ประมาณ 2.5–3.5 เดือน
> แนวทางนี้ใช้เวลามากกว่าเพราะต้องย้ายโมดูลเดิมทั้ง 5 โมดูลเข้า core ใหม่ (R-08)
> แต่ได้โครงสร้างที่สะอาด ไม่มีของเก่าค้าง และไม่ต้องดูแลสองระบบสิทธิ์คู่กัน

---

## 6. ความเสี่ยง

| ความเสี่ยง | ผลกระทบ | การป้องกัน |
|---|---|---|
| ข้อมูลใบลาเดิมเป็นหลักฐานราชการ (ประวัติการลาใช้ประกอบการเลื่อนเงินเดือน/ก.พ.7) | สูญหลักฐานถ้าล้างโดยไม่สำรอง | R-00 export ถาวร + ผู้บริหารลงนาม |
| ช่วงเปลี่ยนผ่านไม่มีระบบลา/ลงเวลาใช้ | งานบุคคลกลับไปใช้กระดาษ | กำหนดวันตัดระบบให้ตรงรอบเดือน และให้ R-08 โมดูลลาเสร็จก่อนปิดระบบเดิม หรือเปิดระบบเดิมแบบอ่านอย่างเดียวระหว่างทาง |
| Permission Matrix ยังไม่ได้รับรอง | ต้องแก้ซ้ำ | R-00/R-01 ต้องได้ matrix ที่ผู้บริหารลงนาม |
| ขอบเขตบานปลาย | เกินกำหนด | ห้ามเพิ่มโมดูลใหม่จนจบ R-09 |

---

## 7. คำถามที่ต้องตอบก่อนเริ่ม R-01

| # | คำถาม | ข้อเสนอ |
|---|---|---|
| Q1 | ระเบียบการลา/วันหยุด ให้เก็บเป็นข้อมูลตั้งต้น (ไม่ลบ) ใช่หรือไม่ | เก็บ — เป็นกฎหมาย ไม่ใช่เงื่อนไขระบบ |
| Q2 | ระหว่างพัฒนา ระบบเดิมจะเปิดใช้ต่อหรือปิด | เปิดใช้ต่อจนโมดูลลาใหม่พร้อม แล้วจึงล้างข้อมูล |
| Q3 | ข้อมูลที่ export จาก R-00 ต้องเก็บกี่ปี และใครเป็นผู้ดูแล | ตามระเบียบงานสารบรรณ/งานบุคคลของวิทยาลัย |
| Q4 | ชื่อประเภทหน่วย L4 ที่ใช้จริง (สาขางาน/กลุ่มงาน) และหน่วยใดบ้างที่มี L4 | ยืนยันจากคำสั่งวิทยาลัย |
| Q5 | ผู้ลงนามรับรอง Permission Matrix | ผอ. ตามข้อ 47 ระเบียบบริหารสถานศึกษา 2569 |
| Q6 | MFA ใช้แอป TOTP ทั่วไปได้หรือไม่ | TOTP + รหัสสำรอง |

---

## 8. ผังเมนูทั้งระบบ

เมนูทั้งหมดประกาศใน manifest ของแต่ละโมดูล (หัวข้อ 3.4) และสร้างอัตโนมัติด้วย `modules:sync`
ไม่มีตาราง `menus` ที่แก้จากหน้าจอแล้ว ตารางนี้คือ **ข้อกำหนด** ที่ manifest ต้องตรงกัน

### 8.1 กติกาการแสดงเมนู

1. **เมนูแสดงเมื่อ** ผู้ใช้มี permission ที่กำหนดในคอลัมน์ "ต้องมีสิทธิ์" อย่างน้อยหนึ่ง scope
2. **กลุ่มเมนูแสดงเมื่อ** มีเมนูย่อยที่มองเห็นอย่างน้อยหนึ่งรายการ กลุ่มว่างไม่แสดง
3. **การซ่อนเมนูไม่ใช่การป้องกัน** ทุก route มี guard ฝั่ง frontend และทุก API ตรวจด้วย `PermissionEngine` ซ้ำ
4. **ชื่อเมนูปรับตาม scope** ถ้าผู้ใช้มีสิทธิ์แคบกว่า `all` ให้แสดงชื่อหน่วย เช่น "บุคลากรแผนกวิชาช่างยนต์" (ต้นแบบหน้า 12)
5. **ตัวเลขบนเมนู (badge)** มาจาก endpoint นับงานค้างของโมดูล เฉพาะรายการในขอบเขตสิทธิ์ของผู้ใช้
6. **โมดูลที่ปิดใน registry** ไม่แสดงเมนูใดเลย และ API ของโมดูลตอบ 404
7. **หน้ารายละเอียด** (ประวัติบุคลากร, รายละเอียดบัญชี ฯลฯ) ไม่อยู่ในเมนู เข้าจากรายการเท่านั้น แต่มี guard ของตัวเอง
8. ลำดับกลุ่มตามคอลัมน์ `order` ใน manifest ลำดับในกลุ่มตามตารางด้านล่าง

### 8.2 ผังเมนู

ตัวย่อ scope: **S** = ตนเอง · **U** = หน่วยตน · **T** = หน่วยตนและหน่วยย่อย · **A** = ทั้งวิทยาลัย
คอลัมน์ "ต้นแบบ" อ้างถึงหน้าในผืนผ้าใบ "ระบบบุคลากร DMTC"

#### ก. ส่วนบุคคล (`core`, order 0) — ทุกคนเห็น

| # | เมนู | Route | ต้องมีสิทธิ์ | ต้นแบบ | เฟส |
|---|---|---|---|---|---|
| 1 | หน้าหลัก | `/` | — (ผู้ใช้ที่เข้าสู่ระบบ) | 2 | R-06 |
| 2 | งานรออนุมัติของฉัน | `/inbox` | `*.*.approve` หรือ `*.*.review` อย่างน้อยหนึ่งรายการ | 2 (กล่องงาน) | R-08 |
| 3 | ข้อมูลของฉัน | `/me` | `personnel.profile.view` S | 4 (มุมมองตนเอง) | R-06 |
| 3.1 | └ ประวัติการเข้าถึงข้อมูลของฉัน | `/me/access-history` | `personnel.profile.view` S | 4 (แท็บ) | R-06 |
| 4 | การแจ้งเตือน | `/notifications` | — | — | R-08 |

> **งานรออนุมัติของฉัน** รวมงานรออนุมัติจากทุกโมดูลไว้ที่เดียว แยกกลุ่มตาม "ในฐานะตำแหน่งใด" แทนเมนูอนุมัติแยกรายโมดูลของระบบเดิม (`/approvals`, `/cancel-approvals`, `/saraban/approve`)

#### ข. ข้อมูลบุคลากร (`personnel`, order 10)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | ต้นแบบ | เฟส |
|---|---|---|---|---|---|---|
| 1 | รายชื่อบุคลากร | `/personnel` | `personnel.profile.view` U/T/A | L1 (A) · L2–L4 (T) · งานบุคคล (A) · ผู้ได้รับมอบ (ตามที่มอบ) | 3, 12 | R-06 |
| — | ประวัติบุคลากร *(ไม่อยู่ในเมนู)* | `/personnel/:uuid` | `personnel.profile.view` ตาม scope | ตามรายชื่อ | 4 | R-06 |
| 2 | คำขอแก้ไขรอตรวจ | `/personnel/change-requests` | `personnel.change_request.review` | HR ทะเบียนประวัติ | 5 | R-06 |
| 3 | โครงสร้างองค์กร | `/org` | `org.unit.view` (ดู) · `org.unit.manage` (แก้) | ทุกคนดูได้ · งานบุคคลแก้ได้ | 6 | R-03 |
| 4 | ตำแหน่ง | `/org/positions` | `org.position.manage` | งานบุคคล | — | R-03 |
| 5 | คำสั่งแต่งตั้งและมอบหมาย | `/org/assignments` | `personnel.assignment.manage` | งานบุคคล | 4 (แท็บตำแหน่ง) | R-03 |
| 6 | รายงานบุคลากร | `/personnel/reports` | `personnel.report.view` T/A | L1 · L2 · HR รายงาน | — | R-06 |

#### ค. ระบบการลา (`leave`, order 20)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | เฟส |
|---|---|---|---|---|---|
| 1 | ยื่นใบลา | `/leave/request` | `leave.request.create` S | ทุกคน | R-08 |
| 2 | ใบลาของฉัน | `/leave/my` | `leave.request.view` S | ทุกคน | R-08 |
| 3 | บัตรบันทึกการลา | `/leave/card` | `leave.request.view` S | ทุกคน | R-08 |
| 4 | ปฏิทินการลา | `/leave/calendar` | `leave.request.view` U/T/A | L1–L4 · HR | R-08 |
| 5 | จัดการวันลาและบันทึกแทน | `/leave/manage` | `leave.balance.manage` A | งานบุคคล | R-08 |
| 6 | คัดกรองการเลื่อนเงินเดือน | `/leave/promotion-screening` | `leave.report.promotion` A | งานบุคคล | R-08 |
| 7 | รายงานการลา | `/leave/reports` | `leave.report.view` T/A | L1–L3 · HR · ผู้ตรวจสอบ (อ่าน) | R-08 |
| 8 | ตั้งค่าการลา | `/leave/settings` | `leave.settings.manage` A *(ความเสี่ยงสูง)* | หัวหน้างานบุคคล | R-08 |
| 8.1 | └ ประเภทการลา · ระเบียบการลา · ปีงบประมาณ | แท็บใน `/leave/settings` | เหมือนข้อ 8 | | |

#### ง. ลงเวลาปฏิบัติงาน (`attendance`, order 30)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | เฟส |
|---|---|---|---|---|---|
| 1 | ลงเวลา | `/attendance/check-in` | `attendance.record.create` S | ทุกคน | R-08 |
| 2 | ประวัติลงเวลาของฉัน | `/attendance/my` | `attendance.record.view` S | ทุกคน | R-08 |
| 3 | ขอแก้ไขเวลา | `/attendance/corrections` | `attendance.correction.create` S | ทุกคน | R-08 |
| 4 | ตรวจสอบการลงเวลา | `/attendance/records` | `attendance.record.view` U/T/A | L1–L4 · HR | R-08 |
| 5 | สรุปและรายงานการลงเวลา | `/attendance/reports` | `attendance.report.view` T/A | L1–L3 · HR · ผู้ตรวจสอบ (อ่าน) | R-08 |
| 6 | ตั้งค่าเวลาราชการและปฏิทินทำงาน | `/attendance/settings` | `attendance.settings.manage` A | หัวหน้างานบุคคล | R-08 |

#### จ. ปฏิบัติงานล่วงเวลา (`overtime`, order 40)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | เฟส |
|---|---|---|---|---|---|
| 1 | OT ของฉัน | `/overtime/my` | `overtime.assignment.view` S | ผู้ที่เคยได้รับคำสั่ง OT | R-08 |
| 2 | คำสั่งปฏิบัติงานล่วงเวลา | `/overtime/orders` | `overtime.order.create` U | หัวหน้าหน่วย · ผู้จัดทำคำสั่ง OT · HR (A) | R-08 |
| 3 | รับรองเวลาปฏิบัติงานจริง | `/overtime/certify` | `overtime.actual.certify` U | หัวหน้าหน่วย | R-08 |
| 4 | สรุป OT | `/overtime/reports` | `overtime.report.view` T/A | L1–L3 · HR | R-08 |

#### ฉ. สารบรรณอิเล็กทรอนิกส์ (`saraban`, order 50)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | เฟส |
|---|---|---|---|---|---|
| 1 | หนังสือถึงฉัน | `/saraban/my` | `saraban.document.view` S | ทุกคน | R-08 |
| 2 | สร้างหนังสือ | `/saraban/create` | `saraban.document.create` S | ทุกคน (ร่าง) | R-08 |
| 3 | ทะเบียนหนังสือรับ | `/saraban/incoming` | `saraban.registry.manage` A | เจ้าหน้าที่สารบรรณ | R-08 |
| 4 | ทะเบียนหนังสือส่ง | `/saraban/outgoing` | `saraban.registry.manage` A | เจ้าหน้าที่สารบรรณ | R-08 |
| 5 | หนังสือเวียน | `/saraban/circulars` | `saraban.circular.view` S/A | ทุกคน (ที่ส่งถึง) · สารบรรณ (A) | R-08 |
| 6 | รายงานสารบรรณ | `/saraban/reports` | `saraban.report.view` A | สารบรรณ · ผู้ตรวจสอบ | R-08 |
| 7 | ตั้งค่าสารบรรณ | `/saraban/settings` | `saraban.settings.manage` A | หัวหน้างานบริหารงานทั่วไป | R-08 |
| 7.1 | └ ประเภทหนังสือ · เลขทะเบียน · แม่แบบสายงาน | แท็บใน `/saraban/settings` | เหมือนข้อ 7 | | |

#### ช. บัญชีและสิทธิ์ (`access`, order 90)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | ต้นแบบ | เฟส |
|---|---|---|---|---|---|---|
| 1 | บัญชีผู้ใช้ | `/access/accounts` | `access.account.view` A | ผู้ดูแลระบบ · งานบุคคล (อ่าน) · ผู้ตรวจสอบ (อ่าน) | 7 | R-05 |
| — | รายละเอียดบัญชีและ Simulator *(ไม่อยู่ในเมนู)* | `/access/accounts/:uuid` | `access.account.view` A | ตามข้อ 1 | 8 | R-07 |
| 2 | บทบาทและสิทธิ์ | `/access/roles` | `access.role.view` A | ผู้ดูแลระบบ · ผู้ตรวจสอบ (อ่าน) | 9 | R-07 |
| 3 | ตั้งค่าสิทธิ์รายโมดูล | `/access/modules` | `access.template.manage` A *(ความเสี่ยงสูง)* | ผู้ดูแลระบบ | 10 | R-07 |
| 4 | คำขอเปลี่ยนสิทธิ์รอยืนยัน | `/access/change-requests` | `access.change.approve` A | ผู้ดูแลคนที่สอง · ผอ. | — | R-07 |
| 5 | ทบทวนสิทธิ์รายภาคเรียน | `/access/reviews` | `access.review.confirm` T · `access.review.manage` A | หัวหน้าหน่วย (ยืนยัน) · ผู้ตรวจสอบ (ออกรอบ) · ผอ. (รับรอง) | — | R-05 |
| 6 | บันทึกการใช้งาน | `/access/audit` | `audit.log.view` A | ผู้ตรวจสอบ · ผู้ดูแลระบบ (เฉพาะ log ระบบ) | 11 | R-07 |

#### ซ. ตั้งค่าระบบ (`system`, order 99)

| # | เมนู | Route | ต้องมีสิทธิ์ | ใครเห็นโดยค่าเริ่มต้น | เฟส |
|---|---|---|---|---|---|
| 1 | โมดูลในระบบ | `/system/modules` | `system.module.manage` A | ผู้ดูแลระบบ | R-04 |
| 2 | สายอนุมัติ | `/system/workflows` | `workflow.definition.manage` A *(ความเสี่ยงสูง · ผอ. อนุมัติ)* | ผู้ดูแลระบบ · หัวหน้างานบุคคล (เสนอ) | R-08 |
| 3 | ศูนย์เอกสารและแม่แบบ | `/system/documents` | `document.template.manage` A | ผู้ดูแลระบบ · งานที่เป็นเจ้าของแบบฟอร์ม | R-08 |
| 4 | ช่องทางแจ้งเตือน | `/system/notifications` | `notification.channel.manage` A | ผู้ดูแลระบบ | R-08 |
| 5 | ตั้งค่าทั่วไป | `/system/settings` | `system.setting.manage` A | ผู้ดูแลระบบ | R-04 |

#### เมนูผู้ใช้ (มุมขวาบน) — ทุกคน

ข้อมูลของฉัน · ความปลอดภัยบัญชี (`/me/security`: รหัสผ่าน, MFA, อุปกรณ์และเซสชัน) · ออกจากระบบ

### 8.3 ตัวอย่างเมนูที่แต่ละคนเห็น (ค่าเริ่มต้น)

| กลุ่มเมนู | บุคลากร L5 | หัวหน้า L3/L4 | รองฯ L2 | ผอ. L1 | งานบุคคล | ผู้ดูแลระบบ | ผู้ตรวจสอบ |
|---|---|---|---|---|---|---|---|
| ส่วนบุคคล | ✔ (ไม่มีงานรออนุมัติ) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| ข้อมูลบุคลากร | โครงสร้างองค์กรเท่านั้น | รายชื่อ (T) + โครงสร้าง | รายชื่อ (T) + รายงาน | รายชื่อ (A) + รายงาน | ครบทุกเมนู | — | — |
| ระบบการลา | ข้อ 1–3 | 1–4, 7 | 1–4, 7 | 1–4, 7 | ครบ | ข้อ 1–3 | 7 (อ่าน) |
| ลงเวลา | ข้อ 1–3 | 1–5 | 1–5 | 1–5 | ครบ | ข้อ 1–3 | 5 (อ่าน) |
| OT | ข้อ 1 | 1–4 | 1, 4 | 1, 4 | ครบ | ข้อ 1 | — |
| สารบรรณ | 1, 2, 5 | 1, 2, 5 | 1, 2, 5 | 1, 2, 5 | 1, 2, 5 | 1, 2, 5 | 6 |
| บัญชีและสิทธิ์ | — | 5 (ยืนยันการทบทวน) | 5 | 4, 5 | 1 (อ่าน) | ครบ ยกเว้น 4 ของตนเอง | 1, 2, 5, 6 (อ่าน) |
| ตั้งค่าระบบ | — | — | — | — | 2 (เสนอ) | ครบ | — |

> ผู้ดูแลระบบใช้เมนูส่วนบุคคลของตนได้ตามปกติ แต่ **ไม่เห็นเมนูข้อมูลบุคลากร** และไม่อยู่ในสายอนุมัติใด (หลักการข้อ 8)

### 8.4 แมปเมนูเดิม → เมนูใหม่

| Route เดิม | ใหม่ | หมายเหตุ |
|---|---|---|
| `/` (dashboard) | `/` | เขียนใหม่ตามต้นแบบหน้า 2 |
| `/request`, `/leaves`, `/leave-card`, `/leave-services`, `/team-calendar` | `/leave/request`, `/leave/my`, `/leave/card`, รวมเข้า `/leave/my`, `/leave/calendar` | |
| `/approvals`, `/cancel-approvals` | `/inbox` | รวมทุกโมดูล |
| `/reports` | `/leave/reports` | |
| `/profile`, `/profile/access-history` | `/me`, `/me/access-history` | |
| `/attendance/check-in`, `/attendance/history` | `/attendance/check-in`, `/attendance/my` | |
| `/admin/attendances`, `/admin/attendances/summary` | `/attendance/records`, `/attendance/reports` | |
| `/admin/overtime-orders`, `/admin/ot-summary` | `/overtime/orders`, `/overtime/reports` | แยกเป็นกลุ่มเมนูของตัวเอง |
| `/saraban/*` | `/saraban/*` | จัดกลุ่มตาม 8.2 ฉ · ตั้งค่ารวมเป็นแท็บ |
| `/admin/users` | `/access/accounts` | ต้นแบบหน้า 7–8 |
| `/admin/roles` | `/access/roles` | ต้นแบบหน้า 9 |
| `/admin/org-structure`, `/admin/departments` | `/org` | รวมเป็นหน้าเดียว ต้นแบบหน้า 6 |
| `/admin/positions` | `/org/positions` | |
| `/admin/personnel-assignments`, `/admin/delegations` | `/org/assignments` | รักษาการเป็น assignment `acting` |
| `/admin/leaves-management`, `/admin/promotion-screening` | `/leave/manage`, `/leave/promotion-screening` | |
| `/admin/leave-policies`, `/admin/leave-types`, `/admin/fiscal-years` | `/leave/settings` (แท็บ) | |
| `/admin/attendance-settings`, `/admin/work-calendar` | `/attendance/settings` (แท็บ) | |
| `/admin/workflow` | `/system/workflows` | ใช้ workflow engine อย่างเดียว |
| `/admin/document-templates`, `/admin/document-center/*` | `/system/documents` | |
| `/admin/notifications` | `/system/notifications` | |
| `/admin/settings`, `/admin/export-settings` | `/system/settings` | |
| `/admin/modules` | `/system/modules` | |
| `/admin/menus` | **ลบ** | เมนูมาจาก manifest |
| `/admin/leave-governance` | **ลบ** | ย้ายไป `/access/modules` |
| — | `/personnel`, `/personnel/:uuid`, `/personnel/change-requests`, `/personnel/reports`, `/access/modules`, `/access/change-requests`, `/access/reviews`, `/access/audit`, `/me/security` | **ใหม่** |

### 8.5 ผลต่อเฟส

- **R-04** เพิ่มชนิด `menus` ใน manifest และ endpoint `GET /api/me/menu` ที่คืนเมนูหลังกรองสิทธิ์ ชื่อตาม scope และ badge
- **R-06/R-07** สร้างหน้าตาม 8.2 ข. และ ช.
- **R-08** ย้าย route ตาม 8.4 ทีละโมดูล และเพิ่ม redirect จาก route เดิมไป route ใหม่ 1 ภาคเรียน
- เทสต์: สำหรับทุกแถวใน 8.3 สร้างผู้ใช้ตัวอย่างแล้วตรวจว่า `GET /api/me/menu` คืนเมนูตรงตาราง
