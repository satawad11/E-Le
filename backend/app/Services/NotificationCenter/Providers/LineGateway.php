<?php

namespace App\Services\NotificationCenter\Providers;

use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ตัวเชื่อม HTTP ของ LINE Messaging API
 *
 * CLEANUP-01 (D8) — เดิมชื่อ `App\Services\LineNotificationService` และมีเมธอด
 * `notifyLeaveCreated()` ที่ประกอบข้อความใบลาเองแล้วส่งตรง ข้ามทั้ง template,
 * preference และ delivery log ของ Notification Center — ถอดออกแล้ว
 * โมดูลธุรกิจต้องเรียกผ่าน `NotificationCenter::emit()` เสมอ
 */
class LineGateway
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function sendMessage(string $message, ?string $recipientLineId = null): bool
    {
        try {
            $settings = $this->settings->runtimeValues();

            $channelToken = $settings->get('line_channel_access_token');
            $targetUserId = $recipientLineId
                ?? $settings->get('line_group_id')
                ?? $settings->get('line_admin_user_id');

            if (blank($channelToken) || blank($targetUserId)) {
                return false;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$channelToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.line.me/v2/bot/message/push', [
                'to' => $targetUserId,
                'messages' => [['type' => 'text', 'text' => $message]],
            ]);

            return $response->successful();
        } catch (\Throwable $exception) {
            Log::error('LINE Notification Exception: '.$exception->getMessage());

            return false;
        }
    }
}
