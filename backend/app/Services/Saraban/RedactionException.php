<?php

namespace App\Services\Saraban;

use RuntimeException;

/** การปกปิดข้อมูลที่ทำไม่ได้ (SoT §22) */
class RedactionException extends RuntimeException {}
