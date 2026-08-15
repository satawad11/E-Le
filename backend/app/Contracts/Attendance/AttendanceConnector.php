<?php

namespace App\Contracts\Attendance;

use App\Models\AttendanceSource;

interface AttendanceConnector
{
    /** @return iterable<array<string, mixed>> Normalized vendor-neutral event envelopes. */
    public function pull(AttendanceSource $source, ?string $cursor = null): iterable;
}
