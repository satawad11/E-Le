<?php

namespace App\Services\Saraban;

use RuntimeException;

/**
 * การลงนามหรือการเกษียนที่ทำไม่ได้ (SoT §14)
 *
 * แยกจาก exception อื่นเพื่อให้ชั้น HTTP แปลงเป็น 422 พร้อมข้อความไทยได้ตรงจุด
 */
class SignatureException extends RuntimeException {}
