<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * เขียน .xlsx อย่างง่าย (ค่าเป็นข้อความทั้งหมด) — ใช้สร้างแม่แบบไฟล์นำเข้าและในเทสต์ (ADR-R-014)
 */
class SheetWriter
{
    /**
     * @param  array<string, array<int, array<int, string|int|float|null>>>  $sheets  ชื่อแผ่นงาน => แถว (แถวแรกคือหัวคอลัมน์)
     */
    public function write(string $path, array $sheets): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("สร้างไฟล์ไม่ได้: {$path}");
        }

        $names = array_keys($sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($names)));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $sheetXml = '';
        $relsXml = '';
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $sheetXml .= '<sheet name="'.$this->esc($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relsXml .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $this->sheet($sheets[$name]));
        }

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relsXml.'</Relationships>');
        $zip->close();
    }

    /** @param  array<int, array<int, string|int|float|null>>  $rows */
    private function sheet(array $rows): string
    {
        $xml = '';
        foreach (array_values($rows) as $r => $cells) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $value) {
                $xml .= '<c r="'.$this->column($c).($r + 1).'" t="inlineStr"><is><t xml:space="preserve">'.$this->esc((string) $value).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($n = 1; $n <= $sheetCount; $n++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides.'</Types>';
    }

    private function column(int $index): string
    {
        $name = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + (($i - 1) % 26)).$name;
        }

        return $name;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
