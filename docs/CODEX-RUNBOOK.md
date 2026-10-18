# CODEX RUNBOOK — สั่งงานต่อเนื่อง REBUILD-2569

คู่มือนี้ใช้สั่ง Codex (หรือ Claude Code) ทำงานทีละเฟสแบบต่อเนื่อง
คัดลอก prompt ในกรอบไปวางได้ทันที ทุก prompt อ้างเอกสารใน repo จึงไม่ต้องอธิบายซ้ำ

---

## 0. ตั้งค่าครั้งเดียว

1. **Branch:** งาน rebuild ทั้งหมดทำบน `rebuild/2569` (แตกจาก `plan/rebuild-core-2569`)
   `main` คงเป็นระบบเดิมที่ใช้งานจริงจนถึง R-09 (ADR-R-004) **ห้าม merge `rebuild/2569` เข้า `main` ก่อน R-09**
   ```bash
   git fetch origin && git checkout -b rebuild/2569 origin/plan/rebuild-core-2569 && git push -u origin rebuild/2569
   ```
2. **Codex** อ่าน `AGENTS.md` อัตโนมัติ ให้ตั้ง working branch เป็น `rebuild/2569`
3. **แต่ละเฟส** ให้ Codex เปิด branch ย่อย `rebuild/R-0x` จาก `rebuild/2569` แล้วเปิด PR กลับเข้า `rebuild/2569`
4. **ก่อน R-02:** ทำ R-00 ให้เสร็จ (TASKS.md) โดยเฉพาะ tag `legacy-final`

---

## 1. วงจรการทำงานต่อเฟส

```
[คุณ] วาง prompt เริ่มเฟส ──► [Codex] ทำ task ทีละข้อ, commit, ติ๊ก TASKS.md
        ▲                              │
        │                     ยังไม่ครบ ▼
        │                   [คุณ] วาง prompt "ทำต่อ"
        │                              │
        │                      ครบเฟส ▼
        │               [Codex] เขียน checkpoint report แล้วหยุด
        │                              │
[คุณ] ตรวจรายงาน + PR ◄─────────────────┘
   ├─ ผ่าน → merge PR → วาง prompt เริ่มเฟสถัดไป
   └─ ไม่ผ่าน → วาง prompt "แก้ตามผลตรวจ"
```

---

## 2. Prompt ทั่วไป (ใช้ได้ทุกเฟส)

### 2.1 ทำต่อ
```text
ทำงานต่อในเฟสปัจจุบันตาม CURRENT_PHASE.md
เลือก task แรกใน TASKS.md ของเฟสนี้ที่ยังไม่ติ๊ก ทำให้ครบ Definition of Done (docs/18-DEFINITION-OF-DONE.md)
รัน php artisan test, vendor/bin/pint --test และ npm run build (ถ้าแตะ frontend) ให้ผ่าน
commit ด้วยข้อความขึ้นต้นด้วยรหัสเฟส ติ๊ก TASKS.md อัปเดต TRACEABILITY-MATRIX และ CHANGELOG
ทำต่อ task ถัดไปได้จนกว่าจะครบเฟส หรือเจอเงื่อนไขหยุดใน AGENTS.md
ถ้าครบเฟส ให้เขียน docs/checkpoints/R-xx-REPORT.md แล้วหยุด
```

### 2.2 แก้ตามผลตรวจ
```text
ผลตรวจเฟส <R-xx> ไม่ผ่าน ประเด็นที่ต้องแก้:
1. <ประเด็น>
2. <ประเด็น>
แก้ทุกข้อ เพิ่มเทสต์ที่จับปัญหานี้ได้ รันเทสต์ทั้งหมด อัปเดตรายงาน checkpoint ส่วน "แก้ไขหลังตรวจ" แล้วหยุด
```

### 2.3 ตรวจตัวเองก่อนส่ง
```text
ตรวจงานบน branch นี้เทียบกับ PROJECT_RULES.md ทุกข้อ และรันการตรวจต่อไปนี้ รายงานผลเป็นตาราง:
- grep -rnE "hasRole\(|Gate::before|Spatie\\\\Permission" backend/app
- grep -rnE "'(Super Admin|Director|Deputy Director|Head of Department|ผู้อำนวยการ|รองผู้อำนวยการ|หัวหน้า)" backend/app
- controller action ใดไม่เรียก PermissionEngine หรือ route ใดไม่มี ->permission()
- query รายการใดไม่ผ่าน scopeQuery
- ฟิลด์ sensitive ใดถูกส่งออก API โดยไม่ผ่าน PersonnelPresenter
แก้สิ่งที่พบ แล้วรายงาน
```

