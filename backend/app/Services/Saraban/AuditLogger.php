<?php

namespace App\Services\Saraban;

use App\Models\Saraban\AuditLog;
use App\Models\Saraban\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * บันทึก audit log ของโมดูลสารบัญ (CLAUDE.md กฎข้อ 8)
 * ทุก endpoint ที่ สร้าง/แก้ไข/ลบ/ลงนาม/ดาวน์โหลด ต้องเรียกผ่านคลาสนี้
 */
class AuditLogger
{
    /**
     * @param  string  $action  เช่น document.created, document.deleted, document.signed
     * @param  Document|int|null  $document
     */
    public static function log(string $action, Document|int|null $document = null, array $payload = [], ?int $userId = null): ?AuditLog
    {
        $documentId = $document instanceof Document ? $document->id : $document;

        try {
            return AuditLog::create([
                'user_id' => $userId ?? Auth::id(),
                'document_id' => $documentId,
                'action' => $action,
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255),
                'payload' => $payload ?: null,
            ]);
        } catch (\Throwable $e) {
            // audit log ต้องไม่ทำให้ธุรกรรมหลักล้ม แต่ต้องเห็นใน log ของระบบ
            Log::error('Saraban audit log failed: ' . $e->getMessage(), [
                'action' => $action,
                'document_id' => $documentId,
            ]);

            return null;
        }
    }
}
