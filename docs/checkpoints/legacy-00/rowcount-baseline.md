# ROW COUNT BASELINE — LEGACY-00

**วันที่:** 2026-08-12 · **Database:** `eleave` @ `eleave_db` (MySQL 8.0.46)
**วิธีนับ:** `SELECT COUNT(*)` จริงทุกตาราง (ไม่ใช่ค่าประมาณจาก `information_schema.tables.table_rows`)
**รวมทั้งหมด: 298 แถว ใน 55 ตาราง**

## ตารางที่มีข้อมูล

| ตาราง | แถว | หมายเหตุ |
|---|---:|---|
| `migrations` | 91 | ครบตามจำนวน migration file |
| `leave_policies` | 49 | นโยบายการลาแยกตามประเภทบุคลากร × ประเภทลา |
| `role_has_permissions` | 27 | |
| `jobs` | 16 | ⚠️ **งานค้างในคิวที่ไม่เคยถูกประมวลผล** — ดู §2 |
| `permissions` | 15 | |
| `leave_types` | 12 | ประเภทลาราชการ |
| `leave_balances` | 11 | |
| `personal_access_tokens` | 9 | token ที่ยังใช้งานได้ (ไม่มีวันหมดอายุ — SEC-012) |
| `users` | 7 | |
| `model_has_roles` | 7 | |
| `leave_approvals` | 6 | ทั้งหมดสถานะ `Pending` |
| `user_profiles` | 6 | ผู้ใช้ 7 คน มีโปรไฟล์ 6 → มี 1 คนไม่มีโปรไฟล์ |
| `roles` | 6 | |
| `personal_data_access_logs` | 6 | PDPA access log ทำงานจริง |
| `leave_audit_logs` | 5 | |
| `leave_action_logs` | 4 | |
| `personnel_types` | 4 | |
| `setting_audit_logs` | 4 | |
| `approval_steps` | 3 | สายอนุมัติ 3 ขั้น |
| `leaves` | 3 | ⚠️ **ทั้ง 3 ใบถูก soft-delete หมด** — ดู §3 |
| `settings` | 3 | |
| `departments` | 2 | |
| `attendances` | 1 | |
| `leave_document_sequences` | 1 | |

## ตารางที่ว่างเปล่า (31 ตาราง)

`cache`, `cache_locks`, `document_templates`, `failed_jobs`, `fiscal_years`, `holidays`, `job_batches`,
`leave_adjustments`, `leave_evidences`, `menu_permissions`, `menus`, `model_has_permissions`, `notifications`,
`password_reset_tokens`, `sessions`, `user_delegations`, `work_calendar_exceptions`,
`saraban_audit_logs`, `saraban_document_attachments`, `saraban_document_circular_recipients`,
`saraban_document_circulars`, `saraban_document_incoming`, `saraban_document_outgoing`,
`saraban_document_receivers`, `saraban_document_sequences`, `saraban_document_signatures`,
`saraban_document_types`, `saraban_document_user_states`, `saraban_document_workflow_steps`,
`saraban_document_workflow_templates`, `saraban_documents`

---

## 1. ข้อสรุปสำคัญ: ระบบนี้ยังไม่ได้ใช้งานจริง

7 ผู้ใช้ · 3 ใบลา (ถูกลบหมด) · 1 รายการลงเวลา · 0 หนังสือราชการ · 0 ปีงบประมาณ · 0 วันหยุด · 0 เมนู

**ตอบ Open Question #3 ของ Assessment ได้แล้ว: นี่คือระบบ development/pilot ไม่ใช่ production ที่มีข้อมูลบุคลากรจริง**

ผลต่อการประเมินความเสี่ยง:

| เดิม (สมมติว่าเป็น production) | หลังทราบข้อมูลจริง |
|---|---|
| ช่องโหว่ SEC-001 ทำให้ลายเซ็นบุคลากรทั้งองค์กรรั่ว | รั่วจริงแต่กระทบ **11 ลายเซ็น + 1 ภาพลงเวลา + 1 ใบรับรอง** ของผู้ใช้ทดสอบ |
| การ migrate มีความเสี่ยงข้อมูลสูญหายสูงมาก | ความเสี่ยงต่ำลงมาก — ข้อมูลน้อยและกู้คืนได้ทั้งหมด |
| ต้องทำ dual-write/shadow-run อย่างเข้มงวด | ยังควรทำ แต่ **ต้นทุนความผิดพลาดต่ำ ตอนนี้คือช่วงเวลาที่ดีที่สุดในการ refactor** |

