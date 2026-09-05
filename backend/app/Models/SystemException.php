<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ข้อผิดพลาดหนึ่งรายการที่ต้องมีคนตามแก้ (SoT §19)
 *
 * ต่างจาก audit log ตรงที่ **มีเจ้าของ มีสถานะ และปิดได้** — audit บันทึกว่า
 * เกิดอะไรขึ้นและห้ามแก้ ส่วนคิวนี้บันทึกว่ามีอะไรค้างและใครกำลังตามแก้อยู่
 */
#[Fillable([
    'uuid', 'type', 'severity', 'module', 'title', 'detail',
    'subject_type', 'subject_id', 'retry_type', 'retry_id',
    'status', 'assigned_to', 'acknowledged_at', 'acknowledged_by',
    'resolved_at', 'resolved_by', 'resolution_note',
    'dedupe_key', 'occurrence_count', 'correlation_id',
    'first_seen_at', 'last_seen_at',
])]
class SystemException extends Model
{
    use HasPublicUuid;

    protected $table = 'system_exceptions';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_RESOLVED = 'resolved';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    /** ปลายทางของการสั่งทำซ้ำที่ระบบรู้จัก */
    public const RETRY_NOTIFICATION = 'notification_delivery';

    public const RETRY_WEBHOOK = 'webhook_delivery';

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'occurrence_count' => 'integer',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return array<int,string> */
    public static function severities(): array
    {
        return [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL];
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
