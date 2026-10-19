<?php

namespace Database\Seeders;

use App\Models\FieldClassification;
use Illuminate\Database\Seeder;

/**
 * R03-3 — ชั้นความลับและผู้แก้ไขของฟิลด์บุคลากร (CORE-02 §personnel, §personnel_profiles · ADR-R-007)
 *
 * class: public · internal · confidential · sensitive
 * editable_by: self (แก้เองได้) · self_with_review (ยื่นคำขอให้งานบุคคลตรวจ) · hr_only (งานบุคคลเท่านั้น)
 *
 * ค่า editable_by เป็นค่าเสนอตามหลัก "ข้อมูลที่กระทบสิทธิ/เงินเดือนต้องมีผู้ตรวจ" — งานบุคคลปรับได้โดยแก้ข้อมูลนี้
 * idempotent: อัปเดตตาม (entity, field) — รันซ้ำจะคืนค่าเสนอ
 */
class FieldClassificationSeeder extends Seeder
{
    public const FIELDS = [
        'personnel' => [
            'personnel_code' => ['internal', 'hr_only'],
            'title' => ['public', 'self_with_review'],
            'first_name' => ['public', 'self_with_review'],
            'last_name' => ['public', 'self_with_review'],
            'first_name_en' => ['public', 'self_with_review'],
            'last_name_en' => ['public', 'self_with_review'],
            'personnel_type_id' => ['public', 'hr_only'],
            'academic_standing' => ['internal', 'hr_only'],
            'status' => ['internal', 'hr_only'],
            'started_at' => ['internal', 'hr_only'],
            'separated_at' => ['internal', 'hr_only'],
            'separation_reason' => ['internal', 'hr_only'],
            'photo_path' => ['public', 'self'],
        ],
        'personnel_profile' => [
            'email_work' => ['internal', 'self'],
            'phone_mobile' => ['internal', 'self'],
            'birth_date' => ['confidential', 'self_with_review'],
            'emergency_contact_name' => ['confidential', 'self'],
            'emergency_contact_phone' => ['confidential', 'self'],
            'emergency_contact_relation' => ['confidential', 'self'],
            'national_id' => ['sensitive', 'self_with_review'],
            'address_registered' => ['sensitive', 'self_with_review'],
            'address_current' => ['sensitive', 'self'],
            'bank_name' => ['sensitive', 'self_with_review'],
            'bank_account' => ['sensitive', 'self_with_review'],
            'health_notes' => ['sensitive', 'self_with_review'],
        ],
        'personnel_education' => [
            'level' => ['internal', 'self_with_review'],
            'degree' => ['internal', 'self_with_review'],
            'major' => ['internal', 'self_with_review'],
            'institution' => ['internal', 'self_with_review'],
            'graduated_year' => ['internal', 'self_with_review'],
        ],
        'personnel_training' => [
            'course' => ['internal', 'self_with_review'],
            'organizer' => ['internal', 'self_with_review'],
            'hours' => ['internal', 'self_with_review'],
            'start_date' => ['internal', 'self_with_review'],
            'end_date' => ['internal', 'self_with_review'],
        ],
        'personnel_license' => [
            'license_type' => ['internal', 'self_with_review'],
            'license_number' => ['confidential', 'self_with_review'],
            'issued_at' => ['internal', 'self_with_review'],
            'expires_at' => ['internal', 'self_with_review'],
        ],
        'assignment' => [
            'kind' => ['public', 'hr_only'],
            'org_unit_id' => ['public', 'hr_only'],
            'position_id' => ['public', 'hr_only'],
            'order_number' => ['internal', 'hr_only'],
            'order_date' => ['internal', 'hr_only'],
            'effective_from' => ['public', 'hr_only'],
            'effective_to' => ['public', 'hr_only'],
        ],
    ];

    public function run(): void
    {
        foreach (self::FIELDS as $entity => $fields) {
            foreach ($fields as $field => [$class, $editableBy]) {
                FieldClassification::updateOrCreate(
                    ['entity' => $entity, 'field' => $field],
                    ['class' => $class, 'editable_by' => $editableBy],
                );
            }
        }
    }
}
