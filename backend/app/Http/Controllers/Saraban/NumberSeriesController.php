<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ตั้งค่าชุดเลขหนังสือ (SoT §9 · ปิด RISK-21)
 *
 * **RISK-21 ที่ปิดในเช็คพอยต์นี้:** ตั้งแต่ INT-09 ชุดเลขหนังสือต้องถูกสร้าง
 * ด้วย seeder หรือ tinker เท่านั้น ผู้ดูแลจึงเปิดใช้งานระบบเองไม่ได้ และ
 * การลงนามครั้งแรกจะล้มเหลวโดยไม่มีทางแก้จากหน้าจอ
 *
 * **ชุดเลขที่ออกเลขไปแล้วลบไม่ได้** — เลขที่ออกไปแล้วอยู่บนกระดาษที่ส่งออกไป
 * แล้ว การลบทะเบียนต้นทางทำให้ตรวจสอบย้อนหลังไม่ได้ · ปิดการใช้งานได้แทน
 */
class NumberSeriesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json([
            'series' => NumberSeries::query()
                ->with('orgUnit:id,name')
                ->withCount(['numbers as issued_count'])
                ->orderBy('name')
                ->get()
                ->map(fn (NumberSeries $series) => [
                    ...$series->only(['id', 'uuid', 'code', 'name', 'category', 'prefix', 'is_active']),
                    'org_unit' => $series->orgUnit?->name,
                    'issued_count' => $series->issued_count,
                ]),
            'categories' => DocumentType::categories(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', 'unique:saraban_number_series,code'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::in(DocumentType::categories())],
            'prefix' => ['required', 'string', 'max:100'],
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $series = NumberSeries::create([...$data, 'is_active' => $data['is_active'] ?? true]);

        $this->audit->log('saraban.number_series.created', $series, $series->id, null, [
            'code' => $series->code,
            'prefix' => $series->prefix,
        ], null, $request->user()->id);

        return response()->json(['series' => $series->only(['id', 'uuid', 'code', 'name', 'category', 'prefix', 'is_active'])], 201);
    }

    public function update(Request $request, NumberSeries $series)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'prefix' => ['sometimes', 'string', 'max:100'],
            'org_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:org_units,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /*
         * รหัสและหมวดเปลี่ยนไม่ได้หลังออกเลขไปแล้ว — เลขที่ออกไปแล้วอ้างชุดนี้อยู่
         * การเปลี่ยนความหมายของชุดย้อนหลังทำให้ทะเบียนอ่านผิดทั้งชุด
         */
        $issued = DocumentNumber::query()->where('number_series_id', $series->id)->exists();

        if ($issued && array_key_exists('prefix', $data) && $data['prefix'] !== $series->prefix) {
            return response()->json([
                'message' => 'ชุดเลขนี้ออกเลขไปแล้ว เปลี่ยนคำนำหน้าไม่ได้ · หากต้องการรูปแบบใหม่ให้สร้างชุดใหม่',
            ], 422);
        }

        $before = $series->only(['name', 'prefix', 'org_unit_id', 'is_active']);
        $series->update($data);

        $this->audit->log(
            'saraban.number_series.updated',
            $series,
            $series->id,
            $before,
            $series->only(['name', 'prefix', 'org_unit_id', 'is_active']),
            null,
            $request->user()->id,
        );

        return response()->json(['series' => $series->only(['id', 'uuid', 'code', 'name', 'category', 'prefix', 'is_active'])]);
    }

    public function destroy(Request $request, NumberSeries $series)
    {
        $issued = DB::table('saraban_document_numbers')->where('number_series_id', $series->id)->exists();

        if ($issued) {
            return response()->json([
                'message' => 'ชุดเลขนี้ออกเลขไปแล้ว ลบไม่ได้ · ปิดการใช้งานแทนเพื่อไม่ให้ออกเลขใหม่',
            ], 422);
        }

        $this->audit->log('saraban.number_series.deleted', $series, $series->id, $series->only(['code', 'name']), null, null, $request->user()->id);

        $series->delete();

        return response()->json(['message' => 'ลบชุดเลขแล้ว']);
    }

    /**
     * ตั้งค่าชุดเลขหนังสือเริ่มต้นสำหรับสถานศึกษา (Quick Setup)
     */
    public function quickSetup(Request $request)
    {
        $defaults = [
            [
                'code' => 'school-internal',
                'name' => 'หนังสือภายในสถานศึกษา',
                'category' => DocumentType::CATEGORY_INTERNAL,
                'prefix' => 'ศธ ๐๔๐๐๑/',
            ],
            [
                'code' => 'school-outgoing',
                'name' => 'หนังสือภายนอกสถานศึกษา',
                'category' => DocumentType::CATEGORY_OUTGOING,
                'prefix' => 'ศธ ๐๔๐๐๑/',
            ],
            [
                'code' => 'school-order',
                'name' => 'คำสั่งสถานศึกษา',
                'category' => DocumentType::CATEGORY_ORDER,
                'prefix' => 'คำสั่งสถานศึกษา ที่ ',
            ],
            [
                'code' => 'school-announcement',
                'name' => 'ประกาศสถานศึกษา',
                'category' => DocumentType::CATEGORY_ANNOUNCEMENT,
                'prefix' => 'ประกาศสถานศึกษา เรื่อง ',
            ],
            [
                'code' => 'school-incoming',
                'name' => 'ทะเบียนรับหนังสือราชการ',
                'category' => DocumentType::CATEGORY_INCOMING,
                'prefix' => 'รับ ',
            ],
        ];

        $created = 0;
        foreach ($defaults as $def) {
            $exists = NumberSeries::query()->where('code', $def['code'])->exists();
            if (! $exists) {
                NumberSeries::create([
                    ...$def,
                    'is_active' => true,
                ]);
                $created++;
            }
        }

        $this->audit->log('saraban.number_series.quick_setup', 'NumberSeries', 0, null, [
            'created_count' => $created,
        ], null, $request->user()->id);

        return response()->json([
            'message' => "สร้างชุดเลขหนังสือเริ่มต้นเรียบร้อยแล้ว ({$created} ชุดใหม่)",
            'created_count' => $created,
        ]);
    }
}
