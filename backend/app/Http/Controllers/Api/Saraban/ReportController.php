<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(Request $request)
    {
        // Monthly stats for current year
        $year = $request->input('year', date('Y'));
        
        $monthlyData = Document::select(
            DB::raw('MONTH(created_at) as month'),
            DB::raw('COUNT(*) as count')
        )
        ->whereYear('created_at', $year)
        ->groupBy('month')
        ->orderBy('month')
        ->get();

        $monthly = array_fill(1, 12, 0);
        foreach ($monthlyData as $data) {
            $monthly[$data->month] = $data->count;
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
            'most_urgent' => 0
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
            'departments' => $deptData
        ]);
    }

    public function exportCsv(Request $request)
    {
        $year = $request->input('year', date('Y'));
        
        $documents = Document::with(['type', 'creator', 'department'])
            ->whereYear('created_at', $year)
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = "saraban_report_{$year}.csv";
        $handle = fopen('php://temp', 'w+');
        fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for UTF-8 Excel

        // Headers
        fputcsv($handle, ['เลขที่เอกสาร', 'เรื่อง', 'วันที่สร้าง', 'ผู้สร้าง', 'ฝ่าย/แผนก', 'ความเร่งด่วน', 'สถานะ']);

        foreach ($documents as $doc) {
            fputcsv($handle, [
                $doc->document_number,
                $doc->title,
                $doc->created_at->format('Y-m-d H:i:s'),
                $doc->creator ? $doc->creator->first_name . ' ' . $doc->creator->last_name : '',
                $doc->department ? $doc->department->name : '',
                $this->translatePriority($doc->priority),
                $this->translateStatus($doc->status)
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function translatePriority($priority) {
        $map = [
            'normal' => 'ปกติ',
            'urgent' => 'ด่วน',
            'very_urgent' => 'ด่วนมาก',
            'most_urgent' => 'ด่วนที่สุด',
        ];
        return $map[$priority] ?? $priority;
    }

    private function translateStatus($status) {
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
