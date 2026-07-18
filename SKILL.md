# SKILL.md — งานสารบัญราชการไทย (e-Saraban Domain Patterns)

> อ่านไฟล์นี้เมื่อทำงานเกี่ยวกับ: เลขที่หนังสือ, workflow เดินเรื่อง, e-signature, พิมพ์ฟอร์มราชการ, ชั้นความลับ/ความเร็ว

---

## 1. รูปแบบเลขที่หนังสือราชการ

รูปแบบมาตรฐาน: `[รหัสหน่วยงาน] [รหัสฝ่าย]/[เลขที่ลำดับ]`
ตัวอย่างจริง: `ศธ 0584.1/ว0123`

- `ศธ` = กระทรวงศึกษาธิการ, `0584` = รหัสวิทยาลัย (ตัวอย่าง ต้องยืนยันรหัสจริงของ DMTC), `.1` = รหัสฝ่ายย่อย
- `ว` นำหน้าเลข = หนังสือเวียน (optional prefix ตามประเภท)
- เลขที่ลำดับ **ห้าม** มี leading zero ตัดหาย เช่น `0123` ไม่ใช่ `123` (ตามธรรมเนียมราชการเก็บ 4 หลัก)

### Pattern การ generate แบบ atomic (ป้องกันเลขซ้ำ)
```php
DB::transaction(function () use ($departmentId, $typeId, $year) {
    $sequence = DocumentSequence::where([
        'department_id' => $departmentId,
        'document_type_id' => $typeId,
        'year_be' => $year, // เก็บเป็น พ.ศ. ตรงๆ เพื่อ query ง่าย
    ])->lockForUpdate()->first();

    if (!$sequence) {
        $sequence = DocumentSequence::create([...  'running_number' => 0]);
    }

    $sequence->increment('running_number');
    return $sequence->running_number;
});
```
**ห้าม** generate เลขนอก transaction หรือใช้ `count()` ของตาราง documents มานับ (จะชนกันได้เมื่อมี concurrent request หรือมีการลบ/ยกเลิกเอกสารระหว่างทาง)

---

## 2. การแปลงปี พ.ศ. ↔ ค.ศ.

```php
// Helper แนะนำ
function toBuddhistYear(int $christianYear): int {
    return $christianYear + 543;
}

function toChristianYear(int $buddhistYear): int {
    return $buddhistYear - 543;
}
```
- เก็บวันที่ใน DB เป็น `DATE`/`DATETIME` มาตรฐาน (ค.ศ., ISO 8601) เสมอ
- แปลงเป็น พ.ศ. เฉพาะชั้น presentation (Vue component / PDF template) เท่านั้น
- รูปแบบวันที่แสดงผลมาตรฐาน: `12 กรกฎาคม 2569` (วัน เดือนไทยเต็ม ปี พ.ศ.)
- ปีงบประมาณไทย: 1 ตุลาคม – 30 กันยายน — ถ้าระบบอิงปีงบประมาณ ปีงบ 2569 = 1 ต.ค. 2568 – 30 ก.ย. 2569 (ต้องระวังเวลาคำนวณ boundary)

---

## 3. ชั้นความลับและชั้นความเร็ว

| ชั้นความลับ | value ในระบบ | สิทธิ์เข้าถึง |
|---|---|---|
| ปกติ | `normal` | ทุกคนที่มี permission ดูเอกสารทั่วไป |
| ลับ | `secret` | เฉพาะ role ที่ระบุ + ผู้เกี่ยวข้องในเส้นทางเดินเรื่อง |
| ลับมาก | `top_secret` | เฉพาะผู้บริหาร + ผู้ได้รับมอบหมายเท่านั้น |
| ลับที่สุด | `most_secret` | เฉพาะผู้อำนวยการ/ผู้ได้รับมอบอำนาจเฉพาะเรื่อง |

| ชั้นความเร็ว | value | SLA แจ้งเตือน |
|---|---|---|
| ปกติ | `normal` | ตาม due_date ปกติ |
| ด่วน | `urgent` | แจ้งเตือนถี่ขึ้น (1 วันก่อนครบกำหนด) |
| ด่วนมาก | `very_urgent` | แจ้งเตือนทันทีที่มอบหมาย + ทุกวัน |
| ด่วนที่สุด | `most_urgent` | แจ้งเตือน real-time (push/LINE) |

**Pattern การเช็คสิทธิ์:** ใช้ Policy class (`DocumentPolicy@view`) เช็คร่วมกันทั้ง (1) permission ของ role และ (2) ว่า user เป็นผู้เกี่ยวข้องในเส้นทางเดินเรื่องของเอกสารนั้นหรือไม่ ไม่ใช้แค่ permission กว้างๆ อย่างเดียวสำหรับหนังสือชั้นความลับขึ้นไป

---

## 4. Workflow State Machine Pattern

