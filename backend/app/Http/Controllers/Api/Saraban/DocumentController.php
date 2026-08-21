<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Http\Requests\Saraban\StoreDocumentRequest;
use App\Models\Department;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAttachment;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentUserState;
use App\Models\User;
use App\Policies\Saraban\DocumentPolicy;
use App\Services\Saraban\AuditLogService;
use App\Services\Saraban\DocumentNumberGeneratorService;
use App\Services\SystemSettingService;
use App\Support\StoredFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    protected $generatorService;

    protected $auditLog;

    public function __construct(DocumentNumberGeneratorService $generatorService, AuditLogService $auditLog)
    {
        $this->generatorService = $generatorService;
        $this->auditLog = $auditLog;
    }

    /**
     * Registrars (เจ้าหน้าที่สารบรรณ) see the whole register.
     *
     * LEGACY-04 (ปิดงาน) · Decision 2 — เลิกเช็คชื่อ role ที่ไม่มีอยู่จริง
     * (`admin_saraban`) และใช้สิทธิ์ "รับ/ส่งหนังสือ" เป็นตัวตัดสินแทน
     * ให้ตรงกับ DocumentPolicy::isRegistrar() ซึ่งเป็นด่านบังคับจริง
     */
    private function isRegistrar(User $user): bool
    {
        return $user->can('saraban.document.register');
    }

    /**
     * บันทึกไฟล์แนบที่อัปโหลดเข้ามา
     *
     * SEC-023 — ชั้นตรวจ (allowlist ของ mime จากเนื้อไฟล์ + นามสกุล) อยู่ที่
     * StoreDocumentRequest ส่วนที่นี่รับผิดชอบเรื่อง "ชื่อไฟล์บนดิสก์":
     *
     *  - ชื่อบนดิสก์เป็น UUID เสมอ ต่อด้วยนามสกุลที่ผ่าน allowlist แล้วเท่านั้น
     *    ชื่อจากผู้ใช้จึงไม่มีทางกำหนด path หรือ นามสกุลของไฟล์ที่ระบบเก็บ
     *  - `mime_type` เก็บค่าที่ตรวจจาก **เนื้อไฟล์** (`getMimeType()`) ไม่ใช่
     *    `getClientMimeType()` ซึ่งเป็นค่าที่ client ส่งมาและปลอมได้
     *  - ชื่อเดิมของผู้ใช้เก็บไว้ในคอลัมน์ `name` เพื่อแสดงผลและตั้งชื่อตอนดาวน์โหลด
     *
     * @param  array<int,UploadedFile>  $files
     */
    private function storeUploadedAttachments(Document $document, array $files): void
    {
        $allowedExtensions = (array) config('saraban.attachments.allowed_extensions', []);

        foreach ($files as $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            // ด่านสุดท้ายเผื่อมีเส้นทางอื่นเรียกเข้ามาโดยไม่ผ่าน FormRequest
            abort_unless(in_array($extension, $allowedExtensions, true), 422, 'ชนิดของไฟล์แนบนี้ไม่ได้รับอนุญาต');

            // ต้องอ่านก่อน storeAs เพราะหลังจากนั้นไฟล์ชั่วคราวถูกย้ายไปแล้ว
            $originalName = $file->getClientOriginalName();
            $detectedMimeType = $file->getMimeType();

            $path = $file->storeAs(
                'saraban/attachments',
                Str::uuid()->toString().'.'.$extension,
                StoredFile::DISK,
            );

            $document->attachments()->create([
                'name' => $originalName,
                'type' => 'file',
                'path' => $path,
                'mime_type' => $detectedMimeType,
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    /**
     * Read-only user picker for the document form and circular recipients.
     * Deliberately narrow so saraban users do not need the admin `view_users`
     * permission just to address a document.
     */
    public function usersList()
    {
        $users = User::with(['profile:id,user_id,first_name,last_name', 'department:id,name'])
            ->select('id', 'name', 'department_id')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * Read-only department picker, same rationale as usersList().
     */
    public function departmentsList()
    {
        return response()->json(Department::select('id', 'name')->orderBy('name')->get());
    }

    /**
     * Authenticated, policy-checked, audited attachment download.
     * Replaces the unauthenticated public /api/storage/{path} route for saraban files.
     */
    public function downloadAttachment(DocumentAttachment $attachment)
    {
        $document = $attachment->document;
        abort_unless($document, 404);

        $this->authorize('download', $document);

        if ($attachment->type === 'link') {
            return response()->json(['url' => $attachment->path]);
        }

        /*
         * SEC-024 — เดิมบรรทัดนี้อ่านจาก disk 'public' ขณะที่ทุกจุดที่ "เขียน"
         * ไฟล์แนบเขียนลง disk 'private_uploads' (SEC-001) ผลคือปุ่มดาวน์โหลด
         * ตอบ 404 กับไฟล์แนบทุกไฟล์ที่มีอยู่จริง
         */
        $disk = Storage::disk(StoredFile::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        $this->auditLog->log('attachment.downloaded', $document, [
            'attachment_id' => $attachment->id,
            'name' => $attachment->name,
        ]);

        return $disk->download($attachment->path, $attachment->name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Document::with(['type', 'creator', 'department'])
            ->leftJoin('saraban_document_user_states', function ($join) use ($user) {
                $join->on('saraban_documents.id', '=', 'saraban_document_user_states.document_id')
                    ->where('saraban_document_user_states.user_id', '=', $user->id);
            })
            ->where(function ($q) {
                $q->whereNull('saraban_document_user_states.is_hidden')
                    ->orWhere('saraban_document_user_states.is_hidden', false);
            })
            ->select('saraban_documents.*', 'saraban_document_user_states.is_read', 'saraban_document_user_states.is_pinned');

        // Registrars see the whole register; everyone else sees only documents
        // they authored or received. Mirrors DocumentPolicy::view().
        if (! $this->isRegistrar($user)) {
            $query->where(function ($q) use ($user) {
                $q->where('saraban_documents.created_by', $user->id)
                    ->orWhereHas('receivers', function ($rq) use ($user) {
                        $rq->where('users.id', $user->id);
                    });
            });
        }

        // Classified documents are excluded unless the user is cleared for them.
        if (! $user->can('saraban.document.view_classified')) {
            $query->where('saraban_documents.secrecy', 'normal');
        }

        // Search by title or document_number
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('saraban_documents.title', 'like', $search)
                    ->orWhere('saraban_documents.document_number', 'like', $search)
                    ->orWhere('saraban_documents.to', 'like', $search);
            });
        }

        // Filter by document type
        if ($request->filled('type_id')) {
            $query->where('saraban_documents.type_id', $request->input('type_id'));
        }

        // Filter by direction. Incoming registration is recorded on the document row
        // itself (incoming_document_number / incoming_date); the legacy
        // saraban_document_incoming/outgoing tables are still honoured if populated.
        if ($request->input('direction') === 'incoming') {
            $query->where(function ($q) {
                $q->whereNotNull('saraban_documents.incoming_document_number')
                    ->orWhereNotNull('saraban_documents.incoming_date')
                    ->orWhereHas('incoming');
            });
        } elseif ($request->input('direction') === 'outgoing') {
            $query->whereNull('saraban_documents.incoming_document_number')
                ->whereNull('saraban_documents.incoming_date')
                ->whereDoesntHave('incoming');
        }

        // Tab filter: unread / urgent / pinned
        $tab = $request->input('tab', 'all');
        if ($tab === 'unread') {
            $query->where(function ($q) {
                $q->whereNull('saraban_document_user_states.is_read')
                    ->orWhere('saraban_document_user_states.is_read', false);
            });
        } elseif ($tab === 'urgent') {
            $query->whereIn('saraban_documents.priority', ['urgent', 'very_urgent', 'most_urgent']);
        } elseif ($tab === 'pinned') {
            $query->where('saraban_document_user_states.is_pinned', true);
        }

        $perPage = $request->input('per_page', 10);
        $documents = $query->orderByRaw('COALESCE(saraban_document_user_states.is_pinned, 0) DESC')
            ->orderByRaw('COALESCE(saraban_document_user_states.is_read, 0) ASC')
            ->orderByRaw("CASE WHEN saraban_documents.priority IN ('urgent', 'very_urgent', 'most_urgent') THEN 1 ELSE 0 END DESC")
            ->orderBy('saraban_documents.created_at', 'desc')
            ->paginate($perPage);

        return response()->json($documents);
    }

    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:saraban_documents,id',
        ]);

        $documents = Document::whereIn('id', $request->ids)->get();

        // Authorize each document individually — a bulk request must not become a
        // way around the per-document rules in DocumentPolicy.
        foreach ($documents as $document) {
            $this->authorize('delete', $document);
        }

        foreach ($documents as $document) {
            $this->auditLog->log('document.deleted', $document, ['bulk' => true]);
            $document->delete();
        }

        return response()->json(['message' => 'ลบหนังสือเรียบร้อยแล้ว'], 200);
    }

    public function store(StoreDocumentRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = Auth::id();
        // New documents enter the workflow at the start of the state machine
        // (draft → pending_review → …). Previously this was hardcoded to
        // 'dispatched', which meant the workflow could never run at all.
        $data['status'] = 'draft';

        // Example of number generation if not provided (will refine later with business logic)
        if (empty($data['document_number'])) {
            $yearBe = $this->generatorService->currentYearBe();

            $runningNumber = $this->generatorService->generate($data['department_id'], $data['type_id'], $yearBe);
            $type = DocumentType::find($data['type_id']);
            $departmentCode = (string) ($type?->code ?: $data['department_id']);
            $data['document_number'] = $this->generatorService->formatWithTemplate(
                $runningNumber,
                $yearBe,
                $departmentCode,
                filled($data['incoming_document_number'] ?? null) || filled($data['incoming_date'] ?? null),
            );
        }

        DB::beginTransaction();
        try {
            $document = Document::create($data);

            // 1. Process files
            if (($data['creation_mode'] ?? null) === 'digital' && ! empty($data['content'])) {
                // Generate PDF from content
                $htmlContent = $data['content'];

                // You could wrap the content in a standardized HTML wrapper here if needed
                // Add Garuda logo if configured
                $settings = app(SystemSettingService::class)->runtimeValues();
                $logoUrl = $settings['saraban_garuda_logo'] ?? '';
                $logoHtml = '';
                if ($logoUrl) {
                    $absoluteLogoPath = StoredFile::path($logoUrl);
                    if (file_exists($absoluteLogoPath)) {
                        $logoData = base64_encode(file_get_contents($absoluteLogoPath));
                        $logoHtml = '<div style="text-align: center; margin-bottom: 20px;"><img src="data:image/png;base64,'.$logoData.'" width="60" /></div>';
                    }
                }

                $fullHtml = '
                <html>
                <head>
                    <style>
                        body { font-family: "THSarabunNew", sans-serif; font-size: 16pt; padding: 2cm 1.5cm; }
                        p { margin: 0; padding: 0; }
                    </style>
                </head>
                <body>
                    '.$logoHtml.'
                    '.$htmlContent.'
                </body>
                </html>';

                $pdf = Pdf::loadHTML($fullHtml);
                $pdf->setPaper('A4', 'portrait');

                // SEC-023 — ชื่อไฟล์บนดิสก์เป็น UUID เสมอ ไม่คาดเดาได้และไม่ชนกัน
                $filename = Str::uuid()->toString().'.pdf';
                $path = 'saraban/attachments/'.$filename;

                Storage::disk('private_uploads')->put($path, $pdf->output());

                $document->attachments()->create([
                    'name' => 'เอกสารอิเล็กทรอนิกส์.pdf',
                    'type' => 'file',
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => Auth::id(),
                ]);
            } elseif ($request->hasFile('files')) {
                $this->storeUploadedAttachments($document, $request->file('files'));
            }

            // 2. Process links
            if ($request->has('links') && is_array($request->input('links'))) {
                foreach ($request->input('links') as $link) {
                    $document->attachments()->create([
                        'name' => $link['title'],
                        'type' => 'link',
                        'path' => $link['url'],
                        'mime_type' => null,
                        'uploaded_by' => Auth::id(),
                    ]);
                }
            }

            // 3. Process receivers (users + users in selected departments)
            $userIds = collect($request->input('receivers', []));

            $departmentIds = $request->input('receiver_departments', []);
            if (! empty($departmentIds)) {
                $deptUserIds = User::whereIn('department_id', $departmentIds)->pluck('id');
                $userIds = $userIds->merge($deptUserIds);
            }

            // Unique user IDs, filter out current user if desired (optional, we keep it simple here)
            $uniqueUserIds = $userIds->unique()->filter()->values();

            if ($uniqueUserIds->isNotEmpty()) {
                $document->receivers()->sync($uniqueUserIds);
            }

            $this->auditLog->log('document.created', $document, [
                'document_number' => $document->document_number,
            ]);

            DB::commit();

            return response()->json($document->load(['attachments', 'receivers']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json(['message' => 'ไม่สามารถสร้างเอกสารได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ'], 500);
        }
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);

        return response()->json($document->load([
            'type',
            'creator',
            'department',
            'incoming',
            'outgoing',
            'attachments',
            'receivers.department',
            'workflowSteps.user',
            'signatures.signer.profile',
            'circulars.recipients.user',
            'circulars.sender',
        ]));
    }

    public function update(StoreDocumentRequest $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validated();

        // The running number is part of the official record — never let an update
        // silently reassign or clear it.
        unset($data['document_number']);

        DB::beginTransaction();
        try {
            $document->update($data);

            // Process new files or digital content
            if (($data['creation_mode'] ?? null) === 'digital' && ! empty($data['content'])) {
                // Delete previous generated e-docs if exist
                $oldDocs = $document->attachments()->where('name', 'เอกสารอิเล็กทรอนิกส์.pdf')->get();
                foreach ($oldDocs as $oldDoc) {
                    Storage::disk('private_uploads')->delete($oldDoc->path);
                    $oldDoc->delete();
                }

                $htmlContent = $data['content'];
                $settings = app(SystemSettingService::class)->runtimeValues();
                $logoUrl = $settings['saraban_garuda_logo'] ?? '';
                $logoHtml = '';
                if ($logoUrl) {
                    $absoluteLogoPath = StoredFile::path($logoUrl);
                    if (file_exists($absoluteLogoPath)) {
                        $logoData = base64_encode(file_get_contents($absoluteLogoPath));
                        $logoHtml = '<div style="text-align: center; margin-bottom: 20px;"><img src="data:image/png;base64,'.$logoData.'" width="60" /></div>';
                    }
                }

                $fullHtml = '
                <html>
                <head>
                    <style>
                        body { font-family: "THSarabunNew", sans-serif; font-size: 16pt; padding: 2cm 1.5cm; }
                        p { margin: 0; padding: 0; }
                    </style>
                </head>
                <body>
                    '.$logoHtml.'
                    '.$htmlContent.'
                </body>
                </html>';

                $pdf = Pdf::loadHTML($fullHtml);
                $pdf->setPaper('A4', 'portrait');

                // SEC-023 — ชื่อไฟล์บนดิสก์เป็น UUID เสมอ ไม่คาดเดาได้และไม่ชนกัน
                $filename = Str::uuid()->toString().'.pdf';
                $path = 'saraban/attachments/'.$filename;
                Storage::disk('private_uploads')->put($path, $pdf->output());

                $document->attachments()->create([
                    'name' => 'เอกสารอิเล็กทรอนิกส์.pdf',
                    'type' => 'file',
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => Auth::id(),
                ]);
            } elseif ($request->hasFile('files')) {
                $this->storeUploadedAttachments($document, $request->file('files'));
            }

            // Process new links
            if ($request->has('links') && is_array($request->input('links'))) {
                foreach ($request->input('links') as $link) {
                    if (! empty($link['title']) && ! empty($link['url'])) {
                        $document->attachments()->create([
                            'name' => $link['title'],
                            'type' => 'link',
                            'path' => $link['url'],
                            'mime_type' => null,
                            'uploaded_by' => Auth::id(),
                        ]);
                    }
                }
            }

            // Process receivers
            $userIds = collect($request->input('receivers', []));
            $departmentIds = $request->input('receiver_departments', []);
            if (! empty($departmentIds)) {
                $deptUserIds = User::whereIn('department_id', $departmentIds)->pluck('id');
                $userIds = $userIds->merge($deptUserIds);
            }

            $uniqueUserIds = $userIds->unique()->filter()->values();
            if ($uniqueUserIds->isNotEmpty()) {
                $document->receivers()->sync($uniqueUserIds);
            } else {
                $document->receivers()->detach();
            }

            $this->auditLog->log('document.updated', $document, [
                'changed' => array_keys($data),
            ]);

            DB::commit();

            return response()->json($document->load(['attachments', 'receivers']), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json(['message' => 'ไม่สามารถแก้ไขเอกสารได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ'], 500);
        }
    }

    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        $this->auditLog->log('document.deleted', $document, [
            'document_number' => $document->document_number,
            'status' => $document->status,
        ]);
        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * ทำเครื่องหมาย "อ่านแล้ว"
     *
     * SEC-025 — เดิม endpoint นี้ไม่ตรวจสิทธิ์ใด ๆ นอกจาก `saraban.document.view`
     * ระดับ route ผู้ใช้ที่ล็อกอินคนใดก็ได้จึงยิงเลข id ไล่ไปเรื่อย ๆ แล้ว
     * (ก) สร้างแถว `saraban_document_user_states` ผูกตัวเองกับหนังสือของคนอื่น
     * (ข) เขียน `read_at` ทับบันทึกการรับทราบซึ่งเป็นหลักฐานของงานสารบรรณ
     * (ค) ยืนยันการมีอยู่ของหนังสือลับจากผลลัพธ์ที่ต่างกัน (IDOR)
     *
     * ตอนนี้ผ่าน `DocumentPolicy::view` ด่านเดียวกับการเปิดอ่านหนังสือ
     */
    public function read(Document $document)
    {
        $this->authorize('view', $document);

        $userId = Auth::id();

        // Find if user is a receiver and hasn't read it yet
        $receiver = $document->receivers()->where('user_id', $userId)->first();
        if ($receiver && is_null($receiver->pivot->read_at)) {
            $document->receivers()->updateExistingPivot($userId, ['read_at' => now()]);
        }

        // Also update the global state
        $state = DocumentUserState::firstOrNew([
            'document_id' => $document->id,
            'user_id' => $userId,
        ]);
        $wasUnread = ! $state->exists || ! $state->is_read;

        $state->is_read = true;
        $state->save();

        // AUDIT-007 — "รับทราบ/อ่านแล้ว" เป็นหลักฐานของงานสารบรรณ บันทึกเฉพาะ
        // ครั้งที่สถานะเปลี่ยนจริง เพื่อไม่ให้ log ท่วมจากการเปิดหน้าซ้ำ ๆ
        if ($wasUnread) {
            $this->auditLog->log('document.read', $document, [
                'document_number' => $document->document_number,
            ]);
        }

        return response()->json(['message' => 'บันทึกการอ่านหนังสือแล้ว']);
    }

    /**
     * ปักหมุด / ซ่อน / ทำเครื่องหมายอ่านแล้ว เฉพาะของผู้ใช้คนนั้น
     *
     * SEC-025 — เดิมเมธอดนี้รับ `$id` ดิบแล้วสร้างแถวสถานะจาก id นั้นตรง ๆ
     * โดยไม่ตรวจว่าหนังสือมีอยู่จริงหรือผู้ใช้มีสิทธิ์เห็นหรือไม่ ผลคือ
     * (ก) IDOR — ยิง id ใดก็ได้เพื่อผูกตัวเองกับหนังสือของหน่วยงานอื่น
     * (ข) แถวสถานะชี้ไปยัง document_id ที่ไม่มีอยู่จริง
     * (ค) เส้นทางนี้ข้าม middleware `saraban.classified` ของหนังสือลับ
     *
     * ตอนนี้ใช้ route model binding (ไม่พบ = 404) และผ่าน `DocumentPolicy::view`
     * (ไม่มีสิทธิ์ = 403) เหมือนทุก endpoint ของหนังสือ
     */
    public function updateState(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $request->validate([
            'is_read' => 'nullable|boolean',
            'is_pinned' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
        ]);

        $user = Auth::user();
        $state = DocumentUserState::firstOrNew([
            'document_id' => $document->id,
            'user_id' => $user->id,
        ]);

        if ($request->has('is_read')) {
            $state->is_read = $request->boolean('is_read');
        }
        if ($request->has('is_pinned')) {
            $state->is_pinned = $request->boolean('is_pinned');
        }
        if ($request->has('is_hidden')) {
            $state->is_hidden = $request->boolean('is_hidden');
        }

        $state->save();

        /*
         * AUDIT-007 — บันทึกเฉพาะการเปลี่ยนที่มีนัยสำคัญ
         *
         * `is_hidden` ทำให้หนังสือหายไปจากทะเบียนของผู้ใช้คนนั้น จึงต้องตามรอยได้
         * ว่าใครซ่อนเมื่อไร ส่วน `is_read` ล้วน ๆ ไม่ถือว่ามีนัยสำคัญพอ
         */
        if ($request->has('is_hidden')) {
            $this->auditLog->log('document.state.hidden', $document, [
                'is_hidden' => $state->is_hidden,
            ]);
        }

        return response()->json(['message' => 'บันทึกสถานะแล้ว', 'state' => $state]);
    }

    public function stats()
    {
        $user = Auth::user();
        $query = Document::leftJoin('saraban_document_user_states', function ($join) use ($user) {
            $join->on('saraban_documents.id', '=', 'saraban_document_user_states.document_id')
                ->where('saraban_document_user_states.user_id', '=', $user->id);
        })
            ->where(function ($q) {
                $q->whereNull('saraban_document_user_states.is_hidden')
                    ->orWhere('saraban_document_user_states.is_hidden', false);
            });

        if (! $this->isRegistrar($user)) {
            $query->where(function ($q) use ($user) {
                $q->where('saraban_documents.created_by', $user->id)
                    ->orWhereHas('receivers', function ($rq) use ($user) {
                        $rq->where('users.id', $user->id);
                    });
            });
        }

        if (! $user->can('saraban.document.view_classified')) {
            $query->where('saraban_documents.secrecy', 'normal');
        }

        $allQuery = clone $query;
        $total = $allQuery->count();

        $unreadQuery = clone $query;
        $unread = $unreadQuery->where(function ($q) {
            $q->whereNull('saraban_document_user_states.is_read')
                ->orWhere('saraban_document_user_states.is_read', false);
        })->count();

        $urgentQuery = clone $query;
        $urgent = $urgentQuery->whereIn('saraban_documents.priority', ['urgent', 'very_urgent', 'most_urgent'])->count();

        $pinnedQuery = clone $query;
        $pinned = $pinnedQuery->where('saraban_document_user_states.is_pinned', true)->count();

        return response()->json([
            'total' => $total,
            'unread' => $unread,
            'urgent' => $urgent,
            'pinned' => $pinned,
        ]);
    }
}
