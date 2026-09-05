<?php

namespace App\Services\Operations;

use App\Jobs\DeliverNotification;
use App\Jobs\DeliverWebhook;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Models\SystemException;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * คิวข้อผิดพลาดที่ต้องมีคนตามแก้ (SoT §19)
 *
 * **ความสามารถของระบบกลาง** — ทุกโมดูลเรียก `raise()` ได้ ไม่ใช่ของสารบรรณ
 *
 * หลักที่คลาสนี้บังคับ:
 *
 *   1. **ปัญหาเดิมที่เกิดซ้ำเป็นแถวเดียว** — ระบบที่แจ้งเตือนซ้ำพันครั้งเท่ากับ
 *      ไม่แจ้งเตือน เพราะคนจะเลิกอ่าน · ใช้ `dedupe_key` นับจำนวนขึ้นแทน
 *   2. **การสั่งทำซ้ำต้องรู้ว่าจะสั่งอะไร** — เก็บ `retry_type` + `retry_id`
 *      แยกจากตัวเรื่อง เพราะบางข้อผิดพลาดชี้ไปที่หนังสือ แต่สิ่งที่ต้องสั่งใหม่
 *      คือแถวการส่ง
 *   3. **ทุกการกระทำลง audit** (SoT §19) — assign / ack / retry / resolve
 *   4. **การยกข้อผิดพลาดห้ามล้มธุรกรรมที่กำลังทำอยู่** — เหมือนการแจ้งเตือน
 */
