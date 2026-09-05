<?php

namespace App\Http\Requests\Saraban;

/**
 * INT-05 — แก้ไขหนังสือฉบับร่าง
 *
 * ใช้กฎชุดเดียวกับตอนสร้าง เพราะข้อมูลที่ยอมรับได้ต้องเหมือนกัน การมีสองชุด
 * ที่ต่างกันเล็กน้อยคือที่มาของช่องโหว่แบบ "สร้างไม่ผ่านแต่แก้ผ่าน"
 *
 * สิทธิ์ในการแก้ (ต้องเป็นฉบับร่าง) ตัดสินที่ `DocumentPolicy::update()`
 */
class UpdateDocumentRequest extends StoreDocumentRequest {}
