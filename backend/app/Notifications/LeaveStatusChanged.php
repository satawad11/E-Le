<?php

namespace App\Notifications;

use App\Models\Leave;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeaveStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public $leave;

    public $status;

    public $actionBy;

    /**
     * Create a new notification instance.
     */
    public function __construct(Leave $leave, string $status, string $actionBy)
    {
        $this->leave = $leave;
        $this->status = $status;
        $this->actionBy = $actionBy;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'leave_id' => $this->leave->id,
            'message' => 'ใบลาของคุณ ('.($this->leave->type->name ?? 'ลางาน').') ได้รับการปรับสถานะเป็น '.$this->status.' โดย '.$this->actionBy,
            'status' => $this->status,
        ];
    }
}
