<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplateVersion;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * ขนาดจริงของแต่ละหน้าในไฟล์แบบฟอร์มต้นฉบับ (หน่วย PDF point)
 *
 * หน้าออกแบบเคยตรึงขนาดกระดาษไว้ที่ A4 แนวตั้ง (595×842) ทุกกรณี ขณะที่
 * `PdfOverlayRenderer` วางฟิลด์ลงบน "ขนาดหน้าจริงของไฟล์ที่นำเข้า" แบบฟอร์ม F4/Legal
 * แนวนอน หรือไฟล์สแกนที่ไม่ใช่ 595×842 จึงวางบนจอตำแหน่งหนึ่งแต่พิมพ์ออกอีกตำแหน่งหนึ่ง
 *
 * คลาสนี้เป็นแหล่งความจริงเดียวของ "หน้ากระดาษนี้กว้างยาวเท่าไร" ทั้งฝั่งที่ส่งให้หน้าจอ
 * ใช้เป็นระบบพิกัด และฝั่งที่ตรวจว่าฟิลด์ที่บันทึกยังอยู่ในหน้ากระดาษจริงหรือไม่
 */
class TemplateGeometry
{
    /**
     * ผ่อนปรนความคลาดเคลื่อนของทศนิยม 1 pt (~0.35 มม.)
     *
     * เล็กกว่าความคลาดเคลื่อนที่ตามองเห็นบนกระดาษ แต่พอให้การปัดเศษจากหน้าจอไม่ถูกปฏิเสธ
     */
    public const TOLERANCE = 1.0;

    /** @var array<string, array<int, array{width: float, height: float}>> */
    private array $cache = [];

    /**
     * ขนาดของทุกหน้าตามลำดับ (index 0 = หน้า 1)
     *
     * @return array<int, array{width: float, height: float}>
     */
    public function pageSizes(DocumentTemplateVersion $version): array
    {
        $path = (string) $version->pdf_file_path;

        if ($path === '') {
            return [];
        }

        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        $disk = Storage::disk('private_uploads');

        if (! $disk->exists($path)) {
            return [];
        }

        /*
         * หน่วยต้องเป็น pt ให้ตรงกับ `PdfOverlayRenderer` ที่สร้างเอกสารด้วย `new Fpdi('P', 'pt')`
         *
         * FPDF คืนขนาดหน้าตาม "หน่วยของเอกสาร" ที่ประกาศตอนสร้างอ็อบเจกต์ ค่าเริ่มต้นคือมิลลิเมตร
         * ถ้าปล่อยเป็นค่าเริ่มต้น A4 จะกลายเป็น 210×297 แทนที่จะเป็น 595×842 และการตรวจขอบกระดาษ
         * จะปฏิเสธฟิลด์ที่ถูกต้องเกือบทั้งหมด
         */
        $pdf = new Fpdi('P', 'pt');
        $pageCount = $pdf->setSourceFile($disk->path($path));

        $sizes = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $size = $pdf->getTemplateSize($pdf->importPage($page));

            $sizes[] = [
                'width' => round((float) $size['width'], 2),
                'height' => round((float) $size['height'], 2),
            ];
        }

        return $this->cache[$path] = $sizes;
    }

    /**
     * ข้อความบอกฟิลด์ที่อยู่นอกหน้ากระดาษหรืออ้างหน้าที่ไม่มีจริง
     *
     * คืนอาร์เรย์ว่าง = ผ่าน ทุกข้อความเป็นภาษาไทยและระบุฟิลด์ให้ผู้ดูแลกลับไปแก้ได้ทันที
     *
     * @param  iterable<int, array<string, mixed>|DocumentFieldMapping>  $mappings
     * @return array<int, string>
     */
    public function problems(DocumentTemplateVersion $version, iterable $mappings): array
    {
        $sizes = $this->pageSizes($version);

        if ($sizes === []) {
            return [];
        }

        $problems = [];

        foreach ($mappings as $mapping) {
            $code = (string) $this->value($mapping, 'field_code');
            $page = (int) $this->value($mapping, 'page', 1);

            if ($page < 1 || $page > count($sizes)) {
                $problems[] = sprintf('ฟิลด์ %s อยู่หน้า %d แต่แบบฟอร์มนี้มี %d หน้า', $code, $page, count($sizes));

                continue;
            }

            $size = $sizes[$page - 1];
            $x = (float) $this->value($mapping, 'x', 0);
            $y = (float) $this->value($mapping, 'y', 0);
            $right = $x + (float) $this->value($mapping, 'width', 0);
            $bottom = $y + (float) $this->value($mapping, 'height', 0);

            if ($x < -self::TOLERANCE || $y < -self::TOLERANCE
                || $right > $size['width'] + self::TOLERANCE
                || $bottom > $size['height'] + self::TOLERANCE) {
                $problems[] = sprintf(
                    'ฟิลด์ %s อยู่นอกขอบกระดาษของหน้า %d (ขนาดหน้า %.0f×%.0f pt)',
                    $code,
                    $page,
                    $size['width'],
                    $size['height'],
                );
            }
        }

        return $problems;
    }

    private function value(array|object $mapping, string $key, mixed $default = null): mixed
    {
        if (is_array($mapping)) {
            return $mapping[$key] ?? $default;
        }

        return $mapping->{$key} ?? $default;
    }
}
