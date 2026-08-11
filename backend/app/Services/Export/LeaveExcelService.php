<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LeaveExcelService
{
    /**
     * Generate native Excel (.xlsx) report for Leave Statistics & Attendance.
     */
    public function generateReportXlsx(array $leaves, string $title = 'รายงานสรุปสถิติการลา'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('รายงานสถิติการลา');

        // Document Header Title
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->setName('TH Sarabun New');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Column Headers
        $headers = [
            'A3' => 'ลำดับ',
            'B3' => 'ชื่อ-นามสกุล',
            'C3' => 'แผนกวิชา / ฝ่าย',
            'D3' => 'ประเภทการลา',
            'E3' => 'วันที่เริ่มลา',
            'F3' => 'วันที่สิ้นสุด',
            'G3' => 'จำนวนวัน',
            'H3' => 'เหตุผลการลา',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
        }

        // Header Styling (Institution Burgundy #800000 background, white text)
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'name' => 'TH Sarabun New',
                'size' => 14,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '800000'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']],
            ],
        ];
        $sheet->getStyle('A3:H3')->applyFromArray($headerStyle);
        $sheet->getRowDimension(3)->setRowHeight(28);

        // Data Rows
        $row = 4;
        foreach ($leaves as $index => $leave) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $leave->user->name ?? '—');
            $sheet->setCellValue('C' . $row, $leave->user->department->name ?? '—');
            $sheet->setCellValue('D' . $row, $leave->type->name ?? '—');
            $sheet->setCellValue('E' . $row, $leave->start_date);
            $sheet->setCellValue('F' . $row, $leave->end_date);
            $sheet->setCellValue('G' . $row, $leave->total_days);
            $sheet->setCellValue('H' . $row, $leave->reason ?? '—');

            // Alignment & Font
            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setName('TH Sarabun New')->setSize(14);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("E{$row}:G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Data Borders
            $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            $row++;
        }

        // Total Row with SUM Formula
        $sheet->setCellValue('A' . $row, 'รวมทั้งสิ้น');
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue('G' . $row, "=SUM(G4:G" . ($row - 1) . ")");
        $sheet->setCellValue('H' . $row, 'วัน');

        $totalStyle = [
            'font' => ['bold' => true, 'name' => 'TH Sarabun New', 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray($totalStyle);

        // Auto-fit Column Widths
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'report_xlsx_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }
}
