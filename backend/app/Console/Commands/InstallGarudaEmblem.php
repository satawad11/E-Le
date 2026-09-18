<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ติดตั้งไฟล์ตราครุฑมาตรฐาน (Garuda Emblem) สำหรับการออกเอกสารราชการ
 *
 * ผลลัพธ์เก็บที่:
 * - public/images/garuda.png
 * - public/images/garuda.svg
 *
 * รองรับการเรียกซ้ำได้ปลอดภัย (idempotent)
 */
class InstallGarudaEmblem extends Command
{
    protected $signature = 'document:install-garuda {--force : สร้างใหม่ทับของเดิม}';

    protected $description = 'ติดตั้งไฟล์ตราครุฑมาตรฐานสำหรับแบบฟอร์มเอกสารราชการและ PDF Engine';

    public function handle(): int
    {
        $dir = public_path('images');

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("สร้างโฟลเดอร์ {$dir} ไม่สำเร็จ");

            return self::FAILURE;
        }

        $pngPath = $dir.DIRECTORY_SEPARATOR.'garuda.png';
        $svgPath = $dir.DIRECTORY_SEPARATOR.'garuda.svg';

        $svgContent = $this->garudaSvgContent();
        file_put_contents($svgPath, $svgContent);

        if ($this->option('force') || ! is_file($pngPath)) {
            $this->generatePng($pngPath);
        }

        $this->info('ติดตั้งไฟล์ตราครุฑมาตรฐานเรียบร้อย: '.$pngPath);

        return self::SUCCESS;
    }

    private function generatePng(string $pngPath): void
    {
        $width = 400;
        $height = 430;
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        $color = imagecolorallocate($img, 20, 20, 20);

        // Chada / Crown
        imagefilledpolygon($img, [200, 30, 190, 80, 210, 80], 3, $color);
        imagefilledellipse($img, 200, 95, 30, 30, $color);

        // Head & Torso
        imagefilledellipse($img, 200, 130, 45, 45, $color);
        imagefilledellipse($img, 200, 190, 75, 95, $color);

        // Wings Left & Right
        imagefilledpolygon($img, [175, 130, 40, 70, 60, 220, 165, 190], 4, $color);
        imagefilledpolygon($img, [225, 130, 360, 70, 340, 220, 235, 190], 4, $color);

        // Wing Feathers
        for ($i = 0; $i < 6; $i++) {
            imagefilledpolygon($img, [170, 140 + $i * 12, 30 + $i * 18, 80 + $i * 22, 160, 155 + $i * 12], 3, $color);
            imagefilledpolygon($img, [230, 140 + $i * 12, 370 - $i * 18, 80 + $i * 22, 240, 155 + $i * 12], 3, $color);
        }

        // Tail & Lower Garment
        imagefilledpolygon($img, [165, 230, 130, 360, 175, 320, 200, 385, 225, 320, 270, 360, 235, 230], 7, $color);

        imagepng($img, $pngPath);
        imagedestroy($img);
    }

    private function garudaSvgContent(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 430" width="400" height="430">
  <g fill="#1a1a1a">
    <polygon points="200,30 190,80 210,80" />
    <circle cx="200" cy="95" r="15" />
    <circle cx="200" cy="130" r="22" />
    <ellipse cx="200" cy="190" rx="37" ry="47" />
    <polygon points="175,130 40,70 60,220 165,190" />
    <polygon points="225,130 360,70 340,220 235,190" />
    <polygon points="165,230 130,360 175,320 200,385 225,320 270,360 235,230" />
  </g>
</svg>';
    }
}
