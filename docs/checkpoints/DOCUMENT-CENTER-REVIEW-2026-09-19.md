# วิเคราะห์ศูนย์จัดการเอกสาร — 2026-09-19

ขอบเขต: ตรวจ source ของ `/admin/document-center`, API, model, resolver และชุดทดสอบที่เกี่ยวข้อง ไม่แก้ application code, schema, migration หรือข้อมูลใช้งานจริง ไม่ deploy ข้อค้นพบด้านพฤติกรรมด้านล่างมาจาก static review ไม่ใช่การทดลองโจมตีระบบจริง

## ข้อค้นพบที่ควรแก้

### DC-01 · P1 · รายการและดาวน์โหลดเอกสารไม่ตรวจสิทธิ์ต่อเอกสารต้นทาง

- `backend/app/Http/Controllers/Admin/DocumentEngineController.php:968` ดึง DocumentGeneratedFile ทุกเจ้าของและคืน model เต็ม รวม snapshot และ file_path; model ไม่มี hidden หรือ global scope กรองเจ้าของ
- `:980` ดาวน์โหลดตาม file ID โดยตรวจเพียงไฟล์มีอยู่ ส่วน `backend/routes/api.php:477` ตรวจสิทธิ์ document.final.download หรือ system.setting.manage ไม่มีการตรวจ source_type/source_id กับ policy ของต้นทาง
- สิทธิ์ดูแม่แบบ (`routes/api.php:415`) จึงอ่าน snapshot ของเอกสารได้ แม้ไม่มีสิทธิ์ดาวน์โหลด; snapshot ของใบลามีชื่อ เหตุผล เบอร์โทร ที่อยู่ และข้อมูลผู้อนุมัติ (`Providers/LeaveRequestDataProvider.php`)
- ควรแยกสิทธิ์บริหารแม่แบบจากสิทธิ์อ่านเอกสารจริง กรอง query ตาม scope และตรวจ resource policy ก่อนดาวน์โหลด คืนเฉพาะ metadata ที่หน้ารายการต้องใช้
- เทสต์ IDOR ใน `DocumentEngineTest:519` ตรวจ `/leaves/{id}/pdf` ไม่ครอบช่องทาง generated นี้ ต้องเพิ่ม role × scope × source tests รวม explicit deny และการไม่คืน snapshot

### DC-02 · P1 · พรีวิว HTML รับโค้ด Blade จากผู้ดูแลแล้วประมวลผลบนเซิร์ฟเวอร์

- `DocumentEngineController.php:221` ตรวจ content_html เพียง required/string แล้วส่งตรงเข้า `Blade::render` ที่บรรทัด 230 ผู้มี document.template.manage เรียกได้
- Blade รองรับ PHP expression/directive จริง ตรวจจาก vendor `CompilesRawPhp.php:13`; จึงเป็นการมอบความสามารถรันโค้ด PHP ให้สิทธิ์จัดการแม่แบบ ไม่ใช่เพียงแก้ข้อความหรือรูปลักษณ์
- เนื้อหาที่บันทึกยังถูกใช้ผ่าน Blade ใน `LeaveDocumentTemplateResolver.php:134`
- ควรเปลี่ยนแม่แบบที่ผู้ใช้แก้ได้เป็นระบบแทนค่าที่จำกัดตัวแปร/เงื่อนไขอย่างชัดเจน แยก trusted Blade ของโครงการออกจากเนื้อหาที่รับผ่าน API และวางแผนรองรับแม่แบบเดิม ไม่แก้ด้วยการบล็อกเฉพาะคำว่า @php
- ต้องมี negative tests สำหรับ PHP directives และ expressions; ไม่ได้ส่ง payload รันโค้ดหรืออ่าน secrets ในงานวิเคราะห์นี้

### DC-03 · P1 · การแก้/ลบกติกาผูกแม่แบบทำลายประวัติ effective-dated

