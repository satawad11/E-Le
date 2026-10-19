<?php

namespace App\Services\Personnel;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\User;

/**
 * แปลงข้อมูลบุคลากรเป็น payload ที่ปิดบังตามชั้นความลับและสิทธิ์ของผู้ดู (CORE-02 · R03-3)
 *
 * - ชั้นที่ผู้ดูไม่มีสิทธิ์: ค่าเป็น null และชื่อฟิลด์อยู่ใน `masked`
 * - ชั้น sensitive: **ไม่ส่งค่าจริงจาก presenter เลย** แม้ผู้ดูมีสิทธิ์ reveal — ส่งเฉพาะค่าปิดบัง (`••••2345`)
 *   ค่าจริงได้จาก SensitiveReveal พร้อมเหตุผลเท่านั้น
 * - ฟิลด์ที่ยังไม่จัดชั้นถือเป็น sensitive (fail-closed)
 */
class PersonnelPresenter
{
    /** ฟิลด์ระบุตัวที่ไม่ใช่ข้อมูลบุคคล ส่งเสมอ */
    private const ALWAYS = ['uuid'];

    private const PERSONNEL_FIELDS = [
        'personnel_code', 'title', 'first_name', 'last_name', 'first_name_en', 'last_name_en',
        'academic_standing', 'status', 'started_at', 'separated_at', 'separation_reason', 'photo_path',
    ];

    private const PROFILE_FIELDS = [
        'email_work', 'phone_mobile', 'birth_date', 'emergency_contact_name', 'emergency_contact_phone',
        'emergency_contact_relation', 'national_id', 'address_registered', 'address_current', 'bank_name',
        'bank_account', 'health_notes',
    ];

    public function __construct(
        private readonly PersonnelFieldAccess $access,
        private readonly FieldClassificationRegistry $classes,
    ) {}

    /**
     * @return array{uuid: string, personnel: array<string, mixed>, profile: array<string, mixed>, masked: array<int, string>, can_reveal: bool}
     */
    public function present(Personnel $personnel, User $actor): array
    {
        $visible = array_values(array_diff($this->access->visibleClasses($actor, $personnel), ['sensitive']));
        $masked = [];

        $personnelData = ['type' => $personnel->type?->name];
        foreach (self::PERSONNEL_FIELDS as $field) {
            $personnelData[$field] = $this->value(FieldClassificationRegistry::ENTITY_PERSONNEL, $field, $personnel->{$field}, $visible, $masked);
        }
        $personnelData['full_name'] = in_array($this->classes->classOf('personnel', 'first_name'), $visible, true)
            ? $personnel->full_name
            : null;

        $profile = $personnel->profile ?? new PersonnelProfile;
        $profileData = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $profileData[$field] = $this->value(FieldClassificationRegistry::ENTITY_PROFILE, $field, $profile->{$field}, $visible, $masked, 'profile.');
        }

        return [
            'uuid' => $personnel->uuid,
            'personnel' => $personnelData,
            'profile' => $profileData,
            'masked' => $masked,
            'can_reveal' => $this->access->canReveal($actor, $personnel),
        ];
    }

    /**
     * @param  array<int, string>  $visible
     * @param  array<int, string>  $masked
     */
    private function value(string $entity, string $field, mixed $raw, array $visible, array &$masked, string $prefix = ''): mixed
    {
        $class = $this->classes->classOf($entity, $field);

        if ($class !== 'sensitive' && in_array($class, $visible, true)) {
            return $raw instanceof \DateTimeInterface ? $raw->format('Y-m-d') : $raw;
        }

        $masked[] = $prefix.$field;

        return $class === 'sensitive' && $raw !== null && $raw !== '' ? self::mask((string) $raw) : null;
    }

    /** แสดงเฉพาะ 4 ตัวท้ายของตัวอักษรหรือตัวเลข ที่เหลือเป็นจุด */
    public static function mask(string $value): string
    {
        $chars = preg_replace('/[\s\-]/u', '', $value);
        $length = mb_strlen($chars);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', min($length - 4, 8)).mb_substr($chars, -4);
    }
}
