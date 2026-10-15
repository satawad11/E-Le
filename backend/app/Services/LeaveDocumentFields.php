<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveBalance;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ชุด "ตัวแปรของแบบฟอร์มใบลา" ที่เทมเพลตทุกแบบใช้ร่วมกัน
 *
 * เดิมแต่ละ blade ดึงข้อมูลเองด้วย `$leave->user->profile->position ?? 'บุคลากร'`
 * และมีชื่อสถานศึกษาเขียนตายอยู่ในไฟล์ ผู้ดูแลจึงแก้แบบฟอร์มจากหน้าเว็บไม่ได้จริง
 * (หน้าแก้เทมเพลตโฆษณาตัวแปรอย่าง `{{ $school_name }}` ไว้ แต่ไม่มีใครส่งค่าให้)
 *
 * คลาสนี้เป็นสัญญาเดียวระหว่าง "ข้อมูลในระบบ" กับ "ช่องว่างในแบบฟอร์มราชการ"
 * ทุกตัวแปรมีคำอธิบายไทยอยู่ใน `catalog()` เพื่อให้หน้าผู้ดูแลแสดงรายการที่ใช้ได้
 * และ `sample()` ใช้เรนเดอร์ตัวอย่างตอนพรีวิวโดยไม่ต้องมีใบลาจริง
 */
