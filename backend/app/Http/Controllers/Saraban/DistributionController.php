<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\Saraban\Distribution;
use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\User;
use App\Services\OrgScopeResolver;
use App\Services\Saraban\DistributionException;
use App\Services\Saraban\DistributionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * การเวียนหนังสือและการติดตามผล (SoT §15 · INT-13)
 *
 * ชั้นนี้ทำหน้าที่ตรวจรูปแบบข้อมูลและสิทธิ์เท่านั้น กติกาว่าหนังสือเวียนได้เมื่อใด
 * และสถานะเดินหน้าอย่างไร อยู่ที่ `DistributionService` ที่เดียว
 */
class DistributionController extends Controller
{
    public function __construct(private readonly DistributionService $distributions) {}

    /** เวียนหนังสือถึงเป้าหมายที่เลือก */
    public function store(Request $request, Document $document)
    {
        $this->authorize('distribute', $document);

        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:50'],
            'targets.*.type' => ['required', 'string', Rule::in([
                DistributionRecipient::TARGET_USER,
                DistributionRecipient::TARGET_ORG_UNIT,
                DistributionRecipient::TARGET_ORG_SUBTREE,
                DistributionRecipient::TARGET_ALL_AUTHORIZED,
            ])],

            /*
             * มีเฉพาะ `all_authorized` เท่านั้นที่ไม่ต้องมี id — ใช้ required_unless
             * ไม่ใช่ nullable เพราะการเลือกหน่วยงานโดยลืมระบุว่าหน่วยงานไหน
             * ต้องเป็นข้อผิดพลาด ไม่ใช่กลายเป็นการเวียนถึงทุกคนเงียบ ๆ
             */
            'targets.*.id' => [
                'required_unless:targets.*.type,'.DistributionRecipient::TARGET_ALL_AUTHORIZED,
                'integer',
                'min:1',
            ],
            'targets.*.role' => ['nullable', 'string', Rule::in([
                DistributionRecipient::ROLE_TO,
                DistributionRecipient::ROLE_CC,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $distribution = $this->distributions->distribute(
                $document,
                $data['targets'],
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (DistributionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['distribution' => $this->present($distribution)], 201);
    }

    /**
     * ตัวเลือกผู้รับสำหรับหน้าจอเวียนหนังสือ
     *
     * **กรองด้วยขอบเขตของสิทธิ์ `saraban.distribution.send` ตัวเดียวกับที่
     * `RecipientResolver` ใช้ตอนส่งจริง** — ถ้าใช้คนละขอบเขต หน้าจอจะเสนอชื่อ
     * ที่กดแล้วถูกปฏิเสธ ซึ่งผู้ใช้ตีความว่าระบบเสีย ไม่ใช่ว่าตนไม่มีสิทธิ์
     */
    public function targets(Request $request, OrgScopeResolver $scopes)
    {
        $this->authorize('viewAny', Document::class);

        $visible = $scopes->visibleOrgUnitIds($request->user(), 'saraban.distribution.send', OrgScopeResolver::UNIT);

        return response()->json([
            'users' => User::query()
                ->when($visible !== null, fn ($query) => $query->whereIn('org_unit_id', $visible))
                ->orderBy('name')
                ->get(['id', 'name', 'org_unit_id']),
            'org_units' => OrgUnit::query()
                ->where('is_active', true)
                ->when($visible !== null, fn ($query) => $query->whereIn('id', $visible))
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * ติดตามการกระจายเอกสารข้ามฉบับ (NAV §2 · §4.8)
     *
     * **ทำไมต้องมีหน้ารวม:** การติดตามผลเดิมดูได้ทีละฉบับเท่านั้น เจ้าหน้าที่ที่
     * เวียนหนังสือสิบฉบับในวันเดียวจึงต้องเปิดทีละฉบับเพื่อตอบคำถามเดียวว่า
     * "ยังมีใครไม่รับทราบบ้าง" · หน้ารวมตอบคำถามนั้นในหน้าจอเดียว
     *
     * ขอบเขตใช้ `saraban.distribution.track` ตัวเดียวกับการติดตามรายฉบับ
     * — ผู้ที่เห็นในหน้ารวมต้องเป็นชุดเดียวกับที่กดเข้าไปดูรายฉบับได้ ไม่งั้น
     * หน้ารวมจะกลายเป็นช่องรั่วของเรื่องที่เจ้าตัวเปิดไม่ได้
     */
    public function overview(Request $request, OrgScopeResolver $scopes)
    {
        $this->authorize('viewAny', Document::class);

        $visible = $scopes->visibleOrgUnitIds($request->user(), 'saraban.distribution.track', OrgScopeResolver::UNIT);

        $distributions = Distribution::query()
            ->with(['recipients', 'sender:id,name', 'document:id,subject,owner_org_unit_id'])
            ->when($visible !== null, fn ($query) => $query->whereHas(
                'document',
                fn ($document) => $document->whereIn('owner_org_unit_id', $visible),
            ))
            ->orderByDesc('sent_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($distributions->items())->map(fn (Distribution $distribution) => [
                'id' => $distribution->id,
                'uuid' => $distribution->uuid,
                'sent_at' => $distribution->sent_at,
                'sent_by' => $distribution->sender?->name,
                'document_id' => $distribution->document_id,
                'subject' => $distribution->document?->subject,
                'total' => $distribution->recipients->count(),
                /*
                 * นับ "รับทราบแล้ว" ตามนิยามเดียวกับรายงานการกระจาย — ผู้ที่
                 * แสดงเจตนาแล้วทุกขั้น ไม่ใช่เฉพาะคำว่า acknowledged อย่างเดียว
                 * มิฉะนั้นคนที่รับไปทำต่อจนเสร็จจะถูกนับว่ายังไม่รับทราบ
                 */
                'acknowledged' => $distribution->recipients
                    ->whereIn('status', [
                        DistributionRecipient::STATUS_ACKNOWLEDGED,
                        DistributionRecipient::STATUS_ACCEPTED,
                        DistributionRecipient::STATUS_IN_PROGRESS,
                        DistributionRecipient::STATUS_COMPLETED,
                    ])
                    ->count(),
            ]),
            'meta' => [
                'current_page' => $distributions->currentPage(),
                'last_page' => $distributions->lastPage(),
                'total' => $distributions->total(),
            ],
        ]);
    }

    /** ติดตามผล — หนังสือฉบับนี้เวียนไปกี่ครั้ง ถึงใคร และแต่ละคนอยู่ขั้นไหน */
    public function index(Document $document)
    {
        $this->authorize('trackDistribution', $document);

        $distributions = Distribution::query()
            ->where('document_id', $document->id)
            ->with(['recipients', 'sender:id,name', 'version:id,version_no,stage'])
            ->orderByDesc('sent_at')
            ->get();

        return response()->json([
            'distributions' => $distributions->map(fn (Distribution $distribution) => $this->present($distribution)),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(Distribution $distribution): array
    {
        return [
            ...$distribution->only(['id', 'uuid', 'note', 'sent_at']),
            'sent_by' => $distribution->sender?->name,
            'version_no' => $distribution->version?->version_no,
            'recipients' => $distribution->recipients->map(fn (DistributionRecipient $recipient) => $recipient->only([
                'id', 'uuid', 'recipient_role', 'target_type',
                'recipient_name_snapshot', 'position_snapshot', 'org_unit_snapshot',
                'status', 'delivered_at', 'opened_at', 'acknowledged_at',
                'accepted_at', 'started_at', 'completed_at', 'response_note',
            ])),
        ];
    }
}
