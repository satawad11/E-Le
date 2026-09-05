<?php

namespace App\Services\Saraban;

use RuntimeException;

/**
 * การจัดการนโยบายที่ทำไม่ได้ (SoT §18)
 *
 * แยกจาก exception อื่นเพื่อให้ชั้น HTTP แปลงเป็น 422 พร้อมข้อความไทยที่ตรงเรื่อง
 */
class PolicyException extends RuntimeException {}
