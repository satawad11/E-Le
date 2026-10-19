<?php

namespace App\Http\Controllers;

use App\Models\PersonnelProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! filter_var($value, FILTER_VALIDATE_EMAIL) && ! preg_match('/^[0-9]{13}$/', $value)) {
                    $fail('รูปแบบอีเมล หรือเลขประจำตัวประชาชน (13 หลัก) ไม่ถูกต้อง');
                }
            }],
            'password' => 'required|string',
        ]);

        $loginField = $request->email;
        $lockKey = 'login-account:'.hash('sha256', mb_strtolower(trim($loginField)));

        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            return response()->json([
                'message' => 'บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ในภายหลัง',
                'retry_after' => RateLimiter::availableIn($lockKey),
            ], 429);
        }
        $user = null;

        if (filter_var($loginField, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $loginField)->first();
        } else {
            // เลขประจำตัวประชาชนค้นผ่าน hash เท่านั้น (CORE-02: national_id เข้ารหัส)
            $profile = PersonnelProfile::where('national_id_hash', PersonnelProfile::hashNationalId($loginField))->first();
            if ($profile) {
                $user = User::where('personnel_id', $profile->personnel_id)->first();
            }
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($lockKey, 900);
            throw ValidationException::withMessages([
                'email' => ['ข้อมูลการเข้าสู่ระบบไม่ถูกต้อง'],
            ]);
        }

        // CORE-03: เข้าสู่ระบบได้เฉพาะบัญชี active ที่ไม่ถูกล็อก · บัญชี service ห้าม interactive login
        if (! $user->isActive() || $user->account_type === 'service') {
            throw ValidationException::withMessages([
                'email' => ['บัญชีผู้ใช้นี้ยังไม่เปิดใช้งานหรือถูกระงับการใช้งาน'],
            ]);
        }

        RateLimiter::clear($lockKey);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        Auth::login($user);
        $expiration = (int) config('sanctum.expiration', 480);
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes($expiration))->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiration * 60,
            'user' => $this->present($user),
        ]);
    }

    public function logout(Request $request)
    {
        $accessToken = $request->user()?->currentAccessToken();

        // Session-authenticated requests receive Sanctum's TransientToken,
        // which is not a persisted model and therefore cannot be deleted.
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'ออกจากระบบเรียบร้อยแล้ว']);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        $expiration = (int) config('sanctum.expiration', 480);
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes($expiration))->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiration * 60,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        // Always return the same response to prevent account enumeration.
        return response()->json(['message' => 'หากพบบัญชี ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้ทางอีเมล']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['ลิงก์ตั้งรหัสผ่านไม่ถูกต้องหรือหมดอายุ']]);
        }

        return response()->json(['message' => 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว']);
    }

    public function user(Request $request)
    {
        return response()->json($this->present($request->user()));
    }

    /**
     * ข้อมูลบัญชีของตนเอง — สิทธิ์ว่างไว้จนกว่า R-04 จะต่อ PermissionEngine (`GET /api/me/permissions`)
     */
    private function present(User $user): array
    {
        $personnel = $user->personnel;

        return [
            'uuid' => $user->uuid,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status,
            'account_type' => $user->account_type,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'personnel' => $personnel ? [
                'uuid' => $personnel->uuid,
                'full_name' => $personnel->full_name,
            ] : null,
            'permission_names' => [],
        ];
    }
}
