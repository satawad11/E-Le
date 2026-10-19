<?php

namespace App\Services\Personnel;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\SensitiveAccessLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * เปิดดูข้อมูลชั้น sensitive (CORE-02 · REQ-PER-04)
 *
 * ลำดับ: ตรวจเหตุผล (422) → ตรวจฟิลด์เป็น sensitive จริง (422) → ตรวจสิทธิ์ (403) → บันทึก sensitive_access_logs → คืนค่า
 * บันทึกก่อนคืนค่าเสมอ — ถ้าบันทึกไม่สำเร็จจะไม่คืนข้อมูล
 */
class SensitiveReveal
{
    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly PersonnelFieldAccess $access,
        private readonly FieldClassificationRegistry $classes,
    ) {}

    /**
     * @param  array<int, string>  $fields  ชื่อฟิลด์ของ personnel_profiles
     * @param  array{ip?: ?string, user_agent?: ?string, acting_as?: ?array<string, mixed>}  $context
     * @return array<string, mixed>
     */
    public function reveal(Personnel $personnel, array $fields, ?string $reason, User $actor, array $context = []): array
    {
        Validator::make(['reason' => $reason, 'fields' => $fields], [
            'reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:500'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'distinct'],
        ], [
            'reason.required' => 'ต้องระบุเหตุผลในการเปิดดูข้อมูล',
            'reason.min' => 'เหตุผลต้องยาวอย่างน้อย '.self::MIN_REASON_LENGTH.' ตัวอักษร',
            'fields.required' => 'ต้องระบุฟิลด์ที่ต้องการเปิดดู',
        ])->validate();

        foreach ($fields as $field) {
            if (! in_array($field, PersonnelProfile::SENSITIVE, true)
                || $this->classes->classOf(FieldClassificationRegistry::ENTITY_PROFILE, $field) !== 'sensitive') {
                throw ValidationException::withMessages(['fields' => "ฟิลด์ {$field} ไม่ใช่ข้อมูลชั้นอ่อนไหวที่เปิดดูได้"]);
            }
        }

        if (! $this->access->canReveal($actor, $personnel)) {
            throw new AuthorizationException('ไม่มีสิทธิ์เปิดดูข้อมูลอ่อนไหวของบุคลากรคนนี้');
        }

        SensitiveAccessLog::create([
            'actor_user_id' => $actor->id,
            'acting_as' => $context['acting_as'] ?? null,
            'personnel_id' => $personnel->id,
            'fields' => array_values($fields),
            'reason' => trim((string) $reason),
            'ip' => $context['ip'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
        ]);

        $profile = $personnel->profile;

        return collect($fields)->mapWithKeys(fn (string $field) => [$field => $profile?->{$field}])->all();
    }
}
