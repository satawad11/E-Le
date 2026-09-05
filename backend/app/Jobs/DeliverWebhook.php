<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ส่ง webhook หนึ่งครั้ง (SoT §17)
 *
 * `tries = 1` เหมือน `DeliverNotification` — การลองใหม่เป็นหน้าที่ของ
 * `WebhookSender` ที่นับครั้งและหน่วงเวลาไว้ในตาราง ไม่ใช่ของคิว
 * เพราะการลองใหม่ต้องมองเห็นและตรวจสอบได้ ไม่ใช่ซ่อนอยู่ในระบบคิว
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookSender $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
