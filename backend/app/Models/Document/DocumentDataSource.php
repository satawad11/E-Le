<?php

namespace App\Models\Document;

use Illuminate\Database\Eloquent\Model;

/**
 * แหล่งข้อมูลที่ระบบอนุญาต (whitelist)
 *
 * ผู้ดูแลเลือกได้เฉพาะรายการที่ลงทะเบียนไว้ในโค้ดเท่านั้น
 * ห้ามรับ SQL อิสระจากหน้าจอไม่ว่ากรณีใด (SEC — Document Engine ข้อ 28)
 */
class DocumentDataSource extends Model
{
    public const KIND_RECORD = 'RECORD';

    public const KIND_DATASET = 'DATASET';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
