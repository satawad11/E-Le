## ADR-MENU-REORGANIZATION-20260916: จัดระเบียบลำดับและโครงสร้างเมนูแบบ Zero-Impact

- **Status**: Accepted & Implemented
- **Date**: 2026-09-16
- **Context**: ระบบมีเมนูรวมกว่า 40 รายการใน 8 กลุ่มหลัก เดิมลำดับรายการภายในแต่ละกลุ่มถูกเพิ่มตามประวัติการพัฒนา ไม่ได้จัดเรียงตามลำดับความสำคัญและเส้นทางการทำงานจริงของบุคลากร (เช่น งานส่วนตัว, งานอนุมัติของหัวหน้างาน, และงานกำกับดูแลของเจ้าหน้าที่บุคคล/สารบรรณ ปะปนกัน)
- **Decision**: จัดระเบียบลำดับการแสดงผล (sort order) ภายในแต่ละกลุ่มใน `MenuSeeder::TREE` ให้สอดคล้องกับพฤติกรรมการใช้งานจริงของสถานศึกษา โดยไม่เปลี่ยน `code`, `route_name`, `route_path`, และ `permissions` เดิม พร้อมเพิ่ม migration `2026_09_25_100000_reorganize_menus_structure` เพื่อซิงก์ลำดับไปยังระบบที่ติดตั้งไปแล้วทันที
- **เหตุผล**: 
  1. การคง `code` และเส้นทาง route เดิม 100% ทำให้บุ๊กมาร์ก, ลิงก์, และการทำงานของระบบเดิมไม่มีผลกระทบ (Zero-Impact)
  2. การจัดเรียงตามลำดับ: บริการตนเอง -> งานอนุมัติ -> งานกำกับดูแล/รายงาน ทำให้ผู้ใช้ทุกระดับหาเมนูได้รวดเร็วและไม่สับสน
  3. ปฏิบัติตามสัญญาการไม่เกิด Menu Drift ด้วยการอัปเดต migration และ `TREE_FINGERPRINT` ใน `MenuDriftTest`
- **Consequences**: ระบบเมนูคงความเป็น Single Source of Truth ผ่าน `MenuSeeder::TREE`, มี migration รองรับระบบเดิม, และชุดทดสอบ `MenuIntegrityTest` + `MenuDriftTest` ผ่าน 100%

## ADR-APPROVAL-CHAIN-EDITOR-20260912: `approval_steps` คือหน้าจอตั้งสายอนุมัติถาวร ไม่ใช่ของเก่าที่รอถูกแทน

- **Status**: Accepted
- **Date**: 2026-09-12
- **Context**: หลัง cutover (ADR-WORKFLOW-CUTOVER-20260912) มีรายการค้างไว้ว่า "สร้างหน้าจอ
  ออกแบบสายอนุมัติแบบอิงเวอร์ชัน แล้วจึงถอด `approval_steps`" · รายการนี้ตั้งอยู่บนสมมติฐานว่า
  `approval_steps` เป็นหนี้ทางเทคนิคที่เหลือจากระบบเดิม
- **Decision**: ไม่สร้างหน้าจอแยก และไม่ถอด `approval_steps` · ให้ถือว่าเป็นหน้าจอตั้งสายอนุมัติ
  ของระบบอย่างถาวร โดย `publishCurrentVersion()` เป็นสะพานที่แปลงเป็นเวอร์ชันให้อัตโนมัติ
  ทุกครั้งที่ผู้ดูแลกดบันทึก
- **เหตุผล**: สมมติฐานเดิมผิด — ผู้ดูแลได้ประโยชน์ของการทำเวอร์ชันครบอยู่แล้วโดยไม่ต้องเรียนรู้
  อะไรใหม่ · เรื่องที่เดินอยู่ pin เวอร์ชันของตัวเอง การแก้สายจึงไม่กระทบใบที่ยื่นไปแล้ว และ
  ประวัติว่าใบไหนเดินด้วยสายแบบใดถูกเก็บครบ · หน้าจอออกแบบแยกจะเพิ่มมาสามอย่างคือ แก้ราย
  เวอร์ชัน · ตั้งวันมีผลล่วงหน้า · แตกสายตามเงื่อนไข — ไม่มีข้อใดที่มีคนขอ และทุกข้อคือหน้าจอ
  ที่ผู้ดูแลต้องเรียนรู้เพิ่ม
- **ราคาที่ยอมจ่าย**: ความสามารถของ engine ที่ `approval_steps` เขียนไม่ได้ — เงื่อนไขแตกสาย
  และขั้นแบบขนาน — จะเรียกใช้จากหน้าจอไม่ได้ · ยอมรับไว้ก่อน และทบทวนใหม่เมื่อมีกฎราชการจริง
  ที่ต้องแตกสาย ไม่ใช่ทำเผื่อไว้
- **Consequences**: `approval_steps` ต้องถูกดูแลในฐานะหน้าจอชั้นหนึ่ง ไม่ใช่ของที่ปล่อยให้ผุ ·
  เอกสารและคอมเมนต์ที่เรียกมันว่า "ของเดิม" หรือ "read-only" ต้องแก้ให้ตรงกับสถานะนี้

## ADR-WORKFLOW-CUTOVER-20260912: หลักฐานที่ใช้ตัดสินใจ cutover คือเทสต์ parity สองทาง ไม่ใช่จำนวน shadow comparison ที่ตรงกัน

- **Status**: Implemented
- **Date**: 2026-09-12
- **Context**: `docs/checkpoints/LEGACY-05-REPORT.md` ข้อ 11 กำหนดเงื่อนไขก่อน cutover ไว้ว่าผลเทียบใน
  `workflow_shadow_comparisons` ต้องตรงกัน 100% จากการอนุมัติจริงตามจำนวนที่ตกลงกัน · เงื่อนไขนี้ค้างมานาน
  เพราะหลักฐานแบบนั้นสะสมได้จากการใช้งานจริงเท่านั้น สร้างจากการพัฒนาไม่ได้ · เมื่อเปิดสวิตช์จริงกลับพบ
  ข้อบกพร่อง 4 จุดทันที รวมถึงช่องโหว่สิทธิ์ที่ผู้อนุมัติสายขอลาอนุมัติคำขอยกเลิกได้
- **Decision**: ถือว่า **เทสต์ parity ที่รันสถานการณ์เดียวกันทั้งเปิดและปิดสวิตช์** เป็นหลักฐานที่ใช้
  ตัดสินใจ cutover แทนตัวเลขผลเทียบของ shadow แล้วถอดกลไก shadow ทิ้งทั้งชุด
- **เหตุผล**: ผลเทียบของ shadow พิสูจน์ได้น้อยกว่าที่เอกสารสมมติไว้สามข้อ
  1. เทียบเฉพาะสาย "ขอลา" — สาย "ขอยกเลิก" ไม่เคยถูกครอบเลย ช่องโหว่สิทธิ์จึงอยู่ในจุดบอดพอดี
  2. ไม่มีอะไรพึ่งผลของ engine จริง ๆ ความผิดจึงถูกบันทึกไว้เฉย ๆ โดยไม่มีใครเดือดร้อน
  3. ตัว shadow ห่อ try/catch ทุกชั้นโดยเจตนา เพื่อไม่ให้ทำระบบจริงล้ม ความล้มเหลวจึงกลายเป็น
     "ไม่มีผลเทียบ" ไม่ใช่ "ผลเทียบไม่ตรง" — ยิ่งพังยิ่งดูเหมือนผ่าน
  เทสต์ parity ไม่มีจุดบอดทั้งสามข้อนี้ เพราะรันทั้งสองเส้นทางจริงแล้วเทียบผลที่ผู้ใช้และเอกสารเห็น
- **Consequences**: ปิดสวิตช์แล้วถอยกลับไปตรรกะเดิมได้ทันทีเพราะ dual-write ยังครบ และรอบ `false`
  ในเทสต์ parity เป็นหลักฐานว่าทางถอยยังใช้ได้จริง · ตาราง `workflow_shadow_comparisons` ถูกลบ
  เพราะเป็นบันทึกการทดลอง ไม่ใช่หลักฐานราชการของใบลาใด · ราคาที่จ่ายคือไม่มีกลไกเดินคู่ขนานเหลืออยู่
  ถ้าอนาคตต้องย้ายสายงานอื่นเข้า engine ต้องสร้างใหม่ ซึ่งคุ้มกว่าการดูแลกลไกที่พิสูจน์อะไรไม่ได้
- **ไม่ถอด `approval_steps` ในรอบนี้**: มันยังเป็นหน้าจอที่ผู้ดูแลใช้แก้สายอนุมัติจริง และเป็นต้นทางที่
  `publishCurrentVersion()` อ่าน · ถอดได้ต่อเมื่อมีหน้าจอออกแบบสายแบบอิงเวอร์ชันมาแทน

## ADR-MENU-NAMING-20260910: ชื่อเมนูต้องบอก "สิ่งที่จะเจอ" และเปลี่ยนได้เฉพาะที่ `MenuSeeder::TREE`

- **Status**: Implemented
- **Date**: 2026-09-10
- **Context**: แถบเมนูโต 64 รายการโดยแต่ละรอบเช็คพอยต์ตั้งชื่อตามศัพท์ของโมดูลตัวเอง ผลคือ
  ชื่อซ้ำข้ามกลุ่ม ("รายงาน" 2 ที่ · "สรุปผลการปฏิบัติงาน" 2 ที่) ชื่อที่ไม่บอกขอบเขต
  ("ประวัติการลงเวลา" กับ "รายงานการลงเวลา") ศัพท์เทคนิคที่ผู้ใช้ไม่ได้พูด ("ซิงค์",
  "ศูนย์เฝ้าระวังการปฏิบัติการ") และชื่อยาวเกินความกว้างแถบเมนูซึ่งใช้ `whitespace-nowrap`
- **Decision**: ตั้งเกณฑ์การตั้งชื่อเมนูไว้ 6 ข้อ และผูกกับกลไกที่มีอยู่แล้ว
  1. ชื่อเมนูคือ **คำนามของสิ่งที่ผู้ใช้จะเจอ** ไม่ใช่ชื่อฟีเจอร์หรือชื่อโมดูล
  2. **ศัพท์ตามระเบียบราชการมาก่อนเสมอ** — "สารบบเรื่อง" "เกษียนหนังสือ" "ทะเบียนหนังสือรับ"
     ห้ามเปลี่ยนให้ "อ่านง่ายขึ้น" เพราะเจ้าหน้าที่ใช้คำเหล่านี้อยู่แล้ว · คำที่แก้ได้คือคำที่ระบบคิดขึ้นเอง
  3. ตัดคำที่ไม่ให้ข้อมูล ("ระบบ…" "ศูนย์…" "จัดการ…") เมื่อตัดแล้วความหมายไม่หาย
  4. ยาวไม่เกินประมาณ 18 ตัวอักษรไทย
  5. เมนูที่เป็น "ของฉัน" กับ "ของทั้งหน่วยงาน" ต้องแยกออกจากกันได้ด้วยชื่อ ไม่ใช่ด้วยสิทธิ์
  6. ห้ามชื่อซ้ำข้ามกลุ่ม
  และ **จุดแก้ชื่อมีที่เดียวคือ `MenuSeeder::TREE`** ตามด้วย migration พาไปให้ระบบที่ติดตั้งแล้ว —
  หน้าจัดการเมนูแก้ชื่อได้ก็จริงแต่จะถูกเขียนทับเมื่อ seed รอบถัดไป จึงไม่ใช่ช่องทางที่รองรับ