class ExceptionQueue
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * บันทึกข้อผิดพลาดหนึ่งรายการ หรือเพิ่มจำนวนครั้งของรายการเดิม
     *
     * @param  array<string,mixed>  $detail
     */
    public function raise(
        string $type,
        string $title,
        array $detail = [],
        ?Model $subject = null,
        string $severity = SystemException::SEVERITY_WARNING,
        ?string $dedupeKey = null,
        ?string $module = null,
        ?string $retryType = null,
        ?int $retryId = null,
    ): SystemException {
        $now = $this->clock->now();

        /*
         * `dedupe_key` ที่ไม่ได้ระบุ ให้คำนวณจากชนิดและตัวเรื่อง — ปัญหาเดียวกัน
         * กับเอกสารฉบับเดียวกันต้องเป็นแถวเดียวเสมอ แม้ผู้เรียกจะไม่ได้คิดมาก่อน
         */
        $key = $dedupeKey ?? implode(':', array_filter([
            $type,
            $subject?->getMorphClass(),
            $subject?->getKey(),
            $retryType,
            $retryId,
        ]));

        $existing = SystemException::query()->where('dedupe_key', $key)->first();

        if ($existing !== null) {
            /*
             * ปัญหาที่เคยปิดไปแล้วกลับมาอีก = ปัญหาใหม่ที่ต้องมีคนดู
             * ไม่ใช่การเพิ่มจำนวนครั้งของเรื่องที่ปิดไปแล้วเงียบ ๆ
             */
            $reopened = $existing->status === SystemException::STATUS_RESOLVED;

            $existing->forceFill([
                'occurrence_count' => $existing->occurrence_count + 1,
                'last_seen_at' => $now,
                'detail' => $detail ?: $existing->detail,
                'status' => $reopened ? SystemException::STATUS_OPEN : $existing->status,
                'resolved_at' => $reopened ? null : $existing->resolved_at,
                'resolved_by' => $reopened ? null : $existing->resolved_by,
            ])->save();

            return $existing;
        }

        try {
            return SystemException::create([
                'type' => $type,
                'severity' => $severity,
                'module' => $module,
                'title' => mb_substr($title, 0, 255),
                'detail' => $detail,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'retry_type' => $retryType,
                'retry_id' => $retryId,
                'status' => SystemException::STATUS_OPEN,
                'dedupe_key' => mb_substr($key, 0, 190),
                'correlation_id' => request()?->attributes?->get('request_id'),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // สองคำขอยกข้อผิดพลาดเดียวกันพร้อมกัน — ผู้แพ้ไปอ่านแถวที่มีอยู่
            return SystemException::query()->where('dedupe_key', $key)->firstOrFail();
        }
    }

    /** รับทราบ — "เห็นแล้ว กำลังดูอยู่" ไม่ใช่ "แก้แล้ว" */
    public function acknowledge(SystemException $exception, User $actor): SystemException
    {
        $exception->forceFill([
            'status' => $exception->status === SystemException::STATUS_ASSIGNED
                ? SystemException::STATUS_ASSIGNED
                : SystemException::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => $this->clock->now(),
            'acknowledged_by' => $actor->id,
        ])->save();

        $this->log('system.exception.acknowledged', $exception, $actor);

        return $exception;
    }

    public function assign(SystemException $exception, User $assignee, User $actor): SystemException
    {
        $exception->forceFill([
            'status' => SystemException::STATUS_ASSIGNED,
            'assigned_to' => $assignee->id,
        ])->save();

        $this->log('system.exception.assigned', $exception, $actor, ['assigned_to' => $assignee->id]);

        return $exception;
    }

    public function resolve(SystemException $exception, User $actor, ?string $note = null): SystemException
    {
        $exception->forceFill([
            'status' => SystemException::STATUS_RESOLVED,
            'resolved_at' => $this->clock->now(),
            'resolved_by' => $actor->id,
            'resolution_note' => $note,
        ])->save();

        $this->log('system.exception.resolved', $exception, $actor, ['note' => $note]);

        return $exception;
    }

    /**
     * สั่งทำงานที่ล้มเหลวซ้ำ
     *
     * **ไม่ปิดรายการให้เอง** — การสั่งซ้ำไม่ได้แปลว่าสำเร็จ · ถ้ายังล้มอีก
     * รายการจะถูกยกขึ้นมาใหม่และจำนวนครั้งเพิ่ม ซึ่งเป็นข้อมูลที่ต้องเห็น
     *
     * @throws ExceptionQueueException
     */
    public function retry(SystemException $exception, User $actor): void
    {
        match ($exception->retry_type) {
            SystemException::RETRY_NOTIFICATION => $this->retryNotification($exception),
            SystemException::RETRY_WEBHOOK => $this->retryWebhook($exception),
            default => throw new ExceptionQueueException('รายการนี้สั่งทำซ้ำอัตโนมัติไม่ได้ ต้องแก้ที่ต้นเหตุ'),
        };

        $this->log('system.exception.retried', $exception, $actor, [
            'retry_type' => $exception->retry_type,
            'retry_id' => $exception->retry_id,
        ]);
    }

    private function retryNotification(SystemException $exception): void
    {
        $delivery = NotificationDelivery::query()->find($exception->retry_id);

        if ($delivery === null) {
            throw new ExceptionQueueException('ไม่พบรายการส่งการแจ้งเตือนที่จะสั่งซ้ำ');
        }

        $delivery->forceFill([
            'status' => 'pending',
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'dead_lettered_at' => null,
        ])->save();

        DeliverNotification::dispatch($delivery->id);
    }

    private function retryWebhook(SystemException $exception): void
    {
        $delivery = WebhookDelivery::query()->find($exception->retry_id);

        if ($delivery === null) {
            throw new ExceptionQueueException('ไม่พบรายการส่ง webhook ที่จะสั่งซ้ำ');
        }

        $delivery->forceFill([
            'status' => 'pending',
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'dead_lettered_at' => null,
        ])->save();

        DeliverWebhook::dispatch($delivery->id);
    }

    /**
     * กวาดหาข้อผิดพลาดที่ระบบรู้อยู่แล้วแต่ยังไม่เคยเข้าคิว
     *
     * dead-letter ของศูนย์แจ้งเตือน (INT-14) และของ webhook (INT-15) ถูกบันทึกไว้
     * ตั้งแต่ตอนนั้นแล้ว แต่ยังไม่มีใครถูกมอบหมายให้ตามแก้ · ตัวกวาดนี้ทำให้
     * ของที่มีอยู่แล้วกลายเป็นงานที่มีเจ้าของ โดยไม่ต้องแก้โค้ดที่บันทึกมันไว้
     */
    public function sweepDeadLetters(): int
    {
        $raised = 0;

        NotificationDelivery::query()
            ->whereNotNull('dead_lettered_at')
            ->with('recipient.event.type')
            ->get()
            ->each(function (NotificationDelivery $delivery) use (&$raised) {
                $this->raise(
                    type: 'notification.dead_letter',
                    title: "ส่งการแจ้งเตือนทาง {$delivery->channel} ไม่สำเร็จ",
                    detail: [
                        'channel' => $delivery->channel,
                        'attempts' => $delivery->attempt_count,
                        'last_error' => $delivery->last_error,
                        'notification_type' => $delivery->recipient?->event?->type?->code,
                    ],
                    subject: $delivery,
                    severity: SystemException::SEVERITY_WARNING,
                    module: 'notification',
                    retryType: SystemException::RETRY_NOTIFICATION,
                    retryId: $delivery->id,
                );
                $raised++;
            });

        WebhookDelivery::query()
            ->whereNotNull('dead_lettered_at')
            ->with('endpoint')
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$raised) {
                $this->raise(
                    type: 'webhook.dead_letter',
                    title: "ส่ง webhook ไปยัง {$delivery->endpoint?->name} ไม่สำเร็จ",
                    detail: [
                        'event' => $delivery->event,
                        'attempts' => $delivery->attempt_count,
                        'response_status' => $delivery->response_status,
                        'last_error' => $delivery->last_error,
                    ],
                    subject: $delivery,
                    severity: SystemException::SEVERITY_WARNING,
                    module: 'integration',
                    retryType: SystemException::RETRY_WEBHOOK,
                    retryId: $delivery->id,
                );
                $raised++;
            });

        return $raised;
    }

    /** @param array<string,mixed> $extra */
    private function log(string $action, SystemException $exception, User $actor, array $extra = []): void
    {
        $this->audit->log($action, $exception, $exception->id, null, [
            'type' => $exception->type,
            'title' => $exception->title,
            ...$extra,
        ], null, $actor->id);
    }
}
