<?php

namespace App\Support;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\LegalHold;
use App\Models\Saraban\RetentionSchedule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * พิสูจน์ว่าการอายัดกับการทำลายเอกสารแย่งกันแล้วไม่พัง (SoT §25)
 *
 * **ทำไมต้องเป็นคำสั่งแยก ไม่ใช่เทสต์ธรรมดา** — เหตุผลเดียวกับการออกเลขหนังสือ:
 * ชุดทดสอบรันบน SQLite ซึ่งไม่มี row-level lock จริง เทสต์บนนั้นพิสูจน์ได้แค่ว่า
 * *ลำดับการตรวจ* ถูกต้อง ไม่ได้พิสูจน์ว่าล็อกกันได้จริง
 *
 * **ที่มา (การตรวจทาน 2026-09-05):** `dispose()` เคยตรวจการอายัด **นอก**
 * transaction แล้วจึงลบไฟล์ข้างใน · การอายัดที่มาถึงระหว่างสองจุดนั้นจะไม่มีผล
 * และ **ไฟล์ที่ถูกลบเรียกคืนไม่ได้**
 *
 * **เกณฑ์ผ่าน:** ไม่มีหนังสือฉบับใดที่ลงเอยด้วย "ถูกอายัดอยู่" พร้อมกับ
 * "ไฟล์ถูกทำลายแล้ว" — สองสถานะนี้อยู่ร่วมกันไม่ได้เลย
 */
class DisposalConcurrencyCheck
{
    private const UNIT_CODE = 'CONCURRENCY-CHECK';

    public function run(Command $console, int $documents): int
    {
        $prepared = [];
        $temporaryActor = User::query()->exists()
            ? null
            : User::factory()->create(['email' => 'disposal-race@localhost']);

        try {
            $prepared = $this->prepare($documents);

            $this->race(array_column($prepared, 'document_id'));

            return $this->report($console, $prepared);
        } catch (Throwable $exception) {
            $console->error('ตรวจไม่สำเร็จ: '.$exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->cleanup($prepared);
            $temporaryActor?->delete();
        }
    }

    /**
     * สร้างหนังสือที่ **ครบกำหนดและได้รับอนุมัติให้ทำลายแล้ว** พร้อมไฟล์จริง
     *
     * @return array<int,array{document_id:int,path:string}>
     */
    private function prepare(int $documents): array
    {
        $unit = OrgUnit::query()->firstOrCreate(
            ['code' => self::UNIT_CODE],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'หน่วยงานสำหรับทดสอบการแย่งกัน',
                'type' => 'department',
                'effective_from' => now()->toDateString(),
                'is_active' => true,
            ],
        );

        $type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );

        $actor = User::query()->firstOrFail();
        $disk = Storage::disk('private_uploads');
        $prepared = [];

        for ($i = 0; $i < $documents; $i++) {
            $document = Document::create([
                'document_type_id' => $type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'หนังสือสำหรับทดสอบการแย่งกัน '.($i + 1),
                'owner_org_unit_id' => $unit->id,
                'created_by' => $actor->id,
            ]);

            $path = 'concurrency-check/'.$document->id.'.pdf';
            $disk->put($path, 'concurrency-check fixture '.$document->id);

            DocumentVersion::create([
                'document_id' => $document->id,
                'version_no' => 1,
                'stage' => 'original',
                'pdf_file_path' => $path,
                'file_hash' => hash('sha512', (string) $disk->get($path)),
                'created_by' => $actor->id,
            ]);

            RetentionSchedule::create([
                'document_id' => $document->id,
                'retain_years' => 1,
                'retain_until' => now()->subDay()->toDateString(),
                'status' => RetentionSchedule::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $prepared[] = ['document_id' => $document->id, 'path' => $path];
        }

        return $prepared;
    }

    /**
     * เปิดสอง process ต่อหนึ่งฉบับ **พร้อมกันจริง** — อายัดกับทำลาย
     *
     * เปิดทุก process ให้ครบก่อนแล้วค่อยรอ เพื่อให้ช่วงเวลาทับกันจริง
     * การเปิดแล้วรอทีละคู่ไม่มีการแย่งกันเลย
     *
     * @param  array<int,int>  $documentIds
     */
    private function race(array $documentIds): void
    {
        $handles = [];
        $pipes = [];

        foreach ($documentIds as $documentId) {
            foreach (['hold', 'dispose'] as $mode) {
                $command = implode(' ', array_map('escapeshellarg', [
                    PHP_BINARY,
                    base_path('artisan'),
                    'saraban:disposal-race-worker',
                    (string) $documentId,
                    $mode,
                ]));

                $key = $documentId.':'.$mode;
                $handles[$key] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$key]);
            }
        }

