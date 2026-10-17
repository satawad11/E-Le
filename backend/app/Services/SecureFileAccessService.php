<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\Saraban\DocumentAttachment;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Gate;

class SecureFileAccessService
{
    public function canView(User $user, string $relative): bool
    {
        $stored = '/storage/'.$relative;

        if ($this->isOwnProfileAsset($user, $stored)) {
            return true;
        }

        if ($this->isManagedProfileAsset($user, $stored)) {
            return true;
        }

        if ($this->isAccessibleAttendanceImage($user, $stored)) {
            return true;
        }

        if ($this->isAccessibleLeaveAsset($user, $stored)) {
            return true;
        }

        if ($this->isAccessibleSarabanAttachment($user, $relative, $stored)) {
            return true;
        }

        return Setting::query()
            ->whereIn('key', ['organization_logo', 'saraban_garuda_logo'])
            ->where('value', $stored)
            ->exists();
    }

    private function isOwnProfileAsset(User $user, string $stored): bool
    {
        return UserProfile::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->where('profile_photo_path', $stored)
                ->orWhere('signature_path', $stored))
            ->exists();
    }

    private function isManagedProfileAsset(User $user, string $stored): bool
    {
        if (! $user->hasAnyPermission(['view_users', 'edit_users', 'system.user.manage'])) {
            return false;
        }

        return UserProfile::query()
            ->where(fn ($query) => $query
                ->where('profile_photo_path', $stored)
                ->orWhere('signature_path', $stored))
            ->exists();
    }

    private function isAccessibleAttendanceImage(User $user, string $stored): bool
    {
        $attendance = Attendance::query()
            ->where(fn ($query) => $query
                ->where('check_in_image', $stored)
                ->orWhere('check_out_image', $stored))
            ->first();

        if (! $attendance) {
            return false;
        }

        return (int) $attendance->user_id === (int) $user->id
            || $user->can('attendance.view_all');
    }

    private function isAccessibleLeaveAsset(User $user, string $stored): bool
    {
        $leave = Leave::query()
            ->where(fn ($query) => $query
                ->where('signature_path', $stored)
                ->orWhere('attachment', $stored))
            ->first();

        if (! $leave) {
            $leave = LeaveApproval::query()
                ->where('signature_path', $stored)
                ->first()
                ?->leave;
        }

        return $leave !== null && Gate::forUser($user)->allows('view', $leave);
    }

    private function isAccessibleSarabanAttachment(User $user, string $relative, string $stored): bool
    {
        $attachment = DocumentAttachment::query()
            ->whereIn('path', [$relative, $stored])
            ->first();

        return $attachment !== null
            && Gate::forUser($user)->allows('download', $attachment->document);
    }
}
