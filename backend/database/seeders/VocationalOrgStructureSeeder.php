<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * ผังหน่วยงานตั้งต้นของสถานศึกษาอาชีวศึกษา — ๔ ฝ่าย และงานในสังกัด
 *
 * **ที่มา:** ผังที่ระบบสร้างให้ตอนติดตั้งมีแค่ยอดผัง กับหน่วยงานชื่อกลาง ๆ สองหน่วย
 * (`ฝ่ายสารสนเทศ`, `ฝ่ายทรัพยากรบุคคล`) ซึ่งไม่ใช่โครงของวิทยาลัยจริง · ส่วนแผนกวิชา
 * ที่ seeder ข้อมูลจำลองสร้างก็แขวนใต้ยอดผังตรง ๆ ผังจึงแบนราบชั้นเดียวทั้งระบบ
 *
 * ผลคือสิ่งที่ผูกกับผัง — สายอนุมัติที่ไต่หาหัวหน้าตามลำดับชั้น ขอบเขตการมองเห็น
 * ของรายงาน และปฏิทินวันทำการรายหน่วยงาน — ไม่มีชั้น "ฝ่าย" ให้ไต่หรือให้กรอง
 * ผู้ดูแลจึงกำหนดได้แค่ "ทั้งสถานศึกษา" หรือ "รายแผนก" ไม่มีตรงกลาง
 *
 * โครงในไฟล์นี้เป็นโครงมาตรฐานตามระเบียบสำนักงานคณะกรรมการการอาชีวศึกษา
 * ว่าด้วยการบริหารสถานศึกษา — ใช้เป็น **จุดตั้งต้น** ไม่ใช่ค่าตายตัว ผู้ดูแลเปลี่ยนชื่อ
 * ปิดใช้งาน ย้ายสังกัด หรือเพิ่มงานเองได้ที่หน้า "โครงสร้างหน่วยงาน"
 *
 * idempotent — อ้างอิงด้วย `code` ที่คงที่ รันซ้ำไม่สร้างซ้ำ และ **ไม่ทับ**
 * ชื่อหรือสังกัดที่ผู้ดูแลแก้ไว้เอง
 */
class VocationalOrgStructureSeeder extends Seeder
{
    /** ฝ่าย (division) และงานในสังกัด (section) — `code` ห้ามเปลี่ยน เพราะเป็นตัวอ้างอิงของ seeder */
    public const STRUCTURE = [
        'ORG-DIV-RESOURCE' => [
            'name' => 'ฝ่ายบริหารทรัพยากร',
            'sections' => [
                'ORG-SEC-GENERAL' => 'งานบริหารงานทั่วไป',
                'ORG-SEC-HR' => 'งานบุคลากร',
                'ORG-SEC-FINANCE' => 'งานการเงิน',
                'ORG-SEC-ACCOUNTING' => 'งานการบัญชี',
                'ORG-SEC-SUPPLY' => 'งานพัสดุ',
                'ORG-SEC-BUILDING' => 'งานอาคารสถานที่',
                'ORG-SEC-REGISTRAR' => 'งานทะเบียน',
                'ORG-SEC-PR' => 'งานประชาสัมพันธ์',
            ],
        ],
        'ORG-DIV-PLANNING' => [
            'name' => 'ฝ่ายแผนงานและความร่วมมือ',
            'sections' => [
                'ORG-SEC-PLANBUDGET' => 'งานวางแผนและงบประมาณ',
                'ORG-SEC-DATACENTER' => 'งานศูนย์ข้อมูลสารสนเทศ',
                'ORG-SEC-COOP' => 'งานความร่วมมือ',
                'ORG-SEC-RESEARCH' => 'งานวิจัยพัฒนานวัตกรรมและสิ่งประดิษฐ์',
                'ORG-SEC-QA' => 'งานประกันคุณภาพและมาตรฐานการศึกษา',
                'ORG-SEC-TRADE' => 'งานส่งเสริมผลิตผล การค้า และประกอบธุรกิจ',
            ],
        ],
        'ORG-DIV-STUDENT' => [
            'name' => 'ฝ่ายพัฒนากิจการนักเรียนนักศึกษา',
            'sections' => [
                'ORG-SEC-ACTIVITY' => 'งานกิจกรรมนักเรียนนักศึกษา',
                'ORG-SEC-ADVISOR' => 'งานครูที่ปรึกษา',
                'ORG-SEC-DISCIPLINE' => 'งานปกครอง',
                'ORG-SEC-GUIDANCE' => 'งานแนะแนวอาชีพและการจัดหางาน',
                'ORG-SEC-WELFARE' => 'งานสวัสดิการนักเรียนนักศึกษา',
                'ORG-SEC-COMMUNITY' => 'งานโครงการพิเศษและการบริการชุมชน',
            ],
        ],
        /*
         * แผนกวิชาทุกแผนกสังกัดฝ่ายวิชาการ — `MockDataSeeder` และหน้าจอจัดการผัง
         * ใช้ `ORG-DIV-ACADEMIC` เป็นหน่วยงานแม่ตั้งต้นของแผนกวิชา
         */
        'ORG-DIV-ACADEMIC' => [
            'name' => 'ฝ่ายวิชาการ',
            'sections' => [
                'ORG-SEC-CURRICULUM' => 'งานพัฒนาหลักสูตรการเรียนการสอน',
                'ORG-SEC-EVALUATION' => 'งานวัดผลและประเมินผล',
                'ORG-SEC-LIBRARY' => 'งานวิทยบริการและห้องสมุด',
                'ORG-SEC-DVE' => 'งานอาชีวศึกษาระบบทวิภาคี',
                'ORG-SEC-MEDIA' => 'งานสื่อการเรียนการสอน',
            ],
        ],
    ];

    public function run(): void
    {
        $root = $this->ensureRoot();

        foreach (self::STRUCTURE as $divisionCode => $division) {
            $divisionUnit = $this->ensureUnit($divisionCode, $division['name'], 'division', $root->id);

            foreach ($division['sections'] as $sectionCode => $sectionName) {
                $this->ensureUnit($sectionCode, $sectionName, 'section', $divisionUnit->id);
            }
        }
    }

    /**
     * ยอดผังต้องมีก่อนเสมอ — `OrgUnitBackfillSeeder` เป็นเจ้าของการสร้าง
     * ที่นี่เพียงหยิบมาใช้ และรองรับกรณีถูกเรียกเดี่ยว ๆ ด้วย `--class`
     */
    private function ensureRoot(): OrgUnit
    {
        $root = OrgUnit::whereNull('parent_id')->first();

        if ($root === null) {
            $this->call(OrgUnitBackfillSeeder::class);
            $root = OrgUnit::whereNull('parent_id')->firstOrFail();
        }

        return $root;
    }

    /**
     * `firstOrCreate` ด้วย `code` — ถ้าผู้ดูแลเปลี่ยนชื่อหรือย้ายสังกัดไว้แล้ว
     * การรันซ้ำจะไม่ทับของเขา ซึ่งตรงกับข้อห้าม "overwrite ประวัติ effective-dated"
     */
    private function ensureUnit(string $code, string $name, string $type, int $parentId): OrgUnit
    {
        return OrgUnit::firstOrCreate(['code' => $code], [
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parentId,
            'name' => $name,
            'type' => $type,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }
}
