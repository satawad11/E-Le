<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * SEC-024 — เปลี่ยนรหัสผ่านของตนเอง
 *
 * เดิมช่อง `password` ปนอยู่ในคำขออัปเดตโปรไฟล์ทั่วไป ใครที่ยึด token ไปได้
 * จึงเปลี่ยนรหัสผ่านของเจ้าของบัญชีได้ทันทีโดยไม่ต้องรู้รหัสเดิม และไม่มีช่อง
 * ยืนยันรหัสใหม่ พิมพ์ผิดครั้งเดียวก็ล็อกตัวเองออกจากระบบถาวร
 *
 * คำขอนี้จึงแยกออกมาเป็นเส้นทางของตัวเอง และบังคับสามอย่าง:
 *   1. ต้องรู้รหัสผ่านปัจจุบัน (`current_password` ของ Laravel เทียบกับ hash จริง)
 *   2. ต้องยืนยันรหัสใหม่ให้ตรงกัน (`confirmed`)
 *   3. รหัสใหม่ต้องไม่ซ้ำกับรหัสเดิม
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'รหัสผ่านปัจจุบัน',
            'password' => 'รหัสผ่านใหม่',
            'password_confirmation' => 'การยืนยันรหัสผ่านใหม่',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่',
            'password.confirmed' => 'การยืนยันรหัสผ่านใหม่ไม่ตรงกับรหัสผ่านใหม่',
            'password.min' => 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย :min ตัวอักษร',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('current_password') === $this->input('password')) {
                    $validator->errors()->add('password', 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม');
                }
            },
        ];
    }
}
