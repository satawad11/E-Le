<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ผู้รับหนังสือหนึ่งคนของการเวียนหนึ่งครั้ง (SoT §15)
 *
 * **`opened_at` กับ `acknowledged_at` เป็นคนละเรื่องกันโดยเด็ดขาด**
 * การเปิดอ่านเป็นข้อเท็จจริงทางเทคนิคที่ระบบบันทึกให้เอง ส่วนการรับทราบเป็น
 * การแสดงเจตนาของผู้รับซึ่งใช้อ้างอิงทางราชการได้ ระบบจึงต้องไม่ถือว่า
 * "เปิดแล้ว = รับทราบแล้ว" ไม่ว่ากรณีใด
 */
#[Fillable([
    'uuid', 'distribution_id', 'recipient_id', 'recipient_role',
    'target_type', 'target_id',
    'recipient_name_snapshot', 'position_snapshot', 'org_unit_snapshot',
    'status', 'delivered_at', 'opened_at', 'acknowledged_at',
    'accepted_at', 'started_at', 'completed_at', 'response_note',
])]
class DistributionRecipient extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_distribution_recipients';

    public const ROLE_TO = 'to';

    public const ROLE_CC = 'cc';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_OPENED = 'opened';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_ACCEPTED = 'accepted_for_action';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const TARGET_USER = 'user';

    public const TARGET_ORG_UNIT = 'org_unit';

    public const TARGET_ORG_SUBTREE = 'org_subtree';

    public const TARGET_ALL_AUTHORIZED = 'all_authorized';

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * ลำดับความคืบหน้าตาม SoT §15 — ใช้กันไม่ให้สถานะถอยหลัง
     *
     * @return array<int,string>
     */
    public static function progression(): array
    {
        return [
            self::STATUS_DELIVERED,
            self::STATUS_OPENED,
            self::STATUS_ACKNOWLEDGED,
            self::STATUS_ACCEPTED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
        ];
    }

    /**
     * สถานะที่ผู้รับรายงานเองได้หลังรับทราบแล้ว
     *
     * ไม่รวม `delivered`/`opened` เพราะสองสถานะนั้นระบบบันทึกให้เอง
     * และไม่รวม `acknowledged` เพราะการรับทราบมี endpoint ของตัวเองที่บันทึกเจตนา
     *
     * @return array<int,string>
     */
    public static function reportableStatuses(): array
    {
        return [self::STATUS_ACCEPTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];
    }

    public function rank(): int
    {
        return (int) array_search($this->status, self::progression(), true);
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function distribution()
    {
        return $this->belongsTo(Distribution::class, 'distribution_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
