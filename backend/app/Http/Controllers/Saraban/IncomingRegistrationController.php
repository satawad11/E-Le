<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Http\Requests\Saraban\RegisterIncomingRequest;
use App\Models\Saraban\Document;
use App\Models\Saraban\IncomingRegistration;
use App\Services\OrgScopeResolver;
use App\Services\Saraban\IncomingRegistrationService;
use Illuminate\Http\Request;

/**
 * ทะเบียนรับหนังสือ (SoT §10 · INT-07)
 *
 * การตรวจหนังสือซ้ำเป็นขั้นที่ผู้ใช้ต้อง **เห็นแล้วตัดสินเอง** ไม่ใช่ระบบปฏิเสธให้
 * เพราะการรับหนังสือฉบับเดียวกันซ้ำอาจถูกต้องได้ในทางปฏิบัติ · ระบบจึงตอบ 409
 * พร้อมรายการที่ซ้ำในครั้งแรก แล้วรับได้เมื่อผู้ใช้ยืนยันด้วย `acknowledge_duplicate`
 */
class IncomingRegistrationController extends Controller
{
    public function __construct(
        private readonly IncomingRegistrationService $registrations,
        private readonly OrgScopeResolver $scopes,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        $query = IncomingRegistration::query()
            ->with(['document.documentType', 'registerNumber', 'registrar:id,name'])
            ->latest('received_at');

        $visibleUnits = $this->scopes->visibleOrgUnitIds($user, 'saraban.document.view', OrgScopeResolver::UNIT);

        if ($visibleUnits !== null) {
            $query->whereHas('document', fn ($q) => $q
                ->whereIn('owner_org_unit_id', $visibleUnits)
                ->orWhere('created_by', $user->id));
        }

        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(fn ($q) => $q
                ->where('sender_name', 'like', "%{$term}%")
                ->orWhere('source_number', 'like', "%{$term}%")
                ->orWhereHas('document', fn ($d) => $d->where('subject', 'like', "%{$term}%")));
        }

        return response()->json($query->paginate(20));
    }

    /** ตรวจหนังสือซ้ำก่อนลงทะเบียน — ให้หน้าจอเตือนได้ตั้งแต่ตอนกรอก */
    public function duplicates(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'source_number' => ['nullable', 'string', 'max:120'],
            'sender_name' => ['required', 'string', 'max:255'],
            'source_date' => ['nullable', 'date'],
        ]);

        return response()->json([
            'duplicates' => $this->registrations->findPossibleDuplicates(
                $data['source_number'] ?? null,
                $data['sender_name'],
                $data['source_date'] ?? null,
            ),
        ]);
    }

    public function store(RegisterIncomingRequest $request)
    {
        $this->authorize('create', Document::class);

        $data = $request->safe()->except('file');

        if (! $request->boolean('acknowledge_duplicate')) {
            $duplicates = $this->registrations->findPossibleDuplicates(
                $data['source_number'] ?? null,
                $data['sender_name'],
                $data['source_date'] ?? null,
            );

            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'message' => 'พบหนังสือที่อาจเป็นฉบับเดียวกันในทะเบียนรับแล้ว กรุณาตรวจสอบก่อนยืนยัน',
                    'duplicates' => $duplicates,
                ], 409);
            }
        }

        $registration = $this->registrations->register($data, $request->user(), $request->file('file'));

        return response()->json($registration, 201);
    }

    public function show(IncomingRegistration $registration)
    {
        $this->authorize('view', $registration->document);

        return response()->json(
            $registration->load(['document.documentType', 'document.versions', 'registerNumber', 'registrar:id,name'])
        );
    }
}
