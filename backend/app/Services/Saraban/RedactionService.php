<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\Redaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

/**
 * สำเนาที่ปกปิดข้อมูล (SoT §22)
 *
 * **การวาดกล่องทึบทับข้อความไม่ใช่การปกปิด**
 *
 * นี่คือความผิดพลาดที่หน่วยงานราชการทั่วโลกเคยทำจนข้อมูลลับหลุด — ข้อความ
 * ยังอยู่ใต้กล่องและคัดลอกออกมาได้ด้วยการลากเลือกใน PDF reader ธรรมดา
 * กล่องดำเป็นแค่ภาพวาดที่วางทับ ไม่ได้ลบอะไรเลย
 *
 * บริการนี้จึงทำสองขั้นเสมอ:
 *
 *   1. วาดกล่องทึบด้วย FPDI ตามพิกัดที่ผู้ใช้เลือก
 *   2. **แปลงทั้งแผ่นเป็นภาพด้วย Ghostscript (`pdfimage24`)** ซึ่งทำลาย
 *      ชั้นข้อความทั้งไฟล์อย่างถาวร รวมถึงข้อความที่อยู่ใต้กล่อง
 *
 * ขั้นที่ 2 ทำให้สำเนาเลือกข้อความไม่ได้อีก ซึ่งเป็นราคาที่ต้องจ่าย และเป็น
 * พฤติกรรมที่ถูกต้องสำหรับสำเนาที่ปล่อยออกไปข้างนอก
 *
 * **ถ้าขั้นที่ 2 ทำไม่ได้ จะไม่สร้างสำเนาเลย** — สำเนาที่ปกปิดไม่จริงอันตราย
 * กว่าการไม่มีสำเนา เพราะผู้ใช้จะเชื่อว่ามันปลอดภัยแล้วส่งออกไป
 */
class RedactionService
{
    private const FILE_DIRECTORY = 'saraban/redactions';

    /** ความละเอียดของภาพที่แปลง — พออ่านและพิมพ์ได้ โดยไฟล์ไม่ใหญ่เกินไป */
    private const RASTER_DPI = 150;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Ghostscript พร้อมใช้งานหรือไม่ — ตัวตัดสินว่าปกปิดข้อมูลได้จริงไหม */
    public function available(): bool
    {
        return is_executable('/usr/bin/gs') || is_executable('/usr/local/bin/gs');
    }

    /**
     * สร้างสำเนาที่ปกปิดข้อมูลจากเวอร์ชันหนึ่งของหนังสือ
     *
     * @param  array<int,array{page:int,x:float,y:float,width:float,height:float}>  $areas
     *
     * @throws RedactionException
     */
    public function redact(Document $document, DocumentVersion $version, array $areas, string $reason, User $actor): Redaction
    {
        if (! $this->available()) {
            throw new RedactionException(
                'ระบบยังติดตั้งเครื่องมือแปลงเอกสารไม่ครบ จึงปกปิดข้อมูลอย่างปลอดภัยไม่ได้ '
                .'— การวาดกล่องทับอย่างเดียวไม่ลบข้อความที่อยู่ใต้กล่อง กรุณาแจ้งผู้ดูแลระบบ',
            );
        }

        if ($areas === []) {
            throw new RedactionException('ต้องระบุพื้นที่ที่ต้องการปกปิดอย่างน้อยหนึ่งจุด');
        }

        $source = Storage::disk('private_uploads')->path($version->pdf_file_path);

        if (! is_file($source)) {
            throw new RedactionException('ไม่พบไฟล์ต้นทางของเวอร์ชันนี้');
        }

        $boxed = $this->drawBoxes($source, $areas);

        try {
            $flattened = $this->flatten($boxed);
        } finally {
            @unlink($boxed);
        }

        try {
            $contents = (string) file_get_contents($flattened);
        } finally {
            @unlink($flattened);
        }

        return DB::transaction(function () use ($document, $version, $areas, $reason, $actor, $contents) {
            $path = self::FILE_DIRECTORY.'/'.Str::uuid().'.pdf';
            Storage::disk('private_uploads')->put($path, $contents);

            $redaction = Redaction::create([
                'document_id' => $document->id,
                'source_version_id' => $version->id,
                'reason' => $reason,
                'areas' => $areas,
                'pdf_file_path' => $path,
                'file_hash' => hash('sha512', $contents),
                'created_by' => $actor->id,
            ]);

            /*
             * บันทึกเหตุผลและ hash ลง audit กลาง (SoT §22) — การปกปิดข้อมูล
             * ราชการต้องตอบได้เสมอว่าใครปกปิดอะไร เพราะอะไร และไฟล์ที่ปล่อย
             * ออกไปคือไฟล์ไหน
             */
            $this->audit->log('saraban.document.redacted', $document, $document->id, null, [
                'redaction_uuid' => $redaction->uuid,
                'source_version_no' => $version->version_no,
                'reason' => $reason,
                'area_count' => count($areas),
                'file_hash' => $redaction->file_hash,
            ], $reason, $actor->id);

            return $redaction;
        });
    }

