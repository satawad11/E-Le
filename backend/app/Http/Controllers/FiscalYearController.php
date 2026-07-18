<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
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
            'name'       => 'required|string|max:255',
            'year'       => 'required|integer|min:2500|max:2700',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
            'is_current' => 'boolean',
        ]);

        // If this one is set as current, unset all others
        if (!empty($data['is_current'])) {
            FiscalYear::where('is_current', true)->update(['is_current' => false]);
        }

        $fiscalYear = FiscalYear::create($data);

        return response()->json([
            'message'     => 'เพิ่มปีงบประมาณเรียบร้อยแล้ว',
            'fiscal_year' => $fiscalYear,
        ], 201);
    }

    /**
     * Update a fiscal year.
     */
    public function update(Request $request, FiscalYear $fiscalYear)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'year'       => 'required|integer|min:2500|max:2700',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
            'is_current' => 'boolean',
        ]);

        // If this one is set as current, unset all others
        if (!empty($data['is_current'])) {
            FiscalYear::where('is_current', true)
                ->where('id', '!=', $fiscalYear->id)
                ->update(['is_current' => false]);
        }

        $fiscalYear->update($data);

        return response()->json([
            'message'     => 'อัปเดตปีงบประมาณเรียบร้อยแล้ว',
            'fiscal_year' => $fiscalYear,
        ]);
    }

    /**
     * Delete a fiscal year.
     */
    public function destroy(FiscalYear $fiscalYear)
    {
        $fiscalYear->delete();

        return response()->json([
            'message' => 'ลบปีงบประมาณเรียบร้อยแล้ว',
        ]);
    }

    /**
     * Get the current active fiscal year (public API for Dashboard, etc.)
     */
    public function current()
    {
        $current = FiscalYear::current();
        return response()->json($current);
    }
}
