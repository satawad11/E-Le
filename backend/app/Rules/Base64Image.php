<?php

namespace App\Rules;

use App\Support\UploadRules;
use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SEC-028 — ตรวจภาพที่ส่งมาเป็นสตริง Base64
 *
 * ปัญหาเดิม: ทุกจุดที่รับลายเซ็นวาดสด (`signature_base64`) ประกาศกฎเพียง
 * `nullable|string` แล้วไปตรวจเองในตัว controller ด้วย regex คนละชุด
 *
 *   - เชื่อ "ชนิด" จากส่วนหัวของ data URI ที่ client เขียนมาเอง
 *     `data:image/png;base64,` แล้วแนบเนื้อไฟล์อะไรก็ได้ ระบบก็เก็บเป็น `.png`
 *   - ไม่จำกัดขนาดเลย ส่งสตริงหลายสิบเมกะไบต์เข้ามาได้
 *   - `base64_decode()` ไม่ใส่ `$strict` สตริงเสียจึงถูก "ซ่อม" ให้เงียบ ๆ
 *     แล้วเขียนขยะลงดิสก์แทนที่จะถูกปฏิเสธ
 *
 * กฎนี้ตรวจจาก **เนื้อไฟล์จริง** หลัง decode เท่านั้น และจำกัดขนาดสตริง
 * ตั้งแต่ก่อน decode เพื่อไม่ให้ต้องกางไฟล์ใหญ่ขึ้นหน่วยความจำก่อนจะปฏิเสธ
 */
class Base64Image implements ValidationRule
{
    public function __construct(private string $profile = 'signatures') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('รูปแบบไฟล์ภาพไม่ถูกต้อง');

            return;
        }

        $maxBytes = UploadRules::maxSizeBytes($this->profile);
        $maxKb = (int) round($maxBytes / 1024);

        /*
         * กันตั้งแต่ก่อน decode — Base64 ใช้พื้นที่ราว 4/3 ของข้อมูลจริง
         * เผื่อส่วนหัว data URI ไว้เล็กน้อย
         */
        if (strlen($value) > (int) ceil($maxBytes * 4 / 3) + 128) {
            $fail("ไฟล์ภาพต้องมีขนาดไม่เกิน {$maxKb} กิโลไบต์");

            return;
        }

        if (preg_match('#^data:[^;,]+;base64,(.+)$#s', $value, $matches) !== 1) {
            $fail('รูปแบบไฟล์ภาพไม่ถูกต้อง');

            return;
        }

        // strict — สตริงที่มีอักขระนอกชุด Base64 ต้องถูกปฏิเสธ ไม่ใช่ถูกซ่อมให้
        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '') {
            $fail('ข้อมูลภาพไม่ถูกต้องหรือเสียหาย');

            return;
        }

        if (strlen($binary) > $maxBytes) {
            $fail("ไฟล์ภาพต้องมีขนาดไม่เกิน {$maxKb} กิโลไบต์");

            return;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        $allowed = UploadRules::allowedMimeTypes($this->profile);

        if (! in_array($mime, $allowed, true)) {
            $names = implode(', ', array_map(
                fn (string $type) => strtoupper((string) UploadRules::extensionForMimeType($type)),
                $allowed
            ));

            $fail("อนุญาตเฉพาะไฟล์ภาพชนิด {$names} เท่านั้น");
        }
    }
}
