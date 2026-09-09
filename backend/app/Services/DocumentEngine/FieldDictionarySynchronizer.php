<?php

namespace App\Services\DocumentEngine;

use App\Models\ApprovalStep;
use App\Models\Document\DocumentFieldDefinition;
use App\Models\LeaveType;
use App\Services\DocumentEngine\Providers\LeaveRequestDataProvider;
use App\Services\LeaveDocumentFields;
use Illuminate\Support\Facades\DB;

/**
 * ทำให้ Field Dictionary ตรงกับสิ่งที่ระบบมีค่าให้จริง ณ ตอนนั้น
 *
 * เดิมพจนานุกรมฟิลด์ถูกเติมครั้งเดียวตอน seed จาก catalog ของ provider เท่านั้น
 * ค่าที่ระบบสร้างให้อยู่แล้วแต่ไม่ได้ประกาศไว้จึงวางบนแบบฟอร์มไม่ได้เลย ได้แก่
 *
 *   - ช่องเฉพาะของประเภทการลา (`leave_types.extra_fields`) → `extra.<คีย์>`
 *   - ผู้อนุมัติรายขั้นตามสายอนุมัติจริง → `approval.step{N}.*`
 *   - สถิติการลารายประเภทที่แบบฟอร์มราชการมีเป็นตาราง → `stats.leave_type_<id>.*`
 *
 * ตรงข้ามกัน ฟิลด์ที่เป็น "ก้อนข้อมูล" (`stats`, `extra`) ถูกปิดในพจนานุกรมของแบบฟอร์ม PDF
 * เพราะวางเป็นช่องเดียวแล้วพิมพ์ออกมาไม่ได้ — แม่แบบ HTML ยังใช้ได้เหมือนเดิมเพราะอ่านจาก
 * `LeaveDocumentFields::catalog()` คนละเส้นทางกัน
 *
 * ทำงานแบบ idempotent เรียกซ้ำได้เสมอ และไม่ลบฟิลด์ที่มีอยู่ทิ้ง (ปิดการใช้งานแทน)
 * เพื่อไม่ให้แบบฟอร์มเก่าที่อ้างฟิลด์นั้นสูญการเชื่อมโยง
 */
class FieldDictionarySynchronizer
{
    /** จำนวนขั้นอนุมัติต่ำสุด/สูงสุดที่ประกาศไว้ให้วางบนแบบฟอร์ม */
    private const MIN_APPROVAL_STEPS = 3;

    private const MAX_APPROVAL_STEPS = 8;

    /** ฟิลด์ที่เป็นก้อนข้อมูล ใช้ได้เฉพาะแม่แบบ HTML */
    private const COMPOSITE_CODES = ['stats', 'extra'];

    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function sync(): void
    {
        DB::transaction(function (): void {
            $this->syncProviderCatalogs();
            $this->syncLegacyLeaveFields();
            $this->syncLeaveTypeExtraFields();
            $this->syncApprovalSteps();
            $this->syncLeaveStatistics();

            DocumentFieldDefinition::whereIn('code', self::COMPOSITE_CODES)->update(['is_active' => false]);
        }, 5);
    }

    /** Field Dictionary หลักมาจาก catalog ของ provider โดยตรง */
    private function syncProviderCatalogs(): void
    {
        foreach ($this->providers->all() as $code => $class) {
            $provider = app($class);

            if (! method_exists($provider, 'catalog')) {
                continue;
            }

            foreach ($provider->catalog() as $field) {
                $this->put($field['code'], [
                    'label_th' => $field['label_th'],
                    'group_th' => $field['group_th'] ?? null,
                    'data_type' => $field['data_type'] ?? 'TEXT',
                    'source' => $code,
                    'module' => $provider->module(),
                    'format' => $field['format'] ?? null,
                ]);
            }
        }
    }

    /** ตัวแปรชุดเดิมของใบลา (`{{ $school_name }}` ฯลฯ) ที่แม่แบบเดิมยังใช้อยู่ */
    private function syncLegacyLeaveFields(): void
    {
        foreach (LeaveDocumentFields::catalog() as $field) {
            if (in_array($field['name'], self::COMPOSITE_CODES, true)) {
                continue;
            }

            $this->put($field['name'], [
                'label_th' => $field['label'],
                'group_th' => $field['group'],
                'data_type' => 'TEXT',
                'source' => LeaveRequestDataProvider::CODE,
                'module' => 'leave',
            ]);
        }
    }

