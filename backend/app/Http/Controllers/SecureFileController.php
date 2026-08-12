<?php

namespace App\Http\Controllers;

use App\Services\SecureFileAccessService;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ช่องทางเดียวในการดาวน์โหลดไฟล์อัปโหลดผ่าน HTTP — ต้องยืนยันตัวตนเสมอ
 *
 * แทนที่ route เดิม GET /api/storage/{path} และ symlink public/storage
 * ที่เปิดให้ทุกคนบนอินเทอร์เน็ตเข้าถึงลายเซ็นและภาพใบหน้าบุคลากรได้ (SEC-001)
 *
 * บังคับ authorization จาก resource ที่อ้างถึง path นั้น และ deny by default
 * หากไฟล์ไม่ได้ผูกกับทรัพยากรที่ผู้ใช้มีสิทธิ์
 */
class SecureFileController extends Controller
{
    public function show(Request $request, string $path, SecureFileAccessService $access)
    {
        $relative = StoredFile::relative('/storage/'.ltrim($path, '/'));

        abort_if($relative === null, 404);

        $disk = Storage::disk(StoredFile::DISK);

        abort_unless($disk->exists($relative), 404);
        abort_unless($access->canView($request->user(), $relative), 403);

        return $disk->response($relative, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
