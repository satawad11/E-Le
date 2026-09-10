<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\OrgScopeResolver;
use App\Support\DocumentFilename;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class GovernmentLeaveReportController extends Controller
{
    public function __construct(private OrgScopeResolver $scopes) {}

    /** สร้าง query ทะเบียนคุมการลาตามเงื่อนไขที่กรอง — ใช้ร่วมกันทั้งหน้าจอและไฟล์ส่งออก */
    private function registerQuery(Request $r)
    {
        abort_unless($r->user()->can('leave.report.view') || $r->user()->can('leave.management.manage'), 403);
        $data = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'org_unit_id' => 'nullable|exists:org_units,id', 'personnel_type_id' => 'nullable|exists:personnel_types,id']);
        // ร่างยังไม่ออกเลขที่หนังสือและยังไม่เข้ากระบวนการ จึงยังไม่ใช่รายการในทะเบียนคุม
        $q = Leave::withTrashed()->where('status', '!=', 'Draft')
            ->with(['user:id,name,org_unit_id,personnel_type_id', 'user.orgUnit:id,name', 'user.personnelType:id,name', 'type:id,name'])
            ->whereBetween('start_date', [$data['from'], $data['to']]);
        $this->applyReportScope($q, $r);
        if ($data['org_unit_id'] ?? null) {
            $q->whereHas('user', fn ($x) => $x->where('org_unit_id', $data['org_unit_id']));
        }
        if ($data['personnel_type_id'] ?? null) {
            $q->whereHas('user', fn ($x) => $x->where('personnel_type_id', $data['personnel_type_id']));
        }

        return $q->orderBy('start_date')->orderBy('id'); // id เป็นตัวตัดสินลำดับให้คงที่ เพื่อให้ chunk ไม่ข้าม/ซ้ำแถว
    }

    /** ทะเบียนคุมต้องคงรายการที่ถูกลบไว้ แต่ต้องบอกให้ชัดว่าถูกลบแล้ว ไม่ใช่ปล่อยค้างเป็น "รออนุมัติ" */
    public function register(Request $r)
    {
        $page = $this->registerQuery($r)->paginate(min((int) $r->input('per_page', 100), 500));
        $page->getCollection()->each(fn ($leave) => $leave->setAttribute('is_deleted', $leave->trashed()));

        return $page;
    }

    /** สถานะที่แสดงต่อผู้ใช้ — ใบที่ถูกลบให้ขึ้นว่าลบแล้วพร้อมสถานะเดิมกำกับ */
    private function statusLabel($leave): string
    {
        $labels = ['Pending' => 'รออนุมัติ', 'Approved' => 'อนุมัติ', 'Rejected' => 'ไม่อนุมัติ', 'Cancelled' => 'ยกเลิก', 'Voided' => 'ยกเลิกเอกสาร'];
        $base = $labels[$leave->status] ?? $leave->status;

        return $leave->trashed() ? "ลบแล้ว (เดิม: {$base})" : $base;
    }

    public function summary(Request $r)
    {
        abort_unless($r->user()->can('leave.report.view') || $r->user()->can('leave.management.manage'), 403);
        /*
         * สรุปต้องเป็นข้อมูลชุดเดียวกับตารางทะเบียนที่ผู้ใช้กรองอยู่
         *
         * เดิมสรุปยึดช่วง "ย้อนหลัง N เดือน" เสมอ ไม่ว่าจะกรองตารางเป็นช่วงใด ตัวเลขบนการ์ด
         * กับตารางด้านล่างจึงเป็นคนละชุดข้อมูลโดยไม่มีอะไรบอก — ตอนนี้รับ from/to แบบเดียวกับ
         * ทะเบียน และคงพารามิเตอร์ months ไว้เป็นทางถอยสำหรับผู้เรียกเดิม
         */
        $r->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'months' => 'nullable|integer|in:6,12',
        ]);

        $months = (int) $r->input('months', 6);
        $from = $r->filled('from')
            ? Carbon::parse($r->input('from'))->startOfDay()
            : now()->subMonths($months)->startOfDay();
        $to = $r->filled('to') ? Carbon::parse($r->input('to'))->endOfDay() : null;

        $query = Leave::query()->join('leave_types', 'leaves.leave_type_id', '=', 'leave_types.id')
            ->where('leaves.start_date', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('leaves.start_date', '<=', $to));
        $this->applyReportScope($query, $r);

        return $query->selectRaw('leave_types.name as leave_type, COUNT(*) requests, SUM(leaves.total_days) days, SUM(leaves.status="Approved") approved, SUM(leaves.status="Rejected") rejected, SUM(CASE WHEN leaves.status="Approved" THEN leaves.total_days ELSE 0 END) approved_days')->groupBy('leave_types.id', 'leave_types.name')->get();
    }

    public function csv(Request $r)
    {
        $query = $this->registerQuery($r);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['เลขที่', 'ชื่อ', 'ประเภทบุคลากร', 'หน่วยงาน', 'ประเภทลา', 'เริ่ม', 'สิ้นสุด', 'จำนวนวัน', 'สถานะ', 'วันที่ลบเอกสาร']);
        // ต้องวนทุกแถว ไม่ใช่แค่หน้าแรกของ paginator
        $query->chunk(500, function ($rows) use ($out) {
            foreach ($rows as $x) {
                fputcsv($out, [$x->document_number, $x->user?->name, $x->user?->personnelType?->name, $x->user?->department?->name, $x->type?->name, $x->start_date?->toDateString(), $x->end_date?->toDateString(), $x->total_days, $this->statusLabel($x), $x->deleted_at?->format('Y-m-d H:i')]);
            }
        });
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        /*
         * ชื่อไฟล์ต้องบอกช่วงข้อมูลในไฟล์ · เดิมทุกครั้งได้ชื่อ `leave-register.csv` เหมือนกันหมด
         * ผู้ใช้ที่ส่งออกหลายช่วงจึงได้ไฟล์ทับกัน (หรือ `(1)`, `(2)`) และแยกไม่ออกว่าไฟล์ไหนช่วงไหน
         */
        $filename = DocumentFilename::make(
            ['ทะเบียนคุมการลา', $r->input('from'), 'ถึง', $r->input('to')],
            'csv',
        );

        return response("\xEF\xBB\xBF".$content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => DocumentFilename::disposition($filename),
        ]);
    }

    private function applyReportScope(Builder $query, Request $request): void
    {
        $ability = $request->user()->can('leave.report.view') ? 'leave.report.view' : 'leave.management.manage';
        $visible = $this->scopes->visibleOrgUnitIds($request->user(), $ability, OrgScopeResolver::ORGANIZATION);
        if ($visible !== null) {
            $query->whereHas('user', fn (Builder $users) => $users->whereIn('org_unit_id', $visible));
        }
    }
}
