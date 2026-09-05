<?php

namespace App\Console\Commands;

use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Support\DisposalConcurrencyCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * INT-20 — พิสูจน์ว่าการออกเลขหนังสือทนการแย่งกันจริง (ปิด RISK-22 · SoT §9)
 *
 * **ทำไมต้องเป็นคำสั่งแยก ไม่ใช่เทสต์ธรรมดา**
 *
 * ชุดทดสอบรันบน SQLite `:memory:` ซึ่งเป็นฐานเดียวต่อหนึ่ง process และไม่มี
 * row-level lock จริง — เทสต์ที่ "ผ่าน" บนนั้นไม่ได้พิสูจน์อะไรเลยเกี่ยวกับ
 * การแย่งกันบน MySQL ซึ่งเป็นฐานที่ใช้จริง
 *
 * INT-06 เคยพิสูจน์เรื่องนี้ด้วยมือ (10 process × 20 ครั้ง = 200 เลขไม่ซ้ำ)
 * แต่การพิสูจน์ครั้งเดียวแล้วไม่มีใครรันอีกคือหลักฐานที่หมดอายุ · คำสั่งนี้
 * ทำให้ CI รันซ้ำได้ทุกครั้ง
 *
 * **รันบนฐานทดสอบเท่านั้น** — สร้างชุดเลขของตัวเองแล้วลบทิ้ง และปฏิเสธ
 * ที่จะทำงานบน production
 */
class CheckNumberingConcurrencyCommand extends Command
{
    protected $signature = 'saraban:concurrency-check
                            {--workers=8 : จำนวน process ที่แย่งกันออกเลขพร้อมกัน}
                            {--per-worker=15 : จำนวนเลขที่แต่ละ process ขอ}
                            {--disposal : ตรวจการแย่งกันระหว่างการอายัดกับการทำลายแทนการออกเลข}
                            {--documents=10 : จำนวนหนังสือที่ใช้ทดสอบเมื่อใช้ --disposal}';

    protected $description = 'พิสูจน์ว่าการออกเลขหนังสือและการทำลายเอกสารทนการแย่งกันจริง';

    private const SERIES_CODE = 'concurrency-check';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('คำสั่งนี้สร้างข้อมูลทดสอบ จึงรันบน production ไม่ได้');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->warn('ข้าม — SQLite ไม่มี row-level lock จริง การรันบนนี้ไม่พิสูจน์อะไร');

            return self::SUCCESS;
        }

        if ($this->option('disposal')) {
            return app(DisposalConcurrencyCheck::class)->run($this, max(1, (int) $this->option('documents')));
        }

        $workers = max(2, (int) $this->option('workers'));
        $perWorker = max(1, (int) $this->option('per-worker'));
        $expected = $workers * $perWorker;

        $series = $this->prepareSeries();

        /*
         * ต้องมีผู้ใช้อย่างน้อยหนึ่งคนเพราะการจองเลขบันทึกว่าใครจอง
         *
         * บน CI ที่เพิ่ง `migrate` จากฐานว่าง ยังไม่มีผู้ใช้เลย — สร้างให้เองแล้ว
         * ลบทิ้ง แทนที่จะบังคับให้ pipeline ต้อง seed ทั้งระบบก่อน
         */
        $temporaryActor = User::query()->exists()
            ? null
            : User::factory()->create(['email' => 'concurrency-check@localhost']);

        try {
            $this->runWorkers($series->id, $workers, $perWorker);

            $issued = DocumentNumber::query()->where('number_series_id', $series->id)->pluck('running_number');
            $unique = $issued->unique();

            $this->table(['วัด', 'ค่า'], [
                ['ขอทั้งหมด', $expected],
                ['ได้รับจริง', $issued->count()],
                ['ไม่ซ้ำกัน', $unique->count()],
                ['เลขต่ำสุด', $issued->min()],
                ['เลขสูงสุด', $issued->max()],
            ]);

            /*
             * เกณฑ์ผ่านสามข้อพร้อมกัน — ขาดข้อใดข้อหนึ่งแปลว่ามีปัญหาคนละแบบ:
             *   ได้ครบ      = ไม่มีคำขอไหนล้มเหลวเงียบ ๆ
             *   ไม่ซ้ำ      = ไม่มีสองคนได้เลขเดียวกัน (อันตรายที่สุด)
             *   ไม่มีช่องว่าง = ไม่มีเลขหายไปโดยไม่มีใครถือ
             */
            $ok = $issued->count() === $expected
                && $unique->count() === $expected
                && $issued->max() === $expected;

            if (! $ok) {
                $this->error('ล้มเหลว — การออกเลขไม่ทนการแย่งกัน');

                return self::FAILURE;
            }

            $this->info("ผ่าน — {$workers} process ขอพร้อมกันได้เลขไม่ซ้ำครบ {$expected} เลข");

            return self::SUCCESS;
        } finally {
            $this->cleanup($series);
            $temporaryActor?->delete();
        }
    }

    private function prepareSeries(): NumberSeries
    {
        $this->cleanup(NumberSeries::query()->where('code', self::SERIES_CODE)->first());

        return NumberSeries::create([
            'code' => self::SERIES_CODE,
            'name' => 'ชุดเลขสำหรับทดสอบการแย่งกัน',
            'category' => DocumentType::CATEGORY_INTERNAL,
            'prefix' => 'TEST',
        ]);
    }

    /**
     * เปิด process จริงหลายตัวพร้อมกัน — **ไม่ใช่ loop ใน process เดียว**
     *
     * การวนใน process เดียวไม่มีการแย่งกันเลย ต่อให้เขียนโค้ดผิดก็ผ่าน
     */
    private function runWorkers(int $seriesId, int $workers, int $perWorker): void
    {
        $handles = [];

        for ($i = 0; $i < $workers; $i++) {
            $command = implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                base_path('artisan'),
                'saraban:concurrency-worker',
                (string) $seriesId,
                (string) $perWorker,
            ]));

            $handles[] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }

        foreach ($handles as $index => $handle) {
            if (! is_resource($handle)) {
                continue;
            }

            foreach ($pipes[$index] as $pipe) {
                if (is_resource($pipe)) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
            }

            proc_close($handle);
        }
    }

    private function cleanup(?NumberSeries $series): void
    {
        if ($series === null) {
            return;
        }

        try {
            DocumentNumber::query()->where('number_series_id', $series->id)->delete();
            DB::table('saraban_number_sequences')->where('number_series_id', $series->id)->delete();
            $series->delete();
        } catch (Throwable $exception) {
            $this->warn('ลบข้อมูลทดสอบไม่สำเร็จ: '.$exception->getMessage());
        }
    }
}
