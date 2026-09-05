<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * นโยบายหนึ่งเรื่องของงานสารบรรณ (SoT §18)
 *
 * ตัวนโยบายเป็นเพียง "หัวเรื่อง" — เนื้อหาจริงอยู่ในเวอร์ชัน เพราะกติกาที่ใช้
 * ตัดสินเอกสารราชการต้องตอบได้เสมอว่า ณ วันนั้นกติกาเป็นอย่างไร
 */
#[Fillable(['uuid', 'key', 'name', 'description'])]
class Policy extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_policies';

    /** ผังการวาง QR และองค์ประกอบบนหน้าเอกสารต่อประเภทหนังสือ (ปิด RISK-32) */
    public const KEY_DOCUMENT_LAYOUT = 'document_layout';

    /** กติกาการเวียนหนังสือและกรอบเวลารับทราบ (SoT §15, §18) */
    public const KEY_DISTRIBUTION = 'distribution';

    /** กติกาการออกเลขหนังสือ (SoT §9, §18) */
    public const KEY_NUMBERING = 'numbering';

    /**
     * ชั้นความลับ — ใครเปิดหนังสือชั้นใดได้ (SoT §18)
     *
     * SoT §18 บังคับให้ `confidentiality` เป็นนโยบายที่มีเวอร์ชันและย้อนกลับได้
     * **แต่ไม่ได้กำหนดเนื้อหาของกติกาไว้** — การจับคู่ชั้นความลับกับผู้มีสิทธิ์
     * เป็นกฎของแต่ละส่วนราชการ · ค่าเริ่มต้นในโค้ดจึงเป็นจุดตั้งต้นที่ปลอดภัย
     * ที่ผู้ดูแลปรับได้จากหน้าจอ ไม่ใช่กติกาที่ฝังตายในโค้ด
     */
    public const KEY_CONFIDENTIALITY = 'confidentiality';

    public function versions()
    {
        return $this->hasMany(PolicyVersion::class, 'policy_id')->orderByDesc('version_no');
    }

    public function publishedVersion()
    {
        return $this->hasOne(PolicyVersion::class, 'policy_id')
            ->where('status', PolicyVersion::STATUS_PUBLISHED)
            ->latest('version_no');
    }
}
