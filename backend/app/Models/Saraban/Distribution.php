<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * การเวียนหนังสือหนึ่งครั้ง (SoT §15)
 *
 * หนึ่งแถวคือการส่งหนึ่งครั้ง ผู้รับทุกคนของครั้งนั้นอยู่ใน
 * `saraban_distribution_recipients` — หนังสือฉบับเดียวเวียนได้หลายครั้ง
 * และแต่ละครั้งอาจส่งคนละเวอร์ชันกัน
 */
#[Fillable([
    'uuid', 'document_id', 'document_version_id', 'sent_by', 'sent_at', 'note',
])]
class Distribution extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_distributions';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function recipients()
    {
        return $this->hasMany(DistributionRecipient::class, 'distribution_id');
    }
}
