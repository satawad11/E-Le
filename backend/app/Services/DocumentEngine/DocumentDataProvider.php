<?php

namespace App\Services\DocumentEngine;

/**
 * สัญญาของแหล่งข้อมูลทุกตัวใน Document Engine
 *
 * เทมเพลตและรายงาน **ห้าม** แตะฐานข้อมูลเอง ต้องผ่าน provider ที่ลงทะเบียนไว้เท่านั้น
 * provider เป็นที่เดียวที่รู้จักโครงสร้างตารางของโมดูลนั้น ๆ
 */
interface DocumentDataProvider
{
    /** code ที่ใช้อ้างถึง provider นี้ (ต้องตรงกับ document_data_sources.code) */
    public function code(): string;

    /** โมดูลเจ้าของข้อมูล */
    public function module(): string;

    /**
     * ข้อมูลแบบแบน (dot notation) สำหรับเติมลงฟิลด์ของแบบฟอร์ม
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function fields(array $context): array;

    /**
     * รายการฟิลด์ที่ provider นี้ให้ได้ พร้อมคำอธิบายไทย — ใช้สร้าง Field Dictionary
     *
     * @return array<int,array{code:string,label_th:string,group_th:string,data_type:string,format?:string}>
     */
    public function catalog(): array;
}
