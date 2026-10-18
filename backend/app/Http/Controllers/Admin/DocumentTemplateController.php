<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Personnel;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DefaultDocumentTemplateService;
use App\Services\LeaveDocumentFields;
use App\Services\LeaveDocumentTemplateResolver;
use App\Services\ThaiDateService;
use App\Support\Leave\SignerCapacity;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * แบบฟอร์ม HTML ของใบลา (เส้นทางเดิมก่อน Document Engine)
 *
 * TPL-SEC-01 — หน้านี้เป็นแบบอ่านอย่างเดียว
 *
 * เดิมผู้ดูแลแก้ `content_html` ได้ และระบบส่งข้อความนั้นเข้า `Blade::render()`
 * ทั้งตอนพิมพ์ใบลาและตอนพรีวิว ซึ่งเท่ากับให้รันโค้ด PHP บนเซิร์ฟเวอร์ได้
 * ตอนนี้แบบฟอร์ม HTML มาจากไฟล์ blade ในโค้ดเท่านั้น และพรีวิวคือการเรนเดอร์
 * ไฟล์นั้นด้วยข้อมูลตัวอย่าง — การปรับแบบฟอร์มโดยผู้ดูแลให้ทำผ่าน Document Engine
 */
class DocumentTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = DocumentTemplate::query();

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        $resolver = app(LeaveDocumentTemplateResolver::class);
        $leaveTypes = LeaveType::query()->orderBy('id')->get(['id', 'name', 'document_template_code']);

        $templates = $query->orderBy('module')->orderBy('id')
            ->get(['id', 'code', 'name', 'module', 'document_type', 'render_mode', 'paper_size', 'orientation', 'is_active', 'updated_at'])
            ->map(function (DocumentTemplate $template) use ($resolver, $leaveTypes) {
                $template->setAttribute('has_preview', $resolver->viewForCode($template->code) !== null);
                $template->setAttribute('leave_types', $leaveTypes
                    ->filter(fn (LeaveType $type) => ($type->document_template_code ?: $resolver->templateCodeForLeaveType($type->name)) === $template->code)
                    ->map(fn (LeaveType $type) => ['id' => $type->id, 'name' => $type->name])
                    ->values());

                return $template;
            });

        return response()->json($templates);
    }

    public function show(DocumentTemplate $template)
    {
        return response()->json($template->makeHidden('content_html'));
    }

    /**
     * ลงทะเบียนแบบฟอร์มตั้งต้นที่ยังไม่มีในฐานข้อมูล
     *
     * แถวในตารางนี้ใช้ pin "ใบลาฉบับนี้ออกด้วยแบบฟอร์มใด" ไม่ได้ใช้เป็นเนื้อหาที่พิมพ์
     */
    public function seedDefaults(DefaultDocumentTemplateService $templates)
    {
        $templates->sync();

        app(AuditLogger::class)->log('document_template.defaults_synced', 'document_template');

        return response()->json(['message' => 'ซิงค์แม่แบบเอกสารตั้งต้นเรียบร้อยแล้ว']);
    }

    /**
     * รายการตัวแปรที่ใช้ในแม่แบบใบลาได้ พร้อมคำอธิบายไทย
     */
    public function fields()
    {
        return response()->json(LeaveDocumentFields::catalog());
    }

    /**
     * พรีวิวแบบฟอร์มด้วยข้อมูลตัวอย่าง — เรนเดอร์จากไฟล์ blade ในโค้ด ไม่รับ HTML จากผู้ใช้
     */
    public function preview(DocumentTemplate $template, LeaveDocumentFields $fields, LeaveDocumentTemplateResolver $resolver, ThaiDateService $thaiDateService)
    {
        $view = $resolver->viewForCode($template->code);

        if ($view === null) {
            return response()->json(['message' => 'แบบฟอร์มนี้ไม่มีไฟล์แบบฟอร์ม HTML ในระบบ จึงพรีวิวจากหน้านี้ไม่ได้'], 422);
        }

        try {
            $html = view($view, array_merge($fields->sample(), [
                'leave' => $this->sampleLeave(),
                'thaiDateService' => $thaiDateService,
                'lastLeave' => null,
                'leaveBalance' => null,
                'settings' => collect(),
                'approverBlocks' => $this->sampleApproverBlocks(),
                'statSem1' => [],
                'statSem2' => [],
                'verificationQr' => null,
                'verificationUrl' => null,
                'verificationHash' => 'ตัวอย่าง',
            ]))->render();
        } catch (\Throwable $exception) {
            Log::warning('document_template.preview_failed', ['code' => $template->code, 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'พรีวิวแบบฟอร์มนี้ด้วยข้อมูลตัวอย่างไม่สำเร็จ'], 422);
        }

        return Pdf::loadHTML($html)
            ->setPaper(strtolower($template->paper_size ?: 'a4'), strtolower($template->orientation ?: 'portrait'))
            ->stream('preview.pdf');
    }

    /**
     * ใบลาตัวอย่างที่ไม่บันทึกลงฐานข้อมูล สำหรับแบบฟอร์มที่ยังอ่านค่าจากโมเดลโดยตรง
     */
    private function sampleLeave(): Leave
    {
        $today = Carbon::today();

        // R02: ชื่อ สังกัด ตำแหน่งย้ายไปอยู่ที่ข้อมูลบุคลากร — แม่แบบเอกสารปรับให้อ่านจาก personnel ใน R-08
        $user = new User;
        $user->setRelation('personnel', new Personnel(['title' => 'นาย', 'first_name' => 'ตัวอย่าง', 'last_name' => 'ทดสอบระบบ']));

        $leave = new Leave([
            'document_number' => 'ตัวอย่าง 0001/'.($today->year + 543),
            'start_date' => $today,
            'end_date' => $today->copy()->addDays(14),
            'total_days' => 15,
            'reason' => 'ตัวอย่างเหตุผลการลา',
            'contact_address' => 'บ้านเลขที่ 99 ถนนตัวอย่าง',
            'status' => 'Approved',
            'meta_data' => [],
        ]);
        $leave->created_at = $today;
        $leave->setRelation('user', $user);
        $leave->setRelation('type', new LeaveType(['name' => 'ตัวอย่างประเภทการลา']));
        $leave->setRelation('substitute', null);

        return $leave;
    }

    /**
     * บล็อกผู้ลงนามตัวอย่างสำหรับพรีวิว
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleApproverBlocks(): array
    {
        return collect([
            SignerCapacity::CHECKER => 'หัวหน้างานบุคลากร',
            SignerCapacity::DEPUTY => 'รองผู้อำนวยการ',
            SignerCapacity::DIRECTOR => 'ผู้อำนวยการ',
        ])
            ->map(fn (string $position, string $capacity) => [
                'name' => 'ตัวอย่าง ผู้ลงนาม',
                'date' => now(),
                'signature' => null,
                'comment' => 'เห็นควรอนุญาต',
                'status' => 'Approved',
                'position' => $position,
                'role_name' => '',
                'capacity' => $capacity,
                'acting_line' => '',
            ])
            ->values()
            ->all();
    }
}
