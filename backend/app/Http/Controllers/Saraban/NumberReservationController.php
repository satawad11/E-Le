<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
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

    /**
     * ผูกเลขที่จองไว้เข้ากับหนังสือฉบับหนึ่ง (SoT §9)
     *
     * **ที่มา:** ก่อนหน้านี้เลขที่จองไว้ไม่มีทางไปอยู่บนหนังสือได้เลย —
     * `DocumentWorkflowService` จองเลขใหม่ทุกครั้งตอนลงนาม ส่วนเลขที่เจ้าหน้าที่
     * จองล่วงหน้าทำได้แค่คืนหรือยกเลิก · สิทธิ์ `saraban.number.allocate` และ
     * `NumberingService::allocate()` มีอยู่แล้วแต่ไม่มี route ใดเรียก
     *
     * **ผูกแล้วยังไม่ใช่การออกเลข** — เลขจะกลายเป็นของถาวรตอนลงนามเท่านั้น
     * ถ้าหนังสือถูกตีกลับหรือไม่ได้ลงนาม เจ้าหน้าที่คืนเลขได้ตามปกติ
     */
    public function allocate(Request $request, DocumentNumber $number)
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:saraban_documents,id'],
        ]);

        $document = Document::query()->findOrFail($data['document_id']);

        /*
         * ตรวจสิทธิ์รายฉบับด้วย `view` **ไม่ใช่ `update`**
         *
         * `update` ปฏิเสธหนังสือที่เข้าสู่กระบวนการแล้ว ซึ่งเป็นช่วงที่เจ้าหน้าที่
         * ผูกเลขจริง ๆ (จองไว้ตอนร่าง แล้วผูกตอนเสนอ) · การผูกเลขไม่ได้แก้เนื้อหา
         * หนังสือ จึงไม่ควรใช้กติกาเดียวกับการแก้ไข
         *
         * `view` บังคับทั้งขอบเขตหน่วยงานและชั้นความลับอยู่แล้ว และ route ยังต้อง
         * ผ่าน `saraban.number.allocate` ซึ่งเป็นสิทธิ์ของเจ้าหน้าที่ธุรการ
         */
        $this->authorize('view', $document);

        if ($document->document_number_id !== null) {
            return response()->json(['message' => 'หนังสือฉบับนี้มีเลขหนังสือแล้ว'], 422);
        }

        // ลงนามหรือยกเลิกไปแล้ว ผูกเลขไม่ได้ — เลขของฉบับที่ลงนามแล้วออกไปแล้ว
        if (in_array($document->status, [Document::STATUS_SIGNED, Document::STATUS_CANCELLED], true)) {
            return response()->json([
                'message' => 'หนังสือที่ลงนามหรือยกเลิกแล้ว ผูกเลขเพิ่มไม่ได้',
            ], 422);
        }

        /*
         * ชุดเลขต้องตรงประเภทของหนังสือ — เลขของทะเบียนหนังสือรับไปอยู่บน
         * หนังสือส่งไม่ได้ ทะเบียนจะอ่านไม่ได้และตรวจย้อนหลังไม่ได้
         */
        $category = $document->documentType?->category;

        if ($category !== null && $number->series?->category !== $category) {
            return response()->json([
                'message' => 'ชุดเลขไม่ตรงกับประเภทของหนังสือฉบับนี้',
            ], 422);
        }

        try {
            $allocated = $this->numbering->allocate($number, $document, $request->user());
        } catch (NumberingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'number' => [
                ...$allocated->only(['id', 'formatted', 'status']),
                'document_id' => $document->id,
                'document_subject' => $document->subject,
            ],
        ]);
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
