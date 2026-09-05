<?php

namespace App\Models\Saraban;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * การลงทะเบียนรับหนังสือหนึ่งฉบับ (SoT §10)
 *
 * แยกข้อมูล "ของหนังสือต้นทาง" ออกจาก "ของการรับ" อย่างชัดเจน — ดูเหตุผลเต็ม
 * ในคอมเมนต์ของ migration `2026_09_04_130000_create_saraban_incoming_registrations`
 */
#[Fillable([
    'uuid', 'document_id', 'source_number', 'source_date', 'sender_name',
    'document_number_id', 'received_at', 'registered_by', 'note',
])]
class IncomingRegistration extends Model
{
    use HasPublicUuid;

    protected $table = 'saraban_incoming_registrations';

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** เลขทะเบียนรับ — ไม่ใช่เลขของหนังสือต้นทาง */
    public function registerNumber()
    {
        return $this->belongsTo(DocumentNumber::class, 'document_number_id');
    }

    public function registrar()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
