# INT-10 — PDF WORKSPACE

**Source of Truth:** `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md`
**Checkpoint:** INT-10 (ROADMAP บรรทัดที่ 11)
**วันที่:** 2026-09-04
**สถานะ:** ✅ ผ่าน Definition of Done

---

## DONE

พื้นที่ทำงานบนหน้าหนังสือ — วางตราประทับรับและข้อความ แล้วเรนเดอร์เป็นไฟล์จริง
**ที่ฝั่ง server เท่านั้น**

| ข้อบังคับ SoT §14 | สถานะ |
|---|---|
| zoom / เลื่อนหน้า / ลาก | ✅ `PdfWorkspace.vue` |
| **พิกัด normalized (PDF point)** | ✅ เก็บและส่งเป็น PDF point จากมุมซ้ายบน |
| ตราประทับรับ (receive stamp) | ✅ เติมข้อมูลจากทะเบียนรับของ INT-07 อัตโนมัติ |
| ข้อความ / วันที่ | ✅ |
| QR | ⏳ จองพื้นที่ไว้แล้ว (วาดกรอบ) — เนื้อหาจริงเป็นงานของ INT-12 |
| **Backend render final PDF** | ✅ `SarabanPdfRenderer` (FPDI + ฟอนต์ไทยชุดเดิม) |
| **ห้ามใช้ภาพจากเบราว์เซอร์** | ✅ **ไม่มี endpoint ใดรับภาพเลย** — พิสูจน์ที่ซอร์สด้วยเทสต์ |

### ทำไมพิกัดต้องเป็น PDF point ไม่ใช่พิกัดหน้าจอ

พิกัดหน้าจอขึ้นกับระดับซูมและขนาดจอของคนวาง — วางตราที่เครื่องจอใหญ่แล้วจะไปโผล่
คนละตำแหน่งเมื่อเรนเดอร์ หนังสือราชการฉบับเดียวกันจะออกมาไม่เหมือนกัน

หน้าเว็บจึงแปลงระยะที่ลากบนจอกลับเป็น PDF point ทันที (`delta / zoom`) และเก็บเป็น
`decimal(8,2)` ไม่ใช่ float เพื่อไม่ให้ตำแหน่งคลาดเคลื่อนตามการปัดเศษ

**เป็นหน่วยเดียวกับที่ `CoordinateConverter` และ `PdfOverlayRenderer` ของ
Document Engine เดิมใช้อยู่แล้ว** จึงไม่ต้องแปลงระบบพิกัดตอนเรนเดอร์

### การตัดสินใจ REUSE vs CREATE ของตัวเรนเดอร์

`PdfOverlayRenderer` เดิมวาดจาก `DocumentFieldMapping` ของ **แม่แบบที่ผู้ดูแลตั้งไว้ล่วงหน้า**
ส่วนงานสารบรรณวาดจาก **วัตถุที่ผู้ใช้ลากวางบนไฟล์ที่อัปโหลดเข้ามา** — แหล่งข้อมูล
คนละชนิดกันโดยสิ้นเชิง

**ตัดสิน: CREATE `SarabanPdfRenderer`** แต่ **REUSE กลไกทั้งหมด** — FPDI, ฟอนต์ไทย
(`document:install-fonts` ตัวเดิม), ระบบพิกัด PDF point ชุดเดียวกัน

เหตุผลที่ไม่ยัดสองแหล่งเข้าคลาสเดียว: `PdfOverlayRenderer` เป็นเส้นทางที่โมดูลการลา
และ Document Engine ใช้ออกเอกสารจริงอยู่ทุกวัน การเพิ่มสาขาให้มันรับข้อมูลอีกชนิด
คือการเอาเส้นทางที่ใช้งานอยู่ไปเสี่ยงโดยไม่จำเป็น

---

## CHANGED

### บั๊กจริงที่เทสต์เจอระหว่างทำ

ตอนแรกใช้ `authorize('update', $document)` กับ endpoint ประทับตรา ซึ่ง
`DocumentPolicy::update()` อนุญาต **เฉพาะฉบับร่าง**

