<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Models\Overtime\OvertimeOrder;
use App\Models\Workflow\WorkflowTransition;
use App\Services\OrgScopeResolver;
use App\Services\Overtime\OvertimeOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class OvertimeOrderController extends Controller
{
    public function __construct(
        private readonly OvertimeOrderService $orders,
        private readonly OrgScopeResolver $scopes,
    ) {}

    public function index(Request $request)
    {
        $visible = $this->scopes->visibleOrgUnitIds($request->user(), 'overtime.management.review', OrgScopeResolver::ORGANIZATION);
        $query = OvertimeOrder::with(['assignments.user', 'assignments.timeRecord'])->latest('scheduled_start');
        if ($visible !== null) {
            $query->whereIn('org_unit_id', $visible);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'order_number' => ['nullable', 'string', 'max:100', 'unique:ot_orders,order_number'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'timezone' => ['nullable', 'timezone'],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
        ]);
        $order = $this->orders->create($request->user(), $data);

        return response()->json(['message' => 'ส่งคำสั่ง OT เข้าสู่ขั้นตอนอนุมัติแล้ว', 'order' => $order], 201);
    }

    public function approve(Request $request, OvertimeOrder $order)
    {
        $data = $request->validate([
            'approved_start' => ['nullable', 'date'],
            'approved_end' => ['nullable', 'date', 'after:approved_start'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $window = [];
        if (isset($data['approved_start'])) {
            $window['approved_start'] = CarbonImmutable::parse($data['approved_start'], $order->timezone);
        }
        if (isset($data['approved_end'])) {
            $window['approved_end'] = CarbonImmutable::parse($data['approved_end'], $order->timezone);
        }
        $result = $this->orders->decide($order, $request->user(), WorkflowTransition::APPROVE, $window, $data['comment'] ?? null);

        return response()->json(['message' => 'อนุมัติคำสั่ง OT แล้ว', 'order' => $result]);
    }

    public function reject(Request $request, OvertimeOrder $order)
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $result = $this->orders->decide($order, $request->user(), WorkflowTransition::REJECT, [], $data['comment']);

        return response()->json(['message' => 'ไม่อนุมัติคำสั่ง OT', 'order' => $result]);
    }

    public function assign(Request $request, OvertimeOrder $order)
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        try {
            $result = $this->orders->assign($order, $request->user(), $data['user_ids']);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'มอบหมายผู้ปฏิบัติงาน OT แล้ว', 'order' => $result]);
    }
}