### 2.4 เมื่อติดคำถาม
```text
หยุดงานไว้ก่อน สรุปคำถามที่ต้องให้เจ้าของระบบตัดสิน ใส่ใน TASKS.md หัวข้อ "รอตัดสินใจ"
ระบุทางเลือก ผลกระทบ และข้อเสนอของคุณ แล้วหยุด
```

---

## 3. Prompt เริ่มแต่ละเฟส

### R-02 Schema baseline
```text
เริ่มเฟส R-02 Schema baseline บน branch rebuild/R-02 ที่แตกจาก rebuild/2569
อ่าน AGENTS.md, PROJECT_RULES.md, docs/REBUILD-PLAN-2569.md หัวข้อ 4.2–4.3, docs/modules/CORE-01..04 และ TASKS.md หัวข้อ R-02
เป้าหมาย: migrate:fresh --seed ได้ฐานข้อมูลตามแบบใหม่ทั้งหมด ไม่มีตารางและแพ็กเกจที่แผนสั่งตัด
ข้อกำหนดสำคัญ:
- ย้าย migration เดิมไป backend/database/migrations-legacy/ (ไม่ให้ Laravel โหลด) แล้วสร้างชุดใหม่ เรียงตาม R02-2 ถึง R02-7
- ตารางโมดูลเดิมให้สร้างในรูปสุดท้ายของมัน โดยอ่านจาก migration เดิมทุกไฟล์ที่ alter ตารางนั้น และตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
- ถอด spatie ทั้ง composer, config และโค้ด · ลบ Gate::before · route ที่ต้องการสิทธิ์ให้ deny ไว้ก่อนจนถึง R-04 (เขียนเทสต์ยืนยัน)
- ห้ามรันกับฐานข้อมูลจริง ใช้ฐานข้อมูลทดสอบเท่านั้น
ทำ task ตามลำดับ ใช้ prompt "ทำต่อ" ของ runbook เป็นวิธีทำงาน เมื่อครบให้เขียน docs/checkpoints/R-02-REPORT.md
ระบุในรายงาน: รายการตารางใหม่ ตารางที่ตัด โมดูลเดิมส่วนใดที่ยังใช้งานไม่ได้เพราะรอ R-04/R-08 แล้วหยุด
```
**Go/No-Go ที่คุณตรวจ:** `migrate:fresh --seed` ผ่าน · grep ไม่พบ spatie/Gate::before · เทสต์ schema ผ่าน · รายงานบอกชัดว่าอะไรยังพัง

### R-03 Core องค์กรและบุคลากร
```text
เริ่มเฟส R-03 บน branch rebuild/R-03 จาก rebuild/2569 (หลัง merge R-02 แล้ว)
อ่าน docs/modules/CORE-01-ORGANIZATION.md, CORE-02-PERSONNEL.md และ TASKS.md หัวข้อ R-03
สร้าง OrgTree service, Personnel models/presenter, Assignment service และตัวนำเข้า Excel ตามที่ spec กำหนด
ทุก service มีเทสต์ตาม "Tests ขั้นต่ำ" ของ spec ครบ
กรณีคนเดียวหลายตำแหน่ง (ครู แผนกช่างยนต์ + หัวหน้าแผนกช่างยนต์ + รองผอ. ฝ่ายบริหารทรัพยากร + รักษาการ ผอ. ช่วงสั้น) ต้องมี fixture และเทสต์
API ในเฟสนี้ยังไม่ต่อ PermissionEngine ให้คืน 403 ทุก endpoint ยกเว้นเทสต์ระดับ service
เมื่อครบให้เขียน docs/checkpoints/R-03-REPORT.md แล้วหยุด
```
**Go/No-Go:** นำเข้าไฟล์ตัวอย่างจริงของวิทยาลัยได้ (dry-run ไม่มี error) · เทสต์ CORE-01/02 ผ่าน