เทสต์ "หนังสือที่ลงนามแล้วประทับตราไม่ได้" คืน 403 แทน 422 ทำให้ไปเจอว่า
**หนังสือรับ (สถานะ `registered`) ก็ประทับตราไม่ได้เช่นกัน** — ซึ่งเป็นงานหลัก
ของงานสารบรรณและเป็นเหตุผลที่มี PDF Workspace ตั้งแต่แรก

**แก้ด้วยการเพิ่ม `DocumentPolicy::stamp()` แยกออกมา:**

| กติกา | `update()` | `stamp()` |
|---|---|---|
| ความหมาย | แก้ **ข้อมูล** ของหนังสือ | ทำงานกับ **หน้ากระดาษ** |
| สถานะที่อนุญาต | ฉบับร่างเท่านั้น | ทุกสถานะยกเว้นลงนาม/ยกเลิกแล้ว |
| สิทธิ์ | `saraban.document.update` | `saraban.incoming.stamp` |

เพิ่มเทสต์ `test_a_registered_incoming_document_can_still_be_stamped` เพื่อล็อกเคสนี้ไว้

### Dependency ใหม่ — ตัวเดียวของทั้งโครงการ

`pdfjs-dist@6.3.289` ตามที่ `UI-COMPATIBILITY-MATRIX.md` §8 คาดไว้ล่วงหน้า

| ด่าน | ผล |
|---|---|
| `npm audit --audit-level=high` | ✅ **found 0 vulnerabilities** |
| Build | ✅ แยกเป็น chunk ของตัวเอง `pdf-CnYgHYaE.js` 431 kB (gzip 129 kB) |
| โหลดเมื่อไร | เฉพาะตอนเปิดหน้าพื้นที่ทำงาน (dynamic import) — ไม่กระทบเวลาโหลดหน้าอื่น |

---

## FILES

**เพิ่มใหม่ (7):**

```
backend/database/migrations/2026_09_04_160000_create_saraban_document_annotations.php
backend/app/Models/Saraban/DocumentAnnotation.php
backend/app/Services/Saraban/SarabanPdfRenderer.php
backend/app/Services/Saraban/AnnotationService.php
backend/app/Http/Controllers/Saraban/AnnotationController.php
backend/tests/Feature/SarabanPdfWorkspaceTest.php
frontend/src/components/saraban/PdfWorkspace.vue
frontend/src/views/saraban/DocumentWorkspaceView.vue
```

**แก้ไข:** `DocumentPolicy` (+`stamp()`), `DocumentService` (+`addVersionFromContents()`),
`Document` (+ความสัมพันธ์ทะเบียนรับ), `routes/api.php`, `router/index.js`,
`DocumentFormView.vue` (+ปุ่มเข้าพื้นที่ทำงาน), `MenuIntegrityTest` (ขึ้นทะเบียนเส้นทาง)

**ไม่แตะไฟล์ Core เลย** — `PdfOverlayRenderer`, `CoordinateConverter` และ Document Engine
ของเดิมไม่ถูกแก้แม้แต่บรรทัดเดียว

---

## MIGRATIONS

`2026_09_04_160000_create_saraban_document_annotations.php` — ตารางใหม่ล้วน

**ผูกกับ `document_version_id` ไม่ใช่ `document_id`** ตาม SoT §8 — ตราที่วางบนต้นฉบับ
ต้องไม่ย้ายตามไปอยู่บนฉบับแก้ไขที่มีเนื้อหาคนละหน้า

`down()` ถอนได้สะอาด

---

## TESTS

`SarabanPdfWorkspaceTest` — **11 กรณี / 50 assertions**

