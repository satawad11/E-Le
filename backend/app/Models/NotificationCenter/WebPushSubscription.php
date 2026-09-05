<?php

namespace App\Models\NotificationCenter;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * การสมัครรับ Web Push ของเบราว์เซอร์หนึ่งตัว (SoT §16)
 *
 * หนึ่งแถว = หนึ่งเบราว์เซอร์บนหนึ่งเครื่อง ไม่ใช่หนึ่งคน — ผู้ใช้คนเดียว
 * มีได้หลายแถว และแถวจะถูกลบเมื่อ push service ตอบว่าปลายทางหมดอายุแล้ว
 */
#[Fillable([
    'uuid', 'user_id', 'endpoint', 'p256dh_key', 'auth_token', 'user_agent', 'last_used_at',
])]
class WebPushSubscription extends Model
{
    use HasPublicUuid;

    protected $table = 'web_push_subscriptions';

    /**
     * ซ่อนกุญแจออกจากทุก response โดยปริยาย
     *
     * ค่าเหล่านี้ใช้เข้ารหัสข้อความถึงเบราว์เซอร์นั้น ไม่มีเหตุผลใดที่ต้องส่ง
     * กลับออกไปทางหน้าเว็บ
     */
    protected $hidden = ['p256dh_key', 'auth_token', 'endpoint'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
