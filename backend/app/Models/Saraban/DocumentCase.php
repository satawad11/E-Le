<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * สารบบเรื่อง — การจัดกลุ่มหนังสือเชิงตรรกะ (SoT §22)
 *
 * **ไม่เปลี่ยนตัวตนของหนังสือ** — หนังสือฉบับหนึ่งอยู่ในสารบบได้หลายเรื่อง
 * ถูกถอดออกได้ และเลขที่หนังสือกับทะเบียนไม่เปลี่ยนแปลงเลย
 *
 * ชื่อคลาสไม่ใช้ `Case` เพราะเป็นคำสงวนของ PHP
 */
#[Fillable(['uuid', 'code', 'title', 'summary', 'status', 'owner_org_unit_id', 'created_by', 'closed_at'])]
class DocumentCase extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_cases';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function documents()
    {
        return $this->belongsToMany(Document::class, 'saraban_case_documents', 'case_id', 'document_id')
            ->withPivot(['note', 'added_by', 'created_at'])
            ->withTimestamps();
    }

    public function ownerOrgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'owner_org_unit_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
