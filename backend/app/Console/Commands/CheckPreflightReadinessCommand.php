<?php

namespace App\Console\Commands;

use App\Services\Operations\PreflightReadinessChecker;
use Illuminate\Console\Command;

class CheckPreflightReadinessCommand extends Command
{
    protected $signature = 'app:preflight-check {--detailed : แสดงรายละเอียดรายชื่อและรายการที่ตรวจพบ}';

    protected $description = 'ตรวจความพร้อมของข้อมูลตั้งต้นทั้งระบบก่อนเปิดใช้งานจริง (Data Onboarding Pre-flight Check)';

    public function handle(PreflightReadinessChecker $checker): int
    {
        $this->output->writeln('<fg=cyan>========================================================================</>');
        $this->output->writeln('<fg=cyan>  ระบบบริหารงานสถานศึกษา E-La — ตรวจความพร้อมข้อมูลตั้งต้น (Pre-flight)</>');
        $this->output->writeln('<fg=cyan>  วันเวลา: </fg=cyan>'.now()->format('Y-m-d H:i:s').' <fg=cyan>สภาพแวดล้อม: </fg=cyan>'.app()->environment());
        $this->output->writeln('<fg=cyan>========================================================================</>');

        $result = $checker->check();
        $summary = $result['summary'];
        $checks = $result['checks'];

        $rows = [];
        foreach ($checks as $r) {
            $tag = match ($r['status']) {
                'PASS' => '<fg=green;options=bold>[ ผ่าน ]</>',
                'WARN' => '<fg=yellow;options=bold>[ แจ้งเตือน ]</>',
                'FAIL' => '<fg=red;options=bold>[ ต้องแก้ไข ]</>',
                default => $r['status'],
            };

            $detail = $r['message'];
            if ($r['advice'] && ($this->option('detailed') || $r['status'] !== 'PASS')) {
                $detail .= "\n<fg=gray>คำแนะนำ: {$r['advice']}</>";
            }

            $rows[] = [$tag, $r['category'], $detail];
        }

        $this->newLine();
        $this->table(['ผลการตรวจ', 'หัวข้อการตรวจสอบ', 'รายละเอียดและคำแนะนำ'], $rows);

        $this->newLine();
        $this->output->writeln('<fg=cyan>------------------------------------------------------------------------</>');
        if ($summary['fail_count'] === 0 && $summary['warn_count'] === 0) {
            $this->output->writeln('<fg=green;options=bold>  สรุป: ข้อมูลตั้งต้นมีความพร้อม 100% สำหรับการเปิดใช้งานจริง (Ready to Go-Live!)</>');
        } elseif ($summary['fail_count'] === 0) {
            $this->output->writeln("<fg=yellow;options=bold>  สรุป: ผ่านเกณฑ์หลัก แต่พบข้อแนะนำปรับปรุง {$summary['warn_count']} รายการ (ระบบสามารถเปิดใช้งานได้)</>");
        } else {
            $this->output->writeln("<fg=red;options=bold>  สรุป: พบจุดที่ต้องแก้ไขก่อนเปิดใช้งานจริง {$summary['fail_count']} รายการ และข้อแนะนำ {$summary['warn_count']} รายการ</>");
        }
        $this->output->writeln('<fg=cyan>------------------------------------------------------------------------</>');

        return $summary['fail_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