- **Consequences**: การเปลี่ยนชื่อไม่กระทบสิทธิ์หรือเส้นทาง เพราะตัวตนของเมนูคือ `code` ·
  แต่ชื่อถูกเก็บซ้ำอีกสองที่คือ `meta.title`/`meta.parent` ใน `router/index.js` (breadcrumb พิมพ์
  `meta.parent` เป็นข้อความดิบ) และหัวข้อบนหน้าจอ ทั้งสามที่จึงต้องแก้พร้อมกันเสมอ ·
  ยังไม่ทำ single source ของชื่อฝั่งหน้าเว็บในรอบนี้ เพราะ breadcrumb ต้องใช้ได้กับหน้าที่ไม่มีเมนู
  (เช่น หน้ารายละเอียดหนังสือ) ซึ่งไม่มีรายการใน `TREE`
- **ไม่เปลี่ยนชื่อกลุ่มบนสุด**: กลุ่ม "รายงาน" เคยพิจารณาเปลี่ยนเป็น "ศูนย์รายงาน" แต่จะซ้อนกับ
  เมนูลูก "ศูนย์รายงานกลาง" · เมื่อ `saraban.reports` เปลี่ยนเป็น "รายงานสารบรรณ" แล้ว ชื่อซ้ำหมดไป
  โดยไม่ต้องแก้ `meta.parent` ของทุกเส้นทางในกลุ่ม

## ADR-WORD-20260909: ไฟล์ Word ของใบลายึด "ความสอดคล้องของสัญญา" ไม่ใช่รูปแบบใดรูปแบบหนึ่ง

- **Status**: Implemented (LS-07)
- **Date**: 2026-09-09
- **Context**: หน้าจอตั้งชื่อไฟล์เป็น `.docx` และประกาศ MIME ของ DOCX แต่เซิร์ฟเวอร์ส่ง Word แบบ HTML
  ผู้ใช้จึงได้ไฟล์ที่นามสกุลไม่ตรงกับเนื้อใน และโปรแกรม Word เตือนทุกครั้งที่เปิด
  ทางเลือกคือ (ก) ให้สองฝั่งตรงกันที่ `.doc` หรือ (ข) ทำ DOCX จริงด้วย PhpWord
- **Decision**: เลือก (ก) และทำให้ผิดซ้ำไม่ได้ — เซิร์ฟเวอร์เป็นผู้ประกาศทั้งชื่อไฟล์และชนิดไฟล์
  ส่วนหน้าจออ่านจาก `Content-Disposition` / `Content-Type` แทนการเดาเอง
- **เหตุผลที่ยังไม่ทำ DOCX จริง**: `LeaveWordService` ที่มีอยู่สร้างเอกสารขึ้นใหม่เอง ไม่ได้ใช้
  เวอร์ชันแบบฟอร์มที่ใบลา pin ไว้ ไม่รองรับช่องเฉพาะของประเภทการลา และไม่แยกใบยกเลิก
  เอกสารที่ได้จึงเป็น "คนละฉบับ" กับ PDF ที่เป็นเอกสารจริง — แย่กว่าไฟล์ `.doc` ที่ใช้เนื้อหาชุดเดียวกับ PDF
  จึงถอดสาขาที่จะเปิดใช้ PhpWord อัตโนมัติออกจาก controller เพื่อไม่ให้พฤติกรรมเปลี่ยนเงียบ ๆ
  หากมีการติดตั้งไลบรารีนี้ในอนาคตด้วยเหตุผลอื่น
- **Consequences**: เทสต์ LS-07 ตรึง "ชนิดที่ประกาศ = นามสกุล = เนื้อไฟล์" ไม่ได้ตรึงว่าต้องเป็น `.doc`
  ตลอดไป การเปลี่ยนไปออก DOCX จริงในภายหลังจึงทำได้โดยไม่ต้องแก้หน้าจอ

## ADR-POLICY-VERSION-20260909: แก้นโยบายที่ใบลาอ้างอยู่ = ออกฉบับใหม่ ไม่ใช่เขียนทับ

- **Status**: Implemented (LS-01, LS-02)
- **Date**: 2026-09-09
- **Context**: `PROJECT_RULES` ข้อ 7 กำหนดว่า Policy ต้อง versioned + effective dated แต่
  `LeavePolicyController::update()` ใช้ `$policy->update()` เสมอ การแก้เพดานวันลาหรือรอบการนับ
  ครั้งเดียวจึงย้อนไปเปลี่ยนหลักเกณฑ์ที่ใบลาเก่าถูกอนุมัติภายใต้ และตรวจย้อนไม่ได้อีก
- **Decision**:
  1. นับ "ผู้อ้างถึง" เฉพาะ **ใบลา** ซึ่งเป็นเอกสารหลักฐาน — ไม่นับ `leave_balances`
     เพราะเป็นยอดปัจจุบันที่ระบบซิงก์ตามนโยบายอยู่แล้วโดยการออกแบบ
  2. มีใบลาอ้างถึง → ต้องระบุวันมีผลใหม่ที่หลังกว่าเดิม ระบบปิดฉบับเดิมด้วย `effective_to`
     แล้วออกฉบับใหม่ (ฉบับเดิมไม่ถูกแก้ค่าใด) · ถ้าไม่ระบุ ปฏิเสธพร้อมบอกวิธีแก้
  3. การซิงก์ยอดวันลาทำเฉพาะกับนโยบายที่ "ใช้บังคับอยู่จริง ณ วันนี้" ตามกติกาเดียวกับ
     `LeavePolicyService::resolve()` — บันทึกนโยบายอนาคต/หมดอายุ/ปิดใช้งานไว้ได้โดยไม่กระทบยอดปัจจุบัน
- **Consequences**: ไม่มี migration (ใช้คอลัมน์ effective dating ที่มีอยู่แล้ว) ·
  ผู้ดูแลจะเห็นข้อความอธิบายเมื่อถูกปฏิเสธ และเห็นว่าการบันทึกมีผลกับยอดปัจจุบันหรือไม่

## ADR-DESIGNER-20260908: ยกระดับหน้าออกแบบแบบฟอร์มโดยไม่แตะ schema

- **Status**: Implemented — ระยะ 1–3 ของ `docs/VISUAL-PDF-TEMPLATE-DESIGNER-PLAN.md`
- **Date**: 2026-09-08
- **Context**: ระบบอยู่ `PRODUCTION PHASE` ซึ่ง `PROJECT_RULES.md` ห้ามแก้ schema เว้นแต่เป็น Critical Bug Fix
  ขณะที่แผนเดิมของงานนี้ระบุตาราง/คอลัมน์ใหม่หลายรายการ
- **Decision**:
  1. **ขนาดหน้ากระดาษอ่านจากไฟล์ต้นฉบับโดยตรง** (`TemplateGeometry` + pdf.js) แทนการเพิ่มคอลัมน์
     `page_width`/`page_height` ที่แผนเสนอไว้ — ไฟล์เป็นแหล่งความจริงอยู่แล้ว และการเก็บซ้ำ
     เปิดช่องให้ค่าคลาดจากไฟล์จริงเมื่อมีการอัปโหลดทับ
  2. **คุณสมบัติใหม่ของฟิลด์ทั้งหมดอยู่ใน `options` (JSON) ที่มีอยู่แล้ว** — ขีดเส้นใต้ ระยะห่างตัวอักษร
     การหมุน เครื่องหมายติ๊ก ค่าที่ถือว่าติ๊ก และเงื่อนไขแสดง/ซ่อน โดยจำกัดคีย์และค่าด้วย whitelist
     (`DocumentFieldMapping::OPTION_KEYS` / `CONDITION_OPERATORS`) ไม่รับนิพจน์อิสระจากหน้าจอ
  3. **ตารางสถิติการลาใช้ฟิลด์รายช่องที่แบนแล้ว** (`stats.leave_type_<id>.<ช่อง>`) แทนการสร้าง
     "บล็อกตารางซ้ำ" — แบบฟอร์มราชการมีแถวคงที่ตามประเภทการลาที่ตั้งไว้ในระบบ การประกาศ
     เป็นฟิลด์จึงวางได้ตรงและตรวจสอบง่ายกว่า โดยอ้างด้วย id เพื่อไม่ให้การเปลี่ยนชื่อประเภททำแบบฟอร์มพัง
     บล็อกตารางซ้ำแบบทั่วไปยังไม่ทำจนกว่าจะมีแบบฟอร์มที่มีจำนวนแถวไม่คงที่จริง
  4. **การเปิดใช้ engine แยกสองชั้น** — `DOCUMENT_ENGINE_ENABLED` ใน `.env` ยังเป็นสวิตช์ตัดตอน
     ที่ไม่ขึ้นกับฐานข้อมูล ส่วนรายการประเภทการลาที่เปิดย้ายมาอยู่ในตาราง `settings` พร้อม audit
     เพื่อให้ผู้ดูแลทยอยเปิดได้เองและตรวจย้อนได้ว่าใครเปิดเมื่อใด
  5. **`BARCODE` ถูกถอดจากตัวเลือก** แทนการปล่อยให้เลือกแล้วพิมพ์เป็นข้อความ — ดู `docs/KNOWN-LIMITATIONS.md` D-01
- **Consequences**: ไม่มี migration ในระยะ 1–3 จึงไม่กระทบข้อห้ามของ PRODUCTION PHASE
  ส่วนงานที่ต้องมี schema จริง (ตาราง `document_entity_types`, gateway กลางของโมดูลอื่น)
  ถูกกันไว้ที่ DESIGNER-06 และต้องขออนุมัติเปิด phase ก่อนเริ่ม

## ADR-API-SURFACE-20260907: ตรวจ API aliases และเชื่อมหน้าจอกับความสามารถที่มีอยู่

- Status: Implemented ตามคำสั่งแก้ Quality gate ทั้ง 2 หลัง Leave audit
- ใช้ canonical UI consumer สำหรับ `/api/v1/saraban/*` ได้เมื่อมี route คู่เดิมที่ action และ HTTP methods ตรงกัน คง middleware เดิมครบและมี idempotency; การไม่มี canonical consumer ยังทำให้ test ล้มเหลว
- อ่าน base URL และ suffix จาก attendance composable จริง ไม่ยกเว้นทั้งกลุ่ม API; health, QR และ OpenAPI ระบุเหตุผลการเรียกจากภายนอกเป็นรายเส้นทาง
- API ที่เป็นความสามารถของผู้ใช้ต้องมีหน้าจอใช้งานร่วมกับ AppModal/AppButton และ permission convention เดิม; การยืนยันซ้ำใช้ `/auth/confirm-password` เมื่อ server ตอบ 423 โดย server ยังคงเป็นผู้ตัดสินสิทธิ์และ scope
- Pin web-push ตาม lock เดิมเป็น 11.0.0 ไม่เปลี่ยนรุ่น dependency หรือ schema เพื่อปิด gate
- การสร้างสำเนาปกปิดยึดฉบับที่โหลดให้ตรวจจริงและ snapshot payload ที่ยืนยัน คำขอ preview เก่าหรือสำเนาที่เปิดอ่านไม่ทำให้แก้พื้นที่ของต้นฉบับได้
- หลักฐานผลตรวจ: `docs/checkpoints/API-SURFACE-FIX-2026-09-07.md`

## ADR-LEAVE-20260905: ผลอนุมัติร่วมและการคงสภาพร่าง/เอกสารที่ยื่นแล้ว

