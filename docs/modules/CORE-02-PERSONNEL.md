# CORE-02 Personnel (REBUILD-2569)

อ้างอิง: `REBUILD-PLAN-2569.md` §3.2, §3.5 · ต้นแบบหน้า 3, 4, 5, 12 · เฟส R-02, R-03, R-06

## Schema

### `personnel_types`
code, name, has_license (bool), has_contract_end (bool), is_active
seed: ข้าราชการครู, ครูผู้ช่วย, พนักงานราชการ, ครูอัตราจ้าง, ลูกจ้างประจำ, ลูกจ้างชั่วคราว, เจ้าหน้าที่ตามสัญญา/โครงการ, บุคลากรทางการศึกษาอื่น ม.38 ค.(2)

### `personnel`
| คอลัมน์ | ชนิด | ชั้น |
|---|---|---|
| uuid | char(36) unique | — |
| personnel_code | varchar(40) unique | internal |
| title, first_name, last_name, first_name_en, last_name_en | varchar | public |
| personnel_type_id | FK | public |
| academic_standing | varchar null | internal (วิทยฐานะ) |
| status | enum `pending`,`active`,`study_leave`,`seconded`,`suspended`,`separated` | internal |
| started_at, separated_at, separation_reason | date/varchar | internal |
| photo_path | varchar null | public |

### `personnel_profiles` (1:1)
| คอลัมน์ | ชั้น | เก็บ |
|---|---|---|
| email_work, phone_mobile | internal | plain |
| birth_date | confidential | plain |
| emergency_contact_name, emergency_contact_phone, emergency_contact_relation | confidential | plain |
| national_id | sensitive | **encrypted** + `national_id_hash` (sha256 + app key) สำหรับค้นหา |
| address_registered, address_current | sensitive | encrypted |
| bank_name, bank_account | sensitive | encrypted |
| health_notes | sensitive | encrypted · เก็บเฉพาะเมื่อจำเป็นต่อการลาหรือสวัสดิการ |

**ไม่เก็บ** เชื้อชาติ ศาสนา หมู่เลือด เว้นแต่มีเหตุตามกฎหมายและ ADR อนุมัติ (ยกเลิกคอลัมน์ `ethnicity`, `religion` ของ `user_profiles` เดิม)

### ประวัติรายหมวด (1:N, มี `uuid`, `verified_by`, `verified_at`)
- `personnel_educations` — level, degree, major, institution, graduated_year
- `personnel_trainings` — course, organizer, hours, start_date, end_date, certificate_document_id
- `personnel_licenses` — license_type, license_number, issued_at, expires_at
- `personnel_documents` — category, storage_path (private), original_name, mime, size

### `assignments` (effective-dated)
| คอลัมน์ | หมายเหตุ |
|---|---|
| uuid | |
| personnel_id | FK |
| kind | `primary` · `management` · `duty` · `acting` |
| org_unit_id | FK |
| position_id | FK |
| acting_for_assignment_id | null · ใช้กับ `acting` |
| order_number, order_date | บังคับทุกแถว ยกเว้น primary ที่นำเข้าครั้งแรก |
| effective_from, effective_to | |
| ended_by, end_reason | |

กฎ: `primary` ที่มีผลได้หนึ่งแถวต่อคน ณ เวลาหนึ่ง · `management` ของหน่วยหนึ่งมีผลได้หนึ่งแถว (ไม่นับ acting) · ห้ามเขียนทับ แก้โดยปิดแถวเดิมแล้วเพิ่มแถวใหม่ · event `AssignmentChanged` ทุกครั้ง

### `field_classifications`
entity (`personnel`,`personnel_profile`, …), field, class (`public`,`internal`,`confidential`,`sensitive`), editable_by (`self`,`self_with_review`,`hr_only`)

### `personnel_change_requests`
uuid, personnel_id, requested_by, entity, entity_id null, payload (json: field → new value), evidence_document_ids (json),
status `pending`,`approved`,`returned`,`rejected`, reviewed_by, reviewed_at, review_note
กฎ: `reviewed_by ≠ requested_by` และ ≠ personnel.user (Maker–Checker) · ค่าเดิมแสดงจนกว่าอนุมัติ

### `sensitive_access_logs` (append-only)
actor_user_id, acting_as (permission + source), personnel_id, fields (json), reason, ip, user_agent, created_at

## Service contracts
- `PersonnelDirectory::visibleQuery(User $actor, string $permission)` — query ที่กรอง scope แล้ว
- `PersonnelPresenter::present(Personnel $p, User $actor)` — ปิดบังตามชั้นและสิทธิ์
- `SensitiveReveal::reveal(Personnel $p, array $fields, string $reason, User $actor)` — ตรวจสิทธิ์ + log + คืนค่า

## Tests ขั้นต่ำ
ปิดบังถูกชั้นทุกระดับ L1–L5 · reveal ไม่มีเหตุผล = 422 · reveal ถูกบันทึก · Maker–Checker · แก้ฟิลด์ `hr_only` ของตนเองไม่ได้แม้มีสิทธิ์ HR · primary ซ้อนกันไม่ได้