| กลุ่ม | ครอบคลุม |
|---|---|
| พิกัด | **บันทึกเป็น PDF point ตรงตามที่ส่งมา (402.75, 58.5) ไม่ถูกปัดทิ้ง** · บันทึกแบบแทนที่ทั้งชุด วัตถุที่ลบแล้วหายจริง |
| **ห้ามภาพจากเบราว์เซอร์** | **ตรวจซอร์สของตัวเรนเดอร์ว่าไม่มี `'image'`, `base64`, `Image(`** — ไม่มีเส้นทางใดนำภาพจากผู้ใช้ไปวางบน PDF |
| เรนเดอร์ | ได้ไฟล์ PDF จริง (`%PDF`) เป็นเวอร์ชันใหม่ · **ต้นฉบับยังอยู่และแฮชไม่เปลี่ยน** · เรียกตัวเรนเดอร์ตรง ๆ ได้ |
| ตราประทับรับ | **เนื้อหามาจากทะเบียนรับ ไม่ใช่ให้พิมพ์เอง** · เวลารับอยู่ในรูป HH:MM |
| สิทธิ์ | ต้องมี `saraban.incoming.stamp` · **หนังสือที่ลงนามแล้วประทับตราไม่ได้** · **หนังสือรับที่ลงทะเบียนแล้วประทับตราได้** · คนต่างหน่วยงานเปิดพื้นที่ทำงานไม่ได้ |
| ประวัติ | `annotated` + `stamped` เข้า audit กลาง |

เทสต์ใช้ **ไฟล์ PDF จริง** ที่ประกอบขึ้นตามรูปแบบ (xref ครบ) ไม่ใช่ stub —
เพราะประเด็นทั้งหมดของเช็คพอยต์นี้คือการเรนเดอร์ลงบนไฟล์จริง

---

## SECURITY

| ประเด็น | ผล |
|---|---|
| ภาพหน้าจอกลายเป็นหนังสือราชการ | ✅ เป็นไปไม่ได้ — ไม่มี endpoint รับภาพ และตัวเรนเดอร์ไม่มีโค้ดวางภาพ |
| ไฟล์รั่ว | ✅ ไฟล์ยังเสิร์ฟผ่าน `SecureFileController` เดิม · หน้าเว็บใช้ `fetchObjectUrl()` ซึ่งเรียก `/api/files/{path}` ไม่ชี้ `/storage` ตรง (SEC-001) |
| แก้เอกสารที่เป็นหลักฐาน | ✅ `DocumentPolicy::stamp()` ปิดเมื่อลงนาม/ยกเลิกแล้ว |
| สิทธิ์แยกจากการแก้ข้อมูล | ✅ `saraban.incoming.stamp` ≠ `saraban.document.update` |
| ขอบเขตหน่วยงาน | ✅ `stamp()` เรียก `view()` ต่อ จึงได้การตรวจขอบเขตของ INT-05 มาด้วย |
| ค่าพิกัดผิดปกติ | ✅ validate `min:0 max:5000` ทั้ง x/y/width/height |
| dependency ใหม่ | ✅ 0 advisories |

---

## REGRESSION

| รอบ | ผล |
|---|---|
| Baseline INT-09 | 823 passed · 0 failed |
| **หลัง INT-10** | ✅ **834 passed · 8 skipped · 0 failed** · 3033 assertions |
| Frontend | ✅ 70 passed · ESLint ✅ · vue-tsc ✅ · **build ✅** · `npm audit` ✅ |
| Pint | ✅ 544 files |

---

## UI-QA

| ข้อบังคับ SoT §2 | ผล |
|---|---|
| ใช้ UI kit เดิม | ✅ `AppBadge`, `AppButton`, `EmptyState`, `ErrorState`, `LoadingState` — **แถบเครื่องมือ zoom/หน้า ประกอบจาก `AppButton`+`AppBadge` ของเดิมทั้งหมด** |
| ไม่สร้าง UI kit ใหม่ | ✅ `PdfWorkspace` เป็น component เฉพาะงานสารบรรณตามที่ UI matrix §7.2 กำหนดให้อยู่ใน `components/saraban/` |
| Theme / tokens | ✅ ใช้ `#800000` เดิมสำหรับกรอบตราประทับ |
| Layout convention | ✅ `max-w-6xl mx-auto space-y-6` · การ์ด `rounded-2xl shadow-sm` |
| a11y | ✅ วัตถุมี `role="group"` + `aria-label` ภาษาไทย · ปุ่มลบมี `aria-label` |
| ภาษาไทย | ✅ ทั้งหมด |

