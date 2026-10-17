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

    /** ฐานะของผู้ลงนามบนแบบฟอร์มราชการ — ใช้เลือกบล็อก "ผู้ตรวจสอบ" และชื่อผู้บริหารจากหน้าตั้งค่า */
    public const CAPACITY_CHECKER = 'checker';

    public const CAPACITY_SUPERVISOR = 'supervisor';

    public const CAPACITY_DEPUTY = 'deputy';

    public const CAPACITY_DIRECTOR = 'director';

    /**
     * ชื่อบทบาทของระบบเดิมที่ยังใช้ในขั้นแบบ ROLE — จุดเดียวที่ยังอ่านชื่อบทบาท (ACCESS-GOV-01 · B2)
     * ขั้นที่ตั้งด้วยวิธีหาผู้อนุมัติอัตโนมัติไม่ต้องพึ่งชื่อบทบาทเลย
     */
    private const LEGACY_ROLE_CAPACITY = [
        'HR Head' => self::CAPACITY_CHECKER, 'HRHead' => self::CAPACITY_CHECKER, 'เจ้าหน้าที่บุคลากร (HR)' => self::CAPACITY_CHECKER,
        'Deputy Director' => self::CAPACITY_DEPUTY, 'รองผู้อำนวยการ' => self::CAPACITY_DEPUTY,
        'Director' => self::CAPACITY_DIRECTOR, 'ผู้อำนวยการ' => self::CAPACITY_DIRECTOR,
    ];

    /**
     * ฐานะผู้ลงนามของขั้น จากวิธีหาผู้อนุมัติ (ขั้นใน approval_steps หรือ snapshot ใน leave_approvals)
     *
     * ORG_UNIT_MANAGER: หน่วยระดับสถานศึกษา = ผู้อำนวยการ · หน่วยที่อยู่ใต้สถานศึกษาโดยตรง (ฝ่าย) = รองฯ · ลึกกว่านั้น = หัวหน้า
     */
    public static function capacityOf(?string $approverType, ?string $roleName, ?int $orgUnitId): ?string
    {
        return match ($approverType ?: self::RESOLVER_ROLE) {
            self::RESOLVER_HR_OFFICER => self::CAPACITY_CHECKER,
            self::RESOLVER_DEPARTMENT_HEAD, self::RESOLVER_REQUESTER_SUPERVISOR => self::CAPACITY_SUPERVISOR,
            self::RESOLVER_DIVISION_DIRECTOR => self::CAPACITY_DEPUTY,
            self::RESOLVER_DIRECTOR => self::CAPACITY_DIRECTOR,
            self::RESOLVER_ORG_UNIT_MANAGER => self::capacityOfUnit($orgUnitId),
            default => self::LEGACY_ROLE_CAPACITY[(string) $roleName] ?? null,
        };
    }

    private static function capacityOfUnit(?int $orgUnitId): ?string
    {
        $unit = $orgUnitId ? OrgUnit::find($orgUnitId) : null;
        if ($unit === null) {
            return null;
        }
        if ($unit->parent_id === null) {
            return self::CAPACITY_DIRECTOR;
        }

        return OrgUnit::whereKey($unit->parent_id)->whereNull('parent_id')->exists()
            ? self::CAPACITY_DEPUTY
            : self::CAPACITY_SUPERVISOR;
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
