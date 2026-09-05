<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * เลขหนังสือหนึ่งเลข พร้อมสถานะและประวัติ (SoT §9)
 *
 * สถานะและการเปลี่ยนสถานะที่ยอมให้ทำ:
 *
 *     (ยังไม่มีแถว = AVAILABLE)
 *              │ reserve
 *              ▼
 *          RESERVED ──allocate──▶ ALLOCATED ──issue──▶ ISSUED ──void──▶ VOID
 *              │                      │
 *              │ release              │ cancel
 *              ▼                      ▼
 *          RELEASED               CANCELLED
 *
 * **ความต่างที่สำคัญระหว่าง RELEASED กับ CANCELLED/VOID:**
 * `RELEASED` คือการคืนเลขที่จองไว้แต่ยังไม่ได้ใช้ เลขนั้นจึงกลับมาใช้ได้อีก
 * เพื่อไม่ให้ทะเบียนมีเลขว่างโดยไม่จำเป็น ส่วน `CANCELLED` และ `VOID`
 * คือเลขที่ถูกยกเลิก/เพิกถอนอย่างเป็นทางการ — **ห้ามนำกลับมาใช้ตลอดไป**
 *
 * `ISSUED` ห้ามนำกลับมาใช้เด็ดขาดตาม SoT §9 เพราะเลขนั้นออกไปอยู่ในหนังสือจริงแล้ว
 */
#[Fillable([
    'uuid', 'number_series_id', 'buddhist_year', 'running_number', 'formatted',
    'status', 'document_id', 'reserved_by', 'reserved_at', 'issued_at',
    'released_at', 'closed_at', 'reason',
])]
class DocumentNumber extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_document_numbers';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_ALLOCATED = 'allocated';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_VOID = 'void';

    /**
     * สถานะที่ถือว่า "เลขนี้ถูกใช้ไปแล้วหรือถูกเผาทิ้งแล้ว" — นำกลับมาใช้ไม่ได้
     *
     * @var array<int,string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_CANCELLED,
        self::STATUS_VOID,
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'issued_at' => 'datetime',
            'released_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** เลขนี้กลับมาใช้ใหม่ได้หรือไม่ */
    public function isReusable(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    public function series()
    {
        return $this->belongsTo(NumberSeries::class, 'number_series_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function reservedBy()
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }
}
