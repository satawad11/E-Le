<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Send a Telegram message.
     *
     * @param string $chatId
     * @param string $message
     * @return bool
     */
    public function sendMessage(string $chatId, string $message): bool
    {
        $tokenSetting = Setting::where('key', 'telegram_bot_token')->first();
        if (!$tokenSetting || empty($tokenSetting->value)) {
            Log::warning('Telegram bot token not configured.');
            return false;
        }

        $token = $tokenSetting->value;
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'HTML',
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram notification failed: ' . $e->getMessage());
            return false;
        }
    }
}
