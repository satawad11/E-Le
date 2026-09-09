<?php

namespace App\Services\DocumentEngine;

use Illuminate\Support\Carbon;

/**
 * แปลงค่าดิบของฟิลด์ให้เป็นข้อความตามรูปแบบที่ผู้ดูแลเลือกไว้ในหน้าออกแบบ
 *
 * คอลัมน์ `document_field_mappings.format` มีมาตั้งแต่ DOC-ENGINE และหน้าออกแบบบันทึกค่าได้
 * แต่ `PdfOverlayRenderer` ไม่เคยอ่านค่านี้ ค่าที่ผู้ดูแลตั้งจึงไม่มีผลกับเอกสารที่พิมพ์ออกมา
 * คลาสนี้เป็นที่เดียวที่ตีความรูปแบบ เพื่อให้พรีวิวกับไฟล์จริงได้ผลตรงกันเสมอ
 *
 * รูปแบบเป็น **whitelist** — ค่าที่ไม่รู้จักจะคืนข้อความเดิมโดยไม่ทำให้การพิมพ์ล้ม
 * เพราะเอกสารราชการที่พิมพ์ไม่ออกเสียหายกว่าเอกสารที่รูปแบบไม่ตรงใจ
 */
class ValueFormatter
{
    /** ชื่อเดือนภาษาไทยเต็มและย่อ — ใช้ชุดเดียวกับที่ระบบเดิมพิมพ์บนใบลา */
    private const MONTHS = [
        1 => ['มกราคม', 'ม.ค.'], 2 => ['กุมภาพันธ์', 'ก.พ.'], 3 => ['มีนาคม', 'มี.ค.'],
        4 => ['เมษายน', 'เม.ย.'], 5 => ['พฤษภาคม', 'พ.ค.'], 6 => ['มิถุนายน', 'มิ.ย.'],
        7 => ['กรกฎาคม', 'ก.ค.'], 8 => ['สิงหาคม', 'ส.ค.'], 9 => ['กันยายน', 'ก.ย.'],
        10 => ['ตุลาคม', 'ต.ค.'], 11 => ['พฤศจิกายน', 'พ.ย.'], 12 => ['ธันวาคม', 'ธ.ค.'],
    ];

    /**
     * รูปแบบที่เลือกได้ทั้งหมด (code => คำอธิบายภาษาไทยสำหรับหน้าออกแบบ)
     *
     * @var array<string, string>
     */
    public const FORMATS = [
        'thai_date' => 'วันที่ไทยเต็ม (1 ตุลาคม 2569)',
        'thai_date_short' => 'วันที่ไทยย่อ (1 ต.ค. 2569)',
        'date_be' => 'วัน/เดือน/ปี พ.ศ. (01/10/2569)',
        'date_ce' => 'วัน/เดือน/ปี ค.ศ. (01/10/2026)',
        'iso_date' => 'ปี-เดือน-วัน (2026-10-01)',
        'number' => 'ตัวเลขมีเครื่องหมายคั่นหลักพัน (1,234)',
        'number_1' => 'ตัวเลขทศนิยม 1 ตำแหน่ง (1,234.5)',
        'number_2' => 'ตัวเลขทศนิยม 2 ตำแหน่ง (1,234.50)',
        'thai_digits' => 'เลขไทย (๑๒๓)',
        'upper' => 'ตัวพิมพ์ใหญ่ทั้งหมด',
        'lower' => 'ตัวพิมพ์เล็กทั้งหมด',
    ];

    private const THAI_DIGITS = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];

    public function format(mixed $raw, ?string $format): string
    {
        $text = $this->text($raw);

        if ($text === '' || $format === null || $format === '' || ! isset(self::FORMATS[$format])) {
            return $text;
        }

        return match ($format) {
            'thai_date', 'thai_date_short', 'date_be', 'date_ce', 'iso_date' => $this->date($text, $format),
            'number' => $this->number($text, 0),
            'number_1' => $this->number($text, 1),
            'number_2' => $this->number($text, 2),
            'thai_digits' => str_replace(range(0, 9), self::THAI_DIGITS, $text),
            'upper' => mb_strtoupper($text),
            'lower' => mb_strtolower($text),
            default => $text,
        };
    }

    /**
     * ค่าที่อ่านเป็นวันที่ไม่ได้จะถูกคืนตามเดิม
     *
     * ฟิลด์วันที่ของระบบเดิมหลายตัวส่งเป็นข้อความไทยมาแล้ว (เช่น "1 ตุลาคม 2569")
     * การคืนค่าเดิมจึงเป็นพฤติกรรมที่ถูกต้อง ไม่ใช่การกลืนข้อผิดพลาด
     */
    private function date(string $text, string $format): string
    {
        try {
            $date = Carbon::parse($text);
        } catch (\Throwable) {
            return $text;
        }

        $buddhistYear = $date->year + 543;

        return match ($format) {
            'thai_date' => sprintf('%d %s %d', $date->day, self::MONTHS[$date->month][0], $buddhistYear),
            'thai_date_short' => sprintf('%d %s %d', $date->day, self::MONTHS[$date->month][1], $buddhistYear),
            'date_be' => sprintf('%02d/%02d/%d', $date->day, $date->month, $buddhistYear),
            'date_ce' => $date->format('d/m/Y'),
            default => $date->format('Y-m-d'),
        };
    }

    private function number(string $text, int $decimals): string
    {
        $clean = str_replace(',', '', $text);

        if (! is_numeric($clean)) {
            return $text;
        }

        return number_format((float) $clean, $decimals);
    }

    private function text(mixed $raw): string
    {
        if ($raw === null || is_bool($raw) || is_array($raw)) {
            return '';
        }

        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance($raw)->toDateString();
        }

        return trim((string) $raw);
    }
}
