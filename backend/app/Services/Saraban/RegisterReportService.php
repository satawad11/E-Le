<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Support\Facades\DB;

/**
 * รายงานทะเบียนงานสารบรรณ (SoT §28)
 *
 * รายงานที่ SoT §28 กำหนด: ทะเบียนรับ/ส่ง/ภายใน/คำสั่ง/ประกาศ · การจองเลข
 * การคืนเลข VOID · และสถิติการเวียนหนังสือกับการรับทราบ
 *
 * **ทุกรายงานถูกจำกัดด้วยขอบเขตของผู้เรียกเสมอ** — รายงานเป็นทางลัดที่ข้าม
 * ขอบเขตได้ง่ายที่สุด เพราะมันรวมข้อมูลจำนวนมากไว้ในคำขอเดียว · ถ้ารายงาน
 * ไม่กรองขอบเขต การควบคุมสิทธิ์ทั้งหมดที่ทำมา 18 เช็คพอยต์จะไร้ความหมาย
 */
class RegisterReportService
{
    public function __construct(private readonly OrgScopeResolver $scopes) {}

    /**
     * ทะเบียนหนังสือตามหมวด
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function register(User $user, array $filters): array
    {
        $query = Document::query()
            ->with(['documentType:id,name,category', 'ownerOrgUnit:id,name', 'documentNumber:id,formatted,buddhist_year'])
            ->when(isset($filters['direction']), fn ($q) => $q->where('direction', $filters['direction']))
            ->when(
                isset($filters['category']),
                fn ($q) => $q->whereIn(
                    'document_type_id',
                    DocumentType::query()->where('category', $filters['category'])->select('id'),
                ),
            )
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));

        $this->applyScope($query, $user);

        $rows = $query->orderByDesc('created_at')->limit(1000)->get();

        return [
            'total' => $rows->count(),
            'rows' => $rows->map(fn (Document $document) => [
                ...$document->only(['id', 'uuid', 'subject', 'direction', 'status', 'confidentiality', 'urgency']),
                'document_number' => $document->documentNumber?->formatted,
                'buddhist_year' => $document->documentNumber?->buddhist_year,
                'document_type' => $document->documentType?->name,
                'owner_org_unit' => $document->ownerOrgUnit?->name,
                'created_at' => $document->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * ทะเบียนเลขหนังสือ — จอง ออก คืน และยกเลิก (SoT §9, §28)
     *
     * **ไม่กรองขอบเขตหน่วยงาน** เพราะเลขหนังสือเป็นทรัพยากรร่วมของชุดเลขนั้น
     * ผู้ที่มีสิทธิ์ดูรายงานเลขต้องเห็นช่องว่างในลำดับได้ ไม่งั้นตรวจสอบไม่ได้ว่า
     * เลขที่หายไปเป็นการคืนเลขปกติหรือความผิดปกติ
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function numbers(array $filters): array
    {
        $query = DocumentNumber::query()
            ->with(['series:id,code,name,prefix'])
            ->when(isset($filters['series_id']), fn ($q) => $q->where('number_series_id', $filters['series_id']))
            ->when(isset($filters['buddhist_year']), fn ($q) => $q->where('buddhist_year', $filters['buddhist_year']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));

        $summary = (clone $query)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'summary' => $summary,
            'rows' => $query
                ->orderByDesc('buddhist_year')
                ->orderByDesc('running_number')
                ->limit(1000)
                ->get()
                ->map(fn (DocumentNumber $number) => [
                    ...$number->only(['id', 'buddhist_year', 'running_number', 'formatted', 'status', 'reason']),
                    'series' => $number->series?->name,
                    'document_id' => $number->document_id,
                ])
                ->values(),
        ];
    }

    /**
     * สรุปการเวียนหนังสือและการรับทราบ (SoT §28)
     *
     * **นับ "ยังไม่รับทราบ" แยกจาก "ยังไม่เปิดอ่าน"** — สองอย่างนี้ต่างกัน
     * ตาม SoT §15 และผู้บริหารต้องเห็นความต่างนั้น: คนที่เปิดอ่านแล้วแต่ไม่กด
     * รับทราบ คือคนที่รู้แล้วแต่ยังไม่ยืนยัน ซึ่งต่างจากคนที่ยังไม่เห็นเลย
     *
     * @return array<string,mixed>
     */
    public function distribution(User $user, array $filters): array
    {
        $rows = DB::table('saraban_distribution_recipients as r')
            ->join('saraban_distributions as d', 'd.id', '=', 'r.distribution_id')
            ->join('saraban_documents as doc', 'doc.id', '=', 'd.document_id')
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('d.sent_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('d.sent_at', '<=', $filters['to']))
            ->when(
                $this->visibleUnits($user) !== null,
                fn ($q) => $q->whereIn('doc.owner_org_unit_id', $this->visibleUnits($user) ?? []),
            )
            ->select('r.status', DB::raw('count(*) as total'))
            ->groupBy('r.status')
            ->pluck('total', 'status')
            ->all();

        $delivered = array_sum($rows);
        $acknowledged = ($rows['acknowledged'] ?? 0)
            + ($rows['accepted_for_action'] ?? 0)
            + ($rows['in_progress'] ?? 0)
            + ($rows['completed'] ?? 0);

        return [
            'by_status' => $rows,
            'total_recipients' => $delivered,
            'acknowledged' => $acknowledged,
            // ยังไม่รับทราบ = ได้รับแล้วแต่ยังไม่แสดงเจตนา (รวมคนที่เปิดอ่านแล้ว)
            'not_acknowledged' => $delivered - $acknowledged,
            'never_opened' => $rows['delivered'] ?? 0,
        ];
    }

    /** @return array<int,int>|null */
    private function visibleUnits(User $user): ?array
    {
        return $this->scopes->visibleOrgUnitIds($user, 'saraban.report.view', OrgScopeResolver::UNIT);
    }

    private function applyScope($query, User $user): void
    {
        $visible = $this->visibleUnits($user);

        if ($visible !== null) {
            $query->whereIn('owner_org_unit_id', $visible);
        }
    }
}
