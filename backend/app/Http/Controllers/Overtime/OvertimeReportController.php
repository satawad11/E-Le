<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Services\OrgScopeResolver;
use Illuminate\Http\Request;

class OvertimeReportController extends Controller
{
    public function __construct(private readonly OrgScopeResolver $scopes) {}

    public function index(Request $request)
    {
        $visible = $this->scopes->visibleOrgUnitIds($request->user(), 'overtime.management.review', OrgScopeResolver::ORGANIZATION);
        $query = OvertimeTimeRecord::with(['user', 'assignment.order'])
            ->when($request->year, fn ($q, $year) => $q->whereYear('actual_start', $year))
            ->when($request->month, fn ($q, $month) => $q->whereMonth('actual_start', $month))
            ->when($request->user_id, fn ($q, $userId) => $q->where('user_id', $userId));
        if ($visible !== null) {
            $query->whereHas('assignment.order', fn ($orders) => $orders->whereIn('org_unit_id', $visible));
        }

        $records = $query->latest('actual_start')->paginate(50);
        $records->getCollection()->transform(function (OvertimeTimeRecord $record) {
            $order = $record->assignment->order;
            $approvedMinutes = $order->approved_start && $order->approved_end
                ? (int) $order->approved_start->diffInMinutes($order->approved_end)
                : null;

            return [
                'uuid' => $record->uuid,
                'user' => $record->user,
                'order' => ['uuid' => $order->uuid, 'title' => $order->title, 'status' => $order->status],
                'approved_start' => $order->approved_start,
                'approved_end' => $order->approved_end,
                'approved_minutes' => $approvedMinutes,
                'actual_start' => $record->actual_start,
                'actual_end' => $record->actual_end,
                'actual_minutes' => $record->actual_minutes,
                'certified_start' => $record->certified_start,
                'certified_end' => $record->certified_end,
                'certified_minutes' => $record->certified_minutes,
                'status' => $record->status,
                'reconciliation_status' => $record->reconciliation_status,
            ];
        });

        return response()->json($records);
    }
}
