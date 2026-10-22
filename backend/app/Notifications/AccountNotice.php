<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * อีเมลเรื่องบัญชีและความปลอดภัย (CORE-03 · R-05) — ส่งผ่านคิว ไม่ถ่วงการเข้าสู่ระบบ
 *
 * kind: account_setup · new_device · break_glass_used · inactive_suspended · leaver_handover · access_changed · mfa_reset · bootstrap_grant
 * เนื้อหาไม่ใส่ข้อมูลอ่อนไหว (เลขประจำตัว รหัสผ่าน รหัส MFA)
 */
class AccountNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const ACCOUNT_SETUP = 'account_setup';

    public const NEW_DEVICE = 'new_device';

    public const BREAK_GLASS_USED = 'break_glass_used';

    public const INACTIVE_SUSPENDED = 'inactive_suspended';

    public const LEAVER_HANDOVER = 'leaver_handover';

    public const ACCESS_CHANGED = 'access_changed';

    public const MFA_RESET = 'mfa_reset';

    public const BOOTSTRAP_GRANT = 'bootstrap_grant';

    /** @param  array<int, string>  $lines */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $actionText = null,
        public readonly ?string $actionUrl = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $message->line($line);
        }
        if ($this->actionText !== null && $this->actionUrl !== null) {
            $message->action($this->actionText, $this->actionUrl);
        }

        return $message->line('อีเมลนี้ส่งจากระบบบุคลากร วิทยาลัยเทคนิคดอนเมือง โดยอัตโนมัติ');
    }
}
