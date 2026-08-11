<?php

namespace App\Http\Controllers;

use App\Services\LineNotificationService;
use App\Services\SystemSettingService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SecureSettingController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings)
    {
    }

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
                'settings' => ['ไม่รองรับการตั้งค่า: ' . implode(', ', $unknown)],
            ]);
        }

        $validated = $request->validate(Arr::only($rules, array_keys($request->all())));
        foreach (['saraban_incoming_number_format', 'saraban_outgoing_number_format'] as $formatKey) {
            if (filled($validated[$formatKey] ?? null)
                && (!str_contains($validated[$formatKey], '{year}') || !str_contains($validated[$formatKey], '{running_number}'))) {
                throw ValidationException::withMessages([
                    $formatKey => ['รูปแบบเลขหนังสือต้องมีตัวแปร {year} และ {running_number}'],
                ]);
            }
        }
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

    public function testNotification(Request $request, string $channel)
    {
        abort_unless(in_array($channel, ['line', 'telegram', 'email'], true), 404);
        $message = 'ทดสอบการแจ้งเตือนจากระบบ E-Leave เวลา ' . now()->format('d/m/Y H:i:s');

        $sent = match ($channel) {
            'line' => app(LineNotificationService::class)->sendMessage($message),
            'telegram' => filled($request->user()->telegram_chat_id)
                && app(TelegramService::class)->sendMessage($request->user()->telegram_chat_id, $message),
            'email' => $this->sendTestEmail($request->user()->email, $message),
        };

        if (!$sent) {
            throw ValidationException::withMessages([
                'channel' => ['ทดสอบไม่สำเร็จ กรุณาตรวจสอบค่าเชื่อมต่อและข้อมูลผู้รับของบัญชีผู้ดูแลระบบ'],
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

    public function uploadGarudaLogo(Request $request)
    {
        return $this->uploadAsset($request, 'saraban_garuda_logo', 'logos', 'ตราครุฑ');
    }

    private function uploadAsset(Request $request, string $key, string $directory, string $label)
    {
        $request->validate(['logo' => 'required|image|mimes:jpeg,png,jpg|max:2048']);
        $oldPath = $this->settings->runtimeValues()->get($key);
        $path = $request->file('logo')->store($directory, 'public');
        $url = '/storage/' . $path;
        $this->settings->updateMany([$key => $url], $request->user(), $request->ip(), $request->userAgent());
        $this->deleteStoredAsset($oldPath, $url);

        return response()->json(['message' => "อัปโหลด{$label}เรียบร้อยแล้ว", 'url' => $url]);
    }

    private function deleteStoredAsset(?string $url, ?string $except = null): void
    {
        if (!$url || $url === $except || !str_starts_with($url, '/storage/')) {
            return;
        }
        Storage::disk('public')->delete(substr($url, strlen('/storage/')));
    }

    private function sendTestEmail(?string $recipient, string $message): bool
    {
        if (blank($recipient) || blank($this->settings->runtimeValues()->get('mail_host'))) {
            return false;
        }

        try {
            Mail::raw($message, fn ($mail) => $mail->to($recipient)->subject('ทดสอบการแจ้งเตือน E-Leave'));
            return true;
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }
    }
}
