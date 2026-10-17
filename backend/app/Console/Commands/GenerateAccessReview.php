<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCommandUsers;
use App\Services\AccessReviewService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * สร้างรายงานทบทวนสิทธิ์ประจำปีการศึกษา (ACCESS-GOV-01)
 *
 * ตัวอย่าง: php artisan access:review 2569 --by=admin@college.ac.th
 * รายงานอยู่ที่ storage/app/private/access-reviews/2569.csv (ไม่เปิดผ่าน URL)
 */
class GenerateAccessReview extends Command
{
    use ResolvesCommandUsers;

    protected $signature = 'access:review
        {year : ปีการศึกษา พ.ศ. เช่น 2569}
        {--by= : อีเมลหรือ UUID ของผู้ดูแลที่สร้างรายงาน (ต้องมีสิทธิ์จัดการสิทธิ์ผู้ใช้)}';

    protected $description = 'สร้างรายงานทบทวนสิทธิ์ผู้ใช้ประจำปีการศึกษา';

    public function handle(AccessReviewService $reviews): int
    {
        try {
            $actor = $this->findUser((string) $this->option('by'), 'ผู้สร้างรายงาน');
            $result = $reviews->generate($actor, (string) $this->argument('year'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("สร้างรายงานทบทวนสิทธิ์ปีการศึกษา {$this->argument('year')} แล้ว: ผู้ใช้ {$result['user_count']} คน · สิทธิ์สูง {$result['privileged_count']} คน");
        $this->line("ไฟล์: storage/app/private/{$result['path']}");
        $this->line('เมื่อหัวหน้างานตรวจแล้ว ให้ผู้อำนวยการรับรองด้วย access:review-signoff');

        return self::SUCCESS;
    }
}
