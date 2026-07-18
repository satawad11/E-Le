<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SarabanDocumentNotification extends Notification
{
    use Queueable;

    protected $documentId;
    protected $title;
    protected $message;
    protected $actionType;

    public function __construct($documentId, $title, $message, $actionType)
    {
        $this->documentId = $documentId;
        $this->title = $title;
        $this->message = $message;
        $this->actionType = $actionType;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'document_id' => $this->documentId,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->actionType,
            'url' => "/saraban/{$this->documentId}"
        ];
    }
}
