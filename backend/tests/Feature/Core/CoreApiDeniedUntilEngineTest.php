<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * R-03: API องค์กร/บุคลากรยังไม่ต่อ PermissionEngine — ทุก endpoint ตอบ 403 แม้เป็นเจ้าของข้อมูล
 * และไม่มีการเปลี่ยนข้อมูลเกิดขึ้น
 */
class CoreApiDeniedUntilEngineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'รายการหน่วยงาน' => ['GET', '/api/org/units'],
            'เพิ่มหน่วยงาน' => ['POST', '/api/org/units'],
            'แก้หน่วยงาน' => ['PUT', '/api/org/units/{unit}'],
            'ย้ายหน่วยงาน' => ['POST', '/api/org/units/{unit}/move'],
            'ปิดหน่วยงาน' => ['POST', '/api/org/units/{unit}/close'],
            'รายการตำแหน่ง' => ['GET', '/api/org/positions'],
            'เพิ่มตำแหน่ง' => ['POST', '/api/org/positions'],
            'แก้ตำแหน่ง' => ['PUT', '/api/org/positions/{position}'],
            'สิ้นสุดตำแหน่ง' => ['POST', '/api/org/assignments/{assignment}/end'],
            'นำเข้า' => ['POST', '/api/personnel/import'],
            'ประวัติบุคลากร' => ['GET', '/api/personnel/{personnel}'],
            'เปิดดูข้อมูลอ่อนไหว' => ['POST', '/api/personnel/{personnel}/reveal'],
            'ยื่นคำขอแก้ไข' => ['POST', '/api/personnel/{personnel}/change-requests'],
            'พิจารณาคำขอ' => ['POST', '/api/personnel/change-requests/{changeRequest}/review'],
            'รายการตำแหน่งของบุคลากร' => ['GET', '/api/personnel/{personnel}/assignments'],
            'แต่งตั้ง' => ['POST', '/api/personnel/{personnel}/assignments'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_endpoint_is_forbidden_even_for_the_data_owner(string $method, string $uri): void
    {
        $f = CollegeFixture::build();
        $owner = User::factory()->create(['personnel_id' => $f->people['multi']->id]);
        Sanctum::actingAs($owner);

        $uri = strtr($uri, [
            '{unit}' => $f->units['auto']->uuid,
            '{position}' => $f->positions['teacher']->uuid,
            '{assignment}' => $f->assignments['multi_acting_director']->uuid,
            '{personnel}' => $f->people['multi']->uuid,
            '{changeRequest}' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->json($method, $uri, ['name' => 'แก้', 'effective_to' => '2026-11-02', 'end_reason' => 'x', 'reason' => 'ทดสอบเหตุผลยาวพอ', 'fields' => ['national_id']])
            ->assertForbidden();

        $this->assertSame('แผนกวิชาช่างยนต์', $f->units['auto']->fresh()->name);
        $this->assertSame(CollegeFixture::ACTING_TO, $f->assignments['multi_acting_director']->fresh()->effective_to->toDateString());
    }
}
