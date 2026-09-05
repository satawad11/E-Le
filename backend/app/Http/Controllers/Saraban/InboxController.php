<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\DistributionRecipient;
use App\Services\Saraban\DistributionException;
use App\Services\Saraban\DistributionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * กล่องหนังสือเข้าของผู้ใช้แต่ละคน (SoT §15 · INT-13)
 *
 * ด่านความสามารถคือ `saraban.inbox.view` ซึ่งแยกจาก `saraban.document.view`
 * โดยตั้งใจ — ข้าราชการทั่วไปต้องเปิดหนังสือที่เวียนถึงตนได้โดยไม่ต้องมีสิทธิ์
 * ค้นทะเบียนหนังสือของหน่วยงาน
 *
 * **แต่ขอบเขตจริงคือการเป็นผู้รับ ไม่ใช่ตัวสิทธิ์**
 *
 * ทุก query กรองด้วย `recipient_id` ของผู้เรียกเสมอ และการกระทำทุกอย่างตรวจซ้ำ
 * ว่าแถวนั้นเป็นของผู้เรียกจริง — **ไม่มีเส้นทางใดที่อ่านหรือแก้กล่องของคนอื่นได้**
 */
class InboxController extends Controller
{
    public function __construct(private readonly DistributionService $distributions) {}

    /** รายการหนังสือที่เวียนถึงผู้ใช้คนนี้ */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(DistributionRecipient::progression())],
            'pending' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = DistributionRecipient::query()
            ->where('recipient_id', $request->user()->id)
            ->with([
                'distribution:id,uuid,document_id,document_version_id,sent_by,sent_at,note',
                'distribution.sender:id,name',
                'distribution.document:id,uuid,subject,direction,urgency,confidentiality,status,document_number_id,owner_org_unit_id',
                'distribution.document.documentNumber:id,formatted',
                'distribution.document.ownerOrgUnit:id,name',
                'distribution.version:id,version_no,stage,pdf_file_path',
            ])
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            // "ยังไม่ได้รับทราบ" คือคำถามที่ผู้ใช้ถามบ่อยที่สุดเมื่อเปิดกล่อง
            ->when(
                filter_var($filters['pending'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($query) => $query->whereNull('acknowledged_at'),
            )
            ->orderByDesc('delivered_at')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => collect($items->items())->map(fn (DistributionRecipient $item) => $this->present($item)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'unacknowledged' => DistributionRecipient::query()
                ->where('recipient_id', $request->user()->id)
                ->whereNull('acknowledged_at')
                ->count(),
        ]);
    }

    /**
     * เปิดอ่านรายการหนึ่งในกล่อง
     *
     * **การเปิดอ่านถูกบันทึก แต่ไม่ใช่การรับทราบ** (SoT §15) — ผู้รับต้องกด
     * รับทราบเป็นการแสดงเจตนาต่างหากเสมอ
     */
    public function show(Request $request, DistributionRecipient $recipient)
    {
        $this->authorizeRecipient($request, $recipient);

        $this->distributions->markOpened($recipient);

        return response()->json(['item' => $this->present($recipient->fresh([
            'distribution.document.documentNumber',
            'distribution.document.ownerOrgUnit',
            'distribution.version',
            'distribution.sender',
        ]))]);
    }

    /** รับทราบหนังสือ */
    public function acknowledge(Request $request, DistributionRecipient $recipient)
    {
        $this->authorizeRecipient($request, $recipient);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $updated = $this->distributions->acknowledge($recipient, $request->user(), $data['note'] ?? null);
        } catch (DistributionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['item' => $this->present($updated)]);
    }

    /** รายงานความคืบหน้าการดำเนินการตามหนังสือ */
    public function reportProgress(Request $request, DistributionRecipient $recipient)
    {
        $this->authorizeRecipient($request, $recipient);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(DistributionRecipient::reportableStatuses())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->distributions->reportProgress(
                $recipient,
                $data['status'],
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (DistributionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['item' => $this->present($updated)]);
    }

    /**
     * กล่องของใครก็ของคนนั้น
     *
     * ใช้ 404 ไม่ใช่ 403 เพราะการตอบ 403 บอกผู้ถามว่า "มีรายการนี้อยู่จริง"
     * ซึ่งเป็นการรั่วข้อมูลว่ามีการเวียนหนังสือถึงใครบ้าง
     */
    private function authorizeRecipient(Request $request, DistributionRecipient $recipient): void
    {
        abort_unless((int) $recipient->recipient_id === (int) $request->user()->id, 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(DistributionRecipient $recipient): array
    {
        $document = $recipient->distribution?->document;

        return [
            ...$recipient->only([
                'id', 'uuid', 'recipient_role', 'status', 'response_note',
                'delivered_at', 'opened_at', 'acknowledged_at',
                'accepted_at', 'started_at', 'completed_at',
            ]),
            'note' => $recipient->distribution?->note,
            'sent_at' => $recipient->distribution?->sent_at,
            'sent_by' => $recipient->distribution?->sender?->name,
            'document' => $document === null ? null : [
                ...$document->only(['id', 'uuid', 'subject', 'direction', 'urgency', 'confidentiality', 'status']),
                'document_number' => $document->documentNumber?->formatted,
                'owner_org_unit' => $document->ownerOrgUnit?->name,

                /*
                 * ไฟล์ของ **เวอร์ชันที่ส่งถึงคนนี้** ไม่ใช่เวอร์ชันปัจจุบันของหนังสือ
                 * (SoT §8) — ถ้าคืนเวอร์ชันปัจจุบัน ผู้รับจะเห็นเอกสารคนละฉบับกับ
                 * ที่ถูกเวียนถึงตน เมื่อหนังสือมีเวอร์ชันใหม่หลังจากนั้น
                 *
                 * การเปิดไฟล์ยังต้องผ่าน `SecureFileController` ซึ่งถาม
                 * `DocumentPolicy::view()` อีกชั้นเสมอ
                 */
                'version_no' => $recipient->distribution?->version?->version_no,
                'pdf_file_path' => $recipient->distribution?->version?->pdf_file_path,
            ],
        ];
    }
}
