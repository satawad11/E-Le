<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ทะเบียนการทำหน้าที่แทน (DELEGATION-01)
 *
 * user_id = ผู้มอบ/เจ้าของตำแหน่ง · delegate_id = ผู้ทำหน้าที่แทน
 * มีผลเมื่อ status = approved, is_active และอยู่ในช่วงวันที่ — คำขอที่ผู้ใช้ส่งเองเป็น pending จนงานบุคลากรรับรอง
 * ยกเลิกด้วยการตั้ง revoked ไม่ลบแถว เพราะใบลาที่ลงนามไปแล้วอ้างอิงอยู่
 */
#[Fillable([
    'user_id', 'delegate_id', 'delegation_type', 'acting_position', 'order_number', 'order_date', 'note',
    'start_date', 'end_date', 'is_active', 'status', 'requested_by', 'approved_by', 'approved_at', 'revoked_by', 'revoked_at',
])]
class UserDelegation extends Model
{
    /** รักษาราชการแทน — ตำแหน่งว่างหรือผู้ดำรงตำแหน่งไม่อาจปฏิบัติราชการได้ */
    public const TYPE_ACTING = 'acting';

    /** ปฏิบัติราชการแทน — ได้รับมอบอำนาจตามคำสั่ง */
    public const TYPE_ON_BEHALF = 'on_behalf';

    public const TYPES = [self::TYPE_ACTING, self::TYPE_ON_BEHALF];

    public const TYPE_LABELS = [
        self::TYPE_ACTING => 'รักษาราชการแทน',
        self::TYPE_ON_BEHALF => 'ปฏิบัติราชการแทน',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'order_date' => 'date',
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param  Builder<UserDelegation>  $query */
    public function scopeInEffect(Builder $query, ?string $on = null): Builder
    {
        $date = $on ?? now()->toDateString();

        return $query->where('is_active', true)
            ->where('status', self::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }

    /** บรรทัดที่พิมพ์ใต้ตำแหน่งในบล็อกลงนาม เช่น "ปฏิบัติราชการแทน ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง" */
    public function actingLine(): string
    {
        return self::lineFor($this->delegation_type, $this->acting_position ?: $this->user?->profile?->position);
    }

    public static function lineFor(?string $type, ?string $position): string
    {
        $label = self::TYPE_LABELS[$type] ?? null;

        return $label === null ? '' : trim($label.' '.(string) $position);
    }

    /** เลขที่และวันที่ของคำสั่ง สำหรับเก็บไว้กับการลงนาม */
    public function orderReference(): ?string
    {
        if (blank($this->order_number)) {
            return null;
        }

        return 'คำสั่งที่ '.$this->order_number.($this->order_date ? ' ลงวันที่ '.$this->order_date->format('d/m/').($this->order_date->year + 543) : '');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function delegate()
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
