<?php

namespace App\Console\Commands;

use App\Services\DocumentEngine\LeaveFormGenerator;
use Illuminate\Console\Command;

/**
 * สร้างแบบฟอร์มใบลาสำหรับหน้าออกแบบ (Visual Designer) จากแบบฟอร์มในระบบ
 *
 * แต่ละแบบฟอร์มได้แม่แบบ `designer_<code>` ฉบับร่าง: พื้นหลังเป็นแบบฟอร์มเปล่า (ไม่มีชื่อหน่วยงานติดอยู่)
 * และวางฟิลด์ครบทุกช่องตรงตำแหน่งแล้ว — ผู้ดูแลเปิดหน้าออกแบบปรับแต่งแล้วเผยแพร่
 * จากนั้นเปิดใช้งานรายประเภทการลาที่ ศูนย์จัดการเอกสาร → แบบฟอร์มใบลา
 *
 * รันซ้ำได้: สร้างเป็นเวอร์ชันใหม่เสมอ ไม่แตะเวอร์ชันที่เผยแพร่อยู่
 */
class GenerateLeaveDesignerForms extends Command
{
    protected $signature = 'document:generate-leave-forms
        {--code=* : เจาะจงรหัสแบบฟอร์ม เช่น leave_vacation (ไม่ระบุ = ทุกแบบฟอร์ม)}
        {--skip-existing : ข้ามแบบฟอร์มที่สร้างไว้แล้ว}
        {--publish : เผยแพร่เวอร์ชันที่สร้างทันที (ยังไม่เปิดใช้กับประเภทการลาจนกว่าจะเปิดสวิตช์)}';

    protected $description = 'สร้างแบบฟอร์มใบลาสำหรับหน้าออกแบบ (Visual Designer) จากแบบฟอร์มในระบบ';

    public function handle(LeaveFormGenerator $generator): int
    {
        $codes = $this->option('code') ?: $generator->codes();
        $unknown = array_diff($codes, $generator->codes());

        if ($unknown !== []) {
            $this->error('ไม่รู้จักรหัสแบบฟอร์ม: '.implode(', ', $unknown).' — ที่มี: '.implode(', ', $generator->codes()));

            return self::FAILURE;
        }

        foreach ($codes as $code) {
            if ($this->option('skip-existing') && $generator->templateFor($code) !== null) {
                $this->line("ข้าม {$code}: สร้างไว้แล้ว");

                continue;
            }

            $version = $generator->generate($code, publish: (bool) $this->option('publish'));
            $this->info(sprintf(
                '%s → %s เวอร์ชัน %d (%s, %d ฟิลด์)',
                $code,
                $version->template->code,
                $version->version,
                $version->status,
                $version->fieldMappings()->count(),
            ));
        }

        $this->newLine();
        $this->comment('ขั้นตอนถัดไป: ศูนย์จัดการเอกสาร → แบบฟอร์มใบลา → ออกแบบ/เผยแพร่ → เปิดใช้งานรายประเภทการลา');

        return self::SUCCESS;
    }
}