- `DocumentEngineController.php:933` ใช้ updateOrCreate กับ ID เดิม; `:958` ลบแถวจริง แม้มี effective_from/effective_to
- `TemplateResolver.php:25` ใช้ช่วงเวลาของกติกาในการเลือกแม่แบบ เมื่อแก้ทับหรือลบจึงไม่เหลือกติกาเดิมให้ตรวจย้อนวัน รวมถึง audit การลบไม่ได้เก็บค่าก่อนลบ
- ควรปิดช่วงมีผลเดิมและออกกติกาใหม่ พร้อม audit before/after; จำกัดการลบจริงเฉพาะรายการที่ยังไม่เคยมีผลตามนโยบาย
- เอกสารที่ pin เวอร์ชันหรือเก็บ PDF แล้วมีการป้องกันแยกต่างหาก ข้อนี้ไม่ได้หมายความว่าไฟล์เก่าทุกฉบับเปลี่ยนตามทันที

### DC-04 · P2 · เอกสารลำดับที่ 31 เป็นต้นไปเข้าถึงจากหน้ารายการไม่ได้

- API `generatedFiles()` ใช้ paginate(30) แต่ `DocumentCenterView.vue:801` เก็บเพียง data ทิ้งข้อมูล pagination และแท็บบรรทัด 431–464 ไม่มีปุ่มเปลี่ยนหน้า
- ควรต่อ pagination จริง เพิ่มค้นหา/กรองตามประเภทเอกสาร สถานะ และช่วงวันที่; ทดสอบด้วยอย่างน้อย 31 รายการ

### DC-05 · P2 · หมวดแบบฟอร์มการลารวมแบบฟอร์มของโมดูลอื่น

- `DocumentCenterView.vue:716` ใช้ module === 'leave' OR document_type === 'FORM'; แบบฟอร์มทั่วไปหรือแบบฟอร์มสารบรรณที่มีชนิด FORM จึงเข้าแท็บการลาด้วย และถูกตัดจากหมวดทั่วไป
- ควรแยกมิติ module ออกจาก document_type และใช้ค่า module ที่ระบบประกาศจริง; เพิ่ม fixture สำหรับ leave FORM, saraban FORM และ admin FORM

### DC-06 · P2 · พรีวิว HTML ไม่ใช้กติกาหน้ากระดาษชุดเดียวกับการพิมพ์จริง

- `DocumentCenterView.vue:1004` ส่งเฉพาะ HTML/paper_size/orientation; `previewContent()` ใช้ Pdf::loadHTML โดยตรง
- การพิมพ์จริงใน `LeaveDocumentTemplateResolver::render()` ต่อท้าย pageStyle($version) ซึ่งใช้ margin และค่าฟอนต์ของระบบ แต่พรีวิวไม่ผ่านขั้นตอนนี้
- เมื่อค่าที่เก็บต่างจาก CSS ใน HTML พรีวิวกับเอกสารจริงอาจมีระยะขอบ การตัดบรรทัด และจำนวนหน้าต่างกัน ควรใช้ renderer และ page style เดียวกันพร้อมทดสอบค่าระยะขอบที่ต่างจากค่าเริ่มต้น

## ข้อเสนอปรับปรุงและความเสี่ยงที่ควรตรวจเพิ่ม

- หน้า DocumentCenter ไม่มีการตรวจสิทธิ์รายปุ่ม แม้ API แยก view/manage/download: ผู้ดูอย่างเดียวเห็นปุ่มแก้ไข ลบ คืนค่า และดาวน์โหลดที่กดแล้วอาจได้ 403 ควรแสดงเฉพาะปุ่มตามสิทธิ์ โดยคงด่านหลังบ้าน
- loadAll() โหลด 8 API ด้วย Promise.all ทุกครั้ง หากหนึ่งคำขอล้มเหลวจะไม่อัปเดตข้อมูลทั้งหมด ควรโหลดตามแท็บและมี retry/error state ต่อส่วน
- รายการเอกสารใช้ source_type และ numeric ID แทนชื่อไทย/เลขหนังสือ และแถบสถานะ engine แสดง ID ประเภทการลาและชื่อตัวแปร environment ควรใช้ชื่อที่ผู้ดูแลเข้าใจได้
- bindings() ยังประกาศ entity เฉพาะ LEAVE และฟอร์มผูกแม่แบบอิง LEAVE โดยตรง การเป็นศูนย์กลางข้ามโมดูลยังไม่ครบ ควรประเมิน registry/provider ก่อนเพิ่มโมดูล
- createVersion() ใช้ max(version)+1 โดยไม่มี lock และ publish/saveMappings ตรวจสถานะก่อน transaction โดยไม่ lock version ร่วมกัน ควรเพิ่ม concurrency tests บน MySQL ก่อนสรุปผลกระทบจริง
- publishVersion() อนุญาต RETIRED กลับเป็น ACTIVE และเขียน effective_from ใหม่; ควรใช้การคัดลอกเป็นเวอร์ชันใหม่สำหรับ rollback เพื่อรักษาประวัติ

