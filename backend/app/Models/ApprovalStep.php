<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'chain', 'role_id', 'step_order', 'name', 'approver_type',
    'specific_user_id', 'permission_name',
])]
class ApprovalStep extends Model
{
    public const RESOLVER_HR_OFFICER = 'HR_OFFICER';

    public const RESOLVER_REQUESTER_SUPERVISOR = 'REQUESTER_SUPERVISOR';

    public const RESOLVER_DEPARTMENT_HEAD = 'DEPARTMENT_HEAD';

    public const RESOLVER_DIVISION_DIRECTOR = 'DIVISION_DIRECTOR';

    public const RESOLVER_DIRECTOR = 'DIRECTOR';

    public const RESOLVER_ROLE = 'ROLE';

    public const RESOLVER_SPECIFIC_USER = 'SPECIFIC_USER';

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

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function specificUser()
    {
        return $this->belongsTo(User::class, 'specific_user_id');
    }
}
