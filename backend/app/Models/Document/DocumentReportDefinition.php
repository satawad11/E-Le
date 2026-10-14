<?php

namespace App\Models\Document;

use Illuminate\Database\Eloquent\Model;

/** โครงรายงานที่ผู้ดูแลสร้างได้จากหน้าจอ โดยดึงข้อมูลผ่าน Data Provider ที่ whitelist ไว้ */
class DocumentReportDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'filters' => 'array', 'columns' => 'array', 'grouping' => 'array',
        'aggregates' => 'array', 'layout' => 'array', 'is_active' => 'boolean',
    ];
}
