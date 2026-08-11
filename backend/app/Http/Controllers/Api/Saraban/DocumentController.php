<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Http\Requests\Saraban\StoreDocumentRequest;
use App\Services\Saraban\DocumentNumberGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class DocumentController extends Controller
{
    protected $generatorService;

    public function __construct(DocumentNumberGeneratorService $generatorService)
    {
        $this->generatorService = $generatorService;
    }

    public function index(Request $request)
    {
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
        if (!$user->hasRole(['Super Admin', 'SuperAdmin', 'admin_saraban', 'Saraban Officer', 'เจ้าหน้าที่สารบัญ'])) {
            $query->where(function($q) use ($user) {
                $q->where('saraban_documents.created_by', $user->id)
                  ->orWhereHas('receivers', function($rq) use ($user) {
                      $rq->where('users.id', $user->id);
                  });
            });
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

        Document::whereIn('id', $request->ids)->delete();
        
        return response()->json(['message' => 'Documents deleted successfully'], 200);
    }

    public function store(StoreDocumentRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = Auth::id();
        $data['status'] = 'dispatched';

        // Example of number generation if not provided (will refine later with business logic)
        if (empty($data['document_number'])) {
            $fiscalYear = \App\Models\FiscalYear::where('is_current', true)->first();
            $yearBe = $fiscalYear ? $fiscalYear->year : (date('Y') + 543);
            
            $runningNumber = $this->generatorService->generate($data['department_id'], $data['type_id'], $yearBe);
            $type = \App\Models\Saraban\DocumentType::find($data['type_id']);
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
            if (($data['creation_mode'] ?? 'physical') === 'digital' && !empty($data['content'])) {
                // Generate PDF from content
                $htmlContent = $data['content'];
                
                // You could wrap the content in a standardized HTML wrapper here if needed
                // Add Garuda logo if configured
                $settings = app(\App\Services\SystemSettingService::class)->runtimeValues();
                $logoUrl = $settings['saraban_garuda_logo'] ?? '';
                $logoHtml = '';
                if ($logoUrl) {
                    $absoluteLogoPath = public_path(str_replace('/storage/', 'storage/', $logoUrl));
                    if (file_exists($absoluteLogoPath)) {
                        $logoData = base64_encode(file_get_contents($absoluteLogoPath));
                        $logoHtml = '<div style="text-align: center; margin-bottom: 20px;"><img src="data:image/png;base64,' . $logoData . '" width="60" /></div>';
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
                    ' . $logoHtml . '
                    ' . $htmlContent . '
                </body>
                </html>';

                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($fullHtml);
                $pdf->setPaper('A4', 'portrait');
                
                $filename = 'edoc_' . time() . '.pdf';
                $path = 'saraban/attachments/' . $filename;
                
                Storage::disk('public')->put($path, $pdf->output());
                
                $document->attachments()->create([
                    'name' => 'เอกสารอิเล็กทรอนิกส์.pdf',
                    'type' => 'file',
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => Auth::id(),
                ]);
            } else if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $path = $file->store('saraban/attachments', 'public');
                    $document->attachments()->create([
                        'name' => $file->getClientOriginalName(),
                        'type' => 'file',
                        'path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'uploaded_by' => Auth::id(),
                    ]);
                }
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
            if (!empty($departmentIds)) {
                $deptUserIds = User::whereIn('department_id', $departmentIds)->pluck('id');
                $userIds = $userIds->merge($deptUserIds);
            }
            
            // Unique user IDs, filter out current user if desired (optional, we keep it simple here)
            $uniqueUserIds = $userIds->unique()->filter()->values();
            
            if ($uniqueUserIds->isNotEmpty()) {
                $document->receivers()->sync($uniqueUserIds);
            }

            DB::commit();
            return response()->json($document->load(['attachments', 'receivers']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create document: ' . $e->getMessage()], 500);
        }
    }

    public function show(Document $document)
    {
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
        $data = $request->validated();
        
        DB::beginTransaction();
        try {
            $document->update($data);

            // Process new files or digital content
            if ($data['creation_mode'] === 'digital' && !empty($data['content'])) {
                // Delete previous generated e-docs if exist
                $oldDocs = $document->attachments()->where('name', 'เอกสารอิเล็กทรอนิกส์.pdf')->get();
                foreach ($oldDocs as $oldDoc) {
                    Storage::disk('public')->delete($oldDoc->path);
                    $oldDoc->delete();
                }

                $htmlContent = $data['content'];
                $settings = app(\App\Services\SystemSettingService::class)->runtimeValues();
                $logoUrl = $settings['saraban_garuda_logo'] ?? '';
                $logoHtml = '';
                if ($logoUrl) {
                    $absoluteLogoPath = public_path(str_replace('/storage/', 'storage/', $logoUrl));
                    if (file_exists($absoluteLogoPath)) {
                        $logoData = base64_encode(file_get_contents($absoluteLogoPath));
                        $logoHtml = '<div style="text-align: center; margin-bottom: 20px;"><img src="data:image/png;base64,' . $logoData . '" width="60" /></div>';
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
                    ' . $logoHtml . '
                    ' . $htmlContent . '
                </body>
                </html>';

                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($fullHtml);
                $pdf->setPaper('A4', 'portrait');
                
                $filename = 'edoc_' . time() . '.pdf';
                $path = 'saraban/attachments/' . $filename;
                Storage::disk('public')->put($path, $pdf->output());
                
                $document->attachments()->create([
                    'name' => 'เอกสารอิเล็กทรอนิกส์.pdf',
                    'type' => 'file',
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => Auth::id(),
                ]);
            } else if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $path = $file->store('saraban/attachments', 'public');
                    $document->attachments()->create([
                        'name' => $file->getClientOriginalName(),
                        'type' => 'file',
                        'path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'uploaded_by' => Auth::id(),
                    ]);
                }
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
            $userIds = collect($request->input('receivers', []));
            $departmentIds = $request->input('receiver_departments', []);
            if (!empty($departmentIds)) {
                $deptUserIds = User::whereIn('department_id', $departmentIds)->pluck('id');
                $userIds = $userIds->merge($deptUserIds);
            }
            
            $uniqueUserIds = $userIds->unique()->filter()->values();
            if ($uniqueUserIds->isNotEmpty()) {
                $document->receivers()->sync($uniqueUserIds);
            } else {
                $document->receivers()->detach();
            }

            DB::commit();
            return response()->json($document->load(['attachments', 'receivers']), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update document: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Document $document)
    {
        $document->delete();
        return response()->json(null, 204);
    }

    public function read(Document $document)
    {
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

        $user = Auth::user();
        $state = \App\Models\Saraban\DocumentUserState::firstOrNew([
            'document_id' => $id,
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
        $user = Auth::user();
        $query = Document::leftJoin('saraban_document_user_states', function($join) use ($user) {
            $join->on('saraban_documents.id', '=', 'saraban_document_user_states.document_id')
                 ->where('saraban_document_user_states.user_id', '=', $user->id);
        })
        ->where(function ($q) {
            $q->whereNull('saraban_document_user_states.is_hidden')
              ->orWhere('saraban_document_user_states.is_hidden', false);
        });

        if (!$user->hasRole(['Super Admin', 'SuperAdmin', 'admin_saraban', 'Saraban Officer', 'เจ้าหน้าที่สารบัญ'])) {
            $query->where(function($q) use ($user) {
                $q->where('saraban_documents.created_by', $user->id)
                  ->orWhereHas('receivers', function($rq) use ($user) {
                      $rq->where('users.id', $user->id);
                  });
            });
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
}