```
draft
  → pending_review        (ส่งตรวจ)
  → pending_approval       (เสนอผู้มีอำนาจ)
  → approved_pending_signature
  → signed                (ลงนามแล้ว)
  → dispatched             (จัดส่งแล้ว / ออกเลขที่ส่งแล้ว)
  → archived                (เก็บเข้าแฟ้ม)

  [ทุกสถานะก่อน signed] → rejected → กลับไป draft พร้อม comment บังคับ
```

กฎสำคัญ:
- ทุก transition ต้องบันทึกลง `saraban_document_workflow_steps` (from_status, to_status, actor_id, comment, timestamp) — **ห้าม update สถานะตรงๆ โดยไม่ผ่าน log**
- ห้าม skip step (เช่น จาก `draft` ตรงไป `signed`) เว้นแต่เป็น role ที่มี permission `saraban.workflow.override` (ควรใช้เฉพาะกรณีฉุกเฉินและต้อง log เหตุผล)
- เอกสารที่ `signed` แล้ว **ห้ามแก้ไขเนื้อหา** — ถ้าต้องแก้ต้องสร้างฉบับใหม่อ้างอิงฉบับเดิม (versioning)

---

## 5. e-Signature Pattern

ไม่ใช่ digital certificate PKI เต็มรูปแบบ (เว้นแต่วิทยาลัยมี CA รองรับ) แต่ต้องมีองค์ประกอบขั้นต่ำตาม พ.ร.บ. ธุรกรรมทางอิเล็กทรอนิกส์:
- ตัวตนผู้ลงนามยืนยันได้ (login + อาจเพิ่ม OTP/PIN สำหรับหนังสือสำคัญ)
- เจตนาลงนามชัดเจน (ปุ่ม "ยืนยันลงนาม" แยกจากปุ่มอื่น พร้อมข้อความยืนยัน)
- บันทึก hash ของเนื้อหาเอกสาร ณ เวลาที่ลงนาม (SHA-256) เพื่อพิสูจน์ว่าเนื้อหาไม่ถูกแก้ไขหลังลงนาม
- Timestamp + IP + User agent

```php
$signatureHash = hash('sha256', $document->content . $document->version . now()->timestamp);
```

QR verify: สร้าง URL public เช่น `/verify/{document_uuid}` แสดงแค่ข้อมูลยืนยันตัวตน (เลขที่, วันที่, ผู้ลงนาม, hash ตรงกันหรือไม่) **ห้ามแสดงเนื้อหาเอกสารเต็มถ้าเป็นหนังสือชั้นความลับ**

---

## 6. พิมพ์ฟอร์มราชการมาตรฐาน

ข้อกำหนดฟอร์มตามระเบียบสารบรรณ:
- ฟอนต์: **TH Sarabun New** ขนาด 16pt (เนื้อหา), 18-20pt (หัวเรื่อง)
- ระยะขอบ: ซ้าย 3 ซม., ขวา/บน/ล่าง 2 ซม. (โดยประมาณ — ควรตรวจสอบระเบียบฉบับล่าสุดหรือแบบฟอร์มที่วิทยาลัยใช้จริง)
- ตราครุฑ: ขนาด 3 ซม. อยู่กึ่งกลางด้านบนของหนังสือภายนอก/หนังสือประทับตรา
- โครงสร้างหนังสือภายนอก: ที่ / ส่วนราชการเจ้าของเรื่อง / วันที่ / เรื่อง / เรียน / อ้างถึง (ถ้ามี) / สิ่งที่ส่งมาด้วย (ถ้ามี) / เนื้อความ / ลงชื่อ / ตำแหน่ง

ใช้ dompdf หรือ spatie/laravel-pdf render จาก Blade template แยกตามชนิดหนังสือ (`resources/views/saraban/pdf/external.blade.php`, `internal.blade.php`, ฯลฯ)

---

## 7. ข้อผิดพลาดที่พบบ่อย (Pitfalls)

- ❌ ใช้ `count()` เอกสารมา generate เลขที่ → ชนกันเมื่อมี concurrent request หรือมีการลบเอกสาร
- ❌ เก็บปี พ.ศ. ปนกับ ค.ศ. ใน field เดียวกันแบบไม่ชัดเจน → query ปีผิดพลาด แนะนำแยก field `year_be` ชัดเจนสำหรับ sequence
- ❌ ลืมบันทึก workflow step log ตอน reject → ตรวจสอบย้อนหลังไม่ได้ว่าใครตีกลับตอนไหนเพราะอะไร
- ❌ เปิดสิทธิ์ดูหนังสือชั้นความลับด้วย permission กว้างเกินไป (เช่น `saraban.document.view` ครอบคลุมทุกชั้นความลับ) → ต้องแยก permission ระดับชั้นความลับชัดเจน
- ❌ แก้เนื้อหาเอกสารหลังลงนามแล้วโดยไม่สร้าง version ใหม่ → hash ไม่ตรง ทำให้ QR verify ล้มเหลว/เอกสารเสียความน่าเชื่อถือทางกฎหมาย
