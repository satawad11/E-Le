<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
class LeaveGovernanceReminder extends Notification
{
    use Queueable;
    public function __construct(public string $title,public string $message,public ?int $leaveId=null){}
    public function via(object $notifiable):array{return ['database'];}
    public function toArray(object $notifiable):array{return ['title'=>$this->title,'message'=>$this->message,'leave_id'=>$this->leaveId,'url'=>$this->leaveId?'/leaves':null];}
}
