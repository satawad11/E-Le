<?php

namespace App\Support\Spreadsheet;

use InvalidArgumentException;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * อ่านค่าจาก .xlsx (ext-zip + XMLReader) และ .csv สำหรับการนำเข้าข้อมูล (ADR-R-014)
 *
 * อ่านเฉพาะค่า ไม่อ่านสูตร/รูปแบบ · แถวแรกของแต่ละแผ่นคือหัวคอลัมน์ · แถวว่างทั้งแถวถูกข้าม
 * ผลลัพธ์: [ชื่อแผ่นงาน => [เลขแถวในไฟล์ => [หัวคอลัมน์ => ค่า (string)]]]
 * วันที่ใน Excel มาเป็นเลข serial — ผู้เรียกแปลงเองด้วย `excelSerialToDate()` เพราะรู้ว่าคอลัมน์ไหนเป็นวันที่
 */
class SheetReader
{
    /** @return array<string, array<int, array<string, string>>> */
    public function read(string $path, ?string $csvSheetName = null): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("อ่านไฟล์ไม่ได้: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => $this->readXlsx($path),
            'csv', 'txt' => [($csvSheetName ?? pathinfo($path, PATHINFO_FILENAME)) => $this->withHeaders($this->readCsv($path))],
            default => throw new InvalidArgumentException('รองรับเฉพาะไฟล์ .xlsx และ .csv'),
        };
    }

    public static function excelSerialToDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            // Excel นับวันที่ 1 = 1900-01-01 และนับ 1900-02-29 ที่ไม่มีจริง จึงเริ่มจาก 1899-12-30
            return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }

        return $value;
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;
            if ($line === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]); // BOM จาก Excel
            }
            $rows[$line] = array_map(fn ($c) => trim((string) $c), $cells);
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<string, array<int, array<string, string>>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('ไฟล์ .xlsx เสียหายหรือไม่ใช่ไฟล์ Excel');
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheets = [];
            foreach ($this->sheetPaths($zip) as $name => $sheetPath) {
                $xml = $zip->getFromName($sheetPath);
                if ($xml === false) {
                    continue;
                }
                $sheets[$name] = $this->withHeaders($this->sheetRows($xml, $shared));
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET);
        $strings = [];
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = $current;
                $current = null;
            }
        }

        return $strings;
    }

    /** @return array<string, string> ชื่อแผ่นงาน => path ใน zip ตามลำดับใน workbook */
    private function sheetPaths(ZipArchive $zip): array
    {
        $rels = [];
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml !== false) {
            $doc = simplexml_load_string($relsXml, options: LIBXML_NONET);
            foreach ($doc->Relationship as $rel) {
                $target = (string) $rel['Target'];
                $rels[(string) $rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
        }

        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'), options: LIBXML_NONET);
        $paths = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $paths[(string) $sheet['name']] = $rels[$rid] ?? 'xl/worksheets/sheet'.(count($paths) + 1).'.xml';
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $shared
     * @return array<int, array<int, string>>
     */
    private function sheetRows(string $xml, array $shared): array
    {
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET);
        $rows = [];
        $rowNumber = 0;
        $cell = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $rowNumber = (int) ($reader->getAttribute('r') ?: $rowNumber + 1);
                $rows[$rowNumber] = [];
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $ref = (string) $reader->getAttribute('r');
                $cell = [
                    'col' => $ref !== '' ? $this->columnIndex($ref) : count($rows[$rowNumber]),
                    'type' => (string) $reader->getAttribute('t'),
                    'value' => '',
                ];
                if ($reader->isEmptyElement) {
                    $rows[$rowNumber][$cell['col']] = '';
                    $cell = null;
                }
            } elseif ($cell !== null && $reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true)) {
                $cell['value'] .= $reader->readString();
            } elseif ($cell !== null && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c') {
                $value = match ($cell['type']) {
                    's' => $shared[(int) $cell['value']] ?? '',
                    'b' => $cell['value'] === '1' ? 'TRUE' : 'FALSE',
                    default => $cell['value'],
                };
                $rows[$rowNumber][$cell['col']] = trim($value);
                $cell = null;
            }
        }

        foreach ($rows as $n => $cells) {
            if ($cells === []) {
                $rows[$n] = [];

                continue;
            }
            $max = max(array_keys($cells));
            $rows[$n] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
        }

        return $rows;
    }

    private function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $index = 0;
        foreach (str_split($m[1] ?? 'A') as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<string, string>>
     */
    private function withHeaders(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $headerRow = array_key_first($rows);
        $headers = array_map(fn ($h) => strtolower(trim($h)), $rows[$headerRow]);
        unset($rows[$headerRow]);

        $result = [];
        foreach ($rows as $number => $cells) {
            if (implode('', $cells) === '') {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $assoc[$header] = $cells[$i] ?? '';
                }
            }
            $result[$number] = $assoc;
        }

        return $result;
    }
}
