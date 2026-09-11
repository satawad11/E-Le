<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\PermissionEvaluator;
use App\Services\StepUpAuthentication;
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

        Auth::login($user);
        $expiration = (int) config('sanctum.expiration', 480);
        $accessToken = $user->createToken('auth_token', ['*'], now()->addMinutes($expiration));

        /*
         * SEC-GAP-01 (INT-11) — การเข้าสู่ระบบคือการยืนยันตัวตนแข็งครั้งหนึ่ง
         * บันทึกเวลาไว้ที่โทเคน เพื่อให้ผู้ที่เพิ่งล็อกอินทำสิ่งที่อ่อนไหวได้ทันที
         * โดยไม่ต้องกรอกรหัสผ่านซ้ำในนาทีเดียวกัน (SoT §24)
         */
        $accessToken->accessToken->forceFill(['last_authenticated_at' => now()])->save();

        $token = $accessToken->plainTextToken;

        $user->load(['department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']);
        $user->permission_names = $this->effectivePermissionNames($user);
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

    /**
     * ยืนยันตัวตนซ้ำก่อนทำสิ่งที่อ่อนไหว (SEC-GAP-01 · SoT §24)
     *
     * ไม่ออกโทเคนใหม่และไม่เปลี่ยนสิทธิ์ใด ๆ — เพียงบันทึกว่าโทเคนใบที่กำลังใช้
     * เพิ่งผ่านการยืนยันตัวตน เพื่อให้ผ่านด่าน `step_up` ได้ภายในช่วงเวลาที่กำหนด
     *
     * จำกัดอัตราด้วย `throttle:password` ชุดเดิมของระบบ เพื่อไม่ให้ใช้ endpoint นี้
     * เดารหัสผ่านของผู้ใช้ที่ล็อกอินค้างไว้
     */
    public function confirmPassword(Request $request, StepUpAuthentication $stepUp)
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! $stepUp->confirm($request->user(), $request->string('password')->toString())) {
            return response()->json(['message' => 'รหัสผ่านไม่ถูกต้อง'], 422);
        }

        return response()->json([
            'message' => 'ยืนยันตัวตนเรียบร้อยแล้ว',
            'valid_for_minutes' => $stepUp->windowMinutes(),
        ]);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        /*
         * SEC-GAP-01 (INT-11) — ยกเวลายืนยันตัวตน "เดิม" ข้ามมาที่โทเคนใบใหม่
         *
         * ยกค่าเดิมมา ไม่ใช่ตั้งเป็นเวลาปัจจุบัน — ถ้าตั้งใหม่ทุกครั้งที่ต่ออายุโทเคน
         * ผู้ใช้จะยืดช่วงยืนยันตัวตนได้ไม่รู้จบเพียงแค่กดต่ออายุ ซึ่งทำให้ด่านนี้
         * ไม่มีความหมาย · การยกค่าเดิมมาทำให้ช่วงเวลายังหมดอายุตามกำหนดเดิม
         * แต่คนที่เพิ่งยืนยันไปก็ไม่ถูกบังคับกรอกซ้ำเพราะโทเคนต่ออายุพอดี
         */
        $previouslyAuthenticatedAt = $accessToken?->last_authenticated_at;

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        $expiration = (int) config('sanctum.expiration', 480);
        $issued = $user->createToken('auth_token', ['*'], now()->addMinutes($expiration));
        $issued->accessToken->forceFill(['last_authenticated_at' => $previouslyAuthenticatedAt])->save();

        $token = $issued->plainTextToken;

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
                // เจ้าตัวตั้งรหัสเองผ่านลิงก์แล้ว — ปลดการบังคับเปลี่ยนรหัสไปด้วย
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['ลิงก์ตั้งรหัสผ่านไม่ถูกต้องหรือหมดอายุ']]);
        }

        return response()->json(['message' => 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว']);
    }

    /**
     * ชื่อสิทธิ์ที่หน้าเว็บใช้ตัดสินการแสดงผล — รวมชื่อที่เทียบเท่ากันมาให้ครบ
     *
     * CLEANUP-02 (D5) — ผู้ใช้ปัจจุบันถือ "ชื่อชุดเดิม" (`manage_settings`) ขณะที่โค้ดใหม่
     * อ้าง "ชื่อมาตรฐาน" (`system.setting.manage`) ฝั่ง server แก้ให้แล้วด้วย
     * `permission_aliases` แต่หน้าเว็บเทียบชื่อตรง ๆ จึงต้องเขียนทั้งสองชื่อทุกจุด
     *
     * ส่งชื่อที่เทียบเท่ากันมาด้วย หน้าเว็บจึงเช็คชื่อมาตรฐานชื่อเดียวได้
     * และไม่ถือเป็นการให้สิทธิ์เพิ่ม เพราะเป็นชื่ออื่นของสิ่งที่ผู้ใช้ถืออยู่แล้ว
     *
     * @return array<int,string>
     */
    private function effectivePermissionNames(User $user): array
    {
        $evaluator = app(PermissionEvaluator::class);

        $names = $user->getAllPermissions()->pluck('name')
            ->flatMap(fn (string $name) => $evaluator->equivalentNames($name));

        return array_values(array_unique($names->all()));
    }

    public function user(Request $request)
    {
        $user = $request->user()->load(['department', 'profile', 'personnel', 'currentAssignment.orgUnit', 'currentAssignment.personnelType', 'orgUnit', 'personnelType']);
        $user->permission_names = $this->effectivePermissionNames($user);
        $user->direct_permissions = array_values(array_unique($user->getDirectPermissions()->pluck('name')->toArray()));
        $user->role_names = array_values(array_unique($user->roles->pluck('name')->toArray()));

        return response()->json($user);
    }
}
