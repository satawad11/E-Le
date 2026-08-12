<?php

namespace App\Console\Commands;

use App\Models\Leave;
use App\Models\LeaveEvidence;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeaveGovernanceReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessLeaveGovernanceCommand extends Command
{
    protected $signature = 'leave:governance {--purge}';

    protected $description = 'แจ้งเตือนใบลาและบังคับใช้นโยบายเก็บรักษาหลักฐาน';

    public function handle(): int
    {
        Leave::where('status', 'Pending')->where('submitted_at', '<=', now()->subDays(2))->each(function ($leave) {
            User::permission(['leave.approval.hr', 'leave.management.manage'])->each(fn ($u) => $u->notify(new LeaveGovernanceReminder('มีใบลาค้างอนุมัติ', "ใบลา {$leave->document_number} ค้างเกิน 2 วัน", $leave->id)));
        });
        Leave::where('status', 'Approved')->whereDate('start_date', tomorrow())->with('user')->each(fn ($l) => $l->user?->notify(new LeaveGovernanceReminder('แจ้งเตือนวันลา', 'ใบลาของคุณจะเริ่มในวันพรุ่งนี้', $l->id)));
        if ($this->option('purge')) {
            $days = (int) (Setting::where('key', 'leave_evidence_retention_days')->value('value') ?? 2555);
            LeaveEvidence::whereHas('leave', fn ($q) => $q->onlyTrashed()->where('deleted_at', '<=', now()->subDays($days)))->each(function ($e) {
                Storage::disk('local')->delete($e->storage_path);
                $e->delete();
            });
        }

        return self::SUCCESS;
    }
}
