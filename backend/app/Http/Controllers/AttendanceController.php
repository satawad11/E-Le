<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\Leave;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attendance\AttendanceEventRecorder;
use App\Services\Attendance\AttendanceReconciliationService;
use App\Services\Attendance\AttendanceScopeService;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\SystemSettingService;
use App\Services\ThaiDateService;
use App\Services\Time\TimeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * LEGACY-06 — บริการเวลากลาง
     *
     * โมดูลนี้ไม่คำนวณวันทำงาน เวลาเริ่ม/เลิกงาน หรือการมาสายเองอีกต่อไป
     */
    public function __construct(
        private readonly TimeService $time,
        private readonly AttendanceEventRecorder $events,
        private readonly AttendanceReconciliationService $reconciliation,
        private readonly AttendanceScopeService $scope,
        private readonly NotificationCenter $notifications,
    ) {}

    /**
     * Check in for the day
     */
    public function checkIn(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string', // Format: "latitude,longitude"
            'notes' => 'nullable|string',
            'image' => 'nullable|string|max:7000000', // base64 data URL, decoded size checked below
        ]);

        $user = auth()->user();
        $time = $this->time->now($user);
        $date = $time->toDateString();

        // ดึง settings ทั้งหมดครั้งเดียว (แทนการ query แยก 5 ครั้ง)
        $settings = app(SystemSettingService::class)->runtimeValues();

        // Check if already checked in today
        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $date)
            ->first();

        if ($attendance && $attendance->check_in_time) {
            return response()->json(['message' => 'คุณได้ลงเวลาเข้างานของวันนี้ไปแล้ว'], 400);
        }

        // Calculate distance if location is provided
        $distance = null;
        if ($request->location) {
            $distance = $this->calculateDistanceFromCollege($request->location, $settings);
        }

        // Geofencing Check
        $maxRadius = $settings->get('max_attendance_radius', '') !== '' ? (float) $settings->get('max_attendance_radius', 0) : 0;

        if ($maxRadius > 0 && $distance !== null && $distance > $maxRadius) {
            if (! $request->notes) {
                return response()->json(['message' => 'คุณอยู่นอกพื้นที่สำนักงาน (ห่าง '.round($distance).' เมตร) กรุณาระบุหมายเหตุการลงเวลา'], 400);
            }
        }

        // Selfie Check
        $requireSelfie = $settings->get('require_attendance_selfie') == '1';

        if ($requireSelfie && ! $request->image) {
            return response()->json(['message' => 'กรุณาถ่ายรูปยืนยันตัวตนก่อนลงเวลา'], 400);
        }

        $imagePath = null;
        if ($request->image) {
            $imagePath = $this->storeAttendanceImage($request->image);
        }

        /*
         * LEGACY-06 — ใช้บริการเวลากลางแทนการคำนวณเอง
         *
         * ตรรกะเดิมเทียบเวลาเป็นสตริงกับ `users.work_start_time` ตรง ๆ ซึ่ง:
         *   - ไม่รู้จักวันหยุดเลย จึงนับ "มาสาย" ได้แม้ในวันหยุดราชการ
         *   - ไม่มีการผ่อนผัน สายทันทีที่เกินเวลาเริ่มงาน 1 วินาที
         *   - ไม่รองรับตารางรายวัน กะ หรือกะข้ามวัน
         *
         * ตอนนี้ทั้งระบบตอบคำถามเรื่องเวลาจากแหล่งเดียวกัน
         */
        $lateMinutes = $this->time->lateMinutes($user, $time, $time);
        $status = $lateMinutes > 0 ? 'Late' : 'Present';

        $source = AttendanceSource::firstOrCreate(
            ['code' => 'web'],
            ['name' => 'ระบบเว็บ', 'type' => 'web', 'timezone' => 'Asia/Bangkok', 'health_state' => 'healthy'],
        );
        $recorded = $this->events->record(
            $source,
            'check_in',
            $time,
            "web:{$user->id}:{$date}:check_in",
            ['location' => $request->location, 'distance' => $distance, 'image_path' => $imagePath, 'notes' => $request->notes],
            $user,
        );
        $attendance = $this->reconciliation->reconcile($recorded['event']);

        if ($lateMinutes > 0) {
            $reviewers = User::permission('attendance.view_all')->get()
                ->filter(fn (User $reviewer) => $this->scope->canSee($reviewer, $user));
            $this->notifications->emit('attendance.late', $reviewers, $attendance, [
                'minutes' => $lateMinutes, 'time' => $time->format('H:i'),
            ], $user);
        }

        return response()->json([
            'message' => 'ลงเวลาเข้างานสำเร็จ',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Check out for the day
     */
    public function checkOut(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string', // Format: "latitude,longitude"
            'notes' => 'nullable|string',
            'image' => 'nullable|string|max:7000000', // base64 data URL, decoded size checked below
        ]);

        $user = auth()->user();
        $time = $this->time->now($user);
        $date = $time->toDateString();

        // ดึง settings ทั้งหมดครั้งเดียว (แทนการ query แยก 4 ครั้ง)
        $settings = app(SystemSettingService::class)->runtimeValues();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $date)
            ->first();

        if ($attendance?->check_out_time) {
            return response()->json(['message' => 'คุณได้ลงเวลาออกงานของวันนี้ไปแล้ว'], 400);
        }

        // Calculate distance if location is provided
        $distance = null;
        if ($request->location) {
            $distance = $this->calculateDistanceFromCollege($request->location, $settings);
        }

        // Geofencing Check
        $maxRadius = $settings->get('max_attendance_radius', '') !== '' ? (float) $settings->get('max_attendance_radius', 0) : 0;

        if ($maxRadius > 0 && $distance !== null && $distance > $maxRadius) {
            if (! $request->notes) {
                return response()->json(['message' => 'คุณอยู่นอกพื้นที่สำนักงาน (ห่าง '.round($distance).' เมตร) กรุณาระบุหมายเหตุการลงเวลา'], 400);
            }
        }

        // Selfie Check
        $requireSelfie = $settings->get('require_attendance_selfie') == '1';

        if ($requireSelfie && ! $request->image) {
            return response()->json(['message' => 'กรุณาถ่ายรูปยืนยันตัวตนก่อนลงเวลา'], 400);
        }

        $imagePath = null;
        if ($request->image) {
            $imagePath = $this->storeAttendanceImage($request->image);
        }

        $source = AttendanceSource::firstOrCreate(
            ['code' => 'web'],
            ['name' => 'ระบบเว็บ', 'type' => 'web', 'timezone' => 'Asia/Bangkok', 'health_state' => 'healthy'],
        );
        $recorded = $this->events->record(
            $source,
            'check_out',
            $time,
            "web:{$user->id}:{$date}:check_out",
            ['location' => $request->location, 'distance' => $distance, 'image_path' => $imagePath, 'notes' => $request->notes],
            $user,
        );
        $attendance = $this->reconciliation->reconcile($recorded['event']);
        $earlyLeaveMinutes = (int) $attendance->early_leave_minutes;

        if ($earlyLeaveMinutes > 0) {
            $reviewers = User::permission('attendance.view_all')->get()
                ->filter(fn (User $reviewer) => $this->scope->canSee($reviewer, $user));
            $this->notifications->emit('attendance.early_leave', $reviewers, $attendance, [
                'minutes' => $earlyLeaveMinutes, 'time' => $time->format('H:i'),
            ], $user);
        }

        return response()->json([
            'message' => 'ลงเวลาออกงานสำเร็จ',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Get current user's today attendance record
     */
    public function today()
    {
        $user = auth()->user();
        $date = Carbon::now()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $date)
            ->first();

        $record = OvertimeTimeRecord::where('user_id', $user->id)
            ->whereDate('actual_start', $date)->latest('id')->first();
        if ($record !== null) {
            $attendance ??= new Attendance(['user_id' => $user->id, 'date' => $date]);
            $this->setOvertimeCompatibilityAttributes($attendance, $record);
        }

        return response()->json($attendance);
    }

    /**
     * Get current user's attendance records
     */
    public function myRecords(Request $request)
    {
        $user = auth()->user();
        $query = Attendance::where('user_id', $user->id)->orderBy('date', 'desc');

        if ($request->month) {
            $query->whereMonth('date', $request->month);
        }
        if ($request->year) {
            $query->whereYear('date', $request->year);
        }

        $attendances = $query->get();
        $this->decorateOvertimeCompatibility($attendances);

        return response()->json($attendances);
    }

    /**
     * Get all users' attendance records (Admin/HR)
     */
    public function allRecords(Request $request)
    {
        $query = Attendance::with('user.department')->orderBy('date', 'desc');
        $this->scope->applyToAttendanceQuery($query, $request->user());

        if ($request->date) {
            $query->where('date', $request->date);
        }
        if ($request->month) {
            $query->whereMonth('date', $request->month);
        }
        if ($request->year) {
            $query->whereYear('date', $request->year);
        }
        if ($request->department_id) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        $attendances = $query->paginate(20);
        $this->decorateOvertimeCompatibility($attendances->getCollection());

        return response()->json($attendances);
    }

    private function decorateOvertimeCompatibility($attendances): void
    {
        if ($attendances->isEmpty()) {
            return;
        }
        $userIds = $attendances->pluck('user_id')->unique()->values();
        $from = $attendances->min(fn (Attendance $attendance) => $attendance->date->toDateString());
        $to = $attendances->max(fn (Attendance $attendance) => $attendance->date->toDateString());
        $records = OvertimeTimeRecord::whereIn('user_id', $userIds)
            ->whereDate('actual_start', '>=', $from)->whereDate('actual_start', '<=', $to)
            ->get()->keyBy(fn (OvertimeTimeRecord $record) => $record->user_id.'|'.$record->actual_start->setTimezone('Asia/Bangkok')->toDateString());

        foreach ($attendances as $attendance) {
            $record = $records->get($attendance->user_id.'|'.$attendance->date->toDateString());
            if ($record !== null) {
                $this->setOvertimeCompatibilityAttributes($attendance, $record);
            }
        }
    }

    private function setOvertimeCompatibilityAttributes(Attendance $attendance, OvertimeTimeRecord $record): void
    {
        $attendance->setAttribute('ot_check_in_time', $record->actual_start);
        $attendance->setAttribute('ot_check_out_time', $record->actual_end);
        $attendance->setAttribute('ot_minutes', $record->actual_minutes ?? 0);
    }

    /**
     * Calculate distance between user and college (in meters) using Haversine formula.
     * รับ $settings (Collection จาก pluck) เพื่อหลีกเลี่ยง N+1 query
     */
    private function calculateDistanceFromCollege($location, $settings = null)
    {
        $coords = explode(',', $location);
        if (count($coords) !== 2) {
            return null;
        }

        $userLat = (float) trim($coords[0]);
        $userLng = (float) trim($coords[1]);

        // ใช้ settings ที่ส่งมาถ้ามี มิฉะนั้นดึงจาก cache
        if ($settings === null) {
            $settings = app(SystemSettingService::class)->runtimeValues();
        }

        $collegeLat = $settings->get('college_latitude');
        $collegeLng = $settings->get('college_longitude');

        if (empty($collegeLat) || empty($collegeLng)) {
            return null; // Coordinates not set
        }

        $collegeLat = (float) $collegeLat;
        $collegeLng = (float) $collegeLng;

        // Earth radius in meters
        $earthRadius = 6371000;

        $latDelta = deg2rad($collegeLat - $userLat);
        $lngDelta = deg2rad($collegeLng - $userLng);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($userLat)) * cos(deg2rad($collegeLat)) *
            sin($lngDelta / 2) * sin($lngDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Export Attendances as PDF.
     */
    public function exportPdf(Request $request, ThaiDateService $thaiDateService)
    {
        $query = Attendance::with('user.department')->orderBy('date', 'desc');
        $this->scope->applyToAttendanceQuery($query, $request->user());

        if ($request->date) {
            $query->where('date', $request->date);
        }
        if ($request->month) {
            $query->whereMonth('date', $request->month);
        }
        if ($request->year) {
            $query->whereYear('date', $request->year);
        }
        if ($request->department_id) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Limit to 25 items per page for the view if needed, but since it's an export we can just get them all
        // or apply the user's requested limit. The requirement says: "แสดงรายการไม่เกิน 25 รายการต่อหน้าด้วย".
        // In PDF, we can paginate manually in the Blade view using CSS page-break or array chunking.
        $attendances = $query->get();

        $settings = Setting::pluck('value', 'key');

        $pdf = Pdf::loadView('pdf.attendance_report', compact('attendances', 'thaiDateService', 'settings'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('attendance_report.pdf');
    }

    /**
     * Export Attendances as Word.
     */
    public function exportWord(Request $request, ThaiDateService $thaiDateService)
    {
        $query = Attendance::with('user.department')->orderBy('date', 'desc');
        $this->scope->applyToAttendanceQuery($query, $request->user());

        if ($request->date) {
            $query->where('date', $request->date);
        }
        if ($request->month) {
            $query->whereMonth('date', $request->month);
        }
        if ($request->year) {
            $query->whereYear('date', $request->year);
        }
        if ($request->department_id) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        $attendances = $query->get();
        $settings = Setting::pluck('value', 'key');

        $html = view('pdf.attendance_report', compact('attendances', 'thaiDateService', 'settings'))->render();

        $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
        $content .= '<head><meta charset="utf-8"></head><body>'.$html.'</body></html>';

        return response($content)
            ->header('Content-Type', 'application/vnd.ms-word')
            ->header('Content-Disposition', 'attachment; filename="attendance_report.doc"');
    }

    private function getSummaryData(Request $request)
    {
        $year = $request->year ?? date('Y');
        $month = $request->month ?? date('m');
        $deptId = $request->department_id;

        $daysInMonth = Carbon::createFromDate($year, $month)->daysInMonth;

        $usersQuery = User::with(['profile', 'department', 'attendances' => function ($q) use ($year, $month) {
            $q->whereYear('date', $year)->whereMonth('date', $month);
        }, 'leaves' => function ($q) use ($year, $month) {
            $q->where('status', 'Approved')
                ->where(function ($query) use ($year, $month) {
                    $query->where(function ($sub) use ($year, $month) {
                        $sub->whereYear('start_date', $year)->whereMonth('start_date', $month);
                    })->orWhere(function ($sub) use ($year, $month) {
                        $sub->whereYear('end_date', $year)->whereMonth('end_date', $month);
                    })->orWhere(function ($sub) use ($year, $month) {
                        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfDay();
                        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
                        $sub->where('start_date', '<=', $endOfMonth)
                            ->where('end_date', '>=', $startOfMonth);
                    });
                })->with('type');
        }]);
        $this->scope->applyToUserQuery($usersQuery, $request->user());

        if ($deptId) {
            $usersQuery->where('department_id', $deptId);
        }
        if ($request->user_id) {
            $usersQuery->where('id', $request->user_id);
        }

        $users = $usersQuery->orderBy('name')->get();

        $records = [];
        foreach ($users as $user) {
            $attendancesByDay = [];
            $totalScore = 0;
            $totalSick = 0;
            $totalPersonal = 0;
            $totalOfficial = 0;
            $totalMaternity = 0;

            $attMap = [];
            foreach ($user->attendances as $att) {
                $day = Carbon::parse($att->date)->day;
                $attMap[$day] = $att;
            }

            $leaveMap = [];
            foreach ($user->leaves as $leave) {
                $start = Carbon::parse($leave->start_date)->startOfDay();
                $end = Carbon::parse($leave->end_date)->endOfDay();

                $current = $start->copy();
                while ($current <= $end) {
                    if ($current->year == $year && $current->month == $month) {
                        $leaveMap[$current->day] = $leave->type->name;
                    }
                    $current->addDay();
                }
            }

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $status = null;
                $isLeave = false;
                $leaveName = '';

                if (isset($attMap[$i]) && $attMap[$i]->status === 'Leave') {
                    $isLeave = true;
                    $leaveName = isset($leaveMap[$i]) ? $leaveMap[$i] : ($attMap[$i]->notes ?? '');
                } elseif (isset($leaveMap[$i])) {
                    $isLeave = true;
                    $leaveName = $leaveMap[$i];
                }

                if ($isLeave) {
                    if (str_contains($leaveName, 'ป่วย')) {
                        $status = 'ป';
                        $totalSick++;
                    } elseif (str_contains($leaveName, 'กิจ')) {
                        $status = 'ก';
                        $totalPersonal++;
                    } elseif (str_contains($leaveName, 'คลอด')) {
                        $status = 'ด';
                        $totalMaternity++;
                    } elseif (str_contains($leaveName, 'ราชการ')) {
                        $status = 'ร';
                        $totalOfficial++;
                    } else {
                        $status = 'ก'; // Default fallback
                        $totalPersonal++;
                    }
                } elseif (isset($attMap[$i])) {
                    $att = $attMap[$i];
                    if ($att->check_in_time && $att->check_out_time) {
                        $status = 1;
                        $totalScore += 1;
                    } elseif ($att->check_in_time && ! $att->check_out_time) {
                        $status = 0;
                    } else {
                        $status = 0;
                    }
                }

                $attendancesByDay[$i] = $status;
            }

            $records[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'position' => $user->profile->position ?? '',
                'department' => $user->department->name ?? '',
                'attendances' => $attendancesByDay,
                'total_score' => $totalScore,
                'total_sick' => $totalSick,
                'total_personal' => $totalPersonal,
                'total_maternity' => $totalMaternity,
                'total_official' => $totalOfficial,
            ];
        }

        return [
            'records' => $records,
            'daysInMonth' => $daysInMonth,
            'year' => $year,
            'month' => $month,
        ];
    }

    private function getYearlySummaryData(Request $request)
    {
        $year = $request->year ?? date('Y');
        $userId = $request->user_id;
        if (! $userId) {
            return ['error' => 'กรุณาเลือกบุคลากร 1 คนเพื่อเรียกดูรายงานรายปี'];
        }

        $user = User::with(['profile', 'department'])->find($userId);
        if (! $user) {
            return ['error' => 'ไม่พบบุคลากร'];
        }
        if (! $this->scope->canSee($request->user(), $user)) {
            return ['error' => 'คุณไม่มีสิทธิ์ดูข้อมูลบุคลากรรายนี้'];
        }

        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

        // ดึง attendances ทั้งปีครั้งเดียว แล้ว group ตามเดือน (แทนการ loop query 12 รอบ)
        $allAttendances = Attendance::where('user_id', $userId)
            ->whereYear('date', $year)
            ->get()
            ->groupBy(fn ($a) => (int) Carbon::parse($a->date)->format('n'));

        // ดึง leaves ทั้งปีครั้งเดียว พร้อม type
        $startOfYear = Carbon::createFromDate($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::createFromDate($year, 12, 31)->endOfDay();
        $allLeaves = Leave::with('type')
            ->where('user_id', $userId)
            ->where('status', 'Approved')
            ->where(function ($q) use ($startOfYear, $endOfYear) {
                $q->whereBetween('start_date', [$startOfYear, $endOfYear])
                    ->orWhereBetween('end_date', [$startOfYear, $endOfYear])
                    ->orWhere(function ($sub) use ($startOfYear, $endOfYear) {
                        $sub->where('start_date', '<=', $endOfYear)
                            ->where('end_date', '>=', $startOfYear);
                    });
            })
            ->get();

        $monthsData = [];
        for ($m = 1; $m <= 12; $m++) {
            $daysInMonth = Carbon::createFromDate($year, $m)->daysInMonth;
            $startOfMonth = Carbon::createFromDate($year, $m, 1)->startOfDay();
            $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

            // Attendance map สำหรับเดือนนี้
            $attMap = [];
            foreach (($allAttendances->get($m) ?? []) as $att) {
                $day = (int) Carbon::parse($att->date)->format('j');
                $attMap[$day] = $att;
            }

            // Leave map สำหรับเดือนนี้
            $leaveMap = [];
            foreach ($allLeaves as $leave) {
                $lStart = Carbon::parse($leave->start_date)->startOfDay();
                $lEnd = Carbon::parse($leave->end_date)->endOfDay();
                $current = $lStart->copy();
                while ($current <= $lEnd) {
                    if ($current->year == $year && $current->month == $m) {
                        $leaveMap[$current->day] = $leave->type->name ?? '';
                    }
                    $current->addDay();
                }
            }

            $attendancesByDay = [];
            $totalScore = 0;
            $totalSick = 0;
            $totalPersonal = 0;
            $totalOfficial = 0;

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $status = null;
                $isLeave = false;
                $leaveName = '';

                if (isset($attMap[$i]) && $attMap[$i]->status === 'Leave') {
                    $isLeave = true;
                    $leaveName = isset($leaveMap[$i]) ? $leaveMap[$i] : ($attMap[$i]->notes ?? '');
                } elseif (isset($leaveMap[$i])) {
                    $isLeave = true;
                    $leaveName = $leaveMap[$i];
                }

                if ($isLeave) {
                    if (str_contains($leaveName, 'ป่วย')) {
                        $status = 'ป';
                        $totalSick++;
                    } elseif (str_contains($leaveName, 'กิจ')) {
                        $status = 'ก';
                        $totalPersonal++;
                    } elseif (str_contains($leaveName, 'คลอด')) {
                        $status = 'ด';
                    } elseif (str_contains($leaveName, 'ราชการ')) {
                        $status = 'ร';
                        $totalOfficial++;
                    } else {
                        $status = 'ก';
                        $totalPersonal++;
                    }
                } elseif (isset($attMap[$i])) {
                    $att = $attMap[$i];
                    if ($att->check_in_time && $att->check_out_time) {
                        $status = 1;
                        $totalScore++;
                    } else {
                        $status = 0;
                    }
                }

                $attendancesByDay[$i] = $status;
            }

            $monthsData[] = [
                'month_no' => $m,
                'month_name' => $thaiMonths[$m],
                'days_in_month' => $daysInMonth,
                'attendances' => $attendancesByDay,
                'total_score' => $totalScore,
                'total_sick' => $totalSick,
                'total_personal' => $totalPersonal,
                'total_official' => $totalOfficial,
            ];
        }

        return [
            'user' => [
                'name' => trim($user->title.' '.$user->name.' '.$user->last_name),
                'position' => $user->profile->position ?? '-',
                'department' => $user->department->name ?? '-',
            ],
            'year' => $year,
            'months' => $monthsData,
        ];
    }

    public function summary(Request $request)
    {
        if ($request->month === 'all') {
            $data = $this->getYearlySummaryData($request);
            if (isset($data['error'])) {
                return response()->json(['error' => $data['error']], 400);
            }

            return response()->json($data);
        }
        $data = $this->getSummaryData($request);

        return response()->json($data['records']);
    }

    public function previewSummary(Request $request)
    {
        // This method is called from frontend via window.open, token is in query string
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก
        $user = $request->user();

        $settings = Setting::pluck('value', 'key');

        if ($request->month === 'all') {
            $data = $this->getYearlySummaryData($request);
            if (isset($data['error'])) {
                abort(400, $data['error']);
            }

            return view('reports.attendance_yearly_preview', array_merge($data, ['settings' => $settings]));
        }

        $data = $this->getSummaryData($request);
        $records = $data['records'];
        $daysInMonth = $data['daysInMonth'];
        $year = $data['year'];
        $month = $data['month'];

        return view('reports.attendance_summary_preview', compact('records', 'daysInMonth', 'year', 'month', 'settings'));
    }

    public function exportSummaryPdf(Request $request, ThaiDateService $thaiDateService)
    {
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก

        $settings = Setting::pluck('value', 'key');
        $export = true;

        if ($request->month === 'all') {
            $data = $this->getYearlySummaryData($request);
            if (isset($data['error'])) {
                abort(400, $data['error']);
            }
            $pdf = Pdf::loadView('reports.attendance_yearly_preview', array_merge($data, ['settings' => $settings, 'export' => true]))->setPaper('a4', 'landscape');

            return $pdf->download('attendance_yearly.pdf');
        }

        $data = $this->getSummaryData($request);
        $records = collect($data['records']); // For chunking in Blade
        $daysInMonth = $data['daysInMonth'];
        $year = $data['year'];
        $month = $data['month'];

        $pdf = Pdf::loadView('reports.attendance_summary_preview', compact('records', 'daysInMonth', 'year', 'month', 'settings', 'export'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('attendance_summary.pdf');
    }

    public function exportSummaryWord(Request $request, ThaiDateService $thaiDateService)
    {
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก

        $settings = Setting::pluck('value', 'key');
        $export = true;

        if ($request->month === 'all') {
            $data = $this->getYearlySummaryData($request);
            if (isset($data['error'])) {
                abort(400, $data['error']);
            }
            $html = view('reports.attendance_yearly_preview', array_merge($data, ['settings' => $settings, 'export' => true]))->render();
            $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';

            return response($content)
                ->header('Content-Type', 'application/vnd.ms-word')
                ->header('Content-Disposition', 'attachment; filename="attendance_yearly.doc"');
        }

        $data = $this->getSummaryData($request);
        $records = collect($data['records']);
        $daysInMonth = $data['daysInMonth'];
        $year = $data['year'];
        $month = $data['month'];
        $settings = Setting::pluck('value', 'key');

        $export = true;

        $html = view('reports.attendance_summary_preview', compact('records', 'daysInMonth', 'year', 'month', 'settings', 'export'))->render();

        $content = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
        $content .= '<head><meta charset="utf-8"></head><body>'.$html.'</body></html>';

        return response($content)
            ->header('Content-Type', 'application/vnd.ms-word')
            ->header('Content-Disposition', 'attachment; filename="attendance_summary.doc"');
    }

    /**
     * Sync approved leaves to attendances
     */
    public function syncLeaves(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer',
        ]);

        $month = str_pad($request->month, 2, '0', STR_PAD_LEFT);
        $year = $request->year;

        $leaves = Leave::with('type')
            ->where('status', 'Approved')
            ->where(function ($query) use ($month, $year) {
                $query->whereMonth('start_date', $month)->whereYear('start_date', $year)
                    ->orWhere(function ($q) use ($month, $year) {
                        $q->whereMonth('end_date', $month)->whereYear('end_date', $year);
                    });
            });
        $this->scope->applyToUserOwnedQuery($leaves, $request->user());
        $leaves = $leaves->get();

        $count = 0;

        foreach ($leaves as $leave) {
            $start = Carbon::parse($leave->start_date);
            $end = Carbon::parse($leave->end_date);

            for ($date = $start; $date->lte($end); $date->addDay()) {
                if ($date->month == $request->month && $date->year == $request->year) {
                    $attendance = Attendance::firstOrCreate(
                        ['user_id' => $leave->user_id, 'date' => $date->toDateString()],
                        ['status' => 'Leave', 'notes' => 'ลา: '.$leave->type->name]
                    );

                    if ($attendance->status !== 'Present' && $attendance->status !== 'Late') {
                        $attendance->status = 'Leave';
                        if (! str_contains($attendance->notes ?? '', 'ลา: '.$leave->type->name)) {
                            $attendance->notes = $attendance->notes ? $attendance->notes.' | ลา: '.$leave->type->name : 'ลา: '.$leave->type->name;
                        }
                        $attendance->save();
                    }
                    $count++;
                }
            }
        }

        return response()->json([
            'message' => "ซิงค์ข้อมูลการลาสำเร็จ (พบข้อมูลวันลาที่ตรงกับเดือนนี้ $count วัน)",
            'synced_count' => $count,
        ]);
    }

    /**
     * Validate a selfie from its decoded bytes, never from the client-supplied extension/MIME.
     */
    private function storeAttendanceImage(string $dataUrl): string
    {
        if (! preg_match('#^data:[^;,]+;base64,(.+)$#s', $dataUrl, $matches)) {
            throw ValidationException::withMessages([
                'image' => 'รูปแบบไฟล์ภาพไม่ถูกต้อง',
            ]);
        }

        $contents = base64_decode($matches[1], true);

        if ($contents === false || strlen($contents) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image' => 'ไฟล์ภาพต้องมีขนาดไม่เกิน 5 MB',
            ]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'image' => 'อนุญาตเฉพาะไฟล์ภาพ JPEG, PNG หรือ WebP',
            ]);
        }

        $relative = 'attendances/'.Str::uuid().'.'.$extension;
        Storage::disk('private_uploads')->put($relative, $contents);

        return '/storage/'.$relative;
    }

    private function getOTSummaryData(Request $request)
    {
        $year = $request->year ?? date('Y');
        $month = $request->month ?? date('m');
        $deptId = $request->department_id;

        $daysInMonth = Carbon::createFromDate($year, $month)->daysInMonth;

        $usersQuery = User::with(['profile', 'department', 'overtimeTimeRecords' => function ($q) use ($year, $month) {
            $q->whereYear('actual_start', $year)->whereMonth('actual_start', $month)
                ->whereNotNull('actual_start')->whereNotNull('actual_end');
        }]);
        $this->scope->applyToUserQuery($usersQuery, $request->user());

        if ($deptId) {
            $usersQuery->where('department_id', $deptId);
        }
        if ($request->user_id) {
            $usersQuery->where('id', $request->user_id);
        }

        $users = $usersQuery->orderBy('name')->get();

        $records = [];
        foreach ($users as $user) {
            $attendancesByDay = array_fill(1, $daysInMonth, null);
            $totalMins = 0;

            foreach ($user->overtimeTimeRecords as $timeRecord) {
                $day = (int) $timeRecord->actual_start->setTimezone('Asia/Bangkok')->format('d');
                $diff = (int) ($timeRecord->actual_minutes ?? 0);
                $h = floor($diff / 60);
                $m = $diff % 60;
                $attendancesByDay[$day] = sprintf('%d.%02d', $h, $m);
                $totalMins += $diff;
            }

            $totalH = floor($totalMins / 60);
            $totalM = $totalMins % 60;
            $totalFormatted = sprintf('%d.%02d', $totalH, $totalM);

            $records[] = [
                'user_id' => $user->id,
                'name' => $user->name.' '.($user->profile->last_name ?? ''),
                'position' => $user->profile->position ?? '-',
                'department' => $user->department->name ?? '-',
                'attendances' => $attendancesByDay,
                'total_ot' => $totalFormatted,
            ];
        }

        return [
            'records' => $records,
            'daysInMonth' => $daysInMonth,
            'month' => $month,
            'year' => $year,
        ];
    }

    public function otSummary(Request $request)
    {
        $data = $this->getOTSummaryData($request);

        return response()->json($data['records']);
    }

    public function previewOTSummary(Request $request)
    {
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก
        $user = $request->user();

        $request->merge(['year' => $request->query('year'), 'month' => $request->query('month'), 'department_id' => $request->query('department_id')]);
        $data = $this->getOTSummaryData($request);
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $monthName = $thaiMonths[(int) $data['month']] ?? '';
        $settings = Setting::pluck('value', 'key');

        $html = view('reports.ot_summary_preview', [
            'records' => $data['records'],
            'daysInMonth' => $data['daysInMonth'],
            'monthName' => $monthName,
            'year' => $data['year'] + 543,
            'settings' => $settings,
            'export' => false,
        ])->render();

        return response($html)->header('Content-Type', 'text/html');
    }

    public function exportOTSummaryPdf(Request $request, ThaiDateService $thaiDateService)
    {
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก
        $user = $request->user();

        $request->merge(['year' => $request->query('year'), 'month' => $request->query('month'), 'department_id' => $request->query('department_id')]);
        $data = $this->getOTSummaryData($request);
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $monthName = $thaiMonths[(int) $data['month']] ?? '';
        $settings = Setting::pluck('value', 'key');

        $pdf = Pdf::loadView('reports.ot_summary_preview', [
            'records' => $data['records'],
            'daysInMonth' => $data['daysInMonth'],
            'monthName' => $monthName,
            'year' => $data['year'] + 543,
            'settings' => $settings,
            'export' => true,
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download("OT_Summary_{$monthName}_{$data['year']}.pdf");
    }

    public function exportOTSummaryWord(Request $request, ThaiDateService $thaiDateService)
    {
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก
        $user = $request->user();

        $request->merge(['year' => $request->query('year'), 'month' => $request->query('month'), 'department_id' => $request->query('department_id')]);
        $data = $this->getOTSummaryData($request);
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $monthName = $thaiMonths[(int) $data['month']] ?? '';
        $settings = Setting::pluck('value', 'key');

        $html = view('reports.ot_summary_preview', [
            'records' => $data['records'],
            'daysInMonth' => $data['daysInMonth'],
            'monthName' => $monthName,
            'year' => $data['year'] + 543,
            'settings' => $settings,
            'export' => false,
        ])->render();

        $headers = [
            'Content-type' => 'application/vnd.ms-word',
            'Content-Disposition' => "attachment;Filename=OT_Summary_{$monthName}_{$data['year']}.doc",
        ];

        $content = "<html><meta charset='utf-8'><body>".$html.'</body></html>';

        return response()->make($content, 200, $headers);
    }

    public function exportOTSummaryExcel(Request $request, ThaiDateService $thaiDateService)
    {
        // SEC-009: ยืนยันตัวตนที่ route ผ่าน auth:sanctum แล้ว ไม่รับ token ทาง query string อีก
        $user = $request->user();

        $request->merge(['year' => $request->query('year'), 'month' => $request->query('month'), 'department_id' => $request->query('department_id')]);
        $data = $this->getOTSummaryData($request);
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $monthName = $thaiMonths[(int) $data['month']] ?? '';
        $settings = Setting::pluck('value', 'key');

        $html = view('reports.ot_summary_preview', [
            'records' => $data['records'],
            'daysInMonth' => $data['daysInMonth'],
            'monthName' => $monthName,
            'year' => $data['year'] + 543,
            'settings' => $settings,
            'export' => true,
        ])->render();

        $headers = [
            'Content-type' => 'application/vnd.ms-excel',
            'Content-Disposition' => "attachment;Filename=OT_Summary_{$monthName}_{$data['year']}.xls",
        ];

        $content = "<html><meta charset='utf-8'><body>".$html.'</body></html>';

        return response()->make($content, 200, $headers);
    }
}
