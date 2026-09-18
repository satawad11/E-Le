<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Services\OrgScopeResolver;
use App\Services\TrustedClock;
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
    public function __construct(
        private readonly OrgScopeResolver $scopes,
        /*
         * เวลาต้องมาจากนาฬิกาที่เชื่อถือได้ของระบบ ไม่ใช่นาฬิกาเครื่อง (SoT §10)
         * — รายงานที่คำนวณช่วงเวลาจากเครื่องที่ตั้งเวลาเพี้ยน จะให้ตัวเลขที่ผิด
         * โดยไม่มีอะไรฟ้อง · ด่าน `SarabanCoreContractTest` จับข้อนี้ได้จริง
         */
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ทะเบียนหนังสือตามหมวด
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function register(User $user, array $filters): array
    {
        $query = Document::query()
            ->with([
                'documentType:id,name,category',
                'ownerOrgUnit:id,name',
                'documentNumber:id,formatted,buddhist_year,running_number',
                'incomingRegistration.registerNumber:id,formatted,buddhist_year,running_number',
                'incomingRegistration.registrar:id,name',
            ])
            ->when(isset($filters['direction']), fn ($q) => $q->where('direction', $filters['direction']))
            ->when(
                isset($filters['category']),
                fn ($q) => $q->whereIn(
                    'document_type_id',
                    DocumentType::query()->where('category', $filters['category'])->select('id'),
                ),
            )
            ->when(
                isset($filters['division_id']),
                fn ($q) => $q->whereIn('owner_org_unit_id', $this->scopes->subtreeIds((int) $filters['division_id'])),
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
                'running_number' => $document->documentNumber?->running_number,
                'buddhist_year' => $document->documentNumber?->buddhist_year,
                'document_type' => $document->documentType?->name,
                'owner_org_unit' => $document->ownerOrgUnit?->name,
                'created_at' => $document->created_at?->toIso8601String(),
                'sender_name' => $document->incomingRegistration?->sender_name,
                'source_number' => $document->incomingRegistration?->source_number,
                'source_date' => $document->incomingRegistration?->source_date?->toDateString(),
                'register_number' => $document->incomingRegistration?->registerNumber?->formatted
                    ?? ($document->incomingRegistration?->registerNumber?->running_number ? (string) $document->incomingRegistration->registerNumber->running_number : null),
                'received_at' => $document->incomingRegistration?->received_at?->toIso8601String(),
                'incoming_note' => $document->incomingRegistration?->note,
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

    /**
     * ตัวเลขสรุปสำหรับกราฟในหน้ารายงาน (SoT §28)
     *
     * **จำกัดขอบเขตเดียวกับทะเบียน** — กราฟที่นับหนังสือของหน่วยงานที่ผู้ดูไม่มี
     * สิทธิ์เห็น คือการรั่วข้อมูลแบบที่มองไม่ออกจากตัวเลข แต่บอกได้ว่าหน่วยงานอื่น
     * มีงานมากน้อยเพียงใด
     *
     * นับ **สิบสองเดือนย้อนหลังเสมอ** ไม่ใช่ตามปีปฏิทิน เพราะกราฟที่เริ่มใหม่
     * ทุกเดือนมกราคมจะว่างเปล่าตลอดเดือนแรกของปี ซึ่งอ่านไม่ได้ความ
     *
     * @return array<string,mixed>
     */
    public function summary(User $user, array $filters = []): array
    {
        $months = collect(range(11, 0))
            ->map(fn (int $back) => $this->clock->now()->startOfMonth()->subMonths($back))
            ->values();

        $since = $months->first();

        $query = Document::query()->where('created_at', '>=', $since);
        $this->applyScope($query, $user);

        $documents = $query->get(['id', 'direction', 'status', 'owner_org_unit_id', 'created_at']);

        $monthly = $months->map(function ($month) use ($documents) {
            $inMonth = $documents->filter(
                fn (Document $document) => $document->created_at?->isSameMonth($month) ?? false,
            );

            return [
                'month' => $month->format('Y-m'),
                'label' => $month->locale('th')->translatedFormat('M'),
                'incoming' => $inMonth->where('direction', Document::DIRECTION_INCOMING)->count(),
                'outgoing' => $inMonth->where('direction', Document::DIRECTION_OUTGOING)->count(),
                'internal' => $inMonth->where('direction', Document::DIRECTION_INTERNAL)->count(),
            ];
        });

        /*
         * สถิติแยกตาม ๔ ฝ่ายหลัก สอศ.
         */
        $rootId = DB::table('org_units')->whereNull('parent_id')->value('id');
        $divisions = $rootId ? DB::table('org_units')->where('parent_id', $rootId)->orderBy('id')->get(['id', 'name', 'code']) : collect();

        $byDivision = $divisions->map(function ($div) use ($documents) {
            $count = $documents->filter(function (Document $doc) use ($div) {
                if (! $doc->owner_org_unit_id) {
                    return false;
                }

                return $this->scopes->divisionOrgUnitId((int) $doc->owner_org_unit_id) === (int) $div->id;
            })->count();

            return [
                'division_id' => $div->id,
                'name' => $div->name,
                'code' => $div->code,
                'count' => $count,
            ];
        })->values();

        /*
         * สถานะทั้งหมดที่พบจริง ไม่ใช่รายการที่ฝังไว้ในโค้ด — สถานะใหม่ที่เพิ่ม
         * ในอนาคตจะปรากฏในกราฟเองโดยไม่ต้องแก้ที่นี่ และจะไม่มีหนังสือกลุ่มใด
         * หายไปจากกราฟเงียบ ๆ เพราะสถานะของมันไม่อยู่ในรายการ
         */
        $byStatus = $documents
            ->groupBy('status')
            ->map(fn ($group, $status) => ['status' => $status, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values();

        return [
            'total' => $documents->count(),
            'monthly' => $monthly,
            'by_status' => $byStatus,
            'by_division' => $byDivision,
            'since' => $since->toDateString(),
        ];
    }
}
