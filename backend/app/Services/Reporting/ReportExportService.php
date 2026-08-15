<?php

namespace App\Services\Reporting;

use ZipArchive;

class ReportExportService
{
    public function csv(ReportDataset $dataset): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values($dataset->columns));
        foreach ($dataset->rows as $row) {
            fputcsv($stream, array_map(fn (string $key) => $this->safeValue($row[$key] ?? null), array_keys($dataset->columns)));
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    public function xlsx(ReportDataset $dataset): string
    {
        $rows = [array_values($dataset->columns)];
        foreach ($dataset->rows as $row) {
            $rows[] = array_map(fn (string $key) => $this->safeValue($row[$key] ?? null), array_keys($dataset->columns));
        }

        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnName($columnIndex + 1).($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string) $value).'</t></is></c>';
                }
            }
            $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];

        return $this->zip($files, 'xlsx');
    }

    public function docx(ReportDataset $dataset): string
    {
        $tableRows = $this->wordRow(array_values($dataset->columns));
        foreach ($dataset->rows as $row) {
            $tableRows .= $this->wordRow(array_map(fn (string $key) => $this->safeValue($row[$key] ?? null), array_keys($dataset->columns)));
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$this->xml($dataset->title).'</w:t></w:r></w:p><w:tbl>'.$tableRows.'</w:tbl><w:sectPr/></w:body></w:document>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => $document,
        ], 'docx');
    }

    private function wordRow(array $values): string
    {
        return '<w:tr>'.implode('', array_map(fn ($value) => '<w:tc><w:p><w:r><w:t xml:space="preserve">'.$this->xml((string) $value).'</w:t></w:r></w:p></w:tc>', $values)).'</w:tr>';
    }

    private function zip(array $files, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ela-report-').'.'.$extension;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create report archive.');
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function safeValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_string($value) && preg_match('/^[=+\-@]/', $value)) {
            return "'".$value;
        }

        return $value ?? '';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}
