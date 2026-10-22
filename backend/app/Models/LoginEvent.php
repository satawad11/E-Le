<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บันทึกการเข้าสู่ระบบ (CORE-03) — เพิ่มได้อย่างเดียว
 */
class LoginEvent extends Model
{
    use AppendOnly;

    /**
     * `blocked` = รหัสผ่านถูกแต่บัญชีไม่อยู่ในสถานะใช้งาน (รอเปิด/ระงับ/ปิด) หรือเป็นบัญชีระบบที่ห้าม interactive login
     * `mfa_reset` = ผู้ดูแลล้าง MFA ของบัญชี (เหตุการณ์ด้านความปลอดภัยการเข้าสู่ระบบที่เจ้าของต้องเห็นในประวัติ)
     */
    public const RESULTS = ['success', 'bad_password', 'locked', 'mfa_failed', 'new_device', 'blocked', 'mfa_reset'];

    protected $fillable = ['user_id', 'username_attempted', 'result', 'ip', 'user_agent', 'device_hash', 'created_at'];

    protected $hidden = ['device_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
