<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * ตรวจความต่อเนื่องของโซ่แฮชในประวัติการดำเนินการ (SEC-GAP-03 · SoT §25)
 *
 * โซ่แฮชจะมีประโยชน์ก็ต่อเมื่อมีคนตรวจจริง — ถ้าไม่มีคำสั่งนี้ การแก้ประวัติ
 * ย้อนหลังก็ยังไม่มีใครรู้อยู่ดี ต่างกันแค่ว่ามีคอลัมน์เพิ่มมาสองช่อง
 *
 * คืน exit code 1 เมื่อพบความผิดปกติ จึงนำไปใส่ scheduler หรือ CI ได้
 */
class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify {--chunk=500 : จำนวนแถวต่อรอบ}';

    protected $description = 'ตรวจว่าประวัติการดำเนินการไม่ถูกแก้ไขย้อนหลัง';

    public function handle(): int
    {
        $problems = [];
        $checked = 0;
        $previousHash = null;
        $startedChain = false;

        AuditLog::query()
            ->orderBy('id')
            ->chunk((int) $this->option('chunk'), function ($entries) use (&$problems, &$checked, &$previousHash, &$startedChain) {
                foreach ($entries as $entry) {
                    /*
                     * แถวที่บันทึกไว้ก่อนเริ่มลงแฮชจะไม่มี `entry_hash`
                     * ข้ามไปโดยไม่ถือว่าผิด — ระบบรับรองได้เฉพาะช่วงที่เริ่มลงแฮชแล้ว
                     * แต่เมื่อโซ่เริ่มแล้ว แถวที่ไม่มีแฮชถือว่าผิดปกติ
                     */
                    if ($entry->entry_hash === null) {
                        if ($startedChain) {
                            $problems[] = "แถว #{$entry->id} ไม่มีแฮชทั้งที่โซ่เริ่มไปแล้ว";
                        }

                        continue;
                    }

                    $startedChain = true;
                    $checked++;

                    if ($entry->previous_hash !== $previousHash) {
                        $problems[] = "แถว #{$entry->id} ชี้ไปยังแถวก่อนหน้าไม่ตรงกับลำดับจริง (โซ่ขาด — อาจมีแถวถูกลบ)";
                    }

                    $expected = AuditLog::hashFor($entry->attributesToArray(), $entry->previous_hash);

                    if (! hash_equals($expected, (string) $entry->entry_hash)) {
                        $problems[] = "แถว #{$entry->id} มีเนื้อหาไม่ตรงกับแฮชที่บันทึกไว้ (ถูกแก้ย้อนหลัง)";
                    }

                    $previousHash = $entry->entry_hash;
                }
            });

        if ($problems !== []) {
            $this->error(sprintf('พบความผิดปกติในประวัติการดำเนินการ %d รายการ:', count($problems)));

            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('ประวัติการดำเนินการต่อเนื่องครบถ้วน ตรวจแล้ว %d แถว', $checked));

        return self::SUCCESS;
    }
}