- Status: Implemented ตามคำสั่งแก้ข้อค้นพบทั้ง 4 รายการ 2026-09-05
- ใช้ LeaveService::completeApproval ร่วมกันระหว่างการอนุมัติตามสายและการบันทึกแทน ให้ตัดยอด, seal, เปลี่ยนสถานะ และ sync attendance ใน transaction ของผู้เรียกเดียวกัน รายการบันทึกแทนเก็บ audit ผู้บันทึกโดยไม่สร้างขั้นอนุมัติหรือลายเซ็นสมมุติ
- วางข้อห้ามอ่านร่างผู้อื่นก่อนสิทธิ์ผู้ดูแลใน LeaveModuleServiceProvider ไม่ให้ Core import โมดูลธุรกิจ
- generic update ใช้ได้เฉพาะร่างของตนและคำนวณผ่านบริการร่าง ใบที่ยื่นแล้วใช้ยกเลิก/เพิกถอนเพื่อรักษาประวัติ
- หนึ่งใบมีรอบยอดเดียว จึงปฏิเสธการข้ามรอบ fiscal/calendar year และให้แยกใบ แทนการเพิ่ม schema แบ่งยอดในรอบนี้
- ไม่ cutover workflow หรือแก้ historical records; หลักฐานและข้อจำกัดอยู่ที่ `docs/checkpoints/LEAVE-MODULE-FIX-2026-09-05.md`

## ADR-ROLE-01: ตัวระบุบทบาทระบบต้องเป็นคีย์ ไม่ใช่ชื่อที่แสดง

- **Status**: Accepted
- **Date**: 2026-09-02
- **Context**:
  `PROJECT_RULES.md` ห้าม hard-code ชื่อ role เพื่อใช้เป็น identity แต่ `RoleController`
  ยังกันบทบาทหลักด้วยรายชื่อที่เขียนตายไว้ `['SuperAdmin', 'Director', 'Employee']`
  ขณะที่บทบาทจริงในฐานข้อมูลชื่อ `Super Admin` (มีเว้นวรรค) — **ด่านนี้จึงไม่เคยทำงานเลย**
  ผู้ดูแลเปลี่ยนชื่อหรือลบบทบาทผู้ดูแลระบบสูงสุดทิ้งได้ และเมื่อทำแล้วจะไม่มีใครในระบบ
  แก้กลับได้อีก นอกจากเข้าไปแก้ฐานข้อมูลโดยตรง

  ระบบอยู่ใน `PROD-00 Architecture Freeze` ซึ่งห้ามแก้ schema เว้นแต่เป็น Critical Bug Fix
- **Decision**:
  1. เพิ่มคอลัมน์ `roles.system_key` (varchar 64, nullable, unique) และ `roles.is_system`
     เป็น migration แบบ **additive** — ไม่แตะคอลัมน์เดิม ไม่ลบข้อมูล โค้ดเดิมทุกบรรทัด
     ยังทำงานได้เหมือนเดิม จึงถือเป็น backward-compatible
  2. จัดคู่แถวเดิมเข้ากับคีย์ด้วยชื่อที่เคยใช้ **ครั้งเดียว** ตอน migrate/seed
     (`App\Support\SystemRoles::backfillExistingRoles()`) หลังจากนั้นระบบอ้างถึงบทบาท
     ด้วย `system_key` เท่านั้น ชื่อที่แสดงกลับไปเป็น "ข้อมูล" ที่ผู้ดูแลแก้ได้ตามเดิม
  3. `system_key` ไม่อยู่ใน fillable และไม่มี endpoint ใดเปิดให้แก้
  4. เพิ่ม `App\Services\SystemRoleGuard` กันสามทางที่ทำให้ระบบเหลือศูนย์ผู้ดูแล:
     เปลี่ยนบทบาทของผู้ดูแลคนสุดท้าย · ลบบัญชีนั้น · ระงับการใช้งานบัญชีนั้น
- **Consequences**:
  - เข้าข่าย Critical Bug Fix ตาม `PROJECT_RULES.md` ข้อ PROD PHASE — บันทึกเหตุผลไว้ที่นี่
  - บทบาทที่ผู้ดูแลสร้างเองยังเปลี่ยนชื่อและลบได้ตามปกติ ด่านนี้ครอบเฉพาะบทบาทที่มีคีย์
  - ระบบที่ไม่มี `Super Admin` เลย (ยังไม่ได้ seed) `SystemRoleGuard` จะไม่ขวางอะไร
    เพราะไม่มีข้อมูลพอจะตัดสิน — ไม่ทำให้การติดตั้งครั้งแรกล็อกตัวเอง
  - `RoleController` ผูก type-hint กับ `App\Models\Role` แทน `Spatie\Permission\Models\Role`
    เพื่อให้ route model binding คืนคลาสที่มีเมธอด `isSystemRole()` · ไม่ได้เปลี่ยน
    `config/permission.php` จึงไม่กระทบการ resolve role ที่อื่นในระบบ

## ADR-AUTHZ-02: ไฟล์บน private storage ตัดสินสิทธิ์ผ่าน PermissionEvaluator ไม่ใช่ Gate

- **Status**: Accepted
- **Date**: 2026-09-02
- **Context**:
  `SecureFileAccessService` เดิมถาม Spatie ตรง ๆ ด้วย `hasAnyPermission()` จึงข้าม
  explicit deny · ไม่รู้จักชื่อ permission ที่เทียบเท่ากัน · และไม่มี organization scope

  ทางเลือกตามธรรมชาติคือเปลี่ยนไปใช้ `$user->can()` แต่มีข้อจำกัดที่ตรวจพบระหว่างทำ:
  **Spatie ลงทะเบียน `Gate::before` ของตัวเองไว้ด้วย** และ package provider boot ก่อน
  `AppServiceProvider` เสมอ เมื่อผู้ใช้ "ถือ" สิทธิ์นั้นอยู่จริง before ของ Spatie จะคืน
  `true` ก่อนที่ before ของเรา (ซึ่งตรวจ explicit deny) จะได้ทำงาน — เทสต์
  `PermissionEvaluatorTest::test_explicit_deny_beats_an_allow` ตรึงข้อจำกัดนี้ไว้แล้ว
  โดยตรวจผ่าน evaluator ไม่ใช่ผ่าน `can()`
- **Decision**:
  เรียก `PermissionEvaluator::allows()` ตรง ๆ ในเส้นทางการเข้าถึงไฟล์ และถามขอบเขต
  หน่วยงานจาก `OrgScopeResolver::canSeeOrgUnit()` · การเข้าถึงไฟล์ของใบลายังใช้
  `LeavePolicy` ผ่าน `Gate` ตามเดิม เพราะเป็นการตัดสินระดับ resource ไม่ใช่ระดับ ability
- **Consequences**:
  - explicit deny มีผลจริงกับการเข้าถึงไฟล์แล้ว
  - **ยังเหลือช่องว่างทั้งระบบ**: ทุก endpoint ที่ใช้ `$user->can()` ยังข้าม explicit deny
    เมื่อผู้ใช้ถือสิทธิ์นั้นอยู่ · การปิดช่องนี้ทั้งระบบต้องตั้ง
    `config('permission.register_permission_check_method') = false` แล้วให้ `Gate::before`
    ของเราเป็นผู้ตัดสินเพียงผู้เดียว ซึ่งเปลี่ยนพฤติกรรมการตรวจสิทธิ์ของทุก endpoint
    พร้อมกัน · **ยังไม่ทำในรอบนี้ ต้องขออนุมัติเจ้าของระบบก่อน** (ดูรายงานปิดงาน)

## ADR-USER-01: Additive Personnel Entity Separation & 1:1 Identity Binding

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  The `users` table previously served dual roles (Authentication/Identity Domain and Staff/Personnel Domain).
  68 Foreign Keys across 38 tables directly reference `users.id`.
- **Decision**:
  1. Separate Staff Identity into a new `personnel` table with UUID and 1:1 binding (`personnel.user_id` -> `users.id`).
  2. Retain `users.id` as the authoritative security/authentication identifier.
  3. Keep PII (national ID card number, address, signature) in `user_profiles` without redundant duplication.
  4. Perform idempotent backfill during additive migration.
  5. Do NOT change foreign keys in Leave, Attendance, OT, Saraban, Workflow, or Audit modules in this foundation phase.
- **Consequences**:
  - Full backward compatibility maintained.
  - Zero breaking changes to existing APIs and tokens.
  - Foundation ready for USER-02 (Dual-Write & Observer synchronization).

## ADR-USER-02: Field Ownership Matrix & Loop-Safe Dual-Write Synchronization

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Separation of `users`, `personnel`, `user_profiles`, and `personnel_assignments` creates risk of data drift if mutations in one domain do not reflect in others.
  Conversely, naive bi-directional observers risk infinite event loops.
- **Decision**:
  1. Define a strict Field Ownership Matrix (users = auth, user_profiles = PII, personnel = staff identity, personnel_assignments = org/position).
  2. Implement `PersonnelSyncService` with thread-safe recursion guard `withoutSyncRecursion(string $key, callable $callback)` preventing circular observer triggers.
  3. Wrap all cross-table synchronizations in database transactions.
  4. Ensure effective-dating history is preserved when assignments change (close previous with yesterday's date, open new with today's date).
  5. Refactor model lifecycle hooks into dedicated Observers registered in `AppServiceProvider`.
- **Consequences**:
  - Zero data drift across all 4 entity tables.
  - Safe against infinite event recursion and partial database updates.
  - Ready for USER-03 (Service refactoring to read Personnel domain directly).

## ADR-USER-03: Core Services Refactoring to Personnel Domain

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Core services previously read organizational units, personnel types, positions, and staff names directly from legacy fields in `users` (`department_id`, `org_unit_id`, `personnel_type_id`, `name`).
- **Decision**:
  1. Refactor `OrgScopeResolver`, `LeavePolicyService`, `TimeService`, and `ReportingDataService` to query from `Personnel` and `currentAssignment` (Personnel Domain) as the primary authoritative source.
  2. Implement safe fallback to legacy `users` fields and `department_org_unit_map` when domain assignments are not yet present.
  3. Keep `users.id` as the security identity without altering authorization boundaries or IDOR limits.
- **Consequences**:
  - Direct reliance on legacy columns is removed from core service business logic.
  - Full backward compatibility and zero regression maintained.
  - Ready for USER-04 (Frontend & API Layer Alignment).

## ADR-USER-04: Non-Breaking API & Frontend Alignment with Personnel Domain

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Frontend and API clients require Personnel Domain data (UUID, Personnel Code, Status, Position, OrgUnit) without breaking existing consumers that expect legacy keys (`id`, `name`, `department_id`, `roles`, `profile`).
- **Decision**:
  1. Add additive accessors and appends (`personnel_uuid`, `personnel_code`, `personnel_status`, `position`) on the `User` model.
  2. Eager load `personnel`, `currentAssignment`, `orgUnit`, and `personnelType` in core API endpoints (`/api/login`, `/api/user`, `/api/admin/users`, `/api/users/list`).
  3. Extend Pinia `useAuthStore` with safe getter accessors that fallback automatically to legacy fields if domain attributes are absent.
  4. Update `UsersManagementView.vue` and `UserProfileView.vue` to display domain attributes.
- **Consequences**:
  - Zero breaking changes to API contracts or UI components.
  - Seamless consumption of Personnel Domain data across the entire application.
  - Ready for USER-05 (Deprecation & Verification).

## ADR-USER-05: Authorization Technical Debt Cleanup & Zero Leakage Verification

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Scattered hardcoded role checks in frontend views (`MyLeavesView.vue`, `DashboardView.vue`) and legacy references in approval delegation queries created security debt and brittle role assumptions.
- **Decision**:
  1. Replace all hardcoded role names with permission-based checks and ABAC getters via `useAuthStore`.
  2. Eliminate remaining `role_id` column references in `ApprovalController.php` in favor of Spatie `roles` relation queries.
  3. Support permission-based approver querying in `LeaveRequestNotificationService`.
  4. Perform comprehensive non-regression verification across all core domains.
