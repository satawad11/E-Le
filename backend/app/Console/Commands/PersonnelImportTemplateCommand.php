<?php

namespace App\Console\Commands;

use App\Services\Import\PersonnelImporter;
use App\Support\Spreadsheet\SheetWriter;
use Illuminate\Console\Command;

/**
 * สร้างแม่แบบไฟล์นำเข้า (.xlsx) ที่มีหัวคอลัมน์ครบ 4 แผ่นงาน ให้งานบุคคลกรอก
 */
class PersonnelImportTemplateCommand extends Command
{
    protected $signature = 'personnel:import-template {path=personnel-import-template.xlsx}';

    protected $description = 'สร้างแม่แบบไฟล์ Excel สำหรับนำเข้าหน่วยงาน ตำแหน่ง บุคลากร และการดำรงตำแหน่ง';

    public function handle(SheetWriter $writer): int
    {
        $path = (string) $this->argument('path');
        $writer->write($path, collect(PersonnelImporter::COLUMNS)->map(fn (array $columns) => [$columns])->all());
        $this->info("สร้างแม่แบบที่ {$path}");

        return self::SUCCESS;
    }
}
