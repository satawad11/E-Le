<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'chain', 'role_id', 'step_order', 'name', 'approver_type',
    'specific_user_id', 'permission_name', 'org_unit_id', 'allow_self_approval',
])]
class ApprovalStep extends Model
{
    protected function casts(): array
    {
        return ['allow_self_approval' => 'boolean'];
    }

    public const RESOLVER_HR_OFFICER = 'HR_OFFICER';

    public const RESOLVER_REQUESTER_SUPERVISOR = 'REQUESTER_SUPERVISOR';

    public const RESOLVER_DEPARTMENT_HEAD = 'DEPARTMENT_HEAD';

    public const RESOLVER_DIVISION_DIRECTOR = 'DIVISION_DIRECTOR';

    public const RESOLVER_DIRECTOR = 'DIRECTOR';

    public const RESOLVER_ROLE = 'ROLE';

    public const RESOLVER_SPECIFIC_USER = 'SPECIFIC_USER';

    /** หัวหน้าของหน่วยงานที่ระบุใน org_unit_id เช่น รองผู้อำนวยการฝ่ายบริหารทรัพยากร (ACCESS-GOV-01) */
    public const RESOLVER_ORG_UNIT_MANAGER = 'ORG_UNIT_MANAGER';

    public const RESOLVERS = [
        self::RESOLVER_HR_OFFICER, self::RESOLVER_REQUESTER_SUPERVISOR, self::RESOLVER_DEPARTMENT_HEAD,
        self::RESOLVER_DIVISION_DIRECTOR, self::RESOLVER_DIRECTOR, self::RESOLVER_ROLE,
        self::RESOLVER_SPECIFIC_USER, self::RESOLVER_ORG_UNIT_MANAGER,
    ];

    /** สายอนุมัติของการขอลา */
    public const CHAIN_LEAVE = 'leave';

    /** สายอนุมัติของคำขอยกเลิกใบลาที่อนุมัติแล้ว */
    public const CHAIN_CANCELLATION = 'cancellation';

    public const CHAINS = [self::CHAIN_LEAVE, self::CHAIN_CANCELLATION];

    /** @param  Builder<ApprovalStep>  $query */
    public function scopeChain(Builder $query, string $chain): Builder
    {
        return $query->where('chain', $chain);
    }

    /**
     * ขั้นตอนของสายที่ระบุ เรียงตามลำดับ
     *
     * สายยกเลิกที่ผู้ดูแล "ยังไม่ได้ตั้งค่า" จะใช้สายของใบลาแทน — นี่คือค่าเริ่มต้น
     * ของระบบ และเป็นเหตุผลที่การอัปเกรดไม่เปลี่ยนพฤติกรรมของหน่วยงานที่ใช้อยู่
     *
     * @return Collection<int,ApprovalStep>
     */
    public static function stepsFor(string $chain): Collection
    {
        $steps = static::query()->chain($chain)->orderBy('step_order')->get();

        if ($steps->isEmpty() && $chain === self::CHAIN_CANCELLATION) {
            return static::query()->chain(self::CHAIN_LEAVE)->orderBy('step_order')->get();
        }

        return $steps;
    }

    /** สายยกเลิกถูกตั้งค่าแยกไว้เองหรือยัง (ยังไม่ตั้ง = ใช้สายของใบลา) */
    public static function cancellationChainIsConfigured(): bool
    {
        return static::query()->chain(self::CHAIN_CANCELLATION)->exists();
    }

    /**
     * วิธีหาตัวผู้อนุมัติของขั้นนี้ สำหรับคัดลอกลง leave_approvals (snapshot ณ วันยื่น)
     *
     * ทุกจุดที่ pin ขั้นตอนให้ใบลาใช้เมธอดนี้ — เพิ่มฟิลด์ resolver ใหม่ที่นี่ที่เดียว
     * ไม่เช่นนั้นจุดที่ตกหล่นจะกลายเป็นขั้นแบบ ROLE แล้วผู้อนุมัติตัวจริงถูกปฏิเสธสิทธิ์
     *
     * @return array<string,mixed>
     */
    public function snapshotAttributes(): array
    {
        return [
            'step_name' => $this->name,
            'required_role_id' => $this->role_id,
            'approver_type' => $this->approver_type ?: self::RESOLVER_ROLE,
            'specific_user_id' => $this->specific_user_id,
            'permission_name' => $this->permission_name,
            'org_unit_id' => $this->org_unit_id,
            'allow_self_approval' => (bool) $this->allow_self_approval,
        ];
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function specificUser()
    {
        return $this->belongsTo(User::class, 'specific_user_id');
    }
}