> **ข้อเสนอ:** ควรแก้ P0 ทั้งหมดให้เสร็จ **ก่อน** นำเข้าข้อมูลบุคลากรจริง — ถ้านำเข้าก่อนแก้
> ต้นทุนของทุกช่องโหว่จะเพิ่มขึ้นทันทีและกลายเป็นเหตุละเมิดข้อมูลส่วนบุคคลตาม พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล

## 2. งานค้างในคิว 16 รายการ — พิสูจน์ NOTIFY-004 แล้ว

```
queue   : default
class   : App\Notifications\LeaveRequested   × 16
oldest  : 2026-08-09 14:10:00
newest  : 2026-08-11 00:57:52
```

การแจ้งเตือน "มีใบลาใหม่รออนุมัติ" **ค้างในตาราง `jobs` มา 2 วันโดยไม่เคยถูกส่ง** เพราะไม่มี
`queue:work` container ใน `docker-compose.yml` — ยืนยันข้อสรุป NOTIFY-004 / DEPLOY-003 ด้วยหลักฐานจากข้อมูลจริง

`failed_jobs` = 0 → ไม่ใช่กรณี job ล้มเหลว แต่คือ **ไม่เคยถูกหยิบไปทำเลย**

## 3. ใบลาทั้ง 3 ใบถูก soft-delete

| id | user_id | status | document_number | deleted_at |
|---:|---:|---|---|---|
| 1 | 1 | Pending | `ลป.-2569-00001` | 2026-08-09 21:44:30 |
| 2 | 2 | Pending | `ลป.-2569-00002` | 2026-08-10 23:21:04 |
| 3 | 2 | Cancelled | `ลป.-2569-00003` | 2026-08-11 22:38:05 |

`voided_at` = NULL ทั้งหมด → ยืนยันว่าฟีเจอร์ void ไม่เคยทำงานสำเร็จ (สอดคล้องกับ DB-RISK-007)

**ตรวจสอบการเข้ารหัสอักขระไทย:** `HEX(document_number)` = `E0B8A5E0B89B2E...` ซึ่งคือ UTF-8 ของ `ลป.`
ที่ถูกต้อง — อักขระ `??` ที่เห็นตอนแรกเป็นข้อจำกัดของ console บน Windows **ไม่ใช่ข้อมูลเสียหาย**

## 4. ความไม่สอดคล้องอื่นที่พบจากข้อมูล (บันทึกไว้ ยังไม่แก้)

| ประเด็น | หลักฐาน | ผลกระทบ |
|---|---|---|
| `fiscal_years` = 0 แถว | ทั้งที่ `LeavePolicyService` และ `LeaveController::balances` เรียก `FiscalYear::current()` | ยอดวันลาคงเหลืออาจคำนวณจากปีที่ fallback ไม่ตรงกับที่ตั้งใจ |
| `menus` = 0 แถว | migration `2026_08_09_030000_add_governance_menus` รันแล้ว และมี `MenuSeeder` | sidebar ที่ generate จาก `menus` อาจว่างเปล่า |
| `document_templates` = 0 แถว | `Admin\DocumentTemplateController::seedDefaults` ไม่เคยถูกเรียก | การพิมพ์แบบฟอร์ม fallback ไปที่ blade view |
| `saraban_document_types` = 0 แถว | `SarabanDocumentTypeSeeder` ไม่เคยรัน | โมดูลสารบรรณสร้างหนังสือไม่ได้ (ตอบ Open Question #5: **ยังไม่เปิดใช้จริง และยังไม่มีเอกสารลับในระบบ**) |
| `model_has_permissions` = 0 แถว | | สิทธิ์ทั้งหมดมาจาก role ยังไม่มีใครได้ direct permission |
| `users` 7 คน แต่ `user_profiles` 6 | | มีผู้ใช้ 1 คนที่ `$user->profile` เป็น null — โค้ดหลายจุดเรียก `$user->profile->department_id` แบบไม่ป้องกัน null |

## 5. ไฟล์หลักฐาน

- `rowcount-raw.txt` — ผลนับดิบทั้ง 55 ตาราง
- `restore-verification.txt` — เปรียบเทียบ row count ระหว่าง production กับสำเนาที่ restore