- **Consequences**:
  - Staff positions and organizational ranks are fully decoupled from system roles.
  - Complete elimination of authorization leakage and IDOR vulnerabilities.
  - User Modernization track (USER-00 through USER-05) is 100% complete and verified for PRODUCTION.

## ADR-APPROVAL-01: Dynamic Approver Resolvers & Workflow Configuration Hardening

- **Status**: Accepted
- **Date**: 2026-08-16
- **Context**: 
  Approval workflow steps were rigidly tied to static `role_id` entries, which failed to adapt to organizational unit structures (e.g. dynamic department heads) and required hardcoding role names.
- **Decision**:
  1. Introduce `ApproverResolverService` supporting 7 dynamic resolver types (`DEPARTMENT_HEAD`, `REQUESTER_SUPERVISOR`, `DIVISION_DIRECTOR`, `DIRECTOR`, `HR_OFFICER`, `ROLE`, `SPECIFIC_USER`).
  2. Implement dynamic resolution through `org_unit_managers`, `OrgScopeResolver`, and Spatie permissions.
  3. Store immutable snapshots in `LeaveApproval` so workflow modifications never mutate historical or pending requests.
  4. Enforce optimistic concurrency control (`X-Workflow-Fingerprint` returning HTTP 409).
- **Consequences**:
  - Full decoupling between organizational hierarchy and system roles.
  - Rock-solid workflow stability, concurrency safety, and audit traceability.






## ADR-APPROVAL-02: Snapshot ต้องเก็บ "วิธีหาตัวผู้อนุมัติ" และรายการงานต้องใช้เกณฑ์เดียวกับการอนุมัติ

- **Status**: Accepted
- **Date**: 2026-08-19
- **Context**:
  ADR-APPROVAL-01 กำหนดให้ `leave_approvals` เป็น snapshot ที่ไม่เปลี่ยนตามการแก้สายอนุมัติ
  แต่ snapshot จริงเก็บเฉพาะ `required_role_id` ขั้นตอนแบบ `DEPARTMENT_HEAD` / `DIRECTOR` /
  `SPECIFIC_USER` จึงถูกลดรูปเป็น `ROLE` ตั้งแต่วินาทีที่ยื่นใบลา ผู้อนุมัติตัวจริงกดอนุมัติแล้วได้ 403
  ขณะเดียวกันขั้นตอนที่ไม่มี role กำกับเลยกลับเปิดให้ผู้ใช้ที่ active ทุกคนอนุมัติได้
  และรายการ "รออนุมัติ" ยังคัดด้วยการจับคู่ role ตรง ๆ ซึ่งเป็นคนละเกณฑ์กับตอนกดอนุมัติ
- **Decision**:
  1. snapshot ต้องผนึกกติกา ณ วันยื่นให้ครบ — `approver_type`, `specific_user_id`, `permission_name`
     ไม่ใช่แค่ `required_role_id`
  2. การหาตัวผู้อนุมัติเป็น fail-closed — ไม่มีเกณฑ์คัด = ไม่มีใครมีสิทธิ์
  3. ห้ามเติม role ให้ขั้นตอนอัตโนมัติโดยที่ผู้ดูแลไม่ได้เลือก สิ่งที่หน้าจอแสดงต้องเป็นสิ่งที่ระบบใช้จริง
  4. รายการงานที่ต้องอนุมัติ (worklist) ต้องคัดด้วย `ApproverResolverService` ตัวเดียวกับที่ใช้ตัดสินสิทธิ์
     ตอนกดอนุมัติ — กติกาคือ "เห็น = อนุมัติได้"
  5. worklist ไม่ถูกหรี่ด้วยขอบเขตหน่วยงาน (`LeaveVisibility`) เพราะสิทธิ์อนุมัติแคบกว่าอยู่แล้ว
     และผู้อนุมัติข้ามสายงาน (ผอ. / ผู้อนุมัติเฉพาะราย) ต้องเห็นงานของตน
  6. สายอนุมัติคำขอยกเลิกใบลาใช้ snapshot + resolver ชุดเดียวกับการอนุมัติใบลา
- **Consequences**:
  - ผู้อนุมัติที่ระบุไว้ในสายอนุมัติทำงานได้จริงทุกรูปแบบ ไม่ต้องพึ่ง Super Admin กดแทน
  - ปิดช่องโหว่ที่ผู้ใช้ทั่วไปอนุมัติใบลาแทนผู้บริหารได้
  - ยังคงเหลือหนี้เชิงสถาปัตยกรรมที่ต้องตัดสินใจแยก: workflow engine (shadow mode) กับ
    `approval_steps`/`leave_approvals` ยังทำงานคู่ขนานกันอยู่

## ADR-APPROVAL-04: คำขอยกเลิกใบลาเป็นสายอนุมัติของตัวเอง บนขั้นตอนชุดเดียวกับการขอลา

- **Status**: Accepted
- **Date**: 2026-08-19
- **Context**:
  ระเบียบราชการถือว่าการยกเลิกวันลาที่อนุมัติแล้วเป็นการแก้ไขเอกสารที่มีผลผูกพัน
  จึงต้องผ่านการพิจารณาเหมือนตอนขอลา แต่ระบบเดิมให้คำขอยกเลิก "ยืม" สายของใบลาไปใช้:
  เขียนทับ `leaves.approval_level`, ไม่มี snapshot ของตัวเอง, ไม่บันทึกว่าใครอนุมัติขั้นไหน
  และเดินตาม `approval_steps` ปัจจุบันจึงเปลี่ยนตามการแก้สายอนุมัติ
- **Decision**:
  1. คำขอยกเลิกใช้ **ขั้นตอนชุดเดียวกับการขอลา** (ผู้ดูแลตั้งค่าที่เดียว ไม่ต้องดูแลสองชุด)
  2. แต่ผนึก **snapshot ของตัวเอง** ณ วันที่ขอยกเลิก แยกด้วย `leave_approvals.stage`
  3. เดินระดับด้วย `leaves.cancellation_approval_level` — ห้ามแตะ `approval_level` ของใบลาเดิม
     เพราะเป็นประวัติว่าใบลาผ่านอนุมัติมาถึงขั้นใด
  4. ทุกขั้นบันทึกผู้อนุมัติ ตำแหน่ง ลายเซ็น ความเห็น เวลา และผู้มอบอำนาจ เท่ากับสายขอลา
  5. ยื่นคำขอยกเลิกใหม่ได้เมื่อคำขอเดิมถูกปฏิเสธ และต้องเริ่มสายใหม่ตั้งแต่ขั้นแรก
  6. ความสัมพันธ์ `Leave::approvals()` กรองเฉพาะ stage `request` เพื่อให้ผู้เรียกเดิมทั้งหมด
     (เอกสาร PDF, timeline, การตรวจสิทธิ์) ได้ผลเท่าเดิม และเพิ่ม `cancellationApprovals()`
     กับ `allApprovals()` สำหรับผู้เรียกที่ต้องการอีกสองมุม
- **Consequences**:
  - คำขอยกเลิกตรวจสอบย้อนหลังได้เต็มรูปแบบ และไม่ถูกกระทบเมื่อสายอนุมัติเปลี่ยน
  - แก้บั๊กที่อนุมัติยกเลิกแล้ววันลาไม่ถูกคืนเข้าสิทธิ์ (คืนก่อนเปลี่ยนสถานะเป็น Cancelled)
  - ถ้าภายหลังต้องการให้สายยกเลิกสั้นกว่าสายขอลา ต้องเพิ่มการตั้งค่าสายที่สอง ซึ่งยังไม่ทำในรอบนี้

## ADR-APPROVAL-05: สายอนุมัติคำขอยกเลิกตั้งค่าแยกได้ โดยค่าเริ่มต้นสืบทอดจากสายใบลา

- **Status**: Accepted
- **Date**: 2026-08-19
- **Context**:
  ADR-APPROVAL-04 กำหนดให้คำขอยกเลิกใช้ขั้นตอนชุดเดียวกับการขอลาเสมอ ซึ่งตรงกับ
  การปฏิบัติของหน่วยงานส่วนใหญ่ แต่บางแห่งต้องการให้การยกเลิกสั้นกว่า เช่นเสนอ
  ผู้อำนวยการโดยตรงโดยไม่ต้องผ่านหัวหน้าแผนกซ้ำ
- **Decision**:
  1. แยกสายด้วยคอลัมน์ `approval_steps.chain` (`leave` / `cancellation`) — ตารางเดียว
     ไม่แตกตารางใหม่ เพราะโครงสร้างขั้นตอนและ resolver เหมือนกันทุกประการ
  2. **สายยกเลิกที่ไม่มีขั้นตอนเลย = ใช้สายของใบลา** (`ApprovalStep::stepsFor()`)
     เป็นค่าเริ่มต้นของระบบ การอัปเกรดจึงไม่เปลี่ยนพฤติกรรมของหน่วยงานที่ใช้อยู่
     และผู้ดูแลที่ไม่ต้องการแยกสายไม่ต้องตั้งค่าอะไรเพิ่ม
  3. สายของใบลาต้องมีอย่างน้อยหนึ่งขั้นเสมอ (ไม่มีขั้นตอน = ไม่มีใครอนุมัติใบลาได้)
     ส่วนสายยกเลิกบันทึกว่างได้ ซึ่งแปลว่ากลับไปสืบทอดสายของใบลา
  4. การบันทึก การตรวจ concurrency (fingerprint) และการดูตัวอย่างผู้อนุมัติ แยกตามสาย
  5. workflow engine (shadow, ADR-009) ยังสะท้อนเฉพาะสายของใบลา — สายยกเลิกไม่ออกเวอร์ชัน
     จนกว่าจะมีการตัดสินใจเรื่อง engine ในเฟสถัดไป
  6. ผู้เรียก `approval_steps` เดิมทุกจุดถูกระบุสายอย่างชัดแจ้ง ไม่ใช้ global scope
     เพื่อให้อ่านโค้ดแล้วเห็นทันทีว่ากำลังพูดถึงสายไหน (โดเมนนี้ตรวจสอบย้อนหลังได้สำคัญกว่าความสั้น)
- **Consequences**:
  - หน่วยงานเลือกได้ว่าจะให้การยกเลิกใช้สายเดียวกับใบลาหรือสายเฉพาะของตน
  - snapshot ของคำขอยกเลิกยังผนึก ณ วันที่ขอเหมือนเดิม การเปลี่ยนสายไม่ย้อนกระทบคำขอที่ค้างอยู่

## ADR-LEAVE-FORM-01 — แบบฟอร์มใบลาเป็นข้อมูลที่ตั้งค่าได้ ไม่ใช่โค้ด (2026-08-19)

- **Context**:
  แบบฟอร์มราชการแต่ละประเภทมีช่องว่างไม่เหมือนกัน และแต่ละสถานศึกษาใช้ถ้อยคำ/ชื่อหน่วยงาน
  ต่างกัน ระบบเดิมเลือกแบบฟอร์มด้วยการเทียบชื่อไทยของประเภทการลาในโค้ด
  (`$type->name === 'ลาพักผ่อน'`) ชื่อสถานศึกษาเขียนตายในไฟล์ blade และหน้าแก้เทมเพลต
  โฆษณาตัวแปรอย่าง `{{ $school_name }}` ที่ไม่เคยถูกส่งค่าให้จริง