### R-04 Permission Engine + manifest + เมนู
```text
เริ่มเฟส R-04 บน branch rebuild/R-04 จาก rebuild/2569
อ่าน docs/modules/CORE-04-RBAC-ABAC.md, CORE-05-WORKFLOW.md (ส่วน REBUILD), docs/REBUILD-PLAN-2569.md หัวข้อ 3.3, 3.4 และ 8 ทั้งหมด และ TASKS.md หัวข้อ R-04
สร้าง manifest format, ModuleRegistry, modules:sync, PermissionEngine (can/scopeQuery/effective/explain), กฎกลางทั้ง 5, route macro ->permission(), /api/me/permissions, /api/me/menu และ workflow resolvers
manifest ของ core ต้องประกาศเมนูตรงตาม PLAN 8.2 ข., ช., ซ. ทุกแถว
สร้าง Permission Matrix fixture และ persona tests ตาม PLAN 8.3 ทุกคอลัมน์ รวมเทสต์ว่าสิทธิ์ของ L2 ไม่รั่วไปใช้ใน subtree ของ L3 ของคนเดียวกัน
เพิ่มการตรวจ grep ใน CI ตามข้อ 2.3 ของ runbook
เมื่อครบให้เขียน docs/checkpoints/R-04-REPORT.md พร้อมตารางผล persona tests แล้วหยุด
```
**Go/No-Go:** persona tests ผ่านทุกช่อง · IDOR tests ผ่าน · `/api/me/menu` ตรงผังเมนู · CI grep สะอาด

### R-05 บัญชีและความปลอดภัย
```text
เริ่มเฟส R-05 บน branch rebuild/R-05 จาก rebuild/2569
อ่าน docs/modules/CORE-03-AUTHENTICATION.md และ TASKS.md หัวข้อ R-05
ทำสถานะบัญชี, JML อัตโนมัติ, accounts:reconcile, MFA TOTP, login events, role grants/overrides API พร้อม grant_ceiling และ two_person, access reviews
หน้า frontend ในเฟสนี้: หน้า 1 เข้าสู่ระบบ + MFA และหน้า 7 บัญชีผู้ใช้ ให้ตรงต้นแบบผืนผ้าใบ "ระบบบุคลากร DMTC"
เลือกไลบรารี TOTP แล้วบันทึก ADR-R-0xx ใน DECISION-LOG
เมื่อครบให้เขียน docs/checkpoints/R-05-REPORT.md แล้วหยุด
```
**Go/No-Go:** ทุกเหตุการณ์ JML มีเทสต์ · MFA บังคับถูกกลุ่ม · บัญชี separated ใช้ token เดิมไม่ได้

### R-06 หน้าจอบุคลากร
```text
เริ่มเฟส R-06 บน branch rebuild/R-06 จาก rebuild/2569
อ่าน TASKS.md หัวข้อ R-06, docs/REBUILD-PLAN-2569.md หัวข้อ 8, docs/09-UI-UX-STANDARDS.md
สร้างหน้าตามต้นแบบหน้า 2, 3, 4, 5, 6, 12 ของผืนผ้าใบ "ระบบบุคลากร DMTC" ด้วย Vue 3 + Tailwind ของโปรเจกต์
sidebar สร้างจาก /api/me/menu เท่านั้น ห้าม hard-code รายการเมนู · ปุ่มและแท็บใช้ v-can/useCan
ข้อมูลทุกหน้าต้องมาจาก API ที่ผ่าน scopeQuery และ PersonnelPresenter
การเปิดดูข้อมูลอ่อนไหวต้องผ่าน endpoint reveal พร้อมเหตุผล
เมื่อครบให้เขียน docs/checkpoints/R-06-REPORT.md พร้อมภาพหน้าจอแต่ละ persona แล้วหยุด
```
**Go/No-Go:** UAT กับงานบุคคลผ่าน · ครูที่ได้ชุดสิทธิ์จำกัดเห็นเมนูตามหน้า 12

### R-07 หน้าจอสิทธิ์และ audit
```text
เริ่มเฟส R-07 บน branch rebuild/R-07 จาก rebuild/2569
อ่าน TASKS.md หัวข้อ R-07 และ docs/modules/CORE-04-RBAC-ABAC.md
สร้างหน้าตามต้นแบบหน้า 8, 9, 10, 11 ของผืนผ้าใบ "ระบบบุคลากร DMTC"
หน้า 10 ต้องมี: เลือกขอบเขตต่อช่อง, requires อัตโนมัติพร้อมข้อความอธิบาย, สรุปเป็นภาษาคน, ผลกระทบก่อนบันทึก (จำนวนคนและรายการ), สิทธิ์ high risk ต้องสร้าง permission_change_request
Simulator ใช้ PermissionEngine::explain จริง ไม่จำลองฝั่ง frontend
บันทึกการใช้งานอ่านอย่างเดียว ห้ามมี endpoint แก้หรือลบ
เมื่อครบให้เขียน docs/checkpoints/R-07-REPORT.md แล้วหยุด
```
**Go/No-Go:** ผู้ดูแลปรับสิทธิ์โมดูลได้โดยไม่แก้โค้ด · สิทธิ์เสี่ยงสูงต้องผ่านคนที่สอง

