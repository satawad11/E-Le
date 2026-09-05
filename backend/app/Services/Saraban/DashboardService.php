<?php

namespace App\Services\Saraban;

use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\User;
use App\Services\OrgScopeResolver;
use App\Services\TrustedClock;

/**
 * ตัวเลขสรุปหน้าแรกของงานสารบรรณ (NAV §4.1)
 *
 * **ทุกตัวเลขต้องกดเข้าไปดูรายการจริงได้** — การ์ดที่บอกว่ามีงานค้าง 12 ฉบับ
 * แต่พาไปดูไม่ได้ คือการทำให้ผู้ใช้กังวลโดยไม่ช่วยอะไร · การ์ดแต่ละใบจึงคู่กับ
 * หน้าจอที่มีอยู่จริงเสมอ
 *
 * **จำกัดขอบเขตเดียวกับทะเบียน** — ตัวเลขสรุปที่นับข้ามหน่วยงาน คือการรั่วข้อมูล
 * แบบที่มองไม่ออกจากตัวเลข แต่บอกได้ว่าหน่วยงานอื่นมีงานมากน้อยเพียงใด
 */
class DashboardService
{
    public function __construct(
        private readonly WorkQueueService $queue,
        private readonly OrgScopeResolver $scopes,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function summaryFor(User $user): array
    {
        $today = $this->clock->now()->startOfDay();
        $monthStart = $this->clock->now()->startOfMonth();

        $visible = $this->scopes->visibleOrgUnitIds($user, 'saraban.document.view', OrgScopeResolver::UNIT);

        $scoped = fn () => Document::query()
            ->when(
                $visible !== null,
                fn ($query) => $query->where(fn ($q) => $q
                    ->whereIn('owner_org_unit_id', $visible ?? [])
                    ->orWhere('created_by', $user->id)),
            );

        /*
         * "รอฉันดำเนินการ" มาจากคิวงานตัวเดียวกับหน้าคิว — ตัวเลขกับรายการจึงตรงกัน
         * เสมอ · ถ้านับด้วยวิธีอื่น ผู้ใช้จะเจอการ์ดบอกว่ามี 3 แต่กดเข้าไปเห็น 5
         * ซึ่งทำให้ไม่เชื่อถือทั้งหน้าจอ
         */
        $waitingForMe = $this->queue->pendingFor($user)['count'];

        return [
            'tiles' => [
                [
                    'key' => 'waiting_for_me',
                    'label' => 'รอฉันดำเนินการ',
                    'value' => $waitingForMe,
                    'route' => '/saraban/documents/queue',
                    'alert' => $waitingForMe > 0,
                ],
                [
                    'key' => 'my_inbox',
                    'label' => 'หนังสือเวียนที่ยังไม่รับทราบ',
                    'value' => $this->unacknowledgedFor($user),
                    'route' => '/saraban/inbox',
                ],
                [
                    'key' => 'today',
                    'label' => 'หนังสือวันนี้',
                    'value' => $scoped()->where('created_at', '>=', $today)->count(),
                    'route' => '/saraban/documents',
                ],
                [
                    'key' => 'this_month',
                    'label' => 'หนังสือเดือนนี้',
                    'value' => $scoped()->where('created_at', '>=', $monthStart)->count(),
                    'route' => '/saraban/documents',
                ],
            ],

            /*
             * งานของฉัน — เอามาจากคิวงานโดยตรง จำกัดห้ารายการแรกเพื่อให้หน้าแรก
             * อ่านได้ในหน้าจอเดียว · ที่เหลือกดดูต่อได้ที่หน้าคิว
             */
            'my_work' => array_slice($this->queue->pendingFor($user)['pending'], 0, 5),
            'generated_at' => $this->clock->now()->toIso8601String(),
        ];
    }

    /** หนังสือเวียนที่ส่งถึงผู้ใช้คนนี้และยังไม่กดรับทราบ */
    private function unacknowledgedFor(User $user): int
    {
        return DistributionRecipient::query()
            ->where('recipient_id', $user->id)
            ->whereNull('acknowledged_at')
            ->count();
    }
}
