<?php

namespace App\Services\Saraban;

use RuntimeException;

/** การเก็บรักษาหรือการทำลายเอกสารที่ทำไม่ได้ (SoT §25) */
class RetentionException extends RuntimeException {}
