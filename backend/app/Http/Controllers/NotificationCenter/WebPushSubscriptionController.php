<?php

namespace App\Http\Controllers\NotificationCenter;

use App\Http\Controllers\Controller;
use App\Models\NotificationCenter\WebPushSubscription;
use App\Services\NotificationCenter\Providers\WebPushGateway;
use Illuminate\Http\Request;

/**
 * การสมัครรับ Web Push ของผู้ใช้ (SoT §16 · INT-14)
 *
 * **ทุก endpoint ทำงานกับการสมัครของผู้เรียกเท่านั้น** — ไม่มีพารามิเตอร์ใด
 * ที่ระบุผู้ใช้คนอื่นได้ · การเปิดให้สมัครแทนคนอื่นเท่ากับให้คนหนึ่งดักรับ
 * การแจ้งเตือนของอีกคนได้
 */
class WebPushSubscriptionController extends Controller
{
    public function __construct(private readonly WebPushGateway $push) {}

    /**
     * กุญแจสาธารณะสำหรับให้เบราว์เซอร์สมัครรับ
     *
     * เป็นกุญแจ **สาธารณะ** โดยนิยาม — ต้องส่งให้เบราว์เซอร์จึงจะสมัครได้
     * ส่วนกุญแจส่วนตัวอยู่ใน env และไม่มีเส้นทางใดในระบบที่ส่งออกไป
     */
    public function publicKey()
    {
        return response()->json([
            'enabled' => $this->push->configured(),
            'public_key' => config('services.web_push.public_key'),
        ]);
    }

    public function subscribe(Request $request)
    {
        abort_unless($this->push->configured(), 503, 'ระบบยังไม่ได้ตั้งค่ากุญแจสำหรับการแจ้งเตือนผ่านเบราว์เซอร์');

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        /*
         * `updateOrCreate` บน endpoint — เบราว์เซอร์เดิมที่สมัครซ้ำต้องได้แถวเดิม
         * และ **ผูกกับผู้ใช้ที่เรียกล่าสุดเสมอ** เพราะเครื่องที่ใช้ร่วมกันอาจ
         * เปลี่ยนคนใช้ ถ้าไม่ย้ายเจ้าของ คนใหม่จะได้รับการแจ้งเตือนของคนเก่า
         */
        $subscription = WebPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'p256dh_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['subscription' => $subscription->only(['uuid', 'user_agent', 'created_at'])], 201);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        WebPushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['message' => 'ยกเลิกการรับการแจ้งเตือนผ่านเบราว์เซอร์นี้แล้ว']);
    }

    /** เบราว์เซอร์ที่ผู้ใช้คนนี้เปิดรับไว้ — ใช้ให้เจ้าตัวถอนเครื่องที่ไม่ได้ใช้แล้ว */
    public function index(Request $request)
    {
        return response()->json([
            'subscriptions' => WebPushSubscription::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get(['uuid', 'user_agent', 'last_used_at', 'created_at']),
        ]);
    }
}
