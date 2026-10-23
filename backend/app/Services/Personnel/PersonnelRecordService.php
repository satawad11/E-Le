<?php

namespace App\Services\Personnel;

use App\Models\Personnel;
use App\Models\PersonnelEducation;
use App\Models\PersonnelLicense;
use App\Models\PersonnelTraining;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ประวัติรายหมวดของบุคลากร (CORE-02 · R-06): การศึกษา การอบรม ใบอนุญาต
 *
 * - งานบุคคล (หรือผู้ได้รับมอบสำหรับการอบรม) บันทึกให้ผู้อื่น → ถือว่าตรวจแล้ว (verified_by)
 * - เจ้าของข้อมูลแก้เองไม่ได้ ต้องยื่นคำขอแก้ไข (ฟิลด์ทุกตัว editable_by = self_with_review) — controller ตรวจสิทธิ์ผ่าน engine
 * - การแก้ที่มาจากคำขอที่อนุมัติแล้วใช้ apply() โดยไม่ตรวจสิทธิ์ซ้ำ (ผ่าน Maker–Checker แล้ว)
 */
class PersonnelRecordService
{
    /** type (path) => [model, entity ใน field_classifications] */
    public const TYPES = [
        'educations' => [PersonnelEducation::class, 'personnel_education'],
        'trainings' => [PersonnelTraining::class, 'personnel_training'],
        'licenses' => [PersonnelLicense::class, 'personnel_license'],
    ];

    public const ENTITIES = ['personnel_education' => 'educations', 'personnel_training' => 'trainings', 'personnel_license' => 'licenses'];

    public function __construct(
        private readonly FieldClassificationRegistry $classes,
        private readonly AuditLogger $audit,
    ) {}

    public static function entityOf(string $type): string
    {
        return self::TYPES[$type][1] ?? throw ValidationException::withMessages(['type' => 'ไม่รู้จักหมวดข้อมูลนี้']);
    }

    /** @return class-string<Model> */
    public static function modelOf(string $type): string
    {
        return self::TYPES[$type][0] ?? throw ValidationException::withMessages(['type' => 'ไม่รู้จักหมวดข้อมูลนี้']);
    }

    /** @return array<string, array<int, string>> */
    public function rules(string $type, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return match ($type) {
            'educations' => [
                'level' => [$required, 'string', 'max:80'],
                'degree' => ['sometimes', 'nullable', 'string', 'max:255'],
                'major' => ['sometimes', 'nullable', 'string', 'max:255'],
                'institution' => ['sometimes', 'nullable', 'string', 'max:255'],
                'graduated_year' => ['sometimes', 'nullable', 'integer', 'between:1950,2700'],
            ],
            'trainings' => [
                'course' => [$required, 'string', 'max:255'],
                'organizer' => ['sometimes', 'nullable', 'string', 'max:255'],
                'hours' => ['sometimes', 'nullable', 'numeric', 'between:0,9999'],
                'start_date' => ['sometimes', 'nullable', 'date'],
                'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            ],
            'licenses' => [
                'license_type' => [$required, 'string', 'max:120'],
                'license_number' => [$required, 'string', 'max:80'],
                'issued_at' => ['sometimes', 'nullable', 'date'],
                'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_at'],
            ],
            default => throw ValidationException::withMessages(['type' => 'ไม่รู้จักหมวดข้อมูลนี้']),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(string $type, array $input, bool $partial = false): array
    {
        $data = Validator::make($input, $this->rules($type, $partial))->validate();
        $entity = self::entityOf($type);
        foreach (array_keys($data) as $field) {
            if (! $this->classes->isClassified($entity, $field)) {
                throw ValidationException::withMessages([$field => "ไม่มีฟิลด์ {$field} ที่แก้ไขได้"]);
            }
        }
        if ($data === []) {
            throw ValidationException::withMessages(['payload' => 'ต้องระบุข้อมูลที่ต้องการบันทึก']);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data  ค่าที่ validate แล้ว */
    public function create(string $type, Personnel $personnel, array $data, ?int $actorId, string $action = 'personnel_record.created'): Model
    {
        $model = self::modelOf($type);

        return DB::transaction(function () use ($model, $personnel, $data, $actorId, $type, $action) {
            $record = $model::create($data + ['personnel_id' => $personnel->id, 'verified_by' => $actorId, 'verified_at' => now()]);
            $this->audit->log($action, $personnel, null, null, ['type' => $type, 'uuid' => $record->uuid] + $this->auditable($type, $data), null, $actorId);

            return $record;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $type, Model $record, array $data, ?int $actorId, string $action = 'personnel_record.updated'): Model
    {
        return DB::transaction(function () use ($type, $record, $data, $actorId, $action) {
            $before = $this->auditable($type, $record->only(array_keys($data)));
            $record->forceFill($data + ['verified_by' => $actorId, 'verified_at' => now()])->save();
            $this->audit->log($action, $record->personnel, null, ['type' => $type, 'uuid' => $record->uuid] + $before, ['type' => $type, 'uuid' => $record->uuid] + $this->auditable($type, $data), null, $actorId);

            return $record;
        });
    }

    public function delete(string $type, Model $record, User $actor, string $reason): void
    {
        DB::transaction(function () use ($type, $record, $actor, $reason) {
            $this->audit->log('personnel_record.deleted', $record->personnel, null, ['type' => $type, 'uuid' => $record->uuid] + $this->auditable($type, $record->attributesToArray()), null, $reason, $actor->id);
            $record->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> ค่าชั้น confidential/sensitive ไม่ลง audit เป็น plaintext
     */
    private function auditable(string $type, array $values): array
    {
        $entity = self::entityOf($type);
        $out = [];
        foreach ($values as $field => $value) {
            if (! $this->classes->isClassified($entity, $field)) {
                continue;
            }
            $class = $this->classes->classOf($entity, $field);
            $out[$field] = in_array($class, ['confidential', 'sensitive'], true) ? ($value === null ? null : '['.$class.']')
                : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value);
        }

        return $out;
    }
}
