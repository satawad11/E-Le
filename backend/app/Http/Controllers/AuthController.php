<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            throw ValidationException::withMessages([
                'email' => ['ข้อมูลการเข้าสู่ระบบไม่ถูกต้อง'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['บัญชีผู้ใช้นี้ถูกระงับการใช้งาน'],
            ]);
        }

        Auth::login($user);
        $token = $user->createToken('auth_token')->plainTextToken;

        $user->load('department', 'profile');
        $user->permission_names = array_values(array_unique($user->getAllPermissions()->pluck('name')->toArray()));
        $user->direct_permissions = array_values(array_unique($user->getDirectPermissions()->pluck('name')->toArray()));
        $user->role_names = array_values(array_unique($user->roles->pluck('name')->toArray()));

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
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

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load('department', 'profile');
        $user->permission_names = array_values(array_unique($user->getAllPermissions()->pluck('name')->toArray()));
        $user->direct_permissions = array_values(array_unique($user->getDirectPermissions()->pluck('name')->toArray()));
        $user->role_names = array_values(array_unique($user->roles->pluck('name')->toArray()));

        return response()->json($user);
    }
}
