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
    'signed_at', 'signature_method', 'signature_type', 'signing_sequence', 'document_hash',
    'auth_token_id', 'reauthenticated_at', 'ip_address', 'user_agent',
    'certificate_serial', 'certificate_subject', 'certificate_issuer', 'certificate_fingerprint',
    'certificate_valid_from', 'certificate_valid_to', 'certificate_revoked_at',
    'timestamp_authority', 'timestamp_token_hash', 'timestamped_at',
])]
class SignatureEvidence extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_signature_evidences';

    public const METHOD_DRAWN = 'drawn';

    public const METHOD_UPLOADED = 'uploaded';

    /**
     * ลายเซ็นอิเล็กทรอนิกส์ — ผูกกับตัวตนที่ยืนยันแล้วและมีหลักฐานประกอบครบ
     *
     * **นี่คือสิ่งที่ระบบทำได้จริงวันนี้** และเป็นค่าเริ่มต้นของทุกลายเซ็น
     */
    public const TYPE_ELECTRONIC = 'electronic';

    /**
     * ลายเซ็นดิจิทัล — ลงนามด้วยกุญแจส่วนตัวและมีใบรับรองรองรับ
     *
     * **ห้ามตั้งค่านี้โดยไม่มีข้อมูลใบรับรองจริง** — บริการตรวจสอบจะรายงานว่า
     * ไม่ผ่านทันที เพราะการอ้างว่าเป็นลายเซ็นดิจิทัลโดยไม่มีหลักฐานเชิงรหัสวิทยา
     * อันตรายกว่าการไม่อ้างอะไรเลย
     */
    public const TYPE_DIGITAL = 'digital';

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
            'certificate_valid_from' => 'datetime',
            'certificate_valid_to' => 'datetime',
            'certificate_revoked_at' => 'datetime',
            'timestamped_at' => 'datetime',
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
