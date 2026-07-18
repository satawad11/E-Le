<?php

namespace App\Http\Controllers\Api\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentCircular;
use App\Models\Saraban\DocumentCircularRecipient;
use Illuminate\Http\Request;

class CircularController extends Controller
{
    public function incoming(Request $request)
    {
        $circulars = DocumentCircularRecipient::with(['circular.document', 'circular.sender'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($circulars);
    }

    public function outgoing(Request $request)
    {
        $circulars = DocumentCircular::with(['document', 'recipients.user'])
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
            'message' => 'nullable|string'
        ]);

        $document = Document::findOrFail($id);

        $circular = DocumentCircular::create([
            'document_id' => $document->id,
            'sender_id' => $request->user()->id,
            'message' => $request->input('message')
        ]);

        $usersToNotify = \App\Models\User::whereIn('id', $request->input('user_ids'))->get();
        foreach ($usersToNotify as $user) {
            DocumentCircularRecipient::create([
                'circular_id' => $circular->id,
                'user_id' => $user->id
            ]);
            
            $user->notify(new \App\Notifications\SarabanDocumentNotification(
                $document->id,
                "มีหนังสือเวียนใหม่ถึงคุณ",
                "เอกสาร {$document->title} แจ้งเพื่อทราบ: " . $request->input('message'),
                'circular'
            ));
        }

        return response()->json(['message' => 'Circular sent successfully.']);
    }

    public function acknowledge(Request $request, $circularId)
    {
        $recipient = DocumentCircularRecipient::where('circular_id', $circularId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $requirePin = \App\Models\Setting::where('key', 'require_pin_for_circular_ack')->value('value') === '1';
        
        if ($requirePin) {
            $request->validate([
                'password' => 'required|string'
            ]);
            
            // Validate password or pin (depends on implementation, usually use Hash::check)
            if (!\Illuminate\Support\Facades\Hash::check($request->input('password'), $request->user()->password)) {
                return response()->json(['message' => 'รหัสผ่านไม่ถูกต้อง'], 400);
            }
        }

        $recipient->update([
            'is_acknowledged' => true,
            'acknowledged_at' => now()
        ]);

        return response()->json(['message' => 'Acknowledged.']);
    }

    public function tracking($id)
    {
        $circulars = DocumentCircular::with(['recipients.user', 'sender'])
            ->where('document_id', $id)
            ->get();
        return response()->json($circulars);
    }
}