class LeaveDocumentFields
{
    public const THAI_MONTHS = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];

    /**
     * ตัวแปรทั้งหมดที่แบบฟอร์มใช้ได้ พร้อมคำอธิบายไทย — ใช้แสดงในหน้าผู้ดูแล
     *
     * @return array<int, array{group: string, name: string, label: string}>
     */
    public static function catalog(): array
    {
        $fields = [
            'สถานศึกษา' => [
                'school_name' => 'ชื่อสถานศึกษา (จากหน้าตั้งค่าระบบ)',
                'school_affiliation' => 'สังกัด',
                'vocational_institute' => 'สถาบันการอาชีวศึกษา',
                'written_at' => 'สถานที่เขียน — (เขียนที่)',
                'director_name' => 'ชื่อผู้อำนวยการ',
                'director_position' => 'ตำแหน่งผู้อำนวยการ — ใช้ในบรรทัด "เรียน"',
            ],
            'เอกสาร' => [
                'doc_number' => 'เลขที่เอกสารใบลา',
                'doc_day' => 'วันที่เขียนใบลา (ตัวเลข)',
                'doc_month' => 'เดือนที่เขียนใบลา (ชื่อเดือนไทย)',
                'doc_year' => 'ปีที่เขียนใบลา (พ.ศ.)',
                'doc_date_thai' => 'วันที่เขียนใบลาแบบเต็ม',
                'subject' => 'เรื่อง — เช่น ขอลาพักผ่อน',
            ],
            'ผู้ลา' => [
                'user_name' => 'ชื่อ-สกุลผู้ลา (พร้อมคำนำหน้า)',
                'position' => 'ตำแหน่ง',
                'level' => 'ระดับ/วิทยฐานะ (มาจากช่องเฉพาะแบบฟอร์มคีย์ level)',
                'division' => 'แผนก/งาน',
                'department' => 'สังกัดของผู้ลา',
                'phone' => 'หมายเลขโทรศัพท์',
                'birth_date_thai' => 'วันเกิด (แบบไทย)',
                'hired_date_thai' => 'วันเข้ารับราชการ (แบบไทย)',
            ],
            'การลาครั้งนี้' => [
                'leave_type' => 'ประเภทการลา',
                'reason' => 'เหตุผลการลา',
                'start_day' => 'วันที่เริ่มลา (ตัวเลข)',
                'start_month' => 'เดือนที่เริ่มลา',
                'start_year' => 'ปีที่เริ่มลา (พ.ศ.)',
                'start_date_thai' => 'วันเริ่มลาแบบเต็ม',
                'end_day' => 'วันที่สิ้นสุดการลา (ตัวเลข)',
                'end_month' => 'เดือนที่สิ้นสุดการลา',
                'end_year' => 'ปีที่สิ้นสุดการลา (พ.ศ.)',
                'end_date_thai' => 'วันสิ้นสุดการลาแบบเต็ม',
                'total_days' => 'จำนวนวันลา',
                'contact_address' => 'ที่อยู่ติดต่อระหว่างลา',
                'is_sick' => 'ติ๊กช่อง "ป่วย" หรือไม่ (true/false)',
                'is_personal' => 'ติ๊กช่อง "กิจส่วนตัว" หรือไม่',
                'is_maternity' => 'ติ๊กช่อง "คลอดบุตร" หรือไม่',
            ],
            'การลาครั้งก่อน' => [
                'has_last_leave' => 'เคยลาประเภทเดียวกันมาก่อนหรือไม่',
                'last_leave_type' => 'ประเภทการลาครั้งก่อน',
                'last_start_date_thai' => 'วันเริ่มลาครั้งก่อน',
                'last_end_date_thai' => 'วันสิ้นสุดการลาครั้งก่อน',
                'last_total_days' => 'จำนวนวันลาครั้งก่อน',
            ],
            'สถิติการลาในปีงบประมาณ' => [
                'stats' => 'ตารางสถิติ — stats[ชื่อประเภท][before|current|total|times_before|times_current|times_total]',
                'fiscal_year' => 'ปีงบประมาณของใบลานี้ (พ.ศ.)',
            ],
            'วันลาพักผ่อน' => [
                'vacation_accumulated' => 'วันลาพักผ่อนสะสม (วันทำการ)',
                'vacation_entitled' => 'สิทธิลาพักผ่อนประจำปีนี้ (วันทำการ)',
                'vacation_total' => 'รวมวันลาพักผ่อนที่ใช้ได้ (วันทำการ)',
            ],
            'การขอยกเลิกวันลา' => [
                'cancel_reason' => 'เหตุผลการขอยกเลิกวันลา',
                'cancel_days' => 'จำนวนวันที่ขอยกเลิก',
                'cancel_date_thai' => 'วันที่ยื่นคำขอยกเลิก',
                'cancelled_leave_text' => 'ข้อความสรุปใบลาที่ขอยกเลิก',
            ],
            'ข้อมูลเฉพาะแบบฟอร์ม' => [
                'extra' => 'ค่าที่กรอกในช่องเฉพาะของประเภทการลา — extra[คีย์ที่ตั้งไว้]',
            ],
        ];

        $catalog = [];
        foreach ($fields as $group => $items) {
            foreach ($items as $name => $label) {
                $catalog[] = ['group' => $group, 'name' => $name, 'label' => $label];
            }
        }

        return $catalog;
    }

    /**
     * ค่าตัวอย่างสำหรับพรีวิวแบบฟอร์มจากหน้าผู้ดูแล (ไม่แตะข้อมูลจริง)
     *
     * @return array<string, mixed>
     */
    public function sample(): array
    {
        $today = Carbon::today();
        $settings = $this->settings();

        return array_merge($this->organization($settings), [
            'doc_number' => 'ลป. 0001/2569',
            'doc_day' => $today->day,
            'doc_month' => self::THAI_MONTHS[$today->month],
            'doc_year' => $today->year + 543,
            'doc_date_thai' => $this->thaiDate($today),
            'subject' => 'ขอลาป่วย',
            'user_name' => 'นายตัวอย่าง ทดสอบระบบ',
            'position' => 'ครู',
            'level' => 'ชำนาญการ',
            'division' => 'แผนกวิชาช่างยนต์',
            'department' => 'แผนกวิชาช่างยนต์',
            'phone' => '08X-XXX-XXXX',
            'birth_date_thai' => $this->thaiDate($today->copy()->subYears(40)),
            'hired_date_thai' => $this->thaiDate($today->copy()->subYears(10)),
            'leave_type' => 'ลาป่วย',
            'reason' => 'มีอาการไข้ ต้องพักรักษาตัวตามคำแนะนำแพทย์',
            'start_day' => $today->day,
            'start_month' => self::THAI_MONTHS[$today->month],
            'start_year' => $today->year + 543,
            'start_date_thai' => $this->thaiDate($today),
            'end_day' => $today->copy()->addDay()->day,
            'end_month' => self::THAI_MONTHS[$today->copy()->addDay()->month],
            'end_year' => $today->copy()->addDay()->year + 543,
            'end_date_thai' => $this->thaiDate($today->copy()->addDay()),
            'total_days' => 2,
            'contact_address' => 'บ้านเลขที่ 99 ถนนตัวอย่าง',
            'is_sick' => true,
            'is_personal' => false,
            'is_maternity' => false,
            'has_last_leave' => true,
            'last_leave_type' => 'ลาป่วย',
            'last_start_date_thai' => $this->thaiDate($today->copy()->subMonth()),
            'last_end_date_thai' => $this->thaiDate($today->copy()->subMonth()->addDay()),
            'last_total_days' => 2,
            'stats' => [
                'ป่วย' => ['before' => 3, 'current' => 2, 'total' => 5, 'times_before' => 2, 'times_current' => 1, 'times_total' => 3],
                'กิจส่วนตัว' => ['before' => 1, 'current' => '', 'total' => 1, 'times_before' => 1, 'times_current' => '', 'times_total' => 1],
                'คลอดบุตร' => ['before' => '', 'current' => '', 'total' => '', 'times_before' => '', 'times_current' => '', 'times_total' => ''],
            ],
            'fiscal_year' => $today->year + 543,
            'vacation_accumulated' => 5,
            'vacation_entitled' => 10,
            'vacation_total' => 15,
            'cancel_reason' => 'ภารกิจที่ลาไว้ถูกยกเลิก',
            'cancel_days' => 2,
            'cancel_date_thai' => $this->thaiDate($today),
            'cancelled_leave_text' => 'ลาป่วย ตั้งแต่วันที่ '.$this->thaiDate($today).' ถึงวันที่ '.$this->thaiDate($today->copy()->addDay()),
            'extra' => ['ตัวอย่างช่องเพิ่มเติม' => 'ค่าที่ผู้ใช้กรอก'],
        ]);
    }

    /**
     * ตัวแปรจริงของใบลาหนึ่งใบ
     *
     * @return array<string, mixed>
     */
    public function build(Leave $leave): array
    {
        $leave->loadMissing(['user.profile', 'user.department', 'type', 'policy']);

        $settings = $this->settings();
        $user = $leave->user;
        $profile = $user?->profile;
        $createdAt = $leave->submitted_at ?? $leave->created_at ?? now();
        $typeName = (string) ($leave->type->name ?? '');

        $lastLeave = Leave::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('status', 'Approved')
            ->where('id', '<', $leave->id)
            ->orderByDesc('id')
            ->first();

        $cancelLog = LeaveActionLog::where('leave_id', $leave->id)
            ->where('action', 'Cancellation Requested')
            ->orderByDesc('id')
            ->first();

        $extra = $this->extra($leave);
        $vacation = $this->vacation($leave);

        return array_merge($this->organization($settings), [
            'doc_number' => $leave->document_number ?? '',
            'doc_day' => Carbon::parse($createdAt)->day,
            'doc_month' => self::THAI_MONTHS[Carbon::parse($createdAt)->month],
            'doc_year' => Carbon::parse($createdAt)->year + 543,
            'doc_date_thai' => $this->thaiDate($createdAt),
            'subject' => $typeName !== '' ? 'ขอ'.$typeName : 'ขออนุญาตลา',

            'user_name' => $user?->full_name_with_title ?: ($user?->name ?? ''),
            'position' => $profile?->position ?: '',
            // ระบบยังไม่มีคอลัมน์ "ระดับ/วิทยฐานะ" — ให้ผู้ดูแลเพิ่มเป็นช่องเฉพาะแบบฟอร์มคีย์ level ได้
            'level' => (string) ($extra['level'] ?? ''),
            'division' => $user?->department?->name ?? '',
            'department' => $user?->department?->name ?? '',
            'phone' => (string) ($user?->phone ?? ''),
            'birth_date_thai' => $profile?->date_of_birth ? $this->thaiDate($profile->date_of_birth) : '',
            'hired_date_thai' => $profile?->start_date ? $this->thaiDate($profile->start_date) : '',

            'leave_type' => $typeName,
            'reason' => (string) $leave->reason,
            'start_day' => $leave->start_date ? Carbon::parse($leave->start_date)->day : '',
            'start_month' => $leave->start_date ? self::THAI_MONTHS[Carbon::parse($leave->start_date)->month] : '',
            'start_year' => $leave->start_date ? Carbon::parse($leave->start_date)->year + 543 : '',
            'start_date_thai' => $leave->start_date ? $this->thaiDate($leave->start_date) : '',
            'end_day' => $leave->end_date ? Carbon::parse($leave->end_date)->day : '',
            'end_month' => $leave->end_date ? self::THAI_MONTHS[Carbon::parse($leave->end_date)->month] : '',
            'end_year' => $leave->end_date ? Carbon::parse($leave->end_date)->year + 543 : '',
            'end_date_thai' => $leave->end_date ? $this->thaiDate($leave->end_date) : '',
            'total_days' => $this->number($leave->total_days),
            'contact_address' => (string) $leave->contact_address,
            'is_sick' => str_contains($typeName, 'ป่วย'),
            'is_personal' => str_contains($typeName, 'กิจ'),
            'is_maternity' => str_contains($typeName, 'คลอด'),

            'has_last_leave' => $lastLeave !== null,
            'last_leave_type' => $typeName,
            'last_start_date_thai' => $lastLeave ? $this->thaiDate($lastLeave->start_date) : '',
            'last_end_date_thai' => $lastLeave ? $this->thaiDate($lastLeave->end_date) : '',
            'last_total_days' => $lastLeave ? $this->number($lastLeave->total_days) : '',

            'stats' => $this->stats($leave),
            'fiscal_year' => Carbon::parse($leave->start_date ?? $createdAt)->year + 543,

            'vacation_accumulated' => $vacation['accumulated'],
            'vacation_entitled' => $vacation['entitled'],
            'vacation_total' => $vacation['total'],

            'cancel_reason' => (string) ($leave->cancellation_reason ?? ''),
            'cancel_days' => $this->number($leave->total_days),
            'cancel_date_thai' => $leave->cancellation_date
                ? $this->thaiDate($leave->cancellation_date)
                : ($cancelLog ? $this->thaiDate($cancelLog->created_at) : ''),
            'cancelled_leave_text' => trim($typeName.' ตั้งแต่วันที่ '.$this->thaiDate($leave->start_date).' ถึงวันที่ '.$this->thaiDate($leave->end_date)),

            'extra' => $extra,
        ]);
    }

    /**
     * ค่าที่กรอกในช่องเฉพาะของประเภทการลา — คีย์เป็น label ที่ผู้ดูแลตั้งไว้
     *
     * @return array<string, mixed>
     */
    private function extra(Leave $leave): array
    {
        $stored = is_array($leave->meta_data) ? ($leave->meta_data['extra_fields'] ?? []) : [];
        $definitions = collect($leave->type->extra_fields ?? []);

        $result = [];
        foreach ($stored as $key => $value) {
            $result[$key] = $value;
        }

        // เติมคีย์ที่นิยามไว้แต่ยังไม่มีค่า เพื่อให้เทมเพลตอ้างถึงได้โดยไม่ error
        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            if ($key !== null && ! array_key_exists($key, $result)) {
                $result[$key] = '';
            }
        }

        return $result;
    }

    /**
     * สถิติการลาในปีงบประมาณของใบลานี้ แยกตามประเภท
     *
     * before  = ลามาแล้วก่อนใบนี้, current = ลาครั้งนี้, total = รวมเป็น
     * times_* = จำนวนครั้ง (แบบฟอร์มราชการนับทั้งวันและครั้ง)
     *
     * @return array<string, array<string, int|float|string>>
     */
    private function stats(Leave $leave): array
    {
        $reference = Carbon::parse($leave->start_date ?? $leave->created_at ?? now());
        [$from, $to] = $this->fiscalRange($reference);

        $rows = Leave::with('type:id,name')
            ->where('user_id', $leave->user_id)
            ->where('status', 'Approved')
            ->whereBetween('start_date', [$from, $to])
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $name = $row->type->name ?? 'อื่น ๆ';
            $stats[$name] ??= ['before' => 0, 'current' => 0, 'total' => 0, 'times_before' => 0, 'times_current' => 0, 'times_total' => 0];

            $bucket = $row->id === $leave->id ? 'current' : 'before';
            $stats[$name][$bucket] += (float) $row->total_days;
            $stats[$name]['times_'.$bucket] += 1;
        }

        // ใบลาที่ยังไม่อนุมัติก็ต้องแสดงเป็น "ลาครั้งนี้" ในเอกสารที่พิมพ์ออกไป
        $currentName = $leave->type->name ?? 'อื่น ๆ';
        $stats[$currentName] ??= ['before' => 0, 'current' => 0, 'total' => 0, 'times_before' => 0, 'times_current' => 0, 'times_total' => 0];
        if ($stats[$currentName]['times_current'] === 0) {
            $stats[$currentName]['current'] = (float) $leave->total_days;
            $stats[$currentName]['times_current'] = 1;
        }

        foreach ($stats as $name => $row) {
            $stats[$name]['total'] = $row['before'] + $row['current'];
            $stats[$name]['times_total'] = $row['times_before'] + $row['times_current'];

            foreach ($stats[$name] as $key => $value) {
                $stats[$name][$key] = $value > 0 ? $this->number($value) : '';
            }
        }

        return $stats;
    }

    /**
     * ยอดวันลาพักผ่อนของปีงบประมาณที่ใบลานี้อยู่
     *
     * @return array{accumulated: int|float|string, entitled: int|float|string, total: int|float|string}
     */
    private function vacation(Leave $leave): array
    {
        $year = Carbon::parse($leave->start_date ?? now())->year;

        $balance = LeaveBalance::where('user_id', $leave->user_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', $year)
            ->first();

        if ($balance === null) {
            return ['accumulated' => '', 'entitled' => '', 'total' => ''];
        }

        $accumulated = (float) ($balance->carried_forward ?? 0);
        $quota = (float) $balance->quota;

        return [
            'accumulated' => $this->number($accumulated),
            'entitled' => $this->number(max(0, $quota - $accumulated)),
            'total' => $this->number($quota),
        ];
    }

    /**
     * ช่วงปีงบประมาณไทย (1 ต.ค. – 30 ก.ย.) ของวันที่อ้างอิง
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function fiscalRange(Carbon $reference): array
    {
        $startYear = $reference->month >= 10 ? $reference->year : $reference->year - 1;

        return [
            Carbon::create($startYear, 10, 1)->startOfDay(),
            Carbon::create($startYear + 1, 9, 30)->endOfDay(),
        ];
    }

    /**
     * @param  Collection<string, mixed>  $settings
     * @return array<string, string>
     */
    private function organization(Collection $settings): array
    {
        $schoolName = (string) ($settings['institution_name'] ?? $settings['school_name'] ?? '');

        return [
            'school_name' => $schoolName,
            'school_affiliation' => (string) ($settings['school_affiliation'] ?? ''),
            'vocational_institute' => (string) ($settings['vocational_institute'] ?? ''),
            'written_at' => (string) ($settings['document_written_at'] ?? $schoolName),
            'director_name' => (string) ($settings['director_name'] ?? ''),
            'director_position' => (string) ($settings['director_position'] ?? ($schoolName !== '' ? 'ผู้อำนวยการ'.$schoolName : '')),
        ];
    }

    /**
     * @return Collection<string, mixed>
     */
    private function settings(): Collection
    {
        return Setting::pluck('value', 'key');
    }

    private function thaiDate($date): string
    {
        if (blank($date)) {
            return '';
        }

        $carbon = Carbon::parse($date);

        return $carbon->day.' '.self::THAI_MONTHS[$carbon->month].' '.($carbon->year + 543);
    }

    /**
     * ตัดทศนิยมที่ไม่จำเป็นออก — แบบฟอร์มราชการเขียน "2" ไม่ใช่ "2.00" แต่ครึ่งวันต้องคงไว้
     */
    private function number(float|int|string|null $value): int|float|string
    {
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