## สิ่งที่มีดีแล้ว

มี private storage, การ pin เวอร์ชัน, revision/snapshot, audit หลายจุด, preflight ก่อนเผยแพร่ และเทสต์ป้องกันแก้/ลบเวอร์ชันที่ใช้งานแล้ว จึงควรแก้เป็นจุดบนโครงสร้างเดิม ไม่จำเป็นต้องรื้อศูนย์เอกสารทั้งหมด

## การตรวจสอบ

| รายการ | ผล |
|---|---|
| Backend Pint --test | ผ่าน 769 files |
| PHPStan | ไม่ผ่าน 2 errors ที่ LeaveQueryController.php:231 และ :235 (undefined relationship properties user/type); ไฟล์นี้มีการแก้ค้างอยู่ก่อนเริ่มงานวิเคราะห์ ไม่ใช่ไฟล์ที่แก้ในงานนี้ |
| Backend php artisan test --compact --filter=Document | 211 passed / 1 failed / 915 assertions; ข้อที่ล้มคือ DocumentFilenameTest เนื่องจากเขียน storage/fonts/installed-fonts.json บน host mount ได้ Input/output error |
| รัน DocumentFilenameTest ซ้ำโดยแยก font cache เป็น tmpfs ใน container | 6 passed / 14 assertions; รวมข้อที่ล้มก่อนหน้า โดยไม่แก้โค้ดหรือข้าม assertion |
| Frontend ESLint / vue-tsc | ผ่าน |
| Frontend Vitest | 213 passed / 33 files; มีคำเตือน jsdom canvas getContext |
| Frontend Vite build | ผ่าน |

Backend รันใน container ชั่วคราว image e-le-backend, network none, mount repository และ SQLite :memory: ตาม phpunit.xml ไม่ใช้ฐานข้อมูลจริง ไม่ได้รัน backend suite ทั้งระบบหรือทดสอบ concurrency บน MySQL และไม่ได้ตรวจ UI ผ่านเบราว์เซอร์/เทียบ PDF ด้วยสายตา งานนี้ไม่ใช่การรับรอง layout หรือความปลอดภัยครบทุกเส้นทาง

การผ่านเทสต์เดิมไม่เท่ากับการปิดข้อค้นพบข้างต้นซึ่งยังไม่ได้เพิ่ม regression tests หรือแก้โค้ด ไม่มีการนำยอดเทสต์ที่รันซ้ำไปบวกเป็นจำนวนเทสต์ใหม่

## ลำดับดำเนินการ

1. ปิด DC-01/DC-02 และเพิ่ม negative authorization/template execution tests
2. รักษาประวัติ DC-03 และทดสอบ rollback/concurrent publish
3. แก้ DC-04 ถึง DC-06 พร้อมเทสต์พฤติกรรมหน้าจอและการพิมพ์
4. ปรับสิทธิ์รายปุ่ม การโหลดตามแท็บ และรองรับโมดูลผ่าน registry

ไม่มี architectural decision ใหม่ที่นำมาใช้ในงานนี้ ข้อเสนอเปลี่ยน template renderer ยังเป็นข้อเสนอ ไม่ใช่การอนุมัติ migration หรือเปลี่ยนสถาปัตยกรรม