หน้าจอบอกผู้ใช้ตรง ๆ ว่า *"ไฟล์ฉบับสมบูรณ์สร้างที่เครื่องแม่ข่าย ไม่ใช่ภาพจากหน้าจอ"*
และ *"ตำแหน่งที่บันทึกเป็นพิกัดบนหน้ากระดาษจริง จึงออกมาตรงกันทุกเครื่องไม่ว่าจะย่อขยายเท่าใด"*

> ยังไม่ได้เปิดเบราว์เซอร์ตรวจด้วยตาด้วยเหตุผลเดิม (ต้องกรอกรหัสผ่าน) — แต่รอบนี้มี
> หลักฐานเพิ่มคือ **production build ผ่านและ pdfjs ถูกแยก chunk จริง**

---

## EVIDENCE

| หลักฐาน | ที่มา / ผล |
|---|---|
| **ห้ามภาพจากเบราว์เซอร์** | `test_the_workspace_never_accepts_an_image_from_the_browser` — ตรวจซอร์สตัวเรนเดอร์ |
| พิกัดคงค่าเดิม | `test_coordinates_are_stored_as_pdf_points_exactly_as_sent` |
| เรนเดอร์ที่ server ได้ PDF จริง | `test_rendering_produces_a_real_pdf_as_a_new_version_without_touching_the_original` |
| ต้นฉบับไม่ถูกแตะ | เทียบแฮชของต้นฉบับก่อน/หลังเรนเดอร์ |
| บั๊กที่เจอและแก้ | `test_a_registered_incoming_document_can_still_be_stamped` |
| dependency ปลอดภัย | `npm audit --audit-level=high` → 0 vulnerabilities |
| build ผ่าน | `pdf-CnYgHYaE.js` 431 kB แยก chunk |
| เทสต์เช็คพอยต์ | **11 passed (50 assertions)** |
| Regression | **834 passed / 0 failed** · frontend **70 passed** |

---

## RISKS

| รหัส | ความเสี่ยง | ระดับ | สถานะ |
|---|---|---|---|
| RISK-27 **(ใหม่)** | ยังไม่มีการปรับขนาด (resize) วัตถุด้วยการลากมุม — ปรับได้เฉพาะตำแหน่ง | ต่ำ | ขนาดตราประทับรับเป็นค่ามาตรฐานตามระเบียบอยู่แล้ว · เพิ่ม handle ปรับขนาดได้ภายหลังโดยไม่กระทบโครงสร้างข้อมูล (มีคอลัมน์ width/height รองรับแล้ว) |
| RISK-28 **(ใหม่)** | ลายมือ/ลายเซ็นยังวางไม่ได้ในเช็คพอยต์นี้ | — | ตั้งใจ — เป็นงานของ **INT-11 (E-Kasian + Signature)** ตาม roadmap · `signature_pad` มีอยู่ใน dependency แล้ว |
| RISK-19 (INT-05) | หน้าจอไม่มีตัวแสดง PDF | ✅ **ปิด** |

**ไม่มี blocker สำหรับ INT-11**

---

## NEXT

**→ INT-11 — E-Kasian + Signature + Step-up Re-auth**

1. เกษียนหนังสือ: พิมพ์ + ลายมือ (stylus/touch/mouse) — **REUSE `signature_pad` ที่มีอยู่แล้ว**
2. หลักฐานการลงนามครบตาม SoT §14: ผู้ลงนาม ตำแหน่ง อำนาจ session เวลา วิธี เวอร์ชัน hash IP UA
3. **SEC-GAP-01 — Step-up re-auth** (SoT §24) ต้องทำที่ **Core Identity** ไม่ใช่ในสารบรรณ
   · เป็นการแก้ที่กระทบทุกโมดูล ต้องมี feature flag ให้ย้อนได้แบบเดียวกับ
   `USE_NEW_PERMISSION_ENGINE` และ regression เต็ม
