<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAttachment;
use App\Http\Requests\Saraban\StoreDocumentRequest;
use App\Policies\Saraban\DocumentPolicy;
use App\Services\Saraban\AttachmentStorage;
use App\Services\Saraban\AuditLogger;
use App\Services\Saraban\DocumentNumberGeneratorService;
use App\Services\Saraban\DocumentPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentNumberGeneratorService $generatorService,
        protected AttachmentStorage $storage,
        protected DocumentPdfRenderer $pdfRenderer,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        $user = Auth::user();
        $query = Document::with(['type', 'creator', 'department'])
            ->leftJoin('saraban_document_user_states', function($join) use ($user) {
                $join->on('saraban_documents.id', '=', 'saraban_document_user_states.document_id')
                     ->where('saraban_document_user_states.user_id', '=', $user->id);
            })
            ->where(function ($q) {
                $q->whereNull('saraban_document_user_states.is_hidden')
                  ->orWhere('saraban_document_user_states.is_hidden', false);
            })
            ->select('saraban_documents.*', 'saraban_document_user_states.is_read', 'saraban_document_user_states.is_pinned');

        // If not admin and not saraban officer, filter only relevant documents
        if (!DocumentPolicy::isOfficer($user)) {
            $query->where(function($q) use ($user) {
                $q->where('saraban_documents.created_by', $user->id)
                  ->orWhereHas('receivers', function($rq) use ($user) {
                      $rq->where('users.id', $user->id);
                  });
            });

            // ชั้นความลับ: ผู้ที่ไม่มีสิทธิ์เห็นเฉพาะหนังสือปกติ หรือหนังสือที่ตนเองเป็นผู้สร้าง
            if (!$user->can('saraban.document.view_classified')) {
                $query->where(function($q) use ($user) {
                    $q->where('saraban_documents.secrecy', 'normal')
                      ->orWhere('saraban_documents.created_by', $user->id);
                });
            }
        }

        // Search by title or document_number
        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function($q) use ($search) {
                $q->where('saraban_documents.title', 'like', $search)
                  ->orWhere('saraban_documents.document_number', 'like', $search)
                  ->orWhere('saraban_documents.to', 'like', $search);
            });
        }

        // Filter by document type
        if ($request->filled('type_id')) {
            $query->where('saraban_documents.type_id', $request->input('type_id'));
        }

        // Filter by direction
        if ($request->input('direction') === 'incoming') {
            $query->has('incoming');
        } elseif ($request->input('direction') === 'outgoing') {
            $query->has('outgoing');
        }

        // Tab filter: unread / urgent / pinned
        $tab = $request->input('tab', 'all');
        if ($tab === 'unread') {
            $query->where(function($q) {
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
            'ids.*' => 'exists:saraban_documents,id'
        ]);

        $documents = Document::whereIn('id', $request->ids)->get();

        // ตรวจสิทธิ์รายฉบับก่อน แล้วจึงลบทั้งชุด (ห้ามลบบางส่วนแล้ว 403 กลางทาง)
        foreach ($documents as $document) {
            $this->authorize('delete', $document);
        }

        DB::transaction(function () use ($documents) {
            foreach ($documents as $document) {
                $document->delete();
                AuditLogger::log('document.deleted', $document, ['bulk' => true]);
            }
        });

        return response()->json(['message' => 'Documents deleted successfully'], 200);
    }

    public function store(StoreDocumentRequest $request)
    {
        $this->authorize('create', Document::class);

        $data = $request->validated();
        $data['created_by'] = Auth::id();
        $data['status'] = 'dispatched';
        $creationMode = $data['creation_mode'] ?? 'physical';

        if (empty($data['document_number'])) {
            $type = \App\Models\Saraban\DocumentType::find($data['type_id']);

            $data['document_number'] = $this->generatorService->buildDocumentNumber(
                $data['department_id'],
                $data['type_id'],
                $type?->code
            );
        }

        DB::beginTransaction();
        try {
            $document = Document::create($data);

            // 1. Process files
            if ($creationMode === 'digital' && !empty($data['content'])) {
                $this->storeGeneratedPdf($document, $data['content']);
            } else if ($request->hasFile('files')) {
                $this->storeUploadedFiles($document, $request->file('files'));
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
            $this->syncReceivers($document, $request, false);

            AuditLogger::log('document.created', $document, [
                'document_number' => $document->document_number,
                'secrecy' => $document->secrecy,
            ]);

            DB::commit();
            return response()->json($document->load(['attachments', 'receivers']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create document: ' . $e->getMessage()], 500);
        }
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);

        AuditLogger::log('document.viewed', $document);

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
            'circulars.sender'
        ]));
    }


    public function update(StoreDocumentRequest $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validated();
        $creationMode = $data['creation_mode'] ?? $document->creation_mode ?? 'physical';
        $original = $document->only(['title', 'secrecy', 'priority', 'status', 'document_number']);

        DB::beginTransaction();
        try {
            $document->update($data);

            // Process new files or digital content
            if ($creationMode === 'digital' && !empty($data['content'])) {
                // Delete previous generated e-docs if exist
                $oldDocs = $document->attachments()
                    ->where('name', DocumentPdfRenderer::GENERATED_NAME)
                    ->get();

                foreach ($oldDocs as $oldDoc) {
                    $this->storage->delete($oldDoc->path);
                    $oldDoc->delete();
                }

                $this->storeGeneratedPdf($document, $data['content']);
            } else if ($request->hasFile('files')) {
                $this->storeUploadedFiles($document, $request->file('files'));
            }

            // Process new links
            if ($request->has('links') && is_array($request->input('links'))) {
                foreach ($request->input('links') as $link) {
                    if (!empty($link['title']) && !empty($link['url'])) {
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
            $this->syncReceivers($document, $request, true);

            AuditLogger::log('document.updated', $document, [
                'before' => $original,
                'after' => $document->only(['title', 'secrecy', 'priority', 'status', 'document_number']),
            ]);

            DB::commit();
            return response()->json($document->load(['attachments', 'receivers']), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update document: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        $document->delete();

        AuditLogger::log('document.deleted', $document, [
            'document_number' => $document->document_number,
        ]);

        return response()->json(null, 204);
    }

    /**
     * ดาวน์โหลดไฟล์แนบผ่าน API เท่านั้น (ไฟล์อยู่บน disk ส่วนตัว)
     */
    public function downloadAttachment(DocumentAttachment $attachment)
    {
        $document = $attachment->document;

        abort_if(!$document, 404);
        $this->authorize('download', $document);

        if ($attachment->type === 'link') {
            return response()->json(['url' => $attachment->path]);
        }

        $disk = $this->storage->resolveDisk($attachment->path);
        abort_if(!$disk, 404, 'ไม่พบไฟล์แนบ');

        AuditLogger::log('document.attachment_downloaded', $document, [
            'attachment_id' => $attachment->id,
            'name' => $attachment->name,
        ]);

        return Storage::disk($disk)->download($attachment->path, $attachment->name);
    }

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
        $state = \App\Models\Saraban\DocumentUserState::firstOrNew([
            'document_id' => $document->id,
            'user_id' => $userId
        ]);
        $state->is_read = true;
        $state->save();

        return response()->json(['message' => 'Document marked as read']);
    }

    public function updateState(Request $request, $id)
    {
        $request->validate([
            'is_read' => 'nullable|boolean',
            'is_pinned' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean'
        ]);

        $document = Document::findOrFail($id);
        $this->authorize('view', $document);

        $user = Auth::user();
        $state = \App\Models\Saraban\DocumentUserState::firstOrNew([
            'document_id' => $document->id,
            'user_id' => $user->id
        ]);

        if ($request->has('is_read')) {
            $state->is_read = $request->is_read;
        }
        if ($request->has('is_pinned')) {
            $state->is_pinned = $request->is_pinned;
        }
        if ($request->has('is_hidden')) {
            $state->is_hidden = $request->is_hidden;
        }

        $state->save();

        return response()->json(['message' => 'State updated', 'state' => $state]);
    }

    public function stats()
    {
        $this->authorize('viewAny', Document::class);

        $user = Auth::user();
        $query = Document::leftJoin('saraban_document_user_states', function($join) use ($user) {
            $join->on('saraban_documents.id', '=', 'saraban_document_user_states.document_id')
                 ->where('saraban_document_user_states.user_id', '=', $user->id);
        })
        ->where(function ($q) {
            $q->whereNull('saraban_document_user_states.is_hidden')
              ->orWhere('saraban_document_user_states.is_hidden', false);
        });

        if (!DocumentPolicy::isOfficer($user)) {
            $query->where(function($q) use ($user) {
                $q->where('saraban_documents.created_by', $user->id)
                  ->orWhereHas('receivers', function($rq) use ($user) {
                      $rq->where('users.id', $user->id);
                  });
            });

            if (!$user->can('saraban.document.view_classified')) {
                $query->where(function($q) use ($user) {
                    $q->where('saraban_documents.secrecy', 'normal')
                      ->orWhere('saraban_documents.created_by', $user->id);
                });
            }
        }

        $allQuery = clone $query;
        $total = $allQuery->count();

        $unreadQuery = clone $query;
        $unread = $unreadQuery->where(function($q) {
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
            'pinned' => $pinned
        ]);
    }

    /**
     * สร้าง PDF จากเนื้อหาที่พิมพ์ในระบบ แล้วผูกเป็นไฟล์แนบ
     */
    protected function storeGeneratedPdf(Document $document, string $content): void
    {
        $path = $this->pdfRenderer->render($content);

        $document->attachments()->create([
            'name' => DocumentPdfRenderer::GENERATED_NAME,
            'type' => 'file',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'uploaded_by' => Auth::id(),
        ]);
    }

    protected function storeUploadedFiles(Document $document, array $files): void
    {
        foreach ($files as $file) {
            $document->attachments()->create([
                'name' => $file->getClientOriginalName(),
                'type' => 'file',
                'path' => $this->storage->putUploadedFile($file),
                'mime_type' => $file->getClientMimeType(),
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    protected function syncReceivers(Document $document, Request $request, bool $detachWhenEmpty): void
    {
        $userIds = collect($request->input('receivers', []));

        $departmentIds = $request->input('receiver_departments', []);
        if (!empty($departmentIds)) {
            $deptUserIds = User::whereIn('department_id', $departmentIds)->pluck('id');
            $userIds = $userIds->merge($deptUserIds);
        }

        $uniqueUserIds = $userIds->unique()->filter()->values();

        if ($uniqueUserIds->isNotEmpty()) {
            $document->receivers()->sync($uniqueUserIds);
        } elseif ($detachWhenEmpty) {
            $document->receivers()->detach();
        }
    }
}
