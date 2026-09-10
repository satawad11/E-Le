<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * หัวหน้าหน่วยงาน — ใครรับผิดชอบหน่วยงานไหน ตั้งแต่เมื่อไร
 *
 * **ที่มา:** `org_unit_managers` ถูกออกแบบไว้ดี (มีวันมีผล มีผู้รักษาการ มีขอบเขต)
 * และเป็นสิ่งที่ตัวหาผู้อนุมัติใช้ตัดสินว่า "หัวหน้าหน่วยงานของผู้ยื่นคือใคร"
 * แต่ **ไม่เคยมีหน้าจอหรือ API ให้กรอกเลย** ตารางจึงว่างเปล่าในทุกการติดตั้ง
 * และขั้นอนุมัติแบบ "หัวหน้าหน่วยงานของผู้ยื่น" ตกไปใช้ทางถอยตามชื่อบทบาททุกครั้ง
 * ผลคือทุกคนทั้งองค์กรเดินไปหาผู้อนุมัติชุดเดียวกันไม่ว่าสังกัดหน่วยไหน
 *
 * หน้าจอนี้คือที่กรอกข้อมูลนั้น และเป็นที่ที่ "หัวหน้าแผนก" ในหน้าจัดการหน่วยงาน
 * ย้ายมาอยู่ — ของเดิมตั้งค่าได้แต่ไม่มีตรรกะใดอ่าน
 */
class OrgUnitManagerController extends Controller
{
    /** MANAGER หัวหน้าตัวจริง · ACTING ผู้รักษาการ · DELEGATE ผู้รับมอบอำนาจเฉพาะช่วง */
    private const TYPES = ['MANAGER', 'ACTING', 'DELEGATE'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'only_current' => ['nullable', 'boolean'],
        ]);

        $today = now()->toDateString();

        $rows = DB::table('org_unit_managers as m')
            ->join('org_units as u', 'u.id', '=', 'm.org_unit_id')
            ->join('users as usr', 'usr.id', '=', 'm.user_id')
            ->when($data['org_unit_id'] ?? null, fn ($q, $id) => $q->where('m.org_unit_id', $id))
            ->when(
                filter_var($data['only_current'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($q) => $q->whereDate('m.effective_from', '<=', $today)
                    ->where(fn ($inner) => $inner->whereNull('m.effective_to')->whereDate('m.effective_to', '>=', $today))
            )
            ->orderBy('u.name')->orderByDesc('m.effective_from')
            ->get([
                'm.id', 'm.org_unit_id', 'm.user_id', 'm.assignment_type', 'm.scope',
                'm.effective_from', 'm.effective_to', 'm.note',
                'u.name as org_unit_name', 'usr.name as user_name',
            ]);

        return response()->json($rows);
    }

    /** หน่วยงานทั้งหมดพร้อมสถานะว่ามีหัวหน้าที่มีผลอยู่หรือยัง */
    public function units()
    {
        $today = now()->toDateString();

        $managed = DB::table('org_unit_managers')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->pluck('org_unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $units = OrgUnit::query()->where('is_active', true)
            ->orderBy('parent_id')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'type']);

        return response()->json(
            $units->map(fn (OrgUnit $unit) => [
                ...$unit->only(['id', 'name', 'parent_id', 'type']),
                'has_manager' => $managed->contains($unit->id),
                'members' => DB::table('users')->where('is_active', true)->where('org_unit_id', $unit->id)->count(),
            ])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'org_unit_id' => ['required', 'integer', 'exists:org_units,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'assignment_type' => ['nullable', Rule::in(self::TYPES)],
            'scope' => ['nullable', Rule::in(['UNIT', 'DESCENDANTS'])],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'org_unit_id.required' => 'กรุณาเลือกหน่วยงาน',
            'user_id.required' => 'กรุณาเลือกผู้รับผิดชอบ',
            'effective_from.required' => 'กรุณาระบุวันที่เริ่มมีผล',
            'effective_to.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มมีผล',
        ]);

        abort_unless(
            User::whereKey($data['user_id'])->where('is_active', true)->exists(),
            422,
            'ผู้ใช้ที่เลือกถูกปิดการใช้งานบัญชีแล้ว จึงรับผิดชอบหน่วยงานไม่ได้',
        );

        $id = DB::table('org_unit_managers')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $data['org_unit_id'],
            'user_id' => $data['user_id'],
            'assignment_type' => $data['assignment_type'] ?? 'MANAGER',
            'scope' => $data['scope'] ?? 'UNIT',
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->log('org_unit_manager.assigned', 'OrgUnit', $data['org_unit_id'], after: $data);

        return response()->json(['id' => $id, 'message' => 'บันทึกผู้รับผิดชอบหน่วยงานเรียบร้อยแล้ว'], 201);
    }

    /**
     * ปิดการมอบหมาย — ไม่ลบแถว
     *
     * ประวัติว่าใครรับผิดชอบหน่วยงานไหนในช่วงใด เป็นหลักฐานว่าใบลาที่อนุมัติไปแล้ว
     * เดินผ่านผู้มีอำนาจจริง · การลบทิ้งจะทำให้เอกสารเก่าอธิบายตัวเองไม่ได้
     * (PROJECT_RULES ข้อ 7 — ห้ามเขียนทับประวัติที่มีวันมีผล)
     */
    public function end(Request $request, int $id)
    {
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
        ], ['effective_to.required' => 'กรุณาระบุวันที่สิ้นสุด']);

        $row = DB::table('org_unit_managers')->where('id', $id)->first();
        abort_if($row === null, 404, 'ไม่พบการมอบหมายที่ระบุ');

        abort_if(
            $data['effective_to'] < $row->effective_from,
            422,
            'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มมีผล',
        );

        DB::table('org_unit_managers')->where('id', $id)
            ->update(['effective_to' => $data['effective_to'], 'updated_at' => now()]);

        $this->audit->log('org_unit_manager.ended', 'OrgUnit', (int) $row->org_unit_id,
            before: ['effective_to' => $row->effective_to],
            after: ['effective_to' => $data['effective_to']],
        );

        return response()->json(['message' => 'ปิดการมอบหมายเรียบร้อยแล้ว']);
    }
}
