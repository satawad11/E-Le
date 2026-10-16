<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\Position;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonnelSyncService
{
    /**
     * Active sync execution keys to prevent recursive event loops.
     */
    protected static array $syncing = [];

    /**
     * Execute a callback within a sync guard for a specific entity key.
     */
    public function withoutSyncRecursion(string $key, callable $callback)
    {
        if (isset(self::$syncing[$key])) {
            return null;
        }

        self::$syncing[$key] = true;

        try {
            return $callback();
        } finally {
            unset(self::$syncing[$key]);
        }
    }

    /**
     * Check if currently syncing for a given entity key.
     */
    public function isSyncing(string $key): bool
    {
        return isset(self::$syncing[$key]);
    }

    /**
     * Synchronize downstream entities when a User record is created or updated.
     */
    public function syncFromUser(User $user): void
    {
        $key = 'user:'.$user->id;
        $this->withoutSyncRecursion($key, function () use ($user): void {
            DB::transaction(function () use ($user): void {
                // 1. Resolve org_unit_id if department_id was modified
                if ($user->department_id && ! $user->org_unit_id) {
                    $resolvedOrgUnitId = $this->resolveOrgUnitForDepartment($user->department_id);
                    if ($resolvedOrgUnitId) {
                        $user->org_unit_id = $resolvedOrgUnitId;
                        $user->saveQuietly();
                    }
                } elseif ($user->org_unit_id && ! $user->department_id) {
                    $resolvedDeptId = $this->resolveDepartmentForOrgUnit($user->org_unit_id);
                    if ($resolvedDeptId) {
                        $user->department_id = $resolvedDeptId;
                        $user->saveQuietly();
                    }
                }

                // 2. Ensure Personnel entity exists
                $personnel = Personnel::where('user_id', $user->id)->first();
                if (! $personnel) {
                    $profile = $user->profile ?: UserProfile::where('user_id', $user->id)->first();
                    $firstName = trim((string) ($profile?->first_name ?? ''));
                    $lastName = trim((string) ($profile?->last_name ?? ''));
                    if ($firstName === '') {
                        $parts = explode(' ', trim((string) $user->name), 2);
                        $firstName = $parts[0] !== '' ? $parts[0] : 'User_'.$user->id;
                        $lastName = $parts[1] ?? '';
                    }

                    Personnel::create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'personnel_code' => $profile?->personnel_code ?: null,
                        'title' => $profile?->title ?: null,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'status' => $user->is_active ? ($profile?->status ?: 'active') : 'inactive',
                        'effective_from' => $profile?->start_date ?: ($user->created_at ? $user->created_at->toDateString() : now()->toDateString()),
                        'effective_to' => null,
                    ]);
                }

                // 3. Ensure active PersonnelAssignment exists and matches
                $currentAssignment = PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->first();
                if ($currentAssignment) {
                    if ($currentAssignment->org_unit_id !== $user->org_unit_id || $currentAssignment->personnel_type_id !== $user->personnel_type_id) {
                        $currentAssignment->update(['effective_to' => now()->subDay()->toDateString()]);
                        PersonnelAssignment::create([
                            'uuid' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'org_unit_id' => $user->org_unit_id,
                            'personnel_type_id' => $user->personnel_type_id,
                            'position' => $currentAssignment->position,
                            // ORG-ACCESS-02: ย้ายหน่วยงาน/เปลี่ยนประเภทบุคลากรต้องไม่ทำให้ตำแหน่งในทะเบียนหลุด
                            'position_id' => $currentAssignment->position_id,
                            'effective_from' => now()->toDateString(),
                            'effective_to' => null,
                        ]);
                    }
                } elseif ($user->org_unit_id || $user->personnel_type_id) {
                    PersonnelAssignment::create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'org_unit_id' => $user->org_unit_id,
                        'personnel_type_id' => $user->personnel_type_id,
                        'position' => $user->profile?->position,
                        'effective_from' => now()->toDateString(),
                        'effective_to' => null,
                    ]);
                }
            });
        });
    }

    /**
     * Synchronize downstream entities when a UserProfile record is created or updated.
     */
    public function syncFromProfile(UserProfile $profile): void
    {
        $key = 'profile:'.$profile->user_id;
        $this->withoutSyncRecursion($key, function () use ($profile): void {
            DB::transaction(function () use ($profile): void {
                $user = User::find($profile->user_id);
                if (! $user) {
                    return;
                }

                // 1. Sync to Personnel
                $personnel = Personnel::where('user_id', $profile->user_id)->first();
                $firstName = trim((string) ($profile->first_name ?? ''));
                $lastName = trim((string) ($profile->last_name ?? ''));

                if ($personnel) {
                    $personnelUpdates = [];
                    if ($firstName !== '' && $personnel->first_name !== $firstName) {
                        $personnelUpdates['first_name'] = $firstName;
                    }
                    if ($lastName !== '' && $personnel->last_name !== $lastName) {
                        $personnelUpdates['last_name'] = $lastName;
                    }
                    if ($profile->title && $personnel->title !== $profile->title) {
                        $personnelUpdates['title'] = $profile->title;
                    }
                    if ($profile->personnel_code && $personnel->personnel_code !== $profile->personnel_code) {
                        $personnelUpdates['personnel_code'] = $profile->personnel_code;
                    }
                    if ($profile->status && $personnel->status !== $profile->status) {
                        $personnelUpdates['status'] = $profile->status;
                    }
                    if (! empty($personnelUpdates)) {
                        $personnel->update($personnelUpdates);
                    }
                } else {
                    if ($firstName === '') {
                        $parts = explode(' ', trim((string) $user->name), 2);
                        $firstName = $parts[0] !== '' ? $parts[0] : 'User_'.$user->id;
                        $lastName = $parts[1] ?? '';
                    }
                    Personnel::create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'personnel_code' => $profile->personnel_code ?: null,
                        'title' => $profile->title ?: null,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'status' => $profile->status ?: 'active',
                        'effective_from' => $profile->start_date ?: now()->toDateString(),
                        'effective_to' => null,
                    ]);
                }

                // 2. Sync display name to users.name
                if ($firstName !== '') {
                    $computedName = trim($firstName.' '.$lastName);
                    if ($computedName !== '' && $user->name !== $computedName) {
                        $user->name = $computedName;
                        $user->saveQuietly();
                    }
                }

                // 3. Sync position to active PersonnelAssignment if changed
                if ($profile->position) {
                    $assignment = PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->first();
                    if ($assignment && $assignment->position !== $profile->position) {
                        $assignment->update(['position' => $profile->position]);
                    }
                }
            });
        });
    }

    /**
     * Synchronize downstream entities when a Personnel record is updated.
     */
    public function syncFromPersonnel(Personnel $personnel): void
    {
        $key = 'personnel:'.$personnel->user_id;
        $this->withoutSyncRecursion($key, function () use ($personnel): void {
            DB::transaction(function () use ($personnel): void {
                $user = User::find($personnel->user_id);
                if (! $user) {
                    return;
                }

                // 1. Sync to UserProfile
                $profile = UserProfile::where('user_id', $personnel->user_id)->first();
                if ($profile) {
                    $profileUpdates = [];
                    if ($personnel->first_name && $profile->first_name !== $personnel->first_name) {
                        $profileUpdates['first_name'] = $personnel->first_name;
                    }
                    if ($personnel->last_name && $profile->last_name !== $personnel->last_name) {
                        $profileUpdates['last_name'] = $personnel->last_name;
                    }
                    if ($personnel->title && $profile->title !== $personnel->title) {
                        $profileUpdates['title'] = $personnel->title;
                    }
                    if ($personnel->personnel_code && $profile->personnel_code !== $personnel->personnel_code) {
                        $profileUpdates['personnel_code'] = $personnel->personnel_code;
                    }
                    if ($personnel->status && $profile->status !== $personnel->status) {
                        $profileUpdates['status'] = $personnel->status;
                    }
                    if (! empty($profileUpdates)) {
                        $profile->update($profileUpdates);
                    }
                }

                // 2. Sync display name to users.name
                $computedName = trim($personnel->first_name.' '.$personnel->last_name);
                if ($computedName !== '' && $user->name !== $computedName) {
                    $user->name = $computedName;
                    $user->saveQuietly();
                }
            });
        });
    }

    /**
     * Synchronize downstream entities when a PersonnelAssignment record is created or updated.
     */
    public function syncFromAssignment(PersonnelAssignment $assignment): void
    {
        $isActive = is_null($assignment->effective_to) || $assignment->effective_to >= today()->toDateString();
        if (! $isActive) {
            return;
        }

        $key = 'assignment:'.$assignment->user_id;
        $this->withoutSyncRecursion($key, function () use ($assignment): void {
            DB::transaction(function () use ($assignment): void {
                $user = User::find($assignment->user_id);
                if (! $user) {
                    return;
                }

                $dirty = false;
                if ($assignment->org_unit_id && $user->org_unit_id !== $assignment->org_unit_id) {
                    $user->org_unit_id = $assignment->org_unit_id;
                    $deptId = $this->resolveDepartmentForOrgUnit($assignment->org_unit_id);
                    if ($deptId && $user->department_id !== $deptId) {
                        $user->department_id = $deptId;
                    }
                    $dirty = true;
                }

                if ($assignment->personnel_type_id && $user->personnel_type_id !== $assignment->personnel_type_id) {
                    $user->personnel_type_id = $assignment->personnel_type_id;
                    $dirty = true;
                }

                if ($dirty) {
                    $user->saveQuietly();
                }

                if ($assignment->position) {
                    $profile = UserProfile::where('user_id', $assignment->user_id)->first();
                    if ($profile && $profile->position !== $assignment->position) {
                        $profile->update(['position' => $assignment->position]);
                    }
                }
            });
        });
    }

    /**
     * Assign a position/unit to a user with effective-dating history management.
     */
    public function assignPosition(int $userId, array $attributes): PersonnelAssignment
    {
        return DB::transaction(function () use ($userId, $attributes) {
            $effectiveFrom = $attributes['effective_from'] ?? now()->toDateString();
            $effectiveTo = $attributes['effective_to'] ?? null;
            $orgUnitId = $attributes['org_unit_id'] ?? null;
            $personnelTypeId = $attributes['personnel_type_id'] ?? null;
            $position = $attributes['position'] ?? null;

            // Close existing active assignment
            $current = PersonnelAssignment::where('user_id', $userId)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current) {
                $closeDate = date('Y-m-d', strtotime($effectiveFrom.' -1 day'));
                $current->update(['effective_to' => $closeDate]);
            }

            $newAssignment = PersonnelAssignment::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'org_unit_id' => $orgUnitId ?: $current?->org_unit_id,
                'personnel_type_id' => $personnelTypeId ?: $current?->personnel_type_id,
                'position' => $position ?: $current?->position,
                // ORG-ACCESS-02: ส่ง null มาได้เพื่อล้างตำแหน่งในทะเบียน ไม่ส่งมา = คงของเดิม
                'position_id' => array_key_exists('position_id', $attributes) ? $attributes['position_id'] : $current?->position_id,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);

            $this->syncFromAssignment($newAssignment);

            return $newAssignment;
        });
    }

    /**
     * ผูกตำแหน่งจากทะเบียนตำแหน่งให้บุคลากร (ORG-ACCESS-02)
     *
     * - ตำแหน่งเดิมไม่ถูกเขียนทับ: ปิด assignment ปัจจุบันแล้วเปิดใหม่ตั้งแต่วันนี้ผ่าน assignPosition()
     *   ยกเว้น assignment ที่เพิ่งเริ่มวันนี้ ซึ่งแก้ในแถวเดิม (ปิดย้อนเป็นเมื่อวานไม่ได้)
     * - ข้อความตำแหน่ง (`position`) ถูกตั้งเป็นชื่อตำแหน่ง ณ ตอนผูก และ sync ไป user_profiles ตามกลไกเดิม
     *   แก้ชื่อตำแหน่งในทะเบียนภายหลังจึงไม่เปลี่ยนข้อความในประวัติหรือแบบฟอร์มที่พิมพ์ไปแล้ว
     * - ส่ง null เพื่อล้างตำแหน่งในทะเบียน ข้อความเดิมยังอยู่
     *
     * คืน true เมื่อมีการเปลี่ยนแปลง
     */
    public function assignCatalogPosition(User $user, ?Position $position, ?int $actorId = null): bool
    {
        return DB::transaction(function () use ($user, $position, $actorId) {
            $current = PersonnelAssignment::where('user_id', $user->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            $newId = $position?->getKey();
            $currentId = $current?->position_id === null ? null : (int) $current->position_id;
            if ($current !== null && $currentId === $newId) {
                return false;
            }

            $before = $current?->only(['uuid', 'position', 'position_id']);
            $attributes = ['position_id' => $newId];
            if ($position !== null) {
                $attributes['position'] = $position->name;
            }

            if ($current !== null && $current->effective_from->isToday()) {
                $current->update($attributes);
                $assignment = $current;
            } else {
                // ยังไม่มี assignment — เริ่มจากหน่วยงานและประเภทบุคลากรปัจจุบันของผู้ใช้ ไม่ให้ต้นสังกัดหาย
                $assignment = $this->assignPosition($user->id, $attributes + ($current === null ? [
                    'org_unit_id' => $user->org_unit_id,
                    'personnel_type_id' => $user->personnel_type_id,
                ] : []));
            }

            app(AuditLogger::class)->log(
                'personnel.position_assigned',
                'user',
                $user->id,
                $before,
                $assignment->only(['uuid', 'position', 'position_id', 'effective_from']),
                null,
                $actorId,
            );

            return true;
        });
    }

    /**
     * Resolve OrgUnit ID for a legacy Department ID.
     */
    public function resolveOrgUnitForDepartment(?int $departmentId): ?int
    {
        if (! $departmentId) {
            return null;
        }

        return DB::table('department_org_unit_map')
            ->where('department_id', $departmentId)
            ->value('org_unit_id');
    }

    /**
     * Resolve legacy Department ID for an OrgUnit ID.
     */
    public function resolveDepartmentForOrgUnit(?int $orgUnitId): ?int
    {
        if (! $orgUnitId) {
            return null;
        }

        return DB::table('department_org_unit_map')
            ->where('org_unit_id', $orgUnitId)
            ->value('department_id');
    }
}
