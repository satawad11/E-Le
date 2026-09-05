<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * หลักฐานการลงนามหนึ่งครั้ง (SoT §14 · INT-11)
 *
 * เป็นระเบียนที่ **แก้ไม่ได้** — การลงนามเป็นเหตุการณ์ที่เกิดขึ้นแล้ว
 * การแก้หลักฐานย้อนหลังคือการทำลายคุณค่าของมัน
 */
#[Fillable([
    'uuid', 'document_id', 'document_version_id', 'signer_id',
    'signer_name_snapshot', 'position_snapshot', 'org_unit_snapshot', 'authority_snapshot',
    'signed_at', 'signature_method', 'document_hash',
    'auth_token_id', 'reauthenticated_at', 'ip_address', 'user_agent',
])]
class SignatureEvidence extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_signature_evidences';

    public const METHOD_DRAWN = 'drawn';

    public const METHOD_UPLOADED = 'uploaded';

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('หลักฐานการลงนามแก้ไขไม่ได้'));
        static::deleting(fn () => throw new \LogicException('หลักฐานการลงนามลบไม่ได้'));
    }

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'reauthenticated_at' => 'datetime',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signer_id');
    }
}
