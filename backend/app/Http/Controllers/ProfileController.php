<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Models\UserDelegation;
use App\Rules\Base64Image;
use App\Services\AuditLogger;
use App\Services\Files\ImageUploadService;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(private ImageUploadService $images) {}

    public function show(Request $request)
    {
        return response()->json($request->user()->load('profile', 'department', 'roles'));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        /*
         * SEC-024 — การเปลี่ยนรหัสผ่านย้ายไปเส้นทางของตัวเองที่ `POST /profile/password`
         *
         * ตอบ 422 แทนการเงียบ ๆ ไม่ทำอะไร เพื่อไม่ให้ client รุ่นเก่าเข้าใจผิดว่า
         * เปลี่ยนรหัสผ่านสำเร็จแล้วทั้งที่ไม่ได้เปลี่ยน
         */
        if ($request->has('password')) {
            throw ValidationException::withMessages([
                'password' => ['การเปลี่ยนรหัสผ่านต้องทำที่หัวข้อ "เปลี่ยนรหัสผ่าน" และต้องกรอกรหัสผ่านปัจจุบัน'],
            ]);
        }

        /*
         * SEC-028 — กฎของไฟล์ทุกช่องมาจาก allowlist กลางที่ `config/uploads.php`
         *
         * เดิม `profile_photo` ไม่มีกฎ validate เลยแม้แต่ข้อเดียว และ `signature_base64`
         * เป็นเพียง `nullable|string` แล้วไปตรวจเองในตัว controller ด้วย regex
         * ที่เชื่อชนิดไฟล์จากส่วนหัว data URI ซึ่ง client เขียนเองได้
         */
        $request->validate([
            'phone' => 'nullable|string|max:20',
            'telegram_chat_id' => 'nullable|string|max:255',
            'line_user_id' => 'nullable|string|max:255',
            'notification_channel' => 'nullable|string|in:line,telegram,both,none',
            'profile' => 'nullable|array',
            'profile_photo' => array_merge(['nullable'], UploadRules::profilePhoto()),
            'signature_file' => array_merge(['nullable'], UploadRules::signatureFile()),
            'signature_base64' => ['nullable', 'string', new Base64Image('signatures')],
        ]);

        $user->phone = $request->phone;
        $user->telegram_chat_id = $request->telegram_chat_id;
        $user->line_user_id = $request->line_user_id;
        $user->notification_channel = $request->notification_channel ?? 'both';

        $user->save();

        if ($request->has('profile')) {
            $allowedProfileFields = [
                'house_registration_number', 'house_number', 'moo', 'soi', 'road',
                'province', 'district', 'sub_district', 'zipcode',
                'fax', 'website',
                // ไม่รับ 'religion'/'ethnicity' อีกต่อไป — ข้อมูลอ่อนไหวตาม ม.26 ที่ระบบไม่ได้ใช้
            ];

            $profileData = is_string($request->profile) ? json_decode($request->profile, true) : $request->profile;
            $filteredData = [];

            foreach ($allowedProfileFields as $field) {
                if (isset($profileData[$field])) {
                    $filteredData[$field] = $profileData[$field];
                }
            }

            $filteredData += $this->storeProfileAssets($request);

            if (! empty($filteredData)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $filteredData
                );
            }
        } else {
            $filteredData = $this->storeProfileAssets($request);

            if (! empty($filteredData)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $filteredData
                );
            }
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว',
            'user' => $user->load('profile', 'department', 'roles'),
        ]);
    }

    /**
     * SEC-028 — เก็บรูปประจำตัวและลายเซ็นผ่านบริการกลางเพียงที่เดียว
     *
     * เดิมตรรกะชุดนี้ถูกคัดลอกไว้สองชุดในเมธอดเดียวกัน (สาขา "ส่ง profile มาด้วย"
     * กับ "ไม่ได้ส่ง") ซึ่งต่างกันเล็กน้อยและแก้ตกหล่นกันได้ง่าย
     *
     * @return array<string, string>
     */
    private function storeProfileAssets(Request $request): array
    {
        $assets = [];

        if ($request->hasFile('profile_photo')) {
            $assets['profile_photo_path'] = $this->images->storeUploadedFile(
                $request->file('profile_photo'), 'profile_photos', 'profile_photos', 'profile_photo'
            );
        }

        if ($request->hasFile('signature_file')) {
            $assets['signature_path'] = $this->images->storeUploadedFile(
                $request->file('signature_file'), 'signatures', 'signatures', 'signature_file'
            );
        } elseif ($request->filled('signature_base64')) {
            $assets['signature_path'] = $this->images->storeBase64(
                $request->string('signature_base64')->toString(), 'signatures', 'signatures'
            );
        }

        return array_filter($assets);
    }

    /**
     * SEC-024 — เปลี่ยนรหัสผ่านของตนเอง
     *
     * นโยบายเดิมของระบบ (`UserObserver::updated`) คือรหัสผ่านเปลี่ยนเมื่อไร
     * token ทุกใบของบัญชีนั้นถูกเพิกถอนทันที ซึ่งเข้มกว่า "เพิกถอนเฉพาะใบอื่น"
     * จึงคงไว้ตามเดิม แล้วออก token ใบใหม่ให้เฉพาะอุปกรณ์ที่ยืนยันรหัสผ่านเดิม
     * สำเร็จในคำขอนี้ ผลคืออุปกรณ์อื่นทุกเครื่องหลุดจริง แต่เจ้าของบัญชีไม่ถูก
     * เตะออกจากหน้าจอที่กำลังทำงานอยู่
     *
     * audit บันทึกเฉพาะข้อเท็จจริงว่าใครเปลี่ยนเมื่อไรและเพิกถอนไปกี่ใบ
     * ไม่บันทึกรหัสผ่านหรือ hash ทั้งของเดิมและของใหม่
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $tokensBefore = $user->tokens()->count();

        DB::transaction(function () use ($user, $request): void {
            // เจ้าตัวตั้งรหัสเองแล้ว — ปลดการบังคับเปลี่ยนรหัสที่ผู้ดูแลตั้งไว้ให้
            $user->forceFill([
                'password' => Hash::make($request->input('password')),
                'must_change_password' => false,
            ])->save();
        }, 3);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        app(AuditLogger::class)->log(
            'user.password_changed',
            'user',
            $user->id,
            null,
            ['revoked_tokens' => $tokensBefore],
        );

        $expiration = (int) config('sanctum.expiration', 480);
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes($expiration))->plainTextToken;

        return response()->json([
            'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว ระบบได้ออกจากระบบในอุปกรณ์อื่นทั้งหมดแล้ว',
            'revoked_sessions' => $tokensBefore,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiration * 60,
        ]);
    }

    public function getDelegation(Request $request)
    {
        $delegations = UserDelegation::with('delegate:id,name')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json($delegations);
    }

    public function setDelegation(Request $request)
    {
        $request->validate([
            'delegate_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean',
        ]);

        $delegation = UserDelegation::updateOrCreate(
            ['user_id' => $request->user()->id, 'delegate_id' => $request->delegate_id],
            [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_active' => $request->is_active ?? true,
            ]
        );

        return response()->json([
            'message' => 'บันทึกการมอบหมายสำเร็จ',
            'delegation' => $delegation->load('delegate:id,name'),
        ]);
    }
}