    /**
     * ขั้นที่ 1 — วาดกล่องทึบตามพิกัดที่เลือก
     *
     * พิกัดเป็น PDF point จากมุมซ้ายบน เหมือน `saraban_document_annotations`
     * ของ INT-10 — ใช้ระบบพิกัดเดียวกันทั้งโมดูล
     *
     * @param  array<int,array{page:int,x:float,y:float,width:float,height:float}>  $areas
     */
    private function drawBoxes(string $source, array $areas): string
    {
        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($source);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            $pdf->SetFillColor(0, 0, 0);

            foreach ($areas as $area) {
                if ((int) ($area['page'] ?? 1) !== $page) {
                    continue;
                }

                $pdf->Rect(
                    (float) $area['x'],
                    (float) $area['y'],
                    (float) $area['width'],
                    (float) $area['height'],
                    'F',
                );
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'redact-boxed-').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    /**
     * ขั้นที่ 2 — แปลงทั้งแผ่นเป็นภาพเพื่อทำลายชั้นข้อความ
     *
     * `pdfimage24` เรนเดอร์แต่ละหน้าเป็นภาพแล้วห่อกลับเป็น PDF · ผลคือไฟล์ที่
     * **ไม่มีชั้นข้อความเหลืออยู่เลย** ข้อความใต้กล่องดำจึงหายไปจริง ไม่ใช่แค่
     * ถูกบัง
     *
     * @throws RedactionException
     */
    private function flatten(string $source): string
    {
        $target = tempnam(sys_get_temp_dir(), 'redact-flat-').'.pdf';

        /*
         * ใช้ `exec` แทน symfony/process เพราะโครงการไม่ได้ติดตั้งแพ็กเกจนั้น
         * และไม่คุ้มที่จะเพิ่ม dependency เพื่อเรียกคำสั่งเดียว
         *
         * ทุกอาร์กิวเมนต์ผ่าน `escapeshellarg` — เส้นทางไฟล์มาจาก `tempnam`
         * และ storage ของระบบ ไม่ใช่จากผู้ใช้ แต่การไม่ escape ไว้ก่อนคือหนี้
         * ที่รอวันมีคนส่งค่าจากภายนอกเข้ามา
         */
        $command = implode(' ', array_map('escapeshellarg', [
            $this->ghostscript(),
            '-sDEVICE=pdfimage24',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-r'.self::RASTER_DPI,
            '-sOutputFile='.$target,
            $source,
        ])).' 2>&1';

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! is_file($target) || filesize($target) === 0) {
            @unlink($target);

            throw new RedactionException('แปลงสำเนาที่ปกปิดไม่สำเร็จ — ไม่ได้สร้างไฟล์ใด ๆ ออกมา');
        }

        return $target;
    }

    private function ghostscript(): string
    {
        return is_executable('/usr/bin/gs') ? '/usr/bin/gs' : '/usr/local/bin/gs';
    }
}
