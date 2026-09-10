<?php

namespace App\Support;

/**
 * ชื่อไฟล์เอกสารที่ส่งให้ผู้ใช้ดาวน์โหลด
 *
 * เดิมแต่ละ endpoint ประกอบชื่อไฟล์เอง ผลคือ:
 *   - ใบยกเลิกได้คำนำหน้าเป็นภาษาอังกฤษ (`cancel_ลาป่วย_...pdf`) ปนกับชื่อไทยทั้งไฟล์
 *   - ชื่อประเภทการลาที่มีช่องว่าง ("ลาไปศึกษาต่อ ดูงาน ฝึกอบรม") หลุดเป็นช่องว่างในชื่อไฟล์
 *     ทั้งที่ชื่อคนถูกแทนด้วย `_` แล้ว
 *   - กรองอักขระต้องห้ามแค่ `/` กับ `\` ส่วน `: * ? " < > |` ผ่านไปได้ ซึ่ง Windows บันทึกไม่ได้
 *   - ไม่ส่ง `filename*` ตาม RFC 6266 ชื่อไฟล์ภาษาไทยจึงเพี้ยนบนบางเบราว์เซอร์/ตัวจัดการดาวน์โหลด
 *
 * คลาสนี้เป็นที่เดียวที่ตัดสินว่า "ไฟล์ที่ผู้ใช้ได้ ชื่อว่าอะไร" และประกอบ header ให้ถูกมาตรฐาน
 */
class DocumentFilename
{
    /** อักขระที่ระบบไฟล์ของ Windows/macOS ห้ามใช้ในชื่อไฟล์ */
    private const FORBIDDEN = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    /** ความยาวสูงสุดของชื่อไฟล์ (ไม่รวมนามสกุล) — กันชื่อยาวเกินที่ระบบไฟล์รับไหว */
    private const MAX_LENGTH = 150;

    /**
     * ประกอบชื่อไฟล์จากส่วนต่าง ๆ โดยข้ามส่วนที่ว่าง
     *
     * @param  array<int, string|int|null>  $parts
     */
    public static function make(array $parts, string $extension): string
    {
        $clean = [];

        foreach ($parts as $part) {
            $value = self::sanitize((string) $part);

            if ($value !== '') {
                $clean[] = $value;
            }
        }

        $name = implode('_', $clean);

        if ($name === '') {
            $name = 'เอกสาร';
        }

        return mb_substr($name, 0, self::MAX_LENGTH).'.'.ltrim($extension, '.');
    }

    /**
     * ค่า `Content-Disposition` ที่ถูกต้องตาม RFC 6266
     *
     * ส่งสองรูปแบบเสมอ: `filename` แบบ ASCII ไว้ให้ตัวอ่านรุ่นเก่า และ `filename*` แบบ UTF-8
     * ที่เบราว์เซอร์ปัจจุบันใช้ — ชื่อไทยจึงไม่กลายเป็นตัวอักษรเพี้ยนหรือ `download.pdf`
     */
    public static function disposition(string $filename, string $type = 'attachment'): string
    {
        return sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $type,
            self::asciiFallback($filename),
            rawurlencode($filename),
        );
    }

    /**
     * ส่วนของชื่อไฟล์ที่ปลอดภัย — ช่องว่างเป็น `_` และไม่มีอักขระต้องห้าม
     */
    public static function sanitize(string $value): string
    {
        $value = str_replace(self::FORBIDDEN, '_', trim($value));

        // อักขระควบคุมและช่องว่างทุกชนิด (รวม non-breaking space) กลายเป็นขีดล่าง
        $value = (string) preg_replace('/[\p{Z}\s\x00-\x1F\x7F]+/u', '_', $value);

        // ขีดล่างซ้อนกันและขีดล่างหัวท้ายไม่มีประโยชน์ต่อผู้อ่าน
        $value = (string) preg_replace('/_+/u', '_', $value);

        return trim($value, '_');
    }

    /**
     * ชื่อสำรองแบบ ASCII สำหรับตัวอ่านที่ไม่รองรับ `filename*`
     *
     * แทนอักขระที่ไม่ใช่ ASCII ด้วย `_` แล้วคงนามสกุลไว้ เพื่อให้ไฟล์ยังเปิดด้วยโปรแกรมที่ถูกต้อง
     */
    private static function asciiFallback(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name);
        $ascii = trim((string) preg_replace('/_+/', '_', $ascii), '_');

        /*
         * ชื่อไทยล้วนเหลือแต่ตัวเลข (เช่น `1234.pdf` จากเลขที่หนังสือ) ซึ่งไม่บอกอะไรเลย
         * เติมคำนำหน้าไว้ให้ตัวอ่านรุ่นเก่ายังพอรู้ว่าเป็นเอกสารของระบบ
         */
        if (! preg_match('/[A-Za-z]/', $ascii)) {
            $ascii = trim('document_'.$ascii, '_');
        }

        return $extension === '' ? $ascii : $ascii.'.'.$extension;
    }
}
