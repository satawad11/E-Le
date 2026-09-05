<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\NumberSeries;
use App\Services\OrgScopeResolver;
use App\Services\Saraban\NumberingException;
use App\Services\Saraban\NumberingService;
use Illuminate\Http\Request;

/**
 * การจองและคืนเลขหนังสือ (SoT §9 · NAV §4.3)
 *
 * **ที่มา:** การตรวจ navigation เมื่อ 2026-09-05 พบว่า `NumberingService`
 * **ไม่ถูกเปิดผ่าน HTTP เลย** — เลขออกอัตโนมัติภายในสายงานเดินหนังสือและการ
 * ลงทะเบียนรับเท่านั้น · สิทธิ์สี่ข้อ (`number.reserve` `allocate` `release`
 * `cancel`) จึงมีอยู่ในสารบัญโดยไม่มี route ใดใช้ และ **เจ้าหน้าที่จองเลขล่วงหน้า
 * ไม่ได้เลย** ทั้งที่เป็นงานปกติของงานสารบรรณ (จองเลขไว้ก่อนร่างเสร็จ)
 *
 * **แยกความรุนแรงของแต่ละการกระทำ** ตาม NAV §3.2:
 *
 *   - จองเลข / คืนเลข — งานประจำวัน ไม่ต้องยืนยันตัวตนซ้ำ
 *   - **ยกเลิกเลข** — เลขนั้นจะไม่ถูกใช้อีกเลย ทะเบียนจะมีช่องว่างถาวรที่ต้อง
 *     อธิบายได้ · เป็น sensitive action ที่ต้อง `step_up` และต้องมีเหตุผล
 */
class NumberReservationController extends Controller
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly OrgScopeResolver $scopes,
    ) {}

    /**
     * เลขที่จองไว้และยังไม่ได้ใช้ — คิวงานของเจ้าหน้าที่ธุรการ
     *
     * คืนเฉพาะเลขที่ยังทำอะไรต่อได้ (`reserved` / `allocated`) · เลขที่ออกไปแล้ว
     * ยกเลิก หรือคืนแล้ว อยู่ในรายงานทะเบียนเลข ซึ่งเป็นคนละคำถาม
     */
    public function index(Request $request)
    {
        $numbers = DocumentNumber::query()
            ->whereIn('status', [DocumentNumber::STATUS_RESERVED, DocumentNumber::STATUS_ALLOCATED])
            ->with(['series:id,code,name,category', 'document:id,subject'])
            ->orderByDesc('reserved_at')
            ->limit(200)
            ->get();

        return response()->json([
            'reserved' => $numbers->map(fn (DocumentNumber $number) => [
                ...$number->only(['id', 'uuid', 'formatted', 'buddhist_year', 'running_number', 'status']),
                'series' => $number->series?->name,
                'series_code' => $number->series?->code,
                'document_subject' => $number->document?->subject,
                'reserved_at' => $number->reserved_at?->toIso8601String(),
            ]),
            'series' => $this->availableSeries(),
            'current_year' => $this->numbering->currentBuddhistYear(),
        ]);
    }

    /** จองเลขถัดไปของชุดเลขที่เลือก */
    public function store(Request $request)
    {
        $data = $request->validate([
            'number_series_id' => ['required', 'integer', 'exists:saraban_number_series,id'],
            /*
             * ปีพุทธศักราช — ยอมให้ระบุได้เพราะช่วงปลายปีงบประมาณมีการจองเลข
             * ของปีถัดไปล่วงหน้า · จำกัดช่วงไว้กันการพิมพ์ผิดที่จะสร้างเลขของ
             * ปีที่ไม่มีอยู่จริงในทะเบียน
             */
            'buddhist_year' => ['nullable', 'integer', 'min:2500', 'max:2700'],
        ]);

        $series = NumberSeries::query()->findOrFail($data['number_series_id']);

        try {
            $number = $this->numbering->reserve($series, $request->user(), $data['buddhist_year'] ?? null);
        } catch (NumberingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'number' => [
                ...$number->only(['id', 'uuid', 'formatted', 'buddhist_year', 'running_number', 'status']),
                'series' => $series->name,
                'reserved_at' => $number->reserved_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** คืนเลขที่จองไว้ — เลขกลับมาใช้ได้อีก ไม่เกิดช่องว่างในทะเบียน */
    public function release(Request $request, DocumentNumber $number)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $released = $this->numbering->release($number, $request->user(), $data['reason'] ?? null);
        } catch (NumberingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['number' => $released->only(['id', 'formatted', 'status'])]);
    }

    /**
     * ยกเลิกเลขอย่างเป็นทางการ — **เลขนี้จะไม่ถูกใช้อีกเลย**
     *
     * ต้องมีเหตุผลเสมอ เพราะทะเบียนจะมีช่องว่างถาวรที่ผู้ตรวจสอบต้องอธิบายได้
     */
    public function cancel(Request $request, DocumentNumber $number)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        try {
            $cancelled = $this->numbering->cancel($number, $request->user(), $data['reason']);
        } catch (NumberingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['number' => $cancelled->only(['id', 'formatted', 'status', 'reason'])]);
    }

    /**
     * ชุดเลขที่ผู้เรียกจองได้
     *
     * @return array<int,array<string,mixed>>
     */
    private function availableSeries(): array
    {
        return NumberSeries::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category', 'prefix'])
            ->map(fn (NumberSeries $series) => $series->only(['id', 'code', 'name', 'category', 'prefix']))
            ->all();
    }
}
