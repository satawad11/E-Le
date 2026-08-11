<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThaiGovernmentLeaveTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_operational_thai_government_leave_types_are_seeded(): void
    {
        $expected = [
            'ลาป่วย' => 120,
            'ลาคลอดบุตร' => 90,
            'ลาไปช่วยเหลือภริยาที่คลอดบุตร' => 15,
            'ลากิจส่วนตัว' => 45,
            'ลาพักผ่อน' => 10,
            'ลาอุปสมบท' => 120,
            'ลาไปประกอบพิธีฮัจญ์' => 120,
            'ลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล' => null,
            'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม' => null,
            'ลาไปปฏิบัติงานในองค์การระหว่างประเทศ' => 365,
            'ลาติดตามคู่สมรส' => 1460,
            'ลาไปฟื้นฟูสมรรถภาพด้านอาชีพ' => 365,
        ];

        foreach ($expected as $name => $maxDays) {
            $this->assertDatabaseHas('leave_types', [
                'name' => $name,
                'max_days' => $maxDays,
            ]);
        }
    }
}