### R-08 ย้ายโมดูลเดิม (สั่งทีละโมดูล)
```text
เริ่มเฟส R-08 โมดูล <leave|attendance|overtime|saraban|notification> บน branch rebuild/R-08-<module> จาก rebuild/2569
อ่าน TASKS.md หัวข้อ R-08, docs/REBUILD-PLAN-2569.md หัวข้อ 4.3 และ 8.2 (กลุ่มของโมดูลนี้) และ 8.4, docs/modules/CORE-05-WORKFLOW.md
1) สร้าง backend/app/Modules/<Module>/manifest.php ให้ features/menus ตรงผังเมนู
2) แทนทุกการตรวจสิทธิ์ในโมดูลด้วย PermissionEngine และ scopeQuery ลบชื่อ role และ permission แบบเก่าทั้งหมด
3) สายอนุมัติใช้ resolver head_of_level ฯลฯ ลบ fallback และโค้ด shadow ของโมดูลนี้
4) ย้าย route ตาม 8.4 พร้อม redirect จาก route เดิม
5) ส่งงานรออนุมัติเข้า /inbox
6) เทสต์ persona ของโมดูล + (ถ้าเป็น leave) dry-run สายอนุมัติ: บุคลากร fixture ทุกคน × ทุกประเภทการลา ต้องได้ผู้อนุมัติครบและถูกฝ่าย
เก็บตรรกะระเบียบการลาไว้ตาม ADR-R-005
เมื่อครบให้เขียน docs/checkpoints/R-08-<module>-REPORT.md แล้วหยุด
```
**Go/No-Go ต่อโมดูล:** grep ไม่พบชื่อ role · เทสต์โมดูลผ่าน · (leave) dry-run ครบ

### R-09 UAT และเปิดใช้
```text
เริ่มเฟส R-09 บน branch rebuild/R-09 จาก rebuild/2569
อ่าน TASKS.md หัวข้อ R-09 และ docs/ACCEPTANCE-CRITERIA.md
เตรียม: สคริปต์ตัดระบบ (export รอบสุดท้าย → backup → migrate:fresh --seed → นำเข้าข้อมูลจริง → modules:sync → ตรวจ persona) พร้อมขั้นตอนย้อนกลับ
เขียน docs/deployment/REBUILD-CUTOVER.md ทีละคำสั่ง
รันชุดตรวจ ACCEPTANCE-CRITERIA ทุกข้อแบบอัตโนมัติเท่าที่ทำได้ ข้อที่ต้องคนตรวจให้ทำเป็น checklist
ห้ามรันคำสั่งใดกับ production เอง เขียนเป็นขั้นตอนให้เจ้าของระบบรัน
เมื่อครบให้เขียน docs/checkpoints/R-09-REPORT.md แล้วหยุด
```
**Go/No-Go:** ACCEPTANCE-CRITERIA ครบทุกข้อ · pentest ไม่มี critical/high · ผู้บริหารลงนาม → merge `rebuild/2569` เข้า `main`

---

## 4. สิ่งที่คุณตรวจทุกครั้งก่อน merge PR ของเฟส

- [ ] อ่าน `docs/checkpoints/R-xx-REPORT.md` — เกณฑ์ Go/No-Go ผ่านจริงไหม
- [ ] CI เขียวทั้ง test, pint, build, grep
- [ ] TASKS.md ของเฟสติ๊กครบ และไม่มีคำถามค้างใน "รอตัดสินใจ"
- [ ] ลองเปิดหน้าจอจริงด้วย persona อย่างน้อย 3 แบบ (บุคลากร, หัวหน้าแผนก, งานบุคคล)
- [ ] อัปเดต `CURRENT_PHASE.md` เป็นเฟสถัดไป (หรือสั่ง agent ให้อัปเดต)
