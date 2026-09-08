<?php

namespace App\Services\Saraban;

use RuntimeException;

/**
 * การแก้สายการเดินหนังสือที่กติกาไม่อนุญาต (SoT §11, §12)
 *
 * แยกเป็น exception ของตัวเองเพื่อให้ชั้น HTTP แปลงเป็น 422 พร้อมข้อความไทย
 * ที่บอกเหตุผลได้ตรง ๆ — ผู้ดูแลที่กดแล้วถูกปฏิเสธต้องรู้ว่าติดกติกาข้อใด
 */
class WorkflowDesignException extends RuntimeException {}
