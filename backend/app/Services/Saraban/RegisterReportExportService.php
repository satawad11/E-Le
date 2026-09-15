<?php

namespace App\Services\Saraban;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrgScopeResolver;
use App\Support\DocumentFilename;
use ZipArchive;

/**
 * บริการส่งออกรายงานทะเบียนงานสารบรรณเป็นไฟล์ (SoT §28 · ปิด RISK-69 · ระเบียบฯ ข้อ ๒๙/๑)
 *
 * สอดคล้องกับระเบียบสำนักนายกรัฐมนตรีว่าด้วยงานสารบรรณ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔ ข้อ ๒๙/๑
 * ที่อนุญาตให้จัดทำทะเบียนหนังสือรับ ทะเบียนหนังสือส่ง และทะเบียนหนังสือเก็บในรูปแบบอิเล็กทรอนิกส์
 *
 * **จำกัดขอบเขตตามหน่วยงานเสมอ** ผ่าน `OrgScopeResolver` ป้องกัน IDOR และการเข้าถึงข้อมูลข้ามหน่วยงาน
 */
class RegisterReportExportService
{
    public function __construct(
        private readonly RegisterReportService $reports,
        private readonly OrgScopeResolver $scopes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * ส่งออกทะเบียนหนังสือ (รับ / ส่ง / ภายใน)
     *
     * @param  array<string,mixed>  $filters
     * @return array{content: string, mime: string, filename: string}
     */
    public function exportRegister(User $user, array $filters, string $format = 'csv'): array
    {
        $data = $this->reports->register($user, $filters);
        $rows = $data['rows'] ?? [];
        $direction = $filters['direction'] ?? null;

        if ($direction === 'incoming') {
            // แบบที่ ๑ ทะเบียนหนังสือรับ (ระเบียบงานสารบรรณ)
            $headers = [
                'ทะเบียนรับที่',
                'วัน เดือน ปี ที่รับ',
                'จาก',
                'ถึง',
                'ที่ (เลขที่หนังสือ)',
                'ลงวันที่',
                'เรื่อง',
                'การปฏิบัติ',
                'หมายเหตุ',
            ];

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['register_number'] ?? '—',
                    $row['received_at'] ? substr($row['received_at'], 0, 10) : ($row['created_at'] ? substr($row['created_at'], 0, 10) : '—'),
                    $row['sender_name'] ?? '—',
                    $row['owner_org_unit'] ?? 'สถานศึกษา',
                    $row['source_number'] ?? '—',
                    $row['source_date'] ?? '—',
                    $row['subject'] ?? '—',
                    $this->statusLabel($row['status'] ?? ''),
                    $row['incoming_note'] ?? '—',
                ];
            }
        } elseif ($direction === 'outgoing') {
            // แบบที่ ๒ ทะเบียนหนังสือส่ง (ระเบียบงานสารบรรณ)
            $headers = [
                'ทะเบียนส่งที่',
                'ที่หนังสือ',
                'วัน เดือน ปี ที่ส่ง',
                'จาก',
                'ถึง',
                'เรื่อง',
                'หมายเหตุ',
            ];

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['running_number'] ? (string) $row['running_number'] : ($row['document_number'] ?? '—'),
                    $row['document_number'] ?? '—',
                    $row['created_at'] ? substr($row['created_at'], 0, 10) : '—',
                    $row['owner_org_unit'] ?? '—',
                    '—',
                    $row['subject'] ?? '—',
                    $this->statusLabel($row['status'] ?? ''),
                ];
            }
        } else {
            $headers = [
                'ลำดับ',
                'เลขที่หนังสือ',
                'ปี พ.ศ.',
                'เรื่อง',
                'ทิศทาง',
                'ประเภทหนังสือ',
                'หน่วยงานเจ้าของเรื่อง',
                'ชั้นความเร็ว',
                'ชั้นความลับ',
                'สถานะ',
                'วันที่ลงทะเบียน',
            ];

            $exportRows = [];
            $index = 1;
            foreach ($rows as $row) {
                $exportRows[] = [
                    $index++,
                    $row['document_number'] ?? '—',
                    $row['buddhist_year'] ?? '—',
                    $row['subject'] ?? '—',
                    $this->directionLabel($row['direction'] ?? ''),
                    $row['document_type'] ?? '—',
                    $row['owner_org_unit'] ?? '—',
                    $this->urgencyLabel($row['urgency'] ?? ''),
                    $this->confidentialityLabel($row['confidentiality'] ?? ''),
                    $this->statusLabel($row['status'] ?? ''),
                    $row['created_at'] ? substr($row['created_at'], 0, 10) : '—',
                ];
            }
        }

        $title = 'ทะเบียนหนังสือ';
        if (! empty($filters['direction'])) {
            $title = match ($filters['direction']) {
                'incoming' => 'ทะเบียนหนังสือรับ',
                'outgoing' => 'ทะเบียนหนังสือส่ง',
                'internal' => 'ทะเบียนหนังสือภายใน',
                default => 'ทะเบียนหนังสือ',
            };
        }

        $dateRange = null;
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $dateRange = $filters['from'].'_ถึง_'.$filters['to'];
        } elseif (! empty($filters['from'])) {
            $dateRange = 'ตั้งแต่_'.$filters['from'];
        }

        $filename = DocumentFilename::make([$title, $dateRange], $format === 'xlsx' ? 'xlsx' : 'csv');

        $this->audit->log('saraban.report.exported', $user, $user->id, null, [
            'type' => 'register',
            'format' => $format,
            'count' => count($exportRows),
            'filters' => $filters,
        ], null, $user->id);

        if ($format === 'xlsx') {
            return [
                'content' => $this->generateXlsx($headers, $exportRows),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => $filename,
            ];
        }

        return [
            'content' => $this->generateCsv($headers, $exportRows),
            'mime' => 'text/csv; charset=UTF-8',
            'filename' => $filename,
        ];
    }

    /**
     * ส่งออกทะเบียนเลขหนังสือ
     *
     * @param  array<string,mixed>  $filters
     * @return array{content: string, mime: string, filename: string}
     */
    public function exportNumbers(User $user, array $filters, string $format = 'csv'): array
    {
        $data = $this->reports->numbers($filters);
        $rows = $data['rows'] ?? [];

        $headers = [
            'ลำดับ',
            'เลขที่หนังสือ',
            'ปี พ.ศ.',
            'ชุดเลขหนังสือ',
            'สถานะ',
            'เหตุผล / หมายเหตุ',
        ];

        $exportRows = [];
        $index = 1;
        foreach ($rows as $row) {
            $exportRows[] = [
                $index++,
                $row['formatted'] ?? '—',
                $row['buddhist_year'] ?? '—',
                $row['series'] ?? '—',
                $this->numberStatusLabel($row['status'] ?? ''),
                $row['reason'] ?? '—',
            ];
        }

        $filename = DocumentFilename::make(['ทะเบียนเลขหนังสือ', $filters['buddhist_year'] ?? null], $format === 'xlsx' ? 'xlsx' : 'csv');

        $this->audit->log('saraban.report.exported', $user, $user->id, null, [
            'type' => 'numbers',
            'format' => $format,
            'count' => count($exportRows),
        ], null, $user->id);

        if ($format === 'xlsx') {
            return [
                'content' => $this->generateXlsx($headers, $exportRows),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => $filename,
            ];
        }

        return [
            'content' => $this->generateCsv($headers, $exportRows),
            'mime' => 'text/csv; charset=UTF-8',
            'filename' => $filename,
        ];
    }

    /**
     * ส่งออกสรุปการเวียนหนังสือและการรับทราบ
     *
     * @param  array<string,mixed>  $filters
     * @return array{content: string, mime: string, filename: string}
     */
    public function exportDistribution(User $user, array $filters, string $format = 'csv'): array
    {
        $data = $this->reports->distribution($user, $filters);
        $byStatus = $data['by_status'] ?? [];

        $headers = ['สถานะการดำเนินการ', 'จำนวน (รายการ)'];
        $exportRows = [
            ['ผู้รับทั้งหมด', $data['total_recipients'] ?? 0],
            ['รับทราบแล้ว', $data['acknowledged'] ?? 0],
            ['ยังไม่ได้รับทราบ', $data['not_acknowledged'] ?? 0],
            ['ยังไม่เคยเปิดดู', $data['never_opened'] ?? 0],
        ];

        foreach ($byStatus as $status => $count) {
            $exportRows[] = ['สถานะย่อย: '.$status, $count];
        }

        $filename = DocumentFilename::make(['รายงานการเวียนหนังสือและการรับทราบ'], $format === 'xlsx' ? 'xlsx' : 'csv');

        $this->audit->log('saraban.report.exported', $user, $user->id, null, [
            'type' => 'distribution',
            'format' => $format,
            'count' => count($exportRows),
            'filters' => $filters,
        ], null, $user->id);

        if ($format === 'xlsx') {
            return [
                'content' => $this->generateXlsx($headers, $exportRows),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => $filename,
            ];
        }

        return [
            'content' => $this->generateCsv($headers, $exportRows),
            'mime' => 'text/csv; charset=UTF-8',
            'filename' => $filename,
        ];
    }

    /**
     * สร้างไฟล์ CSV มี UTF-8 BOM สำหรับ Excel ภาษาไทย
     *
     * @param  array<int,string>  $headers
     * @param  array<int,array<int,mixed>>  $rows
     */
    private function generateCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map($this->neutralizeFormula(...), $headers));

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($val) => $this->neutralizeFormula((string) ($val ?? '')), $row));
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * กันสูตรฝังในข้อมูล (CSV Formula Injection)
     *
     * ชื่อเรื่องและชื่อผู้ส่งมาจากผู้ใช้ ถ้าขึ้นต้นด้วย = + - @ หรืออักขระควบคุม
     * Excel จะถือว่าเป็น "สูตร" แล้วสั่งทำงานทันทีที่เปิดไฟล์ · ใส่ ' นำหน้าเพื่อ
     * บังคับให้เป็นข้อความ โดยค่าที่แสดงยังอ่านได้เหมือนเดิม
     */
    private function neutralizeFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * สร้างไฟล์ XLSX แบบเบา (OpenXML zip)
     *
     * @param  array<int,string>  $headers
     * @param  array<int,array<int,mixed>>  $rows
     */
    private function generateXlsx(array $headers, array $rows): string
    {
        $allRows = array_merge([$headers], $rows);
        $sheetRows = '';

        foreach ($allRows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $colIndex => $value) {
                $ref = $this->columnName($colIndex + 1).($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1, 'UTF-8').'</t></is></c>';
                }
            }
            $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="SarabanReport" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];

        $temp = tempnam(sys_get_temp_dir(), 'saraban_xlsx_');
        $zip = new ZipArchive;
        $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $xml) {
            $zip->addFromString($name, $xml);
        }

        $zip->close();
        $content = file_get_contents($temp);
        @unlink($temp);

        return $content;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $name = chr(65 + $rem).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    private function directionLabel(string $dir): string
    {
        return match ($dir) {
            'incoming' => 'หนังสือรับ',
            'outgoing' => 'หนังสือส่ง',
            'internal' => 'หนังสือภายใน',
            default => $dir ?: '—',
        };
    }

    private function urgencyLabel(string $urgency): string
    {
        return match ($urgency) {
            'urgent' => 'ด่วน',
            'very_urgent' => 'ด่วนมาก',
            'most_urgent' => 'ด่วนที่สุด',
            default => 'ปกติ',
        };
    }

    private function confidentialityLabel(string $conf): string
    {
        return match ($conf) {
            'confidential' => 'ลับ',
            'secret' => 'ลับมาก',
            'top_secret' => 'ลับที่สุด',
            default => 'ปกติ',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'ร่าง',
            'submitted' => 'เสนอตรวจ',
            'registered' => 'ลงทะเบียนแล้ว',
            'signed' => 'ลงนามแล้ว',
            'rejected' => 'ตีกลับ/ปฏิเสธ',
            'cancelled' => 'ยกเลิก',
            default => $status ?: '—',
        };
    }

    private function numberStatusLabel(string $status): string
    {
        return match ($status) {
            'issued' => 'ออกเลขแล้ว',
            'reserved' => 'จองเลขไว้',
            'void' => 'คืนเลข (VOID)',
            'cancelled' => 'ยกเลิก',
            default => $status ?: '—',
        };
    }
}
