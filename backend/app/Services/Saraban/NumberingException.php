<?php

namespace App\Services\Saraban;

use RuntimeException;

/**
 * การเปลี่ยนสถานะเลขหนังสือที่ผังไม่อนุญาต (SoT §9)
 *
 * แยกเป็น exception ของตัวเองเพื่อให้ชั้น HTTP แปลงเป็น 422 พร้อมข้อความไทยได้
 * โดยไม่ปะปนกับข้อผิดพลาดอื่นของระบบ
 */
class NumberingException extends RuntimeException {}
