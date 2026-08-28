<?php

namespace App\Http\Controllers;

use App\Services\NotificationCenter\NotificationChannelTester;
use App\Services\SystemSettingService;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SecureSettingController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function index()
    {
        return response()->json($this->settings->publicValues());
    }

    public function adminIndex()
    {
        return response()->json($this->settings->adminValues());
    }

    public function update(Request $request)
    {
        $rules = config('system_settings.rules', []);
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => ['ไม่รองรับการตั้งค่า: '.implode(', ', $unknown)],
            ]);
        }

        $validated = $request->validate(Arr::only($rules, array_keys($request->all())));
        $changes = $this->settings->updateMany($validated, $request->user(), $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว',
            'changed_keys' => array_keys($changes),
        ]);
    }

    public function clearSecret(Request $request, string $key)
    {
        $this->settings->clearSecret($key, $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['message' => 'ล้างข้อมูลลับเรียบร้อยแล้ว']);
    }

    /*
     * CLEANUP-01 (D8) — ทดสอบผ่าน adapter ชุดเดียวกับที่ส่งของจริง
     *
     * เดิมเมธอดนี้เรียก provider ตรง ๆ (LineNotificationService / TelegramService)
     * จึงตอบว่า "สำเร็จ" ได้ทั้งที่ช่องทางนั้นส่งของจริงไม่ได้ เพราะข้ามการตรวจ
     * `isConfiguredFor` ของ Notification Center ไปทั้งดุ้น
     */
    public function testNotification(Request $request, string $channel, NotificationChannelTester $tester)
    {
        abort_unless($tester->supports($channel), 404);

        $outcome = $tester->send(
            $channel,
            $request->user(),
            'ทดสอบการแจ้งเตือน E-Leave',
            'ทดสอบการแจ้งเตือนจากระบบ E-Leave เวลา '.now()->format('d/m/Y H:i:s'),
        );

        if (! $outcome->successful) {
            throw ValidationException::withMessages([
                'channel' => ['ทดสอบไม่สำเร็จ: '.$outcome->reason],
            ]);
        }

        return response()->json(['message' => 'ส่งการแจ้งเตือนทดสอบเรียบร้อยแล้ว']);
    }

    public function uploadLogo(Request $request)
    {
        return $this->uploadAsset($request, 'organization_logo', 'logos', 'โลโก้สถานศึกษา');
    }

    public function deleteLogo(Request $request)
    {
        $oldPath = $this->settings->runtimeValues()->get('organization_logo');
        $this->settings->updateMany(['organization_logo' => null], $request->user(), $request->ip(), $request->userAgent());
        $this->deleteStoredAsset($oldPath);

        return response()->json(['message' => 'ลบโลโก้เรียบร้อยแล้ว']);
    }

    private function uploadAsset(Request $request, string $key, string $directory, string $label)
    {
        $request->validate(['logo' => array_merge(['required'], UploadRules::image(2048))]);
        $oldPath = $this->settings->runtimeValues()->get($key);
        $path = $request->file('logo')->store($directory, 'private_uploads');
        $url = '/storage/'.$path;
        $this->settings->updateMany([$key => $url], $request->user(), $request->ip(), $request->userAgent());
        $this->deleteStoredAsset($oldPath, $url);

        return response()->json(['message' => "อัปโหลด{$label}เรียบร้อยแล้ว", 'url' => $url]);
    }

    private function deleteStoredAsset(?string $url, ?string $except = null): void
    {
        if (! $url || $url === $except || ! str_starts_with($url, '/storage/')) {
            return;
        }
        Storage::disk('private_uploads')->delete(substr($url, strlen('/storage/')));
    }
}
