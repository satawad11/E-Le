<?php

namespace App\Services\NotificationCenter\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ตัวเชื่อม HTTP ของ Telegram Bot API
 *
 * CLEANUP-01 (D8) — เดิมชื่อ `App\Services\TelegramService` และมี proxy ที่เลิกใช้แล้ว
 * (`TelegramNotificationService`) ซ้อนอยู่อีกชั้น ย้ายมาอยู่ใต้ Notification Center
 * เพื่อให้ชัดว่าเป็น transport ที่ `TelegramChannelAdapter` เรียกเท่านั้น
 * โมดูลธุรกิจต้องเรียกผ่าน `NotificationCenter::emit()` เสมอ
 */
class TelegramGateway
{
    public function sendMessage(string $chatId, string $message): bool
    {
        $token = Setting::query()->where('key', 'telegram_bot_token')->value('value');

        if (blank($token)) {
            Log::warning('Telegram bot token not configured.');

            return false;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            return $response->successful();
        } catch (\Throwable $exception) {
            Log::error('Telegram notification failed: '.$exception->getMessage());

            return false;
        }
    }
}
