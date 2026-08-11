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

    private const THAI_MONTHS = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];

    /**
     * query ใบลาที่อนุมัติแล้วตามตัวกรองเดียวกันทั้ง 3 รายงาน (สรุป/PDF/Excel)
     * basis=fiscal (ค่าเริ่มต้น) นับตามปีงบประมาณให้ตรงกับระบบสิทธิ์ — 1 ต.ค. ปีก่อน ถึง 30 ก.ย. ปีนั้น
     */
    private function reportQuery(Request $request, int $year, ?int $month)
    {
        $query = Leave::with(['user.department', 'type'])->where('status', 'Approved');

        if ($request->input('basis', 'fiscal') === 'fiscal') {
            $query->whereBetween('start_date', [
                Carbon::create($year - 1, 10, 1)->startOfDay(),
                Carbon::create($year, 9, 30)->endOfDay(),
            ]);
        } else {
            $query->whereYear('start_date', $year);
        }

        if ($month) {
            $query->whereMonth('start_date', $month);
        }

        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->leave_type_id);
        }

        if ($request->filled('department_id')) {
            $query->whereHas('user', fn ($q) => $q->where('department_id', $request->department_id));
        }

        $user = $request->user();
        $canViewAllReports = $user->hasAnyRole([
            'ผู้ดูแลระบบสูงสุด', 'Super Admin',
            'ผู้อำนวยการ', 'Director',
            'รองผู้อำนวยการ', 'Deputy Director',
            'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', 'Head of Department',
            'เจ้าหน้าที่บุคลากร (HR)', 'HR Head',
        ]) || $user->can('leave.report.view');

        if (!$canViewAllReports) {
            $query->where('user_id', $user->id);
            return $query;
        }

        // หัวหน้าแผนก/ฝ่ายเห็นได้เฉพาะหน่วยงานตนเอง
        if ($user->hasRole('หัวหน้าแผนกวิชา / หัวหน้าฝ่าย')
            && !$user->hasAnyRole(['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'ผู้อำนวยการ', 'Director', 'เจ้าหน้าที่บุคลากร (HR)'])) {
            $deptId = $user->department_id ?? ($user->profile->department_id ?? null);
            if ($deptId) {
                $query->whereHas('user', fn ($q) => $q->where('department_id', $deptId));
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        return $query;
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
            'basis'         => 'nullable|in:fiscal,calendar',
        ]);

        $year  = (int) $request->input('year', now()->year);
        $month = $request->input('month') ? (int) $request->input('month') : null;

        $leaves = $this->reportQuery($request, $year, $month)->get();

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
            'basis'       => $request->input('basis', 'fiscal'),
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
            'basis' => 'nullable|in:fiscal,calendar',
        ]);

        $year  = (int) $request->input('year', now()->year);
        $month = $request->input('month') ? (int) $request->input('month') : null;

        $query = $this->reportQuery($request, $year, $month);

        $user = $request->user();
        $selectedUserName = null;
        if ($request->filled('user_id')) {
            $selectedUserName = \App\Models\User::find($request->user_id)?->name;
        } elseif (!$user->can('leave.report.view') && !$user->hasAnyRole(['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'ผู้อำนวยการ', 'Director', 'รองผู้อำนวยการ', 'Deputy Director', 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', 'Head of Department', 'เจ้าหน้าที่บุคลากร (HR)', 'HR Head'])) {
            $selectedUserName = $user->name;
        }

        $thaiYear      = $year + 543;
        $thaiMonthName = $month ? (self::THAI_MONTHS[$month] ?? '') : null;

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
            'basis' => 'nullable|in:fiscal,calendar',
        ]);

        $year  = (int) $request->input('year', now()->year);
        $month = $request->input('month') ? (int) $request->input('month') : null;

        // เดิมรับ month มาแต่ไม่เคยกรอง ทำให้หัวรายงานบอกเดือนเดียวแต่ข้อมูลออกมาทั้งปี
        $leaves = $this->reportQuery($request, $year, $month)->orderBy('start_date')->get();

        $excelService = app(\App\Services\Export\LeaveExcelService::class);
        $title = "รายงานสรุปสถิติการลา ประจำปี พ.ศ. " . ($year + 543)
            . ($month ? ' เดือน ' . (self::THAI_MONTHS[$month] ?? $month) : '');
        $tempFile = $excelService->generateReportXlsx($leaves->toArray() ? $leaves->all() : [], $title);

        $filename = "report_{$year}" . ($month ? "_{$month}" : '') . ".xlsx";

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }
}
