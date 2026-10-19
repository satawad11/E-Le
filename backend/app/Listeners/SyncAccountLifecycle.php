<?php

namespace App\Listeners;

use App\Events\AssignmentChanged;
use App\Events\PersonnelStatusChanged;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Access\AccountLifecycleService;

/**
 * JML อัตโนมัติจาก event ของ CORE-02 (R05-1) — `accounts:reconcile` ทำซ้ำทุกวันเผื่อ event หลุด
 *
 * - PersonnelStatusChanged → ปรับบัญชีตามสถานะบุคลากร (Joiner / Leaver / ระงับ)
 * - AssignmentChanged (Mover) → engine ล้าง cache แล้ว (AppServiceProvider) · แจ้งเจ้าของบัญชีว่าสิทธิ์เปลี่ยน
 */
class SyncAccountLifecycle
{
    public function __construct(private readonly AccountLifecycleService $lifecycle) {}

    public function handlePersonnelStatus(PersonnelStatusChanged $event): void
    {
        $this->lifecycle->syncFromPersonnel($event->personnel->fresh(['profile']), auth()->id());
    }

    public function handleAssignment(AssignmentChanged $event): void
    {
        $user = User::where('personnel_id', $event->assignment->personnel_id)->first();
        if ($user === null || ! $user->isActive()) {
            return;
        }

        $assignment = $event->assignment->loadMissing(['position', 'orgUnit']);
        $what = ($assignment->position?->name ?? 'ตำแหน่ง').' · '.($assignment->orgUnit?->name ?? '');

        $user->notify(new AccountNotice(
            AccountNotice::ACCESS_CHANGED,
            'สิทธิ์การใช้งานระบบบุคลากรของคุณเปลี่ยน',
            [
                ($event->action === AssignmentChanged::CREATED ? 'เพิ่มการดำรงตำแหน่ง: ' : 'สิ้นสุดการดำรงตำแหน่ง: ').$what,
                'เมนูและข้อมูลที่เห็นปรับตามตำแหน่งแล้ว ถ้าไม่ถูกต้องให้แจ้งงานบุคลากร',
            ],
        ));
    }
}
