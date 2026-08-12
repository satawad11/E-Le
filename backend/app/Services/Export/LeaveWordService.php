<?php

namespace App\Services\Export;

use App\Models\Leave;
use App\Services\ThaiDateService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

class LeaveWordService
{
    protected ThaiDateService $thaiDateService;

    public function __construct(ThaiDateService $thaiDateService)
    {
        $this->thaiDateService = $thaiDateService;
    }

    /**
     * Export individual leave form as native Word (.docx) document.
     */
    public function generateLeaveDocx(Leave $leave, array $settings, array $approverBlocks): string
    {
        $phpWord = new PhpWord;

        // Define default font styles
        $phpWord->setDefaultFontName('TH Sarabun New');
        $phpWord->setDefaultFontSize(16);

        // Define document section margins (Top 2.5cm, Bottom 2cm, Left 3cm, Right 2cm)
        $section = $phpWord->addSection([
            'marginTop' => Converter::cmToTwip(2.5),
            'marginBottom' => Converter::cmToTwip(2.0),
            'marginLeft' => Converter::cmToTwip(3.0),
            'marginRight' => Converter::cmToTwip(2.0),
        ]);

        $leaveTypeName = $leave->type->name ?? 'การลา';
        $userName = $leave->user->name ?? '—';
        $deptName = $leave->user->department->name ?? '—';

        // Title / Header
        $headerP = $section->addParagraphStyle('HeaderP', ['alignment' => Jc::CENTER, 'spaceAfter' => Converter::pointToTwip(12)]);
        $section->addText("แบบใบ{$leaveTypeName}", ['bold' => true, 'size' => 18], 'HeaderP');

        // Document Details Table / Info
        $section->addText('เขียนที่ '.($settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง'), ['size' => 16], ['alignment' => Jc::RIGHT]);

        $dateStr = $leave->created_at ? $this->thaiDateService->toThaiFullDate($leave->created_at->format('Y-m-d')) : '—';
        $section->addText("วันที่ {$dateStr}", ['size' => 16], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);

        $directorName = $settings['director_name'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง';
        $section->addText("เรื่อง  ขอ{$leaveTypeName}", ['bold' => true, 'size' => 16]);
        $section->addText("เรียน  {$directorName}", ['bold' => true, 'size' => 16]);

        $section->addTextBreak(1);

        // Body Content
        $textRun = $section->addTextRun(['spaceAfter' => Converter::pointToTwip(6)]);
        $textRun->addText('        ข้าพเจ้า ');
        $textRun->addText($userName, ['bold' => true]);
        $textRun->addText(' ตำแหน่ง ');
        $textRun->addText($leave->user->profile->position ?? 'บุคลากร', ['bold' => true]);
        $textRun->addText(' สังกัด/แผนกวิชา ');
        $textRun->addText($deptName, ['bold' => true]);

        $textRun2 = $section->addTextRun(['spaceAfter' => Converter::pointToTwip(6)]);
        $textRun2->addText("มีความประสงค์ขอ{$leaveTypeName} เนื่องจาก ");
        $textRun2->addText($leave->reason, ['bold' => true]);

        $startDateStr = $leave->start_date ? $this->thaiDateService->toThaiFullDate($leave->start_date->format('Y-m-d')) : '—';
        $endDateStr = $leave->end_date ? $this->thaiDateService->toThaiFullDate($leave->end_date->format('Y-m-d')) : '—';

        $textRun3 = $section->addTextRun(['spaceAfter' => Converter::pointToTwip(6)]);
        $textRun3->addText('ตั้งแต่วันที่ ');
        $textRun3->addText($startDateStr, ['bold' => true]);
        $textRun3->addText(' ถึงวันที่ ');
        $textRun3->addText($endDateStr, ['bold' => true]);
        $textRun3->addText(' มีกำหนด ');
        $textRun3->addText("{$leave->total_days} วัน", ['bold' => true]);

        if ($leave->contact_address) {
            $section->addText("ในระหว่างลาสามารถติดต่อข้าพเจ้าได้ที่: {$leave->contact_address}", ['size' => 16]);
        }

        $section->addTextBreak(1);

        // Signature section
        $sigSection = $section->addParagraphStyle('SigP', ['alignment' => Jc::RIGHT, 'spaceAfter' => Converter::pointToTwip(6)]);
        $section->addText('(ลงชื่อ).........................................................ผู้ขอลา', ['size' => 16], 'SigP');
        $section->addText("( {$userName} )", ['size' => 16], 'SigP');

        $section->addTextBreak(1);

        // Approver Table
        $tableStyle = [
            'borderColor' => 'CCCCCC',
            'borderSize' => 6,
            'cellMargin' => 80,
        ];
        $phpWord->addTableStyle('ApproverTable', $tableStyle);
        $table = $section->addTable('ApproverTable');

        $table->addRow();
        $table->addCell(4000)->addText('ลำดับการพิจารณา', ['bold' => true, 'size' => 14]);
        $table->addCell(5000)->addText('ผลการพิจารณา / ความเห็น', ['bold' => true, 'size' => 14]);

        foreach ($approverBlocks as $block) {
            $table->addRow();
            $table->addCell(4000)->addText($block['position'] ?? 'ผู้พิจารณา', ['size' => 14]);
            $statusText = $block['status'] === 'Approved' ? 'อนุมัติแล้ว' : ($block['status'] === 'Rejected' ? 'ไม่อนุมัติ' : 'รอการพิจารณา');
            $table->addCell(5000)->addText('ชื่อ: '.($block['name'] ?? '-')."\nสถานะ: {$statusText}\nความเห็น: ".($block['comment'] ?? '-'), ['size' => 14]);
        }

        // Save to temporary file and return path
        $tempFile = tempnam(sys_get_temp_dir(), 'leave_doc_').'.docx';
        $objWriter = IOFactory::createWriter($phpWord, 'Word2006');
        $objWriter->save($tempFile);

        return $tempFile;
    }
}
