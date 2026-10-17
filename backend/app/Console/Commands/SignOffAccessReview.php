<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCommandUsers;
use App\Services\AccessReviewService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * ผู้อำนวยการรับรองผลการทบทวนสิทธิ์ประจำปีการศึกษา (ACCESS-GOV-01)
 *
 * ตัวอย่าง: php artisan access:review-signoff 2569 --by=director@college.ac.th --note="ถอนสิทธิ์ตามรายการแนบแล้ว"
 */
class SignOffAccessReview extends Command
{
    use ResolvesCommandUsers;

    protected $signature = 'access:review-signoff
        {year : ปีการศึกษา พ.ศ. เช่น 2569}
        {--by= : อีเมลหรือ UUID ของผู้อำนวยการหรือผู้รักษาการ}
        {--note= : หมายเหตุผลการทบทวน}';

    protected $description = 'รับรองผลการทบทวนสิทธิ์ผู้ใช้ประจำปีการศึกษา';

    public function handle(AccessReviewService $reviews): int
    {
        try {
            $actor = $this->findUser((string) $this->option('by'), 'ผู้รับรอง');
            $reviews->signOff($actor, (string) $this->argument('year'), $this->option('note'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("รับรองการทบทวนสิทธิ์ปีการศึกษา {$this->argument('year')} แล้ว");

        return self::SUCCESS;
    }
}
