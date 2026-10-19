<?php

namespace App\Events;

use App\Models\Assignment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * การดำรงตำแหน่งเปลี่ยน (CORE-02) — ผู้ฟัง: ล้าง permission cache (R-04), JML ของบัญชี (R-05), แจ้งผู้ใช้
 */
class AssignmentChanged
{
    use Dispatchable;

    public const CREATED = 'created';

    public const ENDED = 'ended';

    public function __construct(public readonly Assignment $assignment, public readonly string $action) {}
}
