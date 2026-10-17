<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserProfile;
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
            // Check if it matches id_card_number in user_profiles
            $idCardHash = hash('sha256', $loginField.config('app.key'));
            $profile = UserProfile::where('id_card_number_hash', $idCardHash)->first();
            if ($profile) {
                $user = $profile->user;
            }
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($lockKey, 900);
            throw ValidationException::withMessages([
                'email' => ['ข้อมูลการเข้าสู่ระบบไม่ถูกต้อง'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['บัญชีผู้ใช้นี้ถูกระงับการใช้งาน'],
            ]);
        }

        RateLimiter::clear($lockKey);

        // ใช้ในรายงานทบทวนสิทธิ์รายปี (ACCESS-GOV-01) — saveQuietly เพื่อไม่ให้ไปซิงก์ทะเบียนบุคลากรทุกครั้งที่ login
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        Auth::login($user);
        $expiration = (int) config('sanctum.expiration', 480);
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes($expiration))->plainTextToken;

        $user->load(['department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']);
        $user->permission_names = array_values(array_unique($user->getAllPermissions()->pluck('name')->toArray()));
        $user->direct_permissions = array_values(array_unique($user->getDirectPermissions()->pluck('name')->toArray()));
        $user->role_names = array_values(array_unique($user->roles->pluck('name')->toArray()));

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiration * 60,
            'user' => $user,
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
        $user = $request->user()->load(['department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']);
        $user->permission_names = array_values(array_unique($user->getAllPermissions()->pluck('name')->toArray()));
        $user->direct_permissions = array_values(array_unique($user->getDirectPermissions()->pluck('name')->toArray()));
        $user->role_names = array_values(array_unique($user->roles->pluck('name')->toArray()));

        return response()->json($user);
    }
}
