<?php

namespace App\Console\Commands;

use App\Services\Import\PersonnelImporter;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Console\Command;

/**
 * R03-5 — นำเข้าหน่วยงาน ตำแหน่ง บุคลากร และการดำรงตำแหน่งจาก .xlsx/.csv
 *
 * ค่าเริ่มต้นเป็น dry-run (ไม่บันทึก) · ใส่ --commit เพื่อบันทึก ซึ่งจะบันทึกเมื่อไม่มีข้อผิดพลาดเลยเท่านั้น
 */
class ImportPersonnelCommand extends Command
{
    protected $signature = 'personnel:import {file : ไฟล์ .xlsx (แผ่นงาน org_units, positions, personnel, assignments) หรือ .csv}
        {--sheet= : ชื่อแผ่นงานของไฟล์ .csv (org_units, positions, personnel, assignments)}
        {--commit : บันทึกจริง (ค่าเริ่มต้นตรวจอย่างเดียว)}';

    protected $description = 'นำเข้าข้อมูลบุคลากรจาก Excel พร้อม dry-run และรายงานข้อผิดพลาดรายแถว';

    public function handle(PersonnelImporter $importer): int
    {
        $file = (string) $this->argument('file');
        $sheet = $this->option('sheet');

        $sheets = app(SheetReader::class)->read($file, $sheet ? (string) $sheet : null);
        $result = $importer->import($sheets, (bool) $this->option('commit'));

        $this->table(['แผ่นงาน', 'ทั้งหมด', 'สร้าง', 'ข้าม (มีอยู่แล้ว)', 'ผิดพลาด'], collect($result['sheets'])->map(
            fn ($s, $name) => [$name, $s['total'], $s['created'], $s['skipped'], count($s['errors'])]
        )->values()->all());

        foreach ($result['sheets'] as $name => $s) {
            foreach ($s['errors'] as $error) {
                $this->line("  <fg=red>{$name} แถว {$error['row']}</> {$error['message']}");
            }
        }

        if ($result['committed']) {
            $this->info('บันทึกข้อมูลเรียบร้อย');
        } elseif ($result['dry_run']) {
            $this->warn($result['error_count'] === 0
                ? 'ตรวจแล้วไม่พบข้อผิดพลาด (dry-run ยังไม่บันทึก) — รันซ้ำด้วย --commit เพื่อบันทึก'
                : "พบข้อผิดพลาด {$result['error_count']} รายการ (dry-run ไม่บันทึก)");
        } else {
            $this->error("พบข้อผิดพลาด {$result['error_count']} รายการ จึงไม่บันทึกข้อมูลใดเลย");
        }

        return $result['error_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