- **Decision**:
  1. **การผูกแบบฟอร์ม** ย้ายไปเป็นข้อมูล: `leave_types.document_template_code`
     ตั้งค่าได้จากหน้าประเภทการลา — ถอยไปเดาจากชื่อไทยเฉพาะข้อมูลเก่าที่ยังไม่ได้ตั้งค่า
  2. **ชุดตัวแปรเดียว** (`App\Services\LeaveDocumentFields`) เป็นสัญญาระหว่างข้อมูลในระบบ
     กับช่องว่างในแบบฟอร์ม ทุกแบบฟอร์ม (blade และเทมเพลตในฐานข้อมูล) ใช้ชุดเดียวกัน
     และมี `catalog()` เป็นเอกสารในตัวสำหรับหน้าผู้ดูแล
  3. **ช่องเฉพาะแบบฟอร์ม** (ชื่อวัด ชื่อภริยา ฯลฯ) นิยามเป็นข้อมูลใน `leave_types.extra_fields`
     ค่าที่กรอกเก็บใน `leaves.meta_data.extra_fields` — เพิ่มแบบฟอร์มใหม่ไม่ต้องเพิ่มคอลัมน์
  4. ชื่อสถานศึกษา/สังกัด/ตำแหน่งผู้บริหารมาจากตาราง `settings` เท่านั้น ไม่มีค่า default
     เป็นชื่อสถานศึกษาใดในโค้ด (เว้นว่างไว้ให้เป็นเส้นประของแบบฟอร์ม)
  5. การพิมพ์ยังยึด LEGACY-07: ใบลาที่ pin เวอร์ชันไว้พิมพ์ด้วยเวอร์ชันเดิมเสมอ การแก้เทมเพลต
     หรือ "คืนค่าแบบฟอร์มตั้งต้น" จึงไม่ย้อนเปลี่ยนเอกสารที่ออกไปแล้ว
- **Consequences**:
  - ผู้ดูแลเพิ่มประเภทการลา/เปลี่ยนชื่อ/สลับแบบฟอร์ม/เพิ่มช่องกรอก ได้เองโดยไม่ต้องแก้โค้ด
  - แบบฟอร์มในไฟล์ blade กลายเป็นเพียง "ค่าตั้งต้น" ที่ถูกคัดลอกลงฐานข้อมูล
  - ราคาที่จ่าย: แบบฟอร์มแต่ละไฟล์มี CSS ซ้ำกัน เพราะต้อง self-contained ให้แก้ไขได้ทั้งไฟล์จากหน้าเว็บ

## ADR-DOC-ENGINE-01: Universal Document & Report Engine บน Compatibility Layer

- **Status**: Accepted
- **Date**: 2026-08-20
- **Context**:
  ระบบเดิมออกเอกสารด้วย dompdf จากแม่แบบ HTML ซึ่งจัดตำแหน่งให้ตรงแบบฟอร์มราชการได้ยาก
  ผู้ดูแลเพิ่มแบบฟอร์มใหม่เองไม่ได้ ไม่มีการเก็บไฟล์เอกสารที่ออกไปแล้ว และรายงานถูกเขียนตายในโค้ด
  รายละเอียดทั้งหมดอยู่ใน `docs/checkpoints/DOC-ENGINE-00-ASSESSMENT.md`
- **Decision**:
  1. Engine ใหม่ทำงานคู่ขนานกับของเดิม ผ่าน `LeaveDocumentGateway` เป็น Compatibility Layer
     ปิดธง = เส้นทางเดิมทั้งหมด และ engine ล้มเมื่อใดก็ถอยไปเส้นทางเดิมเสมอ (ผู้ใช้ต้องพิมพ์เอกสารได้)
  2. `render_mode` แยกแม่แบบ HTML เดิม (`html`) ออกจากแม่แบบวางฟิลด์บน PDF (`pdf_overlay`)
     แม่แบบเดิมทั้ง 10 ตัวไม่ถูกแตะและยังใช้งานได้ตลอดไป
  3. เพิ่ม `status` (DRAFT/ACTIVE/RETIRED/ARCHIVED) ควบคู่กับ `is_active` เดิม ไม่แทนที่
     เวอร์ชันที่มีเอกสารอ้างอิงห้ามลบและห้ามแก้ในที่เดิม ต้อง Duplicate → Modify → Publish
  4. เอกสารเข้าถึงข้อมูลผ่าน Data Provider ที่ลงทะเบียนในโค้ดเท่านั้น ตารางแหล่งข้อมูลเป็นแค่รายการให้เลือก
     ไม่มีจุดใดรับ SQL จากหน้าจอ และขอบเขตการมองเห็นยังเป็นของ `ReportingScopeService` เดิม
  5. เอกสารฉบับสมบูรณ์เป็น IMMUTABLE บังคับที่ตัวโมเดล การออกซ้ำ = revision ใหม่ ของเดิมเป็น SUPERSEDED ไม่ถูกลบ
  6. เอกสารย้อนหลังอ่านจากไฟล์ที่เก็บไว้ก่อนเสมอ ไม่เรนเดอร์ใหม่จากข้อมูลบุคคล/ผู้บริหาร/แบบฟอร์มปัจจุบัน
  7. ค่าชดเชยเครื่องพิมพ์แยกจาก field mapping (global → template → user) ห้ามขยับ mapping เพื่อแก้ปัญหาเครื่องพิมพ์
  8. ฟอนต์ไทยฝังในระบบ (`storage/fonts/fpdf` สร้างด้วย `php artisan document:install-fonts`) ไม่พึ่งฟอนต์ของ host
  9. สิทธิ์ `document.*` ชุดใหม่ ใช้ควบคู่กับสิทธิ์ตั้งค่าระบบเดิมในระยะเปลี่ยนผ่าน เพื่อไม่ตัดสิทธิ์ผู้ดูแลที่ใช้งานอยู่
- **Consequences**:
  - เพิ่มแบบฟอร์ม/เวอร์ชัน/รายงานใหม่ได้จากหน้าผู้ดูแลโดยไม่ต้องแก้โค้ด
  - Engine ไม่ผูกกับ E-Leave — โมดูลอื่นใช้ได้ด้วยการเพิ่ม provider + binding rule
  - ยังต้องทำต่อ: ย้าย leave type ที่เหลือเข้า engine (DOC-ENGINE-05) และหน้าออกแบบรายงานแบบลากคอลัมน์ (DOC-ENGINE-07)

## ADR-SEC-HOTFIX-01-A: ด่านตรวจ environment แยกทาง HTTP กับทาง console

- **Status**: Accepted
- **Date**: 2026-08-22
- **Context**:
  `ProductionEnvironmentGuard` เดิมโยน `LogicException` ใน `AppServiceProvider::boot()`
  ซึ่งมีผลเป็น HTTP 500 รายคำขอเท่านั้น container ยังขึ้น ยัง migrate และดูเหมือน
  deploy สำเร็จทั้งที่ตั้งค่าไม่ปลอดภัย
  หากปล่อยให้โยนทาง console ด้วย ผู้ดูแลจะรัน artisan อะไรไม่ได้เลย
  รวมถึงคำสั่งที่ใช้ "แก้" ปัญหา (`config:clear`, `key:generate`)
- **Decision**:
  1. ทาง HTTP: ปฏิเสธทุกคำขอทันทีเมื่อการตั้งค่าไม่ปลอดภัย (เหมือนเดิม)
  2. ทาง console: ไม่โยน — ใช้คำสั่ง `app:verify-environment` เป็นด่านแทน
  3. `docker-compose.yml` เรียกคำสั่งนี้เป็นคำสั่งแรกของ backend / worker / scheduler
     ก่อน `migrate` เมื่อไม่ผ่าน คำสั่งถัดไปไม่ถูกรันและ container ออกทันที
  4. `problems()` คืนปัญหาทุกข้อพร้อมกัน ไม่หยุดที่ข้อแรก
- **Consequences**:
  - ระบบที่ตั้งค่าไม่ปลอดภัยไม่มีทางขึ้นให้บริการ และไม่มีการเปลี่ยน schema ก่อนรู้ตัว
  - ผู้ดูแลยังแก้ปัญหาจาก CLI ได้
  - `RuntimeSecurityConfigurationTest` ตรึงลำดับคำสั่งใน compose ไว้

## ADR-SEC-HOTFIX-01-B: การจัดกลุ่มประเภทการลาในรายงานยึดรหัส ไม่ใช่ชื่อ

- **Status**: Accepted
- **Date**: 2026-08-22
- **Context**:
  รายงานสถิติการลาแยกช่องด้วย `leave_types.name LIKE '%ป่วย%'` ที่ฝังอยู่ใน SQL
  ชื่อประเภทการลาเป็นข้อมูลที่ผู้ดูแลแก้ได้จากหน้าจอ การเปลี่ยนชื่อจึงทำให้ยอด
  ในรายงานหายไปเงียบ ๆ โดยไม่มีข้อผิดพลาดใด ๆ และประเภทที่ผู้ดูแลเพิ่มเอง
  ไม่มีทางเข้ารายงานได้เลย
- **Decision**:
  1. ยึด `leave_types.code_prefix` ซึ่งเป็นรหัสประจำประเภทการลาที่ไม่เปลี่ยนตามชื่อ
  2. การจับคู่ "กลุ่มของรายงาน → รหัส" ตั้งค่าได้ที่ `config/leave_reporting.php`
     (override ผ่าน env ได้สำหรับ deployment ที่ใช้รหัสของตัวเอง)
  3. `LeaveReportCategoryResolver` เป็นจุดเดียวที่รู้ว่ากลุ่มไหนมีประเภทใดบ้าง
  4. คงทางถอยด้วยชื่อไว้ **เฉพาะ** ประเภทที่ยังไม่มีรหัส เพื่อไม่ให้ข้อมูลเดิมหาย
     ตอนอัปเกรด — ประเภทที่มีรหัสแล้วตัดสินด้วยรหัสอย่างเดียว
- **Consequences**:
  - เปลี่ยนชื่อประเภทการลาได้โดยรายงานไม่เพี้ยน
  - เพิ่มกลุ่มใหม่ในรายงานทำได้จาก config ไฟล์เดียว (dataset + catalog ใช้ค่าเดียวกัน)
  - ยังต้องตั้ง `code_prefix` ให้ประเภทการลาที่ผู้ดูแลเพิ่มเอง มิฉะนั้นจะเข้าเฉพาะยอดรวม

## ADR-SEC-HOTFIX-01-C: ผู้ได้รับหนังสือเวียนนับเป็นผู้เกี่ยวข้องกับหนังสือ

- **Status**: Accepted
- **Date**: 2026-08-22
- **Context**:
  `DocumentPolicy::isInvolved()` นับเฉพาะผู้สร้างและผู้รับที่ระบุบนหนังสือ
  แต่ `/api/saraban/circulars/incoming` ส่งเนื้อหนังสือกลับไปให้ผู้รับหนังสือเวียน
  (eager load `circular.document`) ระบบจึงขัดกันเอง: ผู้รับเห็นข้อมูลที่ policy
  ไม่อนุญาต แต่พอกดเปิดหน้ารายละเอียดกลับได้ 403 — ทั้งรั่วและใช้งานไม่ได้พร้อมกัน
  ขณะเดียวกัน `CircularController::send()` ไม่ตรวจสิทธิ์ผู้รับเลย การเวียนจึงเป็น
  ทางลัดส่งหนังสือ (รวมหนังสือชั้นความลับ) ไปให้ใครก็ได้ในระบบ
- **Decision**:
  1. `isInvolved()` นับผู้ได้รับหนังสือเวียนด้วย — การเวียนคือการอนุญาตให้อ่าน
  2. ด่านคัดกรองย้ายไปอยู่ที่ "ตอนเวียน": ผู้รับต้องมี `saraban.document.view`
     และต้องมี `saraban.document.view_classified` เมื่อหนังสือเป็นชั้นความลับ
  3. ผู้รับรายเดียวที่ไม่ผ่านทำให้ทั้งคำขอตกไป (422) ไม่ส่งบางส่วนเงียบ ๆ
  4. ตรวจที่ "สิทธิ์" ไม่ใช่ `can('view', $document)` เพราะการเวียนนี่เองคือสิ่งที่
     ทำให้ผู้รับกลายเป็นผู้เกี่ยวข้อง
