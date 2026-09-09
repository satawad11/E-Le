<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Leave;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    /** ปีงบประมาณกำหนดรอบสิทธิ์และคีย์ของยอดวันลา การเปลี่ยนแปลงจึงต้องตรวจย้อนได้ */
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * List all fiscal years, newest first.
     */
    public function index()
    {
        $fiscalYears = FiscalYear::latestYear()->get();

        return response()->json($fiscalYears);
    }

    /**
     * Create a new fiscal year.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2500|max:2700',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_current' => 'boolean',
        ]);

        // If this one is set as current, unset all others
        if (! empty($data['is_current'])) {
            FiscalYear::where('is_current', true)->update(['is_current' => false]);
        }

        $fiscalYear = FiscalYear::create($data);

        $this->audit->log('fiscal_year_created', 'FiscalYear', $fiscalYear->id, after: $fiscalYear->toArray());

        return response()->json([
            'message' => 'เพิ่มปีงบประมาณเรียบร้อยแล้ว',
            'fiscal_year' => $fiscalYear,
        ], 201);
    }

    /**
     * Update a fiscal year.
     */
    public function update(Request $request, FiscalYear $fiscalYear)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2500|max:2700',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_current' => 'boolean',
        ]);

        // If this one is set as current, unset all others
        if (! empty($data['is_current'])) {
            FiscalYear::where('is_current', true)
                ->where('id', '!=', $fiscalYear->id)
                ->update(['is_current' => false]);
        }

        $before = $fiscalYear->toArray();
        $fiscalYear->update($data);

        $this->audit->log('fiscal_year_updated', 'FiscalYear', $fiscalYear->id, before: $before, after: $fiscalYear->fresh()->toArray());

        return response()->json([
            'message' => 'อัปเดตปีงบประมาณเรียบร้อยแล้ว',
            'fiscal_year' => $fiscalYear,
        ]);
    }

    /**
     * Delete a fiscal year.
     */
    public function destroy(FiscalYear $fiscalYear)
    {
        /*
         * LS-05 — ปีงบประมาณที่ใบลาอ้างอยู่ห้ามลบ
         *
         * เลขปีงบประมาณเป็นคีย์ของยอดวันลาและเป็นตัวกำหนดช่วงเวลาของรายงานย้อนหลัง
         * การลบทิ้งทำให้ใบลาที่อ้างอยู่ชี้ไปยังปีที่ไม่มีอยู่ และรายงานของรอบนั้นเปิดดูไม่ได้อีก
         * ใช้การปิดการใช้งาน/ตั้งปีปัจจุบันใหม่แทนการลบ
         */
        $leaves = Leave::where('fiscal_year_id', $fiscalYear->id)->count();

        if ($leaves > 0) {
            return response()->json([
                'message' => "ลบไม่ได้เพราะมีใบลา {$leaves} รายการอ้างถึงปีงบประมาณนี้อยู่",
            ], 422);
        }

        if ($fiscalYear->is_current) {
            return response()->json([
                'message' => 'ลบไม่ได้เพราะเป็นปีงบประมาณปัจจุบัน — ตั้งปีอื่นเป็นปีปัจจุบันก่อน',
            ], 422);
        }

        $before = $fiscalYear->toArray();
        $fiscalYear->delete();

        $this->audit->log('fiscal_year_deleted', 'FiscalYear', $before['id'], before: $before);

        return response()->json([
            'message' => 'ลบปีงบประมาณเรียบร้อยแล้ว',
        ]);
    }
}
