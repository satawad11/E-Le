<?php

namespace App\Services\Saraban;

use RuntimeException;

/**
 * การเวียนหนังสือหรือการรายงานผลที่ทำไม่ได้ (SoT §15)
 *
 * แยกจาก `SignatureException` เพื่อให้ชั้น HTTP แปลงเป็น 422 พร้อมข้อความไทย
 * ที่ตรงกับเรื่องการเวียนหนังสือ ไม่ใช่เรื่องการลงนาม
 */
class DistributionException extends RuntimeException {}
