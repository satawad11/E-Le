<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ปลายทาง webhook หนึ่งราย (SoT §17)
 *
 * `events` ว่าง = **ไม่รับอะไรเลย** ไม่ใช่รับทุกอย่าง — ค่าเริ่มต้นที่ปลอดภัย
 * ต้องเป็นการไม่ส่งออก ไม่ใช่การส่งออกทั้งหมด
 */
#[Fillable(['uuid', 'name', 'url', 'secret', 'events', 'is_active', 'created_by'])]
class WebhookEndpoint extends Model
{
    use HasPublicUuid;

    protected $table = 'webhook_endpoints';

    /** ความลับสำหรับเซ็น HMAC ต้องไม่ออกไปกับ response ใด ๆ */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'is_active' => 'boolean'];
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