- **Consequences**:
  - ฟีเจอร์เวียนหนังสือใช้งานได้จริงเป็นครั้งแรก
  - ชั้นความลับยังถูกบังคับใน `view()` อีกชั้นหนึ่งเสมอ
  - `CircularAuthorizationTest` ตรึงพฤติกรรมทั้งด้านอนุญาตและด้านปฏิเสธ

## ADR-SEC-HOTFIX-01-D: แยก compose ของการพัฒนาออกจาก compose ที่ใช้ deploy

- **Status**: Accepted
- **Date**: 2026-08-22
- **Context**:
  `docker-compose.yml` เป็นไฟล์เดียวกับที่ `docs/deployment/PRODUCTION-DEPLOYMENT.md`
  สั่งให้ใช้ deploy แต่มี service ที่รัน `npm run dev -- --host 0.0.0.0`
  และ publish พอร์ต 5173 ออกทุกอินเทอร์เฟซ ซึ่งเป็น dev server ที่ไม่มี
  authentication เสิร์ฟ source ดิบและ source map ทั้งโครงการ และเปิด websocket HMR
  ไม่เลือกใช้ `docker-compose.override.yml` เพราะ compose โหลดให้อัตโนมัติ
  production ที่ `git pull` repo เดียวกันจะได้ dev server กลับมาเงียบ ๆ
- **Decision**:
  1. dev server ย้ายไป `docker-compose.dev.yml` ซึ่งต้องระบุด้วย `-f` อย่างชัดเจน
     และผูกพอร์ตไว้ที่ `127.0.0.1` เท่านั้น
  2. compose หลักเหลือ job `frontend_build` (profile `build`) ที่สร้าง
     `frontend/dist` ให้ nginx เสิร์ฟ แล้วจบการทำงาน ไม่มีพอร์ตเปิดออก
- **Consequences**:
  - `docker compose up -d` บน production ไม่มีทางได้ dev server
  - นักพัฒนาต้องเปลี่ยนคำสั่งเป็น `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`
  - `RuntimeSecurityConfigurationTest` ล้มทันทีถ้ามีใครใส่พอร์ต 5173 กลับเข้า compose หลัก

## ADR-MODULE-01: ถอดโมดูลสารบรรณอิเล็กทรอนิกส์ออกจากระบบ

- **Status**: Accepted
- **Date**: 2026-08-27
- **Context**:
  เจ้าของระบบสั่งให้ลบระบบสารบรรณ (e-Saraban) และส่วนที่เกี่ยวข้องออกทั้งหมด
  โดยห้ามกระทบระบบลางานและระบบลงเวลาออนไลน์ โมดูลนี้เป็นเจ้าของตาราง
  `saraban_*` 14 ตาราง สิทธิ์ `saraban.*` ทั้งชุด สายงาน `saraban.document`
  ใน Workflow Engine ประเภทแจ้งเตือน `saraban.task` และรายงาน `saraban`
  ในศูนย์รายงานกลาง
- **Decision**:
  1. ลบโค้ด เส้นทาง หน้าเว็บ เทสต์ และ migration ของโมดูลออกทั้งหมด
  2. เขียน migration ทางเดียว `2026_08_27_000000_drop_saraban_module` เพื่อ
     drop ตารางของโมดูลและล้างร่องรอยในตารางกลาง (ตัดสินใจร่วมกับเจ้าของระบบ
     ว่ายอมรับการสูญเสียข้อมูลหนังสือราชการเดิม โดยต้องสำรองฐานข้อมูลก่อนรัน)
  3. **คงบทบาท** "เจ้าหน้าที่สารบรรณกลาง" และ `Document Officer` ไว้ ลบเฉพาะ
     สิทธิ์ `saraban.*` เพื่อไม่ให้ผู้ใช้ที่ถือบทบาทเหล่านี้หลุด role และยังใช้
     ระบบลาและลงเวลาได้ต่อเนื่อง
  4. ประวัติการแจ้งเตือนที่ส่งไปแล้วเป็น historical record จึงไม่ลบ — ถ้ายังมี
     ประวัติอ้าง `saraban.task` อยู่ ให้ปิดการใช้งานประเภทนั้นแทนการลบ
  5. ตัด "สารบรรณ" ออกจากรายการ Future modules ใน Master Blueprint และลบ
     `docs/modules/FUTURE-01-CORRESPONDENCE.md` เพราะไม่อยู่ในขอบเขตของระบบอีกต่อไป
- **Consequences**:
  - ผู้ใช้ที่เคยมีสิทธิ์สารบรรณจะไม่เห็นเมนูและเรียก API ของโมดูลไม่ได้อีก
  - เลิกใช้ SEC-023 (allowlist ชนิดไฟล์แนบ) เพราะ `config/saraban.php` ซึ่งเป็น
    ที่เดียวที่นิยาม allowlist นี้ถูกลบไปพร้อมโมดูล — ถ้าจะทำ allowlist กลางสำหรับ
    ไฟล์แนบของโมดูลอื่น ต้องออกแบบใหม่เป็นงานแยก
  - การตรวจสอบเอกสารด้วย QR ของ **ใบลา** (`/api/leaves/verify/{hash}`) ไม่กระทบ
    เพราะใช้ `LeaveVerificationService` ของตัวเอง ไม่ได้พึ่งโค้ดสารบรรณ

## ADR-SEC-023R: allowlist กลางของไฟล์อัปโหลด

- **Status**: Accepted
- **Date**: 2026-08-27
- **Context**:
  SEC-023 (allowlist สองชั้นของไฟล์แนบ) นิยามอยู่ใน `config/saraban.php` ที่เดียว
  และถูกลบไปพร้อมโมดูลสารบรรณตาม ADR-MODULE-01 จุดรับไฟล์ที่เหลือของระบบ
  ตรวจไม่เท่ากัน — หลักฐานประกอบการลาและไฟล์แนบใบลาใช้ `mimes:` อย่างเดียว
  `ExportSettingController` รับ `svg` และ `SettingController` รับ `gif`
- **Decision**:
  1. ย้าย allowlist ขึ้นเป็นของกลางที่ `config/uploads.php` แยกตามลักษณะการใช้งาน
     (`attachments` / `images` / `document_templates`) ไม่ผูกกับโมดูลใดโมดูลหนึ่งอีก
  2. ทุกจุดที่รับไฟล์ต้องสร้างกฎผ่าน `App\Support\UploadRules` ห้ามเขียน
     `mimes:`/`max:` เองในตัว controller เพื่อไม่ให้ allowlist แตกออกเป็นหลายชุดอีก
  3. กฎที่สร้างมีสองชั้นเสมอ (`mimetypes:` จากเนื้อไฟล์ + `extensions:` จากนามสกุลจริง)
  4. `never_allowed` ในไฟล์ config เป็นด่านของเทสต์ การเพิ่มชนิดไฟล์ในอนาคตที่ชน
     รายการนี้จะทำให้ CI ล้มก่อนขึ้น production
- **Consequences**:
  - เพดานขนาดไฟล์เดิมของแต่ละจุดยังคงเดิม (ใบลา 5 MB, หลักฐาน 10 MB, แม่แบบ 20 MB)
  - `svg` และ `gif` อัปโหลดเป็นโลโก้ไม่ได้อีก — ถ้าหน่วยงานมีโลโก้เป็น svg
    ต้องแปลงเป็น png ก่อน (ยอมรับเพราะ svg รันสคริปต์ได้เมื่อเบราว์เซอร์เปิด)
  - `UploadedFile::fake()` เดาชนิดไฟล์จากนามสกุล เทสต์ที่ต้องพิสูจน์ด่านเนื้อไฟล์
    จึงต้องเขียนไฟล์จริงลงดิสก์ (ดู `UploadAllowlistTest::realUpload()`)

## ADR-CLEANUP-01: ตาราง `menus` เป็นแหล่งความจริงเดียวของแถบเมนู

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  ระบบมีเมนูสองชุดที่ไม่ตรงกัน ชุดที่ผู้ใช้เห็นจริงเขียน hard-code อยู่ใน `frontend/src/App.vue`
  และเขียนซ้ำอีกก๊อปสำหรับจอมือถือ (รวม 80 `<router-link>` ที่ต้องแก้คู่กันทุกครั้ง)
  ส่วนตาราง `menus` พร้อม `MenuController` / `MenuService` / `menuStore` / `MenuSeeder`
  ไม่มีหน้าใดใช้ และชี้ไปยัง route ที่ไม่มีอยู่จริง หน้า "จัดการเมนูตามสิทธิ์" จึงไม่มีผลกับใคร
  ขัดข้อบังคับใน `AGENTS.md` ที่ห้าม hard-code menu
- **Decision**:
  1. `MenuSeeder::TREE` เป็นแหล่งความจริงเดียวของรายการเมนูและลำดับ
  2. แถบเมนูอ่านจาก `/api/user/menus` และใช้ component เดียว (`AppMenuNav.vue`) ทั้งจอใหญ่และมือถือ
  3. seeder ลบเมนูที่ไม่อยู่ใน `TREE` เพราะชี้ไปยังหน้าที่ไม่มีอยู่ — เมนูเป็น configuration ไม่ใช่ historical record
  4. หน้า "จัดการเมนูตามสิทธิ์" ปรับได้สองอย่างคือ เปิด/ปิดเมนู และสิทธิ์ที่ใช้มองเห็นเมนู
     ส่วนรายการและลำดับยังมาจาก `TREE` เพื่อไม่ให้เกิดแหล่งความจริงที่สอง
  5. ป้ายตัวเลข (badge) เป็นสถานะ runtime ไม่เก็บในตาราง `menus`
- **Consequences**:
  - การมองเห็นเมนูยังไม่ใช่การอนุญาต ทุก endpoint ตรวจสิทธิ์ฝั่ง server เหมือนเดิม
  - เพิ่มหน้าใหม่ต้องเพิ่มทั้ง route และรายการใน `TREE` — `MenuIntegrityTest` บังคับบน CI
  - ตาราง `menus` ไม่ว่างอีกต่อไปในชุดทดสอบ เทสต์ที่เคยสมมติว่าตารางว่างถูกปรับให้ตรวจเฉพาะเมนูของตัวเอง

## ADR-CLEANUP-02: ชั้นรายงานกลางเป็นชั้นรายงานหลักของระบบ

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  ระบบมีชั้นรายงาน 5 ชุด สองชุดไม่มีหน้าใดเรียกเลย — `ReportController` (ชั้นเดิม)
  และ `ReportingController` + `Services/Reporting/*` (ชั้นกลางที่สร้างใน LEGACY-11)
  ทั้งสองคำนวณขอบเขตการมองเห็นด้วยตรรกะคนละชุด
- **Decision**:
  1. ชั้นรายงานกลาง (`/api/reporting/*`) เป็นชั้นรายงานมาตรฐานของระบบ และมีหน้าจอจริงที่ `/report-center`
  2. `ReportController` ถูกถอดออกทั้งชุดพร้อม `LeaveExcelService` และ `pdf/report.blade.php`
  3. ทะเบียนคุมการลาของราชการ (`/api/reports/government-leave-*`) **ยังอยู่** เพราะเป็นเอกสารคนละชนิด
     (รายใบ รวมใบที่ถูกลบ) ไม่ใช่รายงานสรุปแบบเดียวกัน
  4. รายงานที่ผู้ดูแลออกแบบเอง (`/api/admin/document-reports`) ยังอยู่ — เป็นคนละบทบาทกับสารบัญรายงานมาตรฐาน
