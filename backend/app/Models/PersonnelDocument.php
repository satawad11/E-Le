<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เอกสารบุคลากร — ไฟล์อยู่ใน private disk เท่านั้น ไม่ส่ง `storage_path` ออก API
 */
class PersonnelDocument extends Model
{
    use HasPublicUuid;

    protected $fillable = ['personnel_id', 'category', 'storage_path', 'original_name', 'mime', 'size', 'uploaded_by', 'verified_by', 'verified_at'];

    protected $casts = ['size' => 'integer', 'verified_at' => 'datetime'];

    protected $hidden = ['id', 'personnel_id', 'storage_path', 'uploaded_by', 'verified_by'];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
