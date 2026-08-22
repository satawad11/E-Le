<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentCircular;
use App\Models\Saraban\DocumentCircularRecipient;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\Saraban\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CircularController extends Controller
{
    protected $auditLog;

    public function __construct(AuditLogService $auditLog, private NotificationCenter $notifications)
    {
        $this->auditLog = $auditLog;
    }

    public function incoming(Request $request)
    {
        $circulars = DocumentCircularRecipient::with(['circular.document', 'circular.sender.profile'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($circulars);
    }

    public function outgoing(Request $request)
    {
        $circulars = DocumentCircular::with(['document', 'recipients.user.profile'])
            ->where('sender_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($circulars);
    }

    public function send(Request $request, $id)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'message' => 'nullable|string',
        ]);

        $document = Document::findOrFail($id);
        $this->authorize('circulate', $document);

        /*
         * SEC-026 — ผู้รับหนังสือเวียนต้องมีสิทธิ์ระดับโมดูลพอจะเห็นหนังสือฉบับนั้น
         *
         * เดิมรับ user_ids มาเท่าไรก็สร้างผู้รับเท่านั้นโดยไม่ตรวจอะไรเลย
         * การเวียนจึงกลายเป็นทางลัดส่งหนังสือ (รวมถึงหนังสือชั้นความลับ)
         * ไปให้ใครก็ได้ในระบบ โดยข้าม DocumentPolicy ทั้งหมด
         *
         * ตรวจที่ "สิทธิ์" ไม่ใช่ `can('view', $document)` เพราะการเวียนนี่เอง
         * คือสิ่งที่ทำให้ผู้รับกลายเป็นผู้เกี่ยวข้องกับหนังสือ (ดู DocumentPolicy)
         */
        $usersToNotify = User::whereIn('id', $request->input('user_ids'))->get();

        $blocked = $usersToNotify
            ->reject(function (User $recipient) use ($document): bool {
                if (! $recipient->can('saraban.document.view')) {
                    return false;
                }

                return $document->secrecy === 'normal'
                    || $recipient->can('saraban.document.view_classified');
            })
            ->pluck('name')
            ->all();

        if ($blocked !== []) {
            return response()->json([
                'message' => 'ผู้รับบางรายไม่มีสิทธิ์เข้าถึงหนังสือฉบับนี้: '.implode(', ', $blocked),
            ], 422);
        }

        $circular = DocumentCircular::create([
            'document_id' => $document->id,
            'sender_id' => $request->user()->id,
            'message' => $request->input('message'),
        ]);

        foreach ($usersToNotify as $user) {
            DocumentCircularRecipient::create([
                'circular_id' => $circular->id,
                'user_id' => $user->id,
            ]);
        }

        $this->notifications->emit('saraban.task', $usersToNotify, $document, [
            'status' => $document->status, 'action' => 'circular',
        ], $request->user());

        $this->auditLog->log('circular.sent', $document, [
            'circular_id' => $circular->id,
            'recipient_count' => $usersToNotify->count(),
        ]);

        return response()->json(['message' => 'เวียนหนังสือเรียบร้อยแล้ว']);
    }

    public function acknowledge(Request $request, $circularId)
    {
        $recipient = DocumentCircularRecipient::where('circular_id', $circularId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $requirePin = Setting::where('key', 'require_pin_for_circular_ack')->value('value') === '1';

        if ($requirePin) {
            $request->validate([
                'password' => 'required|string',
            ]);

            // Validate password or pin (depends on implementation, usually use Hash::check)
            if (! Hash::check($request->input('password'), $request->user()->password)) {
                return response()->json(['message' => 'รหัสผ่านไม่ถูกต้อง'], 400);
            }
        }

        $recipient->update([
            'is_acknowledged' => true,
            'acknowledged_at' => now(),
        ]);

        $this->auditLog->log('circular.acknowledged', $recipient->circular?->document_id, [
            'circular_id' => $circularId,
        ]);

        return response()->json(['message' => 'บันทึกการรับทราบแล้ว']);
    }

    public function tracking($id)
    {
        $this->authorize('circulate', Document::findOrFail($id));

        $circulars = DocumentCircular::with(['recipients.user.profile', 'sender.profile'])
            ->where('document_id', $id)
            ->get();

        return response()->json($circulars);
    }
}
