<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * เตรียมฟอนต์ไทยสำหรับ renderer แบบวางฟิลด์บน PDF (FPDI/FPDF)
 *
 * dompdf ใช้ไฟล์ `.ttf` ใน `storage/fonts` ได้ตรง ๆ แต่ FPDF ต้องการไฟล์นิยามฟอนต์
 * ของตัวเอง (`.php` + `.z`) คำสั่งนี้สร้างจาก TH SarabunNew ที่โครงการมีอยู่แล้ว
 * ผลลัพธ์เก็บใน `storage/fonts/fpdf` — เอกสารจึงไม่พึ่งฟอนต์ของเครื่องที่รันเลย
 *
 * รันซ้ำได้ ปลอดภัย (idempotent) และควรรันหลัง deploy ทุกครั้งที่ล้าง storage
 */
class InstallDocumentEngineFonts extends Command
{
    protected $signature = 'document:install-fonts {--force : สร้างใหม่ทับของเดิม}';

    protected $description = 'สร้างไฟล์นิยามฟอนต์ไทยสำหรับการวางฟิลด์บนแบบฟอร์ม PDF';

    /** ฟอนต์ที่ระบบใช้ — ชื่อไฟล์ผลลัพธ์ต้องคงที่เพราะ renderer อ้างถึงโดยตรง */
    private const FONTS = [
        'THSarabunNew' => 'thsarabunnew_normal_dfbbbbd246b0464d3c71453b25f80cac.ttf',
        'THSarabunNewB' => 'thsarabunnew_bold_9051b9b04071b65e00462daae276c9a0.ttf',
    ];

    public function handle(): int
    {
        $source = storage_path('fonts');
        $target = storage_path('fonts/fpdf');

        if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            $this->error("สร้างโฟลเดอร์ {$target} ไม่สำเร็จ");

            return self::FAILURE;
        }

        $makefont = base_path('vendor/setasign/fpdf/makefont/makefont.php');

        if (! is_file($makefont)) {
            $this->error('ไม่พบ makefont ของ FPDF — ตรวจสอบว่าติดตั้ง setasign/fpdf แล้วหรือยัง');

            return self::FAILURE;
        }

        foreach (self::FONTS as $name => $file) {
            $ttf = $source.DIRECTORY_SEPARATOR.$file;

            if (! is_file($ttf)) {
                $this->warn("ข้าม {$name}: ไม่พบไฟล์ {$file}");

                continue;
            }

            if (! $this->option('force') && is_file($target.DIRECTORY_SEPARATOR.$name.'.php')) {
                $this->line("มีอยู่แล้ว: {$name}");

                continue;
            }

            /*
             * makefont ของ FPDF เป็นสคริปต์ CLI ที่ทำงานทันทีเมื่อถูก include
             * จึงต้องเรียกเป็นโปรเซสแยก และมันเขียนไฟล์ผลลัพธ์ลง working directory
             *
             * cp874 คือหน้ารหัสไทยของ FPDF — renderer แปลง UTF-8 → cp874 ตอนเขียนข้อความ
             */
            $process = Process::path($target)->run(['php', $makefont, $ttf, 'cp874', 'true', 'true']);

            if (! $process->successful()) {
                $this->error("สร้างฟอนต์ {$name} ไม่สำเร็จ: ".trim($process->errorOutput() ?: $process->output()));

                return self::FAILURE;
            }

            $generated = pathinfo($file, PATHINFO_FILENAME);

            // FPDF รุ่นนี้ออกไฟล์นิยามเป็น .json (รุ่นเก่าเป็น .php) รองรับทั้งสองแบบ
            foreach (['json', 'php', 'z'] as $extension) {
                $from = $target.DIRECTORY_SEPARATOR.$generated.'.'.$extension;
                $to = $target.DIRECTORY_SEPARATOR.$name.'.'.$extension;

                if (! is_file($from)) {
                    continue;
                }

                if ($extension === 'z') {
                    rename($from, $to);

                    continue;
                }

                // ให้ไฟล์นิยามฟอนต์ชี้ไปยังไฟล์ .z ที่เปลี่ยนชื่อแล้ว
                file_put_contents($to, str_replace($generated.'.z', $name.'.z', (string) file_get_contents($from)));
                unlink($from);
            }

            $this->info("สร้างฟอนต์ {$name} เรียบร้อย");
        }

        return self::SUCCESS;
    }
}
