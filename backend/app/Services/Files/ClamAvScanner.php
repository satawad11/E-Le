<?php

namespace App\Services\Files;

use App\Contracts\Files\MalwareScanner;
use Illuminate\Support\Facades\Log;

/**
 * ตรวจไวรัสด้วย clamd ผ่าน TCP (SEC-GAP-02 · SoT §26)
 *
 * ใช้คำสั่ง `INSTREAM` ซึ่งส่งเนื้อไฟล์เข้าไปตรวจในหน่วยความจำ ไม่ต้องเขียนไฟล์
 * ลงดิสก์ที่ทั้งสอง container มองเห็นร่วมกันก่อน — จึงไม่มีช่วงเวลาที่ไฟล์ซึ่ง
 * ยังไม่ผ่านการตรวจ ไปวางอยู่บนพื้นที่จัดเก็บจริง
 *
 * รูปแบบ INSTREAM: ส่งเป็นชุด ๆ ละ `<ความยาว 4 ไบต์ big-endian><ข้อมูล>`
 * แล้วปิดท้ายด้วยความยาวศูนย์ · clamd ตอบกลับบรรทัดเดียว เช่น
 *   `stream: OK`
 *   `stream: Eicar-Signature FOUND`
 *   `INSTREAM size limit exceeded. ERROR`
 *
 * **ทุกความล้มเหลวคืน `unavailable()` ไม่ใช่ `clean()`** ตัวเรียกจะได้ปฏิเสธไฟล์
 * ไว้ก่อน การเผลอคืนว่า "สะอาด" ตอนตัวตรวจล่ม จะกลายเป็นช่องให้อัปโหลดอะไรก็ได้
 */
class ClamAvScanner implements MalwareScanner
{
    /** ขนาดชุดข้อมูลที่ส่งต่อรอบ — ต้องไม่เกิน StreamMaxLength ของ clamd */
    private const CHUNK_BYTES = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds,
    ) {}

    public function scan(string $contents): ScanResult
    {
        $errorNumber = 0;
        $errorMessage = '';

        $socket = @fsockopen($this->host, $this->port, $errorNumber, $errorMessage, $this->timeoutSeconds);

        if ($socket === false) {
            return $this->unavailable(sprintf('เชื่อมต่อ clamd ที่ %s:%d ไม่ได้ (%s)', $this->host, $this->port, $errorMessage !== '' ? $errorMessage : "errno {$errorNumber}"));
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);

            if (@fwrite($socket, "zINSTREAM\0") === false) {
                return $this->unavailable('ส่งคำสั่ง INSTREAM ให้ clamd ไม่สำเร็จ');
            }

            foreach (str_split($contents, self::CHUNK_BYTES) as $chunk) {
                if (@fwrite($socket, pack('N', strlen($chunk)).$chunk) === false) {
                    return $this->unavailable('ส่งข้อมูลให้ clamd ไม่สำเร็จ');
                }
            }

            // ความยาวศูนย์ = จบสตรีม
            @fwrite($socket, pack('N', 0));

            $response = @stream_get_contents($socket);

            if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                return $this->unavailable('clamd ไม่ตอบกลับภายในเวลาที่กำหนด');
            }

            return $this->interpret((string) $response);
        } finally {
            @fclose($socket);
        }
    }

    /**
     * แปลคำตอบบรรทัดเดียวของ clamd ให้เป็นผลการตรวจ
     *
     * คำตอบที่อ่านไม่ออกถือเป็น "ตรวจไม่ได้" ไม่ใช่ "สะอาด" — ถ้า clamd
     * เปลี่ยนรูปแบบคำตอบในรุ่นถัดไป ระบบต้องปฏิเสธไฟล์ไว้ก่อน ไม่ใช่ปล่อยผ่านทั้งหมด
     */
    private function interpret(string $response): ScanResult
    {
        $line = trim($response);

        if ($line === '') {
            return $this->unavailable('clamd ตอบกลับเป็นค่าว่าง');
        }

        if (str_ends_with($line, 'ERROR')) {
            return $this->unavailable('clamd แจ้งข้อผิดพลาด: '.$line);
        }

        if (str_ends_with($line, 'OK')) {
            return ScanResult::clean();
        }

        if (preg_match('/:\s*(.+?)\s+FOUND$/', $line, $matches) === 1) {
            return ScanResult::infected($matches[1]);
        }

        return $this->unavailable('อ่านคำตอบของ clamd ไม่ออก: '.$line);
    }

    /**
     * บันทึกเหตุที่ตรวจไม่ได้ไว้เสมอ
     *
     * ผู้ใช้เห็นแค่ว่า "ระบบตรวจยังไม่พร้อม" ซึ่งไม่ควรเปิดเผยรายละเอียดภายใน
     * แต่ฝ่ายดูแลระบบต้องเห็นสาเหตุจริง มิฉะนั้นตัวตรวจล่มค้างไว้ได้โดยไม่มีใครรู้
     */
    private function unavailable(string $reason): ScanResult
    {
        Log::warning('ด่านตรวจไวรัสใช้งานไม่ได้', ['reason' => $reason]);

        return ScanResult::unavailable($reason);
    }
}