        foreach ($handles as $key => $handle) {
            if (! is_resource($handle)) {
                continue;
            }

            foreach ($pipes[$key] as $pipe) {
                if (is_resource($pipe)) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
            }

            proc_close($handle);
        }
    }

    /** @param array<int,array{document_id:int,path:string}> $prepared */
    private function report(Command $console, array $prepared): int
    {
        $disk = Storage::disk('private_uploads');
        $held = 0;
        $disposed = 0;
        $violations = [];

        foreach ($prepared as $row) {
            $onHold = LegalHold::query()
                ->where('document_id', $row['document_id'])
                ->whereNull('released_at')
                ->exists();

            $isDisposed = RetentionSchedule::query()
                ->where('document_id', $row['document_id'])
                ->where('status', RetentionSchedule::STATUS_DISPOSED)
                ->exists();

            $held += $onHold ? 1 : 0;
            $disposed += $isDisposed ? 1 : 0;

            /*
             * สองอย่างนี้อยู่ร่วมกันไม่ได้ · และไฟล์ต้องหายก็ต่อเมื่อทำลายจริงเท่านั้น
             */
            if ($onHold && $isDisposed) {
                $violations[] = "หนังสือ {$row['document_id']} ถูกอายัดและถูกทำลายพร้อมกัน";
            }

            if ($onHold && ! $disk->exists($row['path'])) {
                $violations[] = "หนังสือ {$row['document_id']} ถูกอายัดแต่ไฟล์หายไปแล้ว";
            }

            if (! $isDisposed && ! $disk->exists($row['path'])) {
                $violations[] = "หนังสือ {$row['document_id']} ไฟล์หายทั้งที่ไม่ได้ถูกทำลายตามกระบวนการ";
            }
        }

        $console->table(['วัด', 'ค่า'], [
            ['หนังสือที่ทดสอบ', count($prepared)],
            ['ลงเอยด้วยการถูกอายัด', $held],
            ['ลงเอยด้วยการถูกทำลาย', $disposed],
            ['สถานะขัดกัน', count($violations)],
        ]);

        if ($violations !== []) {
            foreach (array_slice($violations, 0, 10) as $violation) {
                $console->error($violation);
            }

            return Command::FAILURE;
        }

        $console->info('ผ่าน — ไม่มีหนังสือฉบับใดถูกทำลายทั้งที่ถูกอายัดไว้');

        return Command::SUCCESS;
    }

    /** @param array<int,array{document_id:int,path:string}> $prepared */
    private function cleanup(array $prepared): void
    {
        $disk = Storage::disk('private_uploads');

        foreach ($prepared as $row) {
            try {
                $disk->delete($row['path']);
                LegalHold::query()->where('document_id', $row['document_id'])->delete();
                RetentionSchedule::query()->where('document_id', $row['document_id'])->delete();
                DocumentVersion::query()->where('document_id', $row['document_id'])->delete();
                DB::table('saraban_documents')->where('id', $row['document_id'])->delete();
            } catch (Throwable) {
                // เก็บกวาดไม่สำเร็จไม่ควรกลบผลการตรวจ — ข้อมูลทดสอบอยู่ในหน่วยงานของตัวเอง
            }
        }

        OrgUnit::query()->where('code', self::UNIT_CODE)->delete();
    }
}