- **Consequences**:
  - ข้อความทั้งชั้นต้องเป็นภาษาไทย 100% ตาม `AGENTS.md` — แปลชื่อรายงาน คอลัมน์ ยอดรวม และเปลี่ยนฟอนต์ PDF เป็น TH Sarabun
  - ขอบเขตข้อมูลของทุกรายงานมาจาก `ReportingScopeService` ที่เดียว
  - ยังเหลือรายงานเฉพาะกิจ (บัตรลา คัดกรองเลื่อนเงินเดือน สรุปลงเวลา/OT) ที่ยังไม่ผ่านชั้นกลาง — เป็นงานของรอบถัดไป

## ADR-CLEANUP-03: Workflow cutover เป็นสวิตช์ที่ใช้งานได้จริง แต่ยังไม่เปิดบน production

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  เฟส LEGACY-05 เตรียมสวิตช์ `workflow.engine_is_source_of_truth` ไว้ แต่ **ไม่มีโค้ดส่วนใดอ่านค่านี้เลย**
  การ cutover จึงทำไม่ได้จริงแม้เจ้าของระบบสั่ง ขณะเดียวกันตรรกะเดินสายอยู่สองที่
  (`GovernmentApprovalController` และ `WorkflowEngine`) การเปลี่ยนกฎอนุมัติจึงต้องแก้สองที่ให้ตรงกันเอง
  `LEGACY-05-REPORT.md` ข้อ 11 กำหนดเงื่อนไขก่อน cutover ไว้ 4 ข้อ ซึ่งข้อ 1–2 (หลักฐาน shadow
  จากการอนุมัติจริง) เป็นสิ่งที่สร้างจากการพัฒนาไม่ได้ ต้องสะสมจากการใช้งานจริง
- **Decision**:
  1. `LeaveApprovalCoordinator` เป็นจุดตัดสินเดียว และอ่านสวิตช์นั้นจริง
  2. การเขียนข้อมูลเป็นชุดเดียวกันทั้งสองเส้นทาง (dual-write) — เปิดหรือปิดสวิตช์ ข้อมูลที่เอกสาร
     และหน้าจอทุกหน้าอ่านยังเหมือนเดิมทุกแถว
  3. `LeaveApprovalCutoverTest` รัน 6 สถานการณ์เดียวกันทั้งเปิดและปิดสวิตช์เป็นหลักฐาน parity
  4. **ค่าเริ่มต้นยังเป็น false** — การเปิดบน production เป็นการตัดสินใจของเจ้าของระบบหลังตรวจ
     `php artisan workflow:shadow-report` ไม่ใช่ผลข้างเคียงของการ deploy งานชุดนี้
  5. รายการ "ใบลาที่ต้องอนุมัติ" ถามผู้ตัดสินคนเดียวกัน เพื่อไม่ให้สิ่งที่เห็นกับสิ่งที่กดได้ต่างกัน
- **Consequences**:
  - เปิด/ปิดได้ด้วย env ตัวเดียว ไม่ต้องแก้โค้ดหรือ revert — rollback ต้นทุนต่ำ
  - ขอบเขตครอบเฉพาะสาย "ขอลา" · สาย "ขอยกเลิกใบลา" ยังเดินด้วยตรรกะเดิมทั้งสองค่า
    และต้องนำเข้า engine ก่อนจึงจะถือว่า cutover สมบูรณ์
  - ตรรกะเดิมยังอยู่ครบและยังเป็นค่าเริ่มต้น จึงยังไม่เข้าข่าย Big Bang Cutover ที่เฟสห้ามไว้

## ADR-CLEANUP-04: สารบัญชื่อสิทธิ์ที่เดียว โดยยังไม่ถอดชื่อชุดเดิม

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  ชื่อสิทธิ์ถูกประกาศกระจายอยู่ 5 ที่ที่ไม่ตรงกัน ผลคือมีชื่อที่โค้ดบังคับใช้แต่ไม่เคยถูกสร้าง
  (`leave.management.manage`, `system.role.manage`) endpoint และเมนูที่อ้างถึงจึงใช้ได้เฉพาะ
  Super Admin ที่ผ่าน `Gate::before` ส่วนผู้ที่ควรมีสิทธิ์ถูกปฏิเสธเงียบ ๆ
- **Decision**:
  1. `PermissionCatalogSeeder` เป็นแหล่งความจริงเดียวของ **ชื่อ** สิทธิ์ทั้งหมด
  2. สร้างชื่ออย่างเดียว **ไม่แจกสิทธิ์ให้บทบาทหรือผู้ใช้คนใด** — ขอบเขตของทุกคนเท่าเดิมทุกประการ
  3. การแจกสิทธิ์ยังอยู่ที่ `RolesAndPermissionsSeeder` และ `OperationalPermissionSeeder` ตามเดิม
  4. **ยังไม่ถอดชื่อชุดเดิม** — ผู้ใช้ปัจจุบันถือชื่อชุดเดิม การถอดออกตอนนี้จะตัดสิทธิ์ทันที
     ต้องย้ายการแจกสิทธิ์ของทุกบทบาทไปยังชื่อมาตรฐานให้ครบก่อน
  5. `StandardRolesAndPermissionsSeeder` ถูกลบ — สร้างบทบาทชื่อไทยอีกชุดที่ระบบไม่ได้ใช้
     และถูกกันไม่ให้รันตอนติดตั้งอยู่แล้ว จึงเป็นเพียงแหล่งความจริงปลอมที่ทำให้อ่านโค้ดแล้วเข้าใจผิด
- **Consequences**:
  - `PermissionCatalogTest` บังคับว่าทุกชื่อที่ route, โค้ด และเมนูอ้างถึงต้องอยู่ในสารบัญ
  - ชื่อชุดเดิมกับชุดมาตรฐานยังถูก OR กันใน route ต่อไปจนกว่าจะทำระยะสอง

## ADR-CLEANUP-05: ด่านสิทธิ์ของ route เดินผ่าน Gate ทางเดียว

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  ระบบมีสองทางตัดสินสิทธิ์ที่ให้ผลต่างกัน — `can:` ของ Laravel เดินผ่าน Gate จึงรู้จัก
  ทั้ง `permission_aliases` (ชื่อชุดเดิม ↔ ชื่อมาตรฐาน) และ `permission_denies`
  ส่วน `permission:` ของ Spatie เทียบชื่อดิบ ผลคือ (1) ทุก route ต้องเขียนชื่อสองชุดคู่กัน
  และ (2) สิทธิ์ที่ถูกตัดเฉพาะราย **ยังผ่านด่าน `permission:` ได้**
- **Decision**:
  1. `can:<ชื่อมาตรฐาน>` สำหรับความสามารถเดียว
  2. `ability:a|b` (middleware ใหม่ที่เรียก `Gate::allows` วนทุกชื่อ) สำหรับกรณีที่
     ความสามารถ "ต่างกันจริง" และยอมรับได้ทั้งคู่ เช่น ดูเวลาของตัวเอง หรือ ดูทั้งองค์กร
  3. ชื่อชุดเดิมหายจาก route ทั้งหมด — ผู้ที่ถือชื่อชุดเดิมยังผ่านได้ผ่าน alias
  4. `/api/user` ส่ง `permission_names` ที่รวมชื่อเทียบเท่ามาให้ หน้าเว็บจึงเช็คชื่อเดียว
  5. `MenuService` ตัดสินด้วย Gate เช่นกัน เมนูจึงเห็นภาพเดียวกับ route
- **Consequences**:
  - explicit deny มีผลกับทุก route แล้ว (เป็นการอุดช่อง ไม่ใช่ regression)
  - `AbilityMiddlewareTest` ล็อกไว้ว่าไม่มี route ใดกลับไปใช้ `permission:` อีก
  - การลบชื่อสิทธิ์ชุดเดิม **ออกจากฐานข้อมูล** ยังทำไม่ได้จนกว่าจะย้ายการแจกสิทธิ์
    ของทุกบทบาทไปยังชื่อมาตรฐานครบ — โค้ดเลิกอ้างแล้ว แต่ผู้ใช้ยังถืออยู่

## ADR-CLEANUP-06: การเปิด-ปิดโมดูลมีกลไกเดียว และตั้งได้จากหน้าจอ

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  มีสองกลไกที่ไม่คุยกัน — `config/modules.*.enabled` ตั้งจาก env เท่านั้นแต่เป็นตัวที่
  เมนู ด่าน route และ Notification Center ใช้จริง ส่วนค่าตั้งค่า `enable_leave_menu` /
  `enable_attendance_menu` ที่หน้า "โมดูลระบบ" เขียนนั้น หลังแถบเมนูเปลี่ยนไปอ่านจาก
  ตาราง `menus` ก็ไม่มีอะไรอ่านอีกเลย สวิตช์ในหน้านั้นจึงกดแล้วไม่มีอะไรเกิดขึ้น
- **Decision**:
  1. ค่าตั้งค่า `module_{configKey}_enabled` เป็นแหล่งความจริง
  2. `ModuleStateResolver` เติมทับ `config('modules.*.enabled')` ตอน boot —
     ค่าที่ตั้งด้วย `config([...])` ภายหลัง (เช่นในชุดทดสอบ) ยังชนะเสมอ
  3. โมดูลแกน (`system`) ปิดไม่ได้ และการปิดโมดูลที่มีผู้พึ่งพาจะแจ้งรายชื่อให้ทราบ
- **Consequences**:
  - ผู้ดูแลเปิด-ปิดโมดูลได้จากหน้าจอโดยไม่ต้องแก้ env และไม่ต้อง deploy ใหม่
  - ปิดโมดูลแล้ว **ทั้งเมนูและ API** หายพร้อมกัน ไม่ใช่ซ่อนแค่เมนู

## ADR-CLEANUP-07: ประวัติการดำเนินการอ่านจากทะเบียนกลางที่เดียว

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  โมดูลลาบันทึกประวัติสองที่ — `leave_audit_logs` (การตัดสินสำคัญ) และ
  `leave_action_logs` (การกระทำของผู้ใช้) ทั้งคู่ส่งต่อเข้า `audit_logs` ให้เองอยู่แล้ว
  ผ่าน model hook แต่หน้าประวัติอ่านเฉพาะตารางแรก ผู้ใช้จึงไม่เห็นการยื่นใบลา
  และการขอยกเลิกเลย
- **Decision**:
  1. `/api/leaves/{leave}/audit` อ่านจาก `audit_logs` ซึ่งรวมทุกแหล่งไว้แล้ว
  2. **ไม่ยุบตารางเดิมทิ้ง** — `leave_action_logs` ถูกใช้เป็น "ข้อมูล" ของฝั่งเอกสาร
     (หาผู้ขอยกเลิกและวันที่มาพิมพ์ลงแบบฟอร์ม) ไม่ใช่แค่ประวัติ การยุบจะทำให้เอกสารเสีย
  3. ไม่ทำ migration ย้ายประวัติ — ทะเบียนกลางมีข้อมูลครบอยู่แล้ว การย้ายจึงมีแต่
     ความเสี่ยงโดยไม่ได้อะไรเพิ่ม และขัดข้อห้ามลบ record ประวัติ
- **Consequences**:
  - ประวัติที่ผู้ใช้เห็นครบขึ้นทันทีโดยไม่ต้องแตะข้อมูลเดิมแม้แต่แถวเดียว
  - `audit_logs` เป็นแหล่งเดียวที่ต้องอ่านเมื่อสืบย้อนเหตุการณ์

## ADR-CLEANUP-06: การติดตั้งไม่สร้างบัญชีที่รู้รหัสผ่านล่วงหน้า

