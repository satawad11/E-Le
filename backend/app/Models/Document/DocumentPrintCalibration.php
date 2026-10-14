<?php

namespace App\Models\Document;

use Illuminate\Database\Eloquent\Model;

/** ค่าชดเชยการพิมพ์ต่อ global / แม่แบบ / ผู้ใช้ */
class DocumentPrintCalibration extends Model
{
    public const SCOPE_GLOBAL = 'GLOBAL';

    public const SCOPE_TEMPLATE = 'TEMPLATE';

    public const SCOPE_USER = 'USER';

    protected $guarded = [];

    protected $casts = ['x_offset' => 'float', 'y_offset' => 'float', 'scale' => 'float', 'is_active' => 'boolean'];
}
