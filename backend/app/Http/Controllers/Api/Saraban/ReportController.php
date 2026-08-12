<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Services\Saraban\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    protected $auditLog;

    public function __construct(AuditLogService $auditLog)
    {
        $this->auditLog = $auditLog;
    }

    public function summary(Request $request)
    {
        // Monthly stats for current year
        $year = $request->input('year', date('Y'));

        // MONTH() is MySQL-only; SQLite (used by the test suite) needs strftime.
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', created_at) AS INTEGER)"
            : 'MONTH(created_at)';

        $monthlyData = Document::select(
            DB::raw("{$monthExpr} as month"),
            DB::raw('COUNT(*) as count')
        )
            ->whereYear('created_at', $year)
            ->groupBy(DB::raw($monthExpr))
            ->orderBy('month')
            ->get();

        $monthly = array_fill(1, 12, 0);
        foreach ($monthlyData as $data) {
            $monthly[(int) $data->month] = $data->count;
        }

        // Stats by Priority
        $priorityData = Document::select(
            'priority',
            DB::raw('COUNT(*) as count')
        )
            ->whereYear('created_at', $year)
            ->groupBy('priority')
            ->get();

        $priorities = [
            'normal' => 0,
            'urgent' => 0,
            'very_urgent' => 0,
            'most_urgent' => 0,
        ];
        foreach ($priorityData as $data) {
            $priorities[$data->priority] = $data->count;
        }

        // Stats by Department
        $deptData = Document::join('departments', 'saraban_documents.department_id', '=', 'departments.id')
            ->select('departments.name', DB::raw('COUNT(saraban_documents.id) as count'))
            ->whereYear('saraban_documents.created_at', $year)
            ->groupBy('departments.name')
            ->get();

        return response()->json([
            'year' => $year,
            'monthly' => array_values($monthly), // 0-indexed for chart.js
            'priorities' => $priorities,
            'departments' => $deptData,
        ]);
    }

    public function exportCsv(Request $request)
    {
        $year = $request->input('year', date('Y'));

        $documents = Document::with(['type', 'creator.profile', 'department'])
            ->whereYear('created_at', $year)
            ->when(! Auth::user()->can('saraban.document.view_classified'),
                fn ($q) => $q->where('secrecy', 'normal'))
            ->orderBy('created_at', 'desc')
            ->get();

        $this->auditLog->log('report.exported', null, ['year' => $year, 'rows' => $documents->count()]);

        $filename = "saraban_report_{$year}.csv";
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8 Excel

        // Headers
        fputcsv($handle, ['เลขที่เอกสาร', 'เรื่อง', 'วันที่สร้าง', 'ผู้สร้าง', 'ฝ่าย/แผนก', 'ความเร่งด่วน', 'สถานะ']);

        foreach ($documents as $doc) {
            fputcsv($handle, [
                $doc->document_number,
                $doc->title,
                $doc->created_at->format('Y-m-d H:i:s'),
                $doc->creator
                    ? trim(trim(($doc->creator->profile?->first_name ?? '').' '.($doc->creator->profile?->last_name ?? '')) ?: $doc->creator->name)
                    : '',
                $doc->department ? $doc->department->name : '',
                $this->translatePriority($doc->priority),
                $this->translateStatus($doc->status),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    private function translatePriority($priority)
    {
        $map = [
            'normal' => 'ปกติ',
            'urgent' => 'ด่วน',
            'very_urgent' => 'ด่วนมาก',
            'most_urgent' => 'ด่วนที่สุด',
        ];

        return $map[$priority] ?? $priority;
    }

    private function translateStatus($status)
    {
        $map = [
            'draft' => 'ฉบับร่าง',
            'pending_review' => 'รอดำเนินการ',
            'pending_approval' => 'รอดำเนินการ',
            'approved_pending_signature' => 'รอลงนาม',
            'signed' => 'เสร็จสิ้น',
            'dispatched' => 'เสร็จสิ้น',
            'rejected' => 'ไม่อนุมัติ',
            'cancelled' => 'ยกเลิก',
        ];

        return $map[$status] ?? $status;
    }
}
