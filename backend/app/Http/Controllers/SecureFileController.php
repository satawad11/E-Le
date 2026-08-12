<?php

namespace App\Http\Controllers;

use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ช่องทางเดียวในการดาวน์โหลดไฟล์อัปโหลดผ่าน HTTP — ต้องยืนยันตัวตนเสมอ
 *
 * แทนที่ route เดิม GET /api/storage/{path} และ symlink public/storage
 * ที่เปิดให้ทุกคนบนอินเทอร์เน็ตเข้าถึงลายเซ็นและภาพใบหน้าบุคลากรได้ (SEC-001)
 *
 * ข้อจำกัดที่ยังเหลือ (งาน LEGACY-02): ยังไม่มี ABAC scope รายไฟล์
 * ผู้ใช้ที่ล็อกอินแล้วยังเปิดไฟล์ของผู้อื่นได้ถ้ารู้ path
 * ต้องเพิ่ม Policy ต่อ resource เมื่อทำ LEGACY-02 §02-B
 */
class SecureFileController extends Controller
{
    public function show(Request $request, string $path)
    {
        $relative = StoredFile::relative('/storage/' . ltrim($path, '/'));

        abort_if($relative === null, 404);

        $disk = Storage::disk(StoredFile::DISK);

        abort_unless($disk->exists($relative), 404);

        return $disk->response($relative, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
