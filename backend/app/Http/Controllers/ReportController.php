<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\ThaiDateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    protected ThaiDateService $thaiDateService;

    public function __construct(ThaiDateService $thaiDateService)
    {
        $this->thaiDateService = $thaiDateService;
    }

    /**
     * Get summary statistics for reports.
     * Query params: year (ค.ศ.), month (1-12, optional), leave_type_id (optional), department_id (optional)
     */
    public function summary(Request $request)
    {
        $request->validate([
            'year'          => 'nullable|integer|min:2000|max:2100',
            'month'         => 'nullable|integer|min:1|max:12',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $year  = $request->input('year', now()->year);
        $month = $request->input('month');

        $query = Leave::with(['user.department', 'type'])
            ->whereYear('start_date', $year)
            ->where('status', 'Approved');

        $user = $request->user();
        $roleName = $user->role->name ?? '';

        if ($roleName !== 'Employee') {
            // Non-employees can see all leaves in the organization
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        if ($month) {
            $query->whereMonth('start_date', $month);
        }

        if ($request->leave_type_id) {
            $query->where('leave_type_id', $request->leave_type_id);
        }

        if ($request->department_id) {
            $query->whereHas('user', fn($q) => $q->where('department_id', $request->department_id));
        }

        $leaves = $query->get();

        // Total days by type
        $byType = $leaves->groupBy('leave_type_id')->map(function ($group) {
            $first = $group->first();
            return [
                'type_id'    => $first->leave_type_id,
                'type_name'  => $first->type->name ?? '—',
                'count'      => $group->count(),
                'total_days' => $group->sum('total_days'),
            ];
        })->values();

        // Monthly breakdown
        $monthly = collect(range(1, 12))->map(function ($m) use ($leaves) {
            $monthLeaves = $leaves->filter(fn($l) => Carbon::parse($l->start_date)->month === $m);
            return [
                'month'      => $m,
                'count'      => $monthLeaves->count(),
                'total_days' => $monthLeaves->sum('total_days'),
            ];
        });

        // Overall totals
        $totals = [
            'total_requests' => $leaves->count(),
            'total_days'     => $leaves->sum('total_days'),
            'by_type'        => $byType,
            'monthly'        => $monthly,
        ];

        $leaveTypes = LeaveType::all(['id', 'name']);

        return response()->json([
            'year'        => $year,
            'month'       => $month,
            'leave_types' => $leaveTypes,
            'summary'     => $totals,
        ]);
    }

    /**
     * Export report as PDF (using DomPDF).
     */
    public function exportPdf(Request $request)
    {
        $request->validate([
            'year'  => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $year  = $request->input('year', now()->year);
        $month = $request->input('month');

        $query = Leave::with(['user.department', 'type'])
            ->whereYear('start_date', $year)
            ->where('status', 'Approved');

        $user = $request->user();
        $roleName = $user->role->name ?? '';

        $selectedUserName = null;
        if ($roleName !== 'Employee') {
            // Non-employees can see all leaves in the organization
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
                $targetUser = \App\Models\User::find($request->user_id);
                $selectedUserName = $targetUser?->name;
            }
        } else {
            $query->where('user_id', $user->id);
            $selectedUserName = $user->name;
        }

        if ($month) {
            $query->whereMonth('start_date', $month);
        }

        $thaiMonths = [
            1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
            5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
            9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
        ];
        $thaiYear      = $year + 543;
        $thaiMonthName = $month ? ($thaiMonths[$month] ?? '') : null;

        $leaves          = $query->orderBy('start_date')->get();
        $leaveTypes      = LeaveType::all();
        $thaiDateService = $this->thaiDateService;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pdf.report',
            compact(
                'leaves', 'leaveTypes', 'year', 'month',
                'thaiDateService', 'thaiYear', 'thaiMonthName', 'selectedUserName'
            )
        )->setPaper('a4', 'landscape');

        $filename = "รายงานการลา_พ.ศ.{$thaiYear}" . ($thaiMonthName ? "_{$thaiMonthName}" : '') . ".pdf";
        return $pdf->download($filename);
    }

    /**
     * Export report as CSV (simple, no extra package needed).
     */
    public function exportExcel(Request $request)
    {
        $request->validate([
            'year'  => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $year  = $request->input('year', now()->year);
        $month = $request->input('month');

        $query = Leave::with(['user.department', 'type'])
            ->whereYear('start_date', $year)
            ->where('status', 'Approved');

        $user = $request->user();
        $roleName = $user->role->name ?? '';

        if ($roleName !== 'Employee') {
            // Non-employees can see all leaves in the organization
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        if ($month) {
            $query->whereMonth('start_date', $month);
        }

        $leaves = $query->orderBy('start_date')->get();

        $filename = "report_{$year}" . ($month ? "_{$month}" : '') . ".csv";

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($leaves) {
            $handle = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ลำดับ', 'ชื่อ-นามสกุล', 'หน่วยงาน', 'ประเภทการลา',
                'วันที่เริ่มลา', 'วันที่สิ้นสุด', 'จำนวนวัน', 'เหตุผล',
            ]);

            foreach ($leaves as $i => $leave) {
                fputcsv($handle, [
                    $i + 1,
                    $leave->user->name ?? '—',
                    $leave->user->department->name ?? '—',
                    $leave->type->name ?? '—',
                    $leave->start_date,
                    $leave->end_date,
                    $leave->total_days,
                    $leave->reason,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
