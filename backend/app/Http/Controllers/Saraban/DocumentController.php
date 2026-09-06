<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Http\Requests\Saraban\StoreDocumentRequest;
use App\Http\Requests\Saraban\UpdateDocumentRequest;
use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\User;
use App\Models\Workflow\WorkflowTransition;
use App\Policies\Saraban\DocumentPolicy;
use App\Services\AuditLogger;
use App\Services\OrgScopeResolver;
use App\Services\Saraban\AuthorityResolver;
use App\Services\Saraban\DashboardService;
use App\Services\Saraban\DocumentComparator;
use App\Services\Saraban\DocumentComparisonException;
use App\Services\Saraban\DocumentService;
use App\Services\Saraban\DocumentWorkflowService;
use App\Services\Saraban\NumberingException;
use App\Services\Saraban\WorkQueueService;
use App\Services\Workflow\WorkflowAuthorizationException;
use App\Services\Workflow\WorkflowTransitionException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ทะเบียนหนังสือ (SoT §8 · INT-05)
 *
 * ทุก action ผ่านลำดับเดียวกับที่สัญญา INT-01 §1.6 กำหนด:
 * ยืนยันตัวตน (`auth:sanctum`) → สิทธิ์ระดับความสามารถ (`ability:` ที่ route)
 * → สิทธิ์ระดับรายฉบับ (`authorize()` → `DocumentPolicy`) → ตรรกะธุรกิจ → audit
 *
 * รายการหนังสือถูกจำกัดด้วย `OrgScopeResolver` ซึ่งเป็นตัวตัดสินขอบเขตกลาง
 * — ผู้ที่ถือสิทธิ์ไม่ได้แปลว่าเห็นทั้งองค์กรโดยอัตโนมัติ
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly OrgScopeResolver $scopes,
        private readonly AuthorityResolver $authority,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        $query = Document::query()
            ->with(['documentType', 'currentVersion', 'ownerOrgUnit'])
            ->latest('id');

        /*
         * `null` = ไม่จำกัดขอบเขต (ORGANIZATION) ตามสัญญาของ OrgScopeResolver
         * นอกเหนือจากขอบเขตที่ได้รับ ผู้ใช้ยังต้องเห็นเรื่องที่ตัวเองสร้างเสมอ
         * มิฉะนั้นคนที่ยื่นหนังสือแล้วถูกย้ายหน่วยงานจะหาเรื่องของตัวเองไม่เจอ
         */
        $visibleUnits = $this->scopes->visibleOrgUnitIds($user, 'saraban.document.view', OrgScopeResolver::UNIT);

        if ($visibleUnits !== null) {
            $query->where(fn ($q) => $q
                ->whereIn('owner_org_unit_id', $visibleUnits)
                ->orWhere('created_by', $user->id));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        /*
         * กล่องรอลงทะเบียน (NAV §2 · §4.2)
         *
         * หนังสือรับที่เข้ามาแล้วแต่ยังไม่มีเลขรับ — เป็นคิวงานประจำวันของ
         * เจ้าหน้าที่ธุรการ ไม่ใช่การค้นทะเบียน · เขียนเป็นตัวกรองบนทะเบียนเดิม
         * แทนที่จะทำ endpoint ใหม่ เพราะขอบเขตการมองเห็นและการเรียงลำดับ
         * ต้องเป็นชุดเดียวกันเสมอ ถ้าแยกทางกันเมื่อใดสองหน้าจะไม่ตรงกัน
         */
        if ($request->boolean('pending_registration')) {
            $query->where('direction', Document::DIRECTION_INCOMING)
                ->whereDoesntHave('incomingRegistration');
        }

        /*
         * ค้นหาขั้นสูง (NAV §4.10)
         *
         * **ค้นเฉพาะเมทาดาทาที่ปรากฏบนหน้าเอกสาร** — เรื่อง และเลขที่หนังสือ ·
         * ไม่ค้นในเนื้อไฟล์ PDF เพราะจะทำให้ผู้ที่เห็นรายการในทะเบียนได้ (แต่เปิด
         * ไฟล์ไม่ได้) อนุมานเนื้อหาข้างในจากผลการค้นหา
         */
        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(fn ($q) => $q
                ->where('subject', 'like', "%{$term}%")
                ->orWhereHas('documentNumber', fn ($n) => $n->where('formatted', 'like', "%{$term}%")));
        }

        if ($request->filled('document_type_id')) {
            $query->where('document_type_id', $request->integer('document_type_id'));
        }

        if ($request->filled('confidentiality')) {
            $query->where('confidentiality', $request->string('confidentiality')->toString());
        }

        // ช่วงวันที่ — นับจากวันที่สร้างระเบียนในทะเบียน
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return response()->json($query->paginate(20));
    }

    /**
     * ข้อมูลอ้างอิงสำหรับหน้าฟอร์ม
     *
     * คืนหน่วยงานมาจากที่นี่ด้วย แทนที่จะให้หน้าเว็บไปเรียก `/admin/departments`
     * ซึ่งต้องมีสิทธิ์ `manage_departments` — ผู้ที่สร้างหนังสือได้ไม่จำเป็นต้องมี
     * สิทธิ์จัดการโครงสร้างองค์กร การผูกสองอย่างนี้เข้าด้วยกันจะบังคับให้ต้อง
     * แจกสิทธิ์เกินความจำเป็นเพียงเพื่อให้ฟอร์มใช้งานได้
     */
    /**
     * คิวงาน — หนังสือที่ผู้เรียกลงมือทำได้ ณ ตอนนี้ (NAV §4.7)
     *
     * **ที่มา:** การตรวจ navigation พบว่าระบบตอบได้เฉพาะ "ฉันทำอะไรกับหนังสือ
     * ฉบับนี้ได้บ้าง" แต่ไม่มีคำถามย้อนกลับ · ผู้พิจารณาและผู้ลงนามจึงต้องเปิด
     * ทะเบียนแล้วไล่เปิดทีละฉบับเพื่อหาว่าฉบับไหนถึงคิวตน
     *
     * ใช้สิทธิ์เดียวกับการเปิดทะเบียน เพราะคิวคือมุมมองหนึ่งของทะเบียน ไม่ใช่
     * ความสามารถใหม่ · สิ่งที่จำกัดว่าใครเห็นอะไร คือสายงานและ policy รายฉบับ
     */
    /**
     * ตัวเลขสรุปหน้าแรกของงานสารบรรณ (NAV §4.1)
     *
     * ใช้สิทธิ์เดียวกับทะเบียน — หน้าแรกคือมุมมองรวมของข้อมูลชุดเดียวกัน
     * ไม่ใช่ความสามารถใหม่ที่ต้องคุมแยก
     */
    public function dashboard(Request $request, DashboardService $dashboard)
    {
        $this->authorize('viewAny', Document::class);

        return response()->json($dashboard->summaryFor($request->user()));
    }

    public function queue(Request $request, WorkQueueService $queue)
    {
        $this->authorize('viewAny', Document::class);

        return response()->json($queue->pendingFor($request->user()));
    }

    public function options(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        return response()->json([
            'document_types' => DocumentType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'abbreviation', 'category']),
            /*
             * RISK-20 (ปิดใน INT-08) — เดิมคืนทุกหน่วยงานที่ยังใช้งานอยู่ ผู้ใช้จึง
             * ยื่นหนังสือแทนหน่วยงานใดก็ได้ · ตอนนี้จำกัดตามอำนาจที่ resolve ได้จริง
             * (`null` = ไม่จำกัด ตามสัญญาของ OrgScopeResolver)
             */
            'org_units' => OrgUnit::query()
                ->where('is_active', true)
                ->when(
                    $this->authority->unitsUnderAuthorityOf($request->user(), 'saraban.document.create') !== null,
                    fn ($query) => $query->whereIn(
                        'id',
                        $this->authority->unitsUnderAuthorityOf($request->user(), 'saraban.document.create') ?? [],
                    ),
                )
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'directions' => Document::directions(),
            'confidentiality_levels' => Document::confidentialityLevels(),
            'urgency_levels' => Document::urgencyLevels(),
        ]);
    }

    public function store(StoreDocumentRequest $request)
    {
        $this->authorize('create', Document::class);

        /*
         * RISK-20 — กรองรายการในฟอร์มอย่างเดียวไม่พอ หน้าเว็บไม่ใช่ด่านความปลอดภัย
         * (SoT §6) ต้องตรวจซ้ำที่ server ด้วย มิฉะนั้นส่ง id หน่วยงานอื่นเข้ามาตรง ๆ ก็ผ่าน
         */
        $this->assertMayActForUnit($request->user(), (int) $request->validated('owner_org_unit_id'));

        $document = $this->documents->createDraft(
            $request->safe()->except('file'),
            $request->user(),
            $request->file('file'),
        );

        return response()->json($document->load('versions'), 201);
    }

    /**
     * เปรียบเทียบสองเวอร์ชันของหนังสือฉบับนี้ (SoT §22 · INT-18)
     *
     * เทียบ metadata / file hash / จำนวนหน้า / ขนาดไฟล์ — **ไม่เทียบเนื้อความ**
     * เพราะการอ่านข้อความออกจาก PDF ให้ผลต่างกันตามฟอนต์และวิธีสร้างไฟล์
     * ความต่างที่รายงานผิดในเอกสารราชการอันตรายกว่าการไม่รายงาน
     */
    public function compare(Request $request, Document $document, DocumentComparator $comparator)
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'left' => ['required', 'integer'],
            'right' => ['required', 'integer'],
        ]);

        $versions = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->whereIn('id', [$data['left'], $data['right']])
            ->get()
            ->keyBy('id');

        // ทั้งสองเวอร์ชันต้องเป็นของหนังสือฉบับนี้ — ไม่งั้นเป็นช่องอ่านข้ามฉบับ
        abort_unless($versions->has($data['left']) && $versions->has($data['right']), 422, 'เวอร์ชันที่เลือกไม่ใช่ของหนังสือฉบับนี้');

        try {
            $result = $comparator->compare($versions[$data['left']], $versions[$data['right']]);
        } catch (DocumentComparisonException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function show(Request $request, Document $document, DocumentPolicy $policy)
    {
        $this->authorize('view', $document);

        /*
         * **ทะเบียนการเข้าถึงข้อมูลข่าวสารลับ**
         * (ระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544 หมวด 4)
         *
         * ระเบียบกำหนดให้ต้องบันทึกว่าใครเข้าถึงเอกสารลับเมื่อใด · บันทึกหลัง
         * `authorize()` เท่านั้น เพื่อให้ทะเบียนมีแต่การเข้าถึงที่เกิดขึ้นจริง
         * ไม่ใช่ความพยายามที่ถูกปฏิเสธ (ซึ่งอยู่ในบันทึกความปลอดภัยคนละชุด)
         *
         * เกณฑ์ว่าชั้นใดต้องบันทึกมาจากนโยบาย ไม่ได้ฝังในโค้ด
         */
        if ($policy->requiresAccessLog($document)) {
            $this->audit->log(
                'saraban.document.classified_accessed',
                $document,
                $document->id,
                null,
                [
                    'confidentiality' => $document->confidentiality,
                    'document_number' => $document->documentNumber?->formatted,
                ],
                null,
                $request->user()?->id,
            );
        }

        return response()->json(
            $document->load(['documentType', 'ownerOrgUnit', 'creator', 'versions', 'currentVersion'])
        );
    }

    public function update(UpdateDocumentRequest $request, Document $document)
    {
        $this->authorize('update', $document);

        $updated = $this->documents->updateDraft(
            $document,
            $request->safe()->except('file'),
            $request->user(),
            $request->file('file'),
        );

        return response()->json($updated->load('versions'));
    }

    public function submit(Request $request, Document $document)
    {
        $this->authorize('submit', $document);

        /*
         * หนังสือที่ไม่มีต้นฉบับแนบมาเลย เสนอเข้าสู่กระบวนการไม่ได้ —
         * ผู้อนุมัติในขั้นถัดไปจะไม่มีอะไรให้พิจารณา
         */
        if ($document->currentVersion === null) {
            return response()->json([
                'message' => 'ต้องแนบไฟล์หนังสืออย่างน้อยหนึ่งฉบับก่อนเสนอเรื่อง',
            ], 422);
        }

        return response()->json($this->documents->submit($document, $request->user()));
    }

    /**
     * ผู้ใช้คนนี้ทำเรื่องแทนหน่วยงานนี้ได้หรือไม่ (RISK-20)
     *
     * @throws ValidationException
     */
    private function assertMayActForUnit(User $user, int $orgUnitId): void
    {
        if ($this->authority->hasAuthorityOver($user, 'saraban.document.create', $orgUnitId)) {
            return;
        }

        throw ValidationException::withMessages([
            'owner_org_unit_id' => ['ท่านไม่มีอำนาจทำเรื่องแทนหน่วยงานที่เลือก'],
        ]);
    }

    /** สถานะการเดินเรื่องของหนังสือฉบับนี้ (INT-08) */
    public function workflow(Request $request, Document $document, DocumentWorkflowService $workflow)
    {
        $this->authorize('view', $document);

        return response()->json($workflow->stateFor($document, $request->user()));
    }

    /**
     * ลงมือทำขั้นปัจจุบันของสายการเดินหนังสือ
     *
     * **สิทธิ์ในการทำขั้นตอนถูกตัดสินโดย engine กลาง** (`WorkflowEngine::authorize()`)
     * ซึ่งรู้ทั้งผู้ได้รับมอบหมายและผู้รับมอบอำนาจ — controller ไม่ตัดสินเอง
     * มิฉะนั้นจะมีกติกาสองชุดที่หลุดจากกัน
     */
    public function performWorkflowAction(Request $request, Document $document, DocumentWorkflowService $workflow)
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in([
                WorkflowTransition::APPROVE,
                WorkflowTransition::REJECT,
                WorkflowTransition::RETURN,
            ])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $workflow->perform($document, $data['action'], $request->user(), $data['comment'] ?? null);
        } catch (WorkflowAuthorizationException $exception) {
            return response()->json(['message' => 'ท่านไม่มีสิทธิ์ดำเนินการขั้นตอนนี้'], 403);
        } catch (WorkflowTransitionException|NumberingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($workflow->stateFor($document->fresh(), $request->user()));
    }
}
