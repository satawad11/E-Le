<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Services\Saraban\RegisterReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * รายงานทะเบียนงานสารบรรณ (SoT §28)
 *
 * **รายงานเป็นทางลัดที่ข้ามขอบเขตได้ง่ายที่สุด** เพราะรวมข้อมูลจำนวนมากไว้ใน
 * คำขอเดียว · ทุกรายงานจึงถูกจำกัดด้วย `OrgScopeResolver` เหมือนหน้าทะเบียนปกติ
 *
 * รายงานเลขหนังสือเป็นข้อยกเว้นที่ตั้งใจ — เลขเป็นทรัพยากรร่วมของชุดเลขนั้น
 * ผู้ตรวจสอบต้องเห็นช่องว่างในลำดับได้ ไม่งั้นแยกไม่ออกว่าเลขที่หายไปเป็น
 * การคืนเลขปกติหรือความผิดปกติ
 */
class RegisterReportController extends Controller
{
    public function __construct(private readonly RegisterReportService $reports) {}

    public function register(Request $request)
    {
        $filters = $request->validate([
            'direction' => ['nullable', 'string', Rule::in(Document::directions())],
            'category' => ['nullable', 'string', Rule::in(DocumentType::categories())],
            'status' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json($this->reports->register($request->user(), $filters));
    }

    public function numbers(Request $request)
    {
        $filters = $request->validate([
            'series_id' => ['nullable', 'integer', 'exists:saraban_number_series,id'],
            'buddhist_year' => ['nullable', 'integer', 'min:2500', 'max:2700'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json($this->reports->numbers($filters));
    }

    public function distribution(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json($this->reports->distribution($request->user(), $filters));
    }
}
