<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineNotificationService
{
    /** Send a message through the LINE Messaging API. */
    public function sendMessage(string $message, ?string $recipientLineId = null): bool
    {
        try {
            $settings = app(SystemSettingService::class)->runtimeValues();

            $channelToken = $settings->get('line_channel_access_token');
            $targetUserId = $recipientLineId
                ?? $settings->get('line_group_id')
                ?? $settings->get('line_admin_user_id');

            if ($channelToken && $targetUserId) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $channelToken,
                    'Content-Type' => 'application/json',
                ])->post('https://api.line.me/v2/bot/message/push', [
                    'to' => $targetUserId,
                    'messages' => [
                        [
                            'type' => 'text',
                            'text' => $message,
                        ],
                    ],
                ]);

                return $response->successful();
            }

            return false;
        } catch (\Exception $e) {
            Log::error('LINE Notification Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Format and send leave application alert
     */
    public function notifyLeaveCreated($leave, string $userName): void
    {
        $startDateStr = \Carbon\Carbon::parse($leave->start_date)->addYears(543)->format('d/m/Y');
        $endDateStr = \Carbon\Carbon::parse($leave->end_date)->addYears(543)->format('d/m/Y');
        
        $msg = "\n🔔 [E-Leave] มีการยื่นใบลาใหม่\n"
             . "ผู้ยื่น: {$userName}\n"
             . "เลขที่: {$leave->document_number}\n"
             . "วันที่: {$startDateStr} ถึง {$endDateStr} ({$leave->total_days} วัน)\n"
             . "เหตุผล: {$leave->reason}";

        $this->sendMessage($msg);
    }

    /**
     * Format and send Saraban document alert
     */
    public function notifySarabanDocument($document, string $action): void
    {
        $msg = "\n📄 [e-Saraban] อัปเดตหนังสือราชการ ({$action})\n"
             . "เลขที่หนังสือ: {$document->document_number}\n"
             . "เรื่อง: {$document->title}\n"
             . "สถานะ: {$document->status}";

        $this->sendMessage($msg);
    }
}