    /**
     * ช่องเฉพาะของแต่ละประเภทการลาที่ผู้ดูแลตั้งไว้เอง
     *
     * คีย์เดียวกันอาจถูกใช้หลายประเภท (เช่น `level`) จึงรวมเป็นฟิลด์เดียว
     * แล้วบอกในคำอธิบายว่ามาจากประเภทใดบ้าง
     */
    private function syncLeaveTypeExtraFields(): void
    {
        $definitions = [];

        foreach (LeaveType::query()->get(['id', 'name', 'extra_fields']) as $type) {
            foreach ((array) ($type->extra_fields ?? []) as $field) {
                $key = $field['key'] ?? null;

                if (! is_string($key) || $key === '') {
                    continue;
                }

                $definitions[$key] ??= [
                    'label' => $field['label'] ?? $key,
                    'type' => $field['type'] ?? 'text',
                    'types' => [],
                ];
                $definitions[$key]['types'][] = $type->name;
            }
        }

        foreach ($definitions as $key => $definition) {
            $this->put('extra.'.$key, [
                'label_th' => $definition['label'],
                'group_th' => 'ช่องเฉพาะประเภทการลา',
                'data_type' => $this->dataTypeFor($definition['type']),
                'source' => LeaveRequestDataProvider::CODE,
                'module' => 'leave',
                'description' => 'ใช้กับ: '.implode(', ', array_unique($definition['types'])),
            ]);
        }
    }

    /** ผู้อนุมัติรายขั้นตามจำนวนขั้นสูงสุดของสายอนุมัติที่ตั้งไว้จริง */
    private function syncApprovalSteps(): void
    {
        $configured = (int) ApprovalStep::query()->max('step_order');
        $steps = max(self::MIN_APPROVAL_STEPS, min(self::MAX_APPROVAL_STEPS, $configured));

        $parts = [
            'name' => ['ชื่อผู้อนุมัติ', 'TEXT'],
            'position' => ['ตำแหน่งผู้อนุมัติ', 'TEXT'],
            'signature' => ['ลายเซ็นผู้อนุมัติ', 'SIGNATURE'],
            'comment' => ['ความเห็นผู้อนุมัติ', 'MULTILINE_TEXT'],
            'date' => ['วันที่อนุมัติ', 'TEXT'],
        ];

        for ($step = 1; $step <= $steps; $step++) {
            foreach ($parts as $part => [$label, $dataType]) {
                $this->put("approval.step{$step}.{$part}", [
                    'label_th' => sprintf('%s ขั้นที่ %d', $label, $step),
                    'group_th' => 'การอนุมัติรายขั้น',
                    'data_type' => $dataType,
                    'source' => LeaveRequestDataProvider::CODE,
                    'module' => 'leave',
                ]);
            }
        }
    }

    /**
     * ตารางสถิติการลาในแบบฟอร์มราชการ
     *
     * แบบฟอร์มมีแถวคงที่ตามประเภทการลา จึงประกาศเป็นฟิลด์รายช่องให้วางได้ตรง ๆ
     * แทนการทำบล็อกตารางซ้ำ (ดู docs/DECISION-LOG.md)
     */
    private function syncLeaveStatistics(): void
    {
        $parts = [
            'days_before' => 'ลามาแล้ว (วัน)',
            'times_before' => 'ลามาแล้ว (ครั้ง)',
            'days_current' => 'ลาครั้งนี้ (วัน)',
            'times_current' => 'ลาครั้งนี้ (ครั้ง)',
            'days_total' => 'รวมเป็น (วัน)',
            'times_total' => 'รวมเป็น (ครั้ง)',
        ];

        foreach (LeaveType::query()->get(['id', 'name']) as $type) {
            foreach ($parts as $part => $label) {
                $this->put("stats.leave_type_{$type->id}.{$part}", [
                    'label_th' => sprintf('%s — %s', $type->name, $label),
                    'group_th' => 'สถิติการลาในปีงบประมาณ',
                    'data_type' => 'NUMBER',
                    'source' => LeaveRequestDataProvider::CODE,
                    'module' => 'leave',
                ]);
            }
        }
    }

    /**
     * เขียนฟิลด์ลงพจนานุกรมโดยไม่ทับค่าที่ผู้ดูแลตั้งเอง
     *
     * ป้ายชื่อ กลุ่ม และรูปแบบเป็นสิ่งที่ผู้ดูแลแก้ได้จากหน้าจอ การ sync จึงตั้งค่าให้เฉพาะ
     * ตอนสร้างครั้งแรก ส่วนที่มาของข้อมูลกับชนิดเป็นข้อเท็จจริงของระบบ จึงเขียนทับเสมอ
     *
     * @param  array<string, mixed>  $attributes
     */
    private function put(string $code, array $attributes): void
    {
        $definition = DocumentFieldDefinition::firstOrNew(['code' => $code]);

        if (! $definition->exists) {
            $definition->fill([
                'label_th' => $attributes['label_th'],
                'group_th' => $attributes['group_th'] ?? null,
                'format' => $attributes['format'] ?? null,
                'description' => $attributes['description'] ?? null,
                'is_active' => true,
            ]);
        }

        $definition->fill([
            'data_type' => $attributes['data_type'] ?? 'TEXT',
            'source' => $attributes['source'] ?? null,
            'module' => $attributes['module'] ?? 'leave',
        ])->save();
    }

    private function dataTypeFor(string $type): string
    {
        return match ($type) {
            'textarea' => 'MULTILINE_TEXT',
            'date' => 'DATE',
            'number' => 'NUMBER',
            default => 'TEXT',
        };
    }
}
