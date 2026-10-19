<?php

namespace Tests\Feature\Core;

use App\Support\Spreadsheet\SheetReader;
use App\Support\Spreadsheet\SheetWriter;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * R-04 แก้ตามผลตรวจ R-03 — SheetReader ตรวจขนาดรวมหลังแตกไฟล์ก่อนอ่าน (กัน zip bomb)
 */
class SheetReaderSafetyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'bomb').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_default_limit_is_50_mb(): void
    {
        $this->assertSame(50 * 1024 * 1024, SheetReader::MAX_UNCOMPRESSED_BYTES);
    }

    public function test_archive_that_expands_beyond_the_limit_is_rejected_before_reading(): void
    {
        app(SheetWriter::class)->write($this->path, ['personnel' => [['personnel_code'], ['T1']]]);
        $zip = new ZipArchive;
        $zip->open($this->path);
        $zip->addFromString('xl/media/padding.bin', str_repeat("\0", 3 * 1024 * 1024)); // บีบอัดเหลือไม่กี่ KB
        $zip->close();
        $this->assertLessThan(200 * 1024, filesize($this->path));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ใหญ่เกินกำหนด');
        (new SheetReader(maxUncompressedBytes: 2 * 1024 * 1024))->read($this->path);
    }

    public function test_too_many_entries_are_rejected(): void
    {
        app(SheetWriter::class)->write($this->path, ['personnel' => [['personnel_code'], ['T1']]]);
        $zip = new ZipArchive;
        $zip->open($this->path);
        for ($i = 0; $i < 30; $i++) {
            $zip->addFromString("xl/extra/{$i}.xml", '<x/>');
        }
        $zip->close();

        $this->expectException(RuntimeException::class);
        (new SheetReader(maxEntries: 20))->read($this->path);
    }

    public function test_normal_workbook_within_limits_still_reads(): void
    {
        app(SheetWriter::class)->write($this->path, ['personnel' => [['personnel_code'], ['T1']]]);

        $this->assertSame([2 => ['personnel_code' => 'T1']], (new SheetReader(maxUncompressedBytes: 1024 * 1024))->read($this->path)['personnel']);
    }
}