- **Status**: Accepted
- **Date**: 2026-08-28
- **Context**:
  `DatabaseSeeder` สร้างผู้ใช้ 6 คนรวม Super Admin ด้วยรหัสผ่านตายตัวในซอร์สโค้ด
  (`1111111111111` ถึง `6666666666666` ซึ่งเป็นเลขบัตรประชาชนสมมติของแต่ละบัญชีด้วย)
  SEC-027 เอาค่าเหล่านี้ออกจาก bundle ของหน้าเข้าสู่ระบบไปแล้ว แต่แก้เฉพาะ "การแสดงผล"
  ตัวบัญชียังถูกสร้างทุกครั้งที่รัน `php artisan db:seed --force` ตามคู่มือติดตั้ง
  ระบบที่เพิ่งขึ้น production จึงมีผู้ดูแลระบบสูงสุดที่ใครเปิดซอร์สก็เข้าได้ตั้งแต่วินาทีแรก
- **Decision**:
  1. ชุดบัญชีทดลองครบทุกบทบาทสร้างเฉพาะบน `local`/`testing` หรือเมื่อสั่ง `SEED_DEMO_ACCOUNTS=true`
     อย่างชัดแจ้ง — ชุดทดสอบและเครื่องพัฒนาจึงทำงานเหมือนเดิมทุกประการ
  2. สภาพแวดล้อมอื่นได้ผู้ดูแลระบบ **หนึ่งบัญชี** เท่าที่จำเป็นต่อการเข้าไปตั้งค่าครั้งแรก
     รหัสผ่านมาจาก `ADMIN_PASSWORD` ถ้าไม่ตั้งไว้ระบบสุ่มด้วย `Str::password(20)`
     แล้วพิมพ์บนหน้าจอครั้งเดียวตอนติดตั้ง — ไม่มีรหัสผ่านตัวใดอยู่ในซอร์สอีก
  3. **ไม่ลบบัญชีเดิมออกโดยอัตโนมัติ** ระบบที่ติดตั้งไปแล้วอาจใช้บัญชีเหล่านั้นทำงานจริง
     การลบผู้ใช้จะทำให้ใบลาและประวัติที่อ้างถึงเสียหาย — เป็นการตัดสินใจของเจ้าของระบบ
- **Consequences**:
  - ขั้นตอนติดตั้ง production เพิ่มหนึ่งข้อ: ตั้ง `ADMIN_EMAIL`/`ADMIN_PASSWORD` ก่อน seed
    หรือบันทึกรหัสผ่านสุ่มที่พิมพ์ออกมาให้ทันที (`.env.example` อธิบายไว้แล้ว)
  - `ProductionSeedSafetyTest` ยึดพฤติกรรมนี้ไว้ทั้งสองด้าน — production ต้องไม่มีบัญชีทดลอง
    และ local ต้องยังมีครบชุด การเผลอกลับไปฝังรหัสผ่านจะทำให้เทสต์แดงทันที

## ADR-LEAVE-AUDIT-01: การลงเวลาในวันที่ลาอนุมัติแล้ว — บันทึกได้แต่ต้องไม่เงียบ

- **Status**: Accepted
- **Date**: 2026-08-29
- **Context**:
  ระบบไม่เคยตรวจว่าวันที่กำลังลงเวลามีใบลาที่อนุมัติแล้วอยู่หรือไม่ และการลงเวลาเข้า
  ยังเขียนทับสถานะ `Leave` ของวันนั้นด้วย ผลจึงขึ้นกับลำดับว่าใครทำก่อน
  เจ้าของระบบไม่ได้ตอบคำถามนี้ตอนอนุมัติแผน จึงต้องเลือกค่าเริ่มต้นให้
- **Decision**:
  1. **ไม่ห้ามลงเวลา** ผู้ที่กลับมาปฏิบัติงานกลางคันแล้วยื่นยกเลิกลาทีหลังต้องบันทึก
     เวลาจริงได้ การห้ามจะทำให้ข้อมูลหายไปเลย ซึ่งเสียหายกว่าข้อมูลที่ขัดกันแล้วมีคนตรวจ
  2. แจ้งเตือนผู้มีสิทธิ์ตรวจสอบทันทีด้วยชนิดใหม่ `attendance.leave_conflict`
  3. การลงเวลาจริงชนะสถานะวันลาในใบลงเวลาเสมอ และการล้างวันลาจะไม่ลบแถวที่มีเวลาเข้า-ออก
- **Consequences**:
  - หน่วยงานที่ต้องการห้ามเด็ดขาดเปลี่ยนได้ที่บล็อกเดียวใน `AttendanceController::checkIn()`
    (ตอบ 422 แทนการแจ้งเตือน) — เขียนกำกับไว้ในโค้ดแล้ว
  - ความขัดแย้งไม่หายไปเงียบ ๆ อีกต่อไป มีร่องรอยทั้งในใบลงเวลาและในทะเบียนแจ้งเตือน

## ADR-LEAVE-AUDIT-02: ใบลงเวลาแยกประเภทการลาจากค่าที่กำหนด ไม่ใช่ชื่อประเภท

- **Status**: Accepted
- **Date**: 2026-08-29
- **Context**:
  สรุปรายเดือนและรายปีตัดสินช่อง ป/ก/ด/ร ด้วย `str_contains` บนชื่อไทยของประเภทการลา
  แล้วมีทางออกสุดท้ายว่า "อะไรที่ไม่เข้าเงื่อนไข ให้นับเป็นลากิจ" ผลคือลาพักผ่อนถูกนับ
  เป็นลากิจ และ "ลาไปช่วยเหลือภริยาที่คลอดบุตร" มีคำว่า "คลอด" จึงถูกนับเป็นลาคลอดบุตร
  ยอดรวมท้ายใบลงเวลาที่ส่งต่องานบุคคลผิดตั้งแต่ต้นทาง
- **Decision**:
  1. เพิ่มคอลัมน์ `leave_types.attendance_bucket` เป็นค่าที่กำหนดชัดเจน
     (`sick|personal|maternity|vacation|official|other`) และแก้ได้จากหน้าจัดการประเภทการลา
     ตามข้อห้าม hard-code ประเภทบุคลากร/ประเภทการลาใน `AGENTS.md`
  2. ค่าตั้งต้นผูกจาก `code_prefix` ซึ่งเป็นรหัสประจำประเภทที่ระบบใช้ออกเลขที่หนังสืออยู่แล้ว
     ไม่ใช่ชื่อที่แก้ได้ตลอดเวลา
  3. **เพิ่มช่อง "พักผ่อน" ในใบลงเวลา** แทนที่จะยัดลาพักผ่อนเข้าช่องลากิจหรือทิ้งไปเฉย ๆ
  4. ช่อง "ราชการ" ยังอยู่ตามแบบฟอร์มเดิม ปัจจุบันผูกกับลาเข้ารับการตรวจเลือก
     และลาไปปฏิบัติงานในองค์การระหว่างประเทศ ผู้ดูแลเปลี่ยนได้เอง
- **Consequences**:
  - ยอดในใบลงเวลาตรงกับจำนวนวันที่ตัดโควตาไปจริง เพราะใช้เกณฑ์นับวันชุดเดียวกัน
  - ประเภทการลาที่เพิ่มใหม่ภายหลังจะตกช่อง "อื่น ๆ" จนกว่าผู้ดูแลจะกำหนด — เห็นได้ชัด
    ดีกว่าถูกเดาเข้าช่องผิดเงียบ ๆ แบบเดิม

## ADR-LEAVE-DRAFT-01: ใบลาที่เข้าสู่กระบวนการแล้วลบไม่ได้ และเพิ่มสถานะ "ร่าง"

- **Status**: Accepted
- **Date**: 2026-08-29
- **Context**:
  เจ้าของระบบทักว่าใบลาที่ผ่านการพิจารณาแล้วไม่ควรลบได้ เพราะจะตรวจสอบย้อนหลังไม่ได้
  ตรวจแล้วพบว่าปัญหาหนักกว่านั้นสามชั้น:
  1. `LeaveRequestController::destroy()` **ไม่เขียน audit log เลยสักบรรทัด** ลบแล้วไม่มี
     ร่องรอยว่าใครลบ เมื่อไร เพราะอะไร ต่างจาก "เพิกถอนเอกสาร" ที่บังคับใส่เหตุผล
  2. ผู้ที่มีสิทธิ์ `leave.management.manage` ลบได้ทุกสถานะ และการลบยังคืนโควตาให้ด้วย
     ประวัติการใช้วันลาของคนคนหนึ่งจึงถูกลบเกลี้ยงได้ด้วยคลิกเดียว
  3. แม้แต่ใบที่ยังรออนุมัติ ถ้าผู้อนุมัติคนแรกเซ็นไปแล้ว การลบก็เท่ากับลบหลักฐานนั้น
- **Decision**:
  1. **ลบได้เฉพาะสถานะ `Draft` เท่านั้น** ทุกสถานะอื่นตอบ 422 พร้อมชี้ทางที่ถูกต้อง —
     ยังรออนุมัติใช้ "ยกเลิกใบลา" อนุมัติแล้วใช้ "เพิกถอนเอกสาร" ทั้งคู่เก็บใบลาไว้ใน
     ทะเบียนคุมการลาเสมอ · ตรวจสิทธิ์ก่อนตรวจสถานะ ผู้ไม่เกี่ยวข้องยังได้ 403 เหมือนเดิม
  2. **ตัดสิทธิ์เจ้าของใบลาที่เคยลบใบ Pending ของตนเองได้ภายใน 5 วัน** — ผลต่อผู้ใช้เหมือน
     "ยกเลิกใบลา" ทุกประการ (คืนวันที่กันไว้เท่ากัน) ต่างกันแค่อันหลังตรวจสอบย้อนหลังได้
  3. **เพิ่มสถานะ `Draft`** ซึ่งมีอยู่ใน enum ของ migration แรกตั้งแต่ต้นแต่ไม่เคยมีโค้ดใช้
     ร่างต้องไม่แตะสิ่งที่เป็นของทางราชการเลย: ไม่ออกเลขที่หนังสือ ไม่กันโควตา
     ไม่ผนึก snapshot สายอนุมัติ ไม่แจ้งเตือนผู้อนุมัติ และไม่เปิด workflow instance
     ทั้งหมดเกิดพร้อมกันตอนกด "ส่งอนุมัติ"
  4. ร่างเป็นของส่วนตัวจนกว่าจะส่ง — ผู้มีสิทธิ์ดูทั้งองค์กรก็ไม่เห็นร่างของคนอื่น
     และร่างไม่ปรากฏในทะเบียนคุมการลา
- **Consequences**:
  - **เลขที่หนังสือไม่ขาดช่วงอีกต่อไป** เดิมเลขถูกออกตอนสร้างใบลาและเป็นลำดับที่เดินหน้า
    อย่างเดียว ทุกครั้งที่มีคนยื่นผิดแล้วลบ เลขนั้นหายไปตลอดกาล ทะเบียนหนังสือราชการ
    จึงมีช่องว่างที่อธิบายกับผู้ตรวจสอบไม่ได้
  - ร่างคือสิ่งที่ทำให้กฎ "ห้ามลบ" อยู่ได้โดยไม่ทำให้ผู้ใช้ลำบาก — กรอกผิดก็ลบร่างทิ้งได้
    ไม่ต้องไปขอให้ผู้ดูแลลบให้ ซึ่งเป็นต้นเหตุที่ทำให้ต้องเปิดสิทธิ์ลบกว้าง ๆ ตั้งแต่แรก
  - ยุบการตรวจใบลาซ้ำช่วงเวลาให้เหลือที่เดียวใน `LeaveService` — ของเดิมใน controller
    ใช้เงื่อนไขคนละชุด ไม่ยกเว้น `Voided` ไม่รู้จัก `Draft` และไม่ล็อกแถว
