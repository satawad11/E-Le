<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ทะเบียนบุคลากรต้องแบ่งหน้า กรองที่เซิร์ฟเวอร์ และเคารพขอบเขตของผู้เรียก
 *
 * **ที่มา:** `GET /admin/users` เคยคืนบุคลากร **ทุกคน** พร้อมความสัมพันธ์ครบชุด
 * ในคำขอเดียว แล้วให้หน้าจอค้นหาและกรองเอง — ทะเบียนประวัติทั้งสถานศึกษาจึงถูกส่ง
 * ออกไปทุกครั้งที่เปิดหน้า ทั้งที่ผู้ใช้ดูจริงแค่ไม่กี่แถว
 *
 * และหน้ารายละเอียด (`show()`) ตรวจขอบเขตหน่วยงานมาตลอด แต่หน้ารายการไม่เคยตรวจเลย
 * ผู้ดูแลที่ถูกจำกัดขอบเขตจึงเห็นรายชื่อทั้งองค์กรแต่กดเปิดแล้วได้ 403
 */
class UserDirectoryScopeAndPagingTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create(['name' => 'ผู้ดูแล ระบบ']);
        $user->givePermissionTo(Permission::findOrCreate('view_users', 'web'));

        return $user->fresh();
    }

    public function test_รายการแบ่งหน้าแทนการส่งทุกคนมาในคำขอเดียว(): void
    {
        User::factory()->count(30)->create();

        $response = $this->actingAs($this->viewer())
            ->getJson('/api/admin/users?per_page=10')
            ->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(10, $response->json('per_page'));
        $this->assertGreaterThan(1, $response->json('last_page'));
    }

    public function test_ขอจำนวนต่อหน้ามากเกินกำหนดไม่ได้(): void
    {
        $this->actingAs($this->viewer())
            ->getJson('/api/admin/users?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_ค้นหาชื่อที่ฝั่งเซิร์ฟเวอร์(): void
    {
        User::factory()->create(['name' => 'สมชาย ใจดี']);
        User::factory()->create(['name' => 'สมหญิง รักเรียน']);

        $names = collect(
            $this->actingAs($this->viewer())->getJson('/api/admin/users?search=สมชาย')->assertOk()->json('data')
        )->pluck('name');

        $this->assertContains('สมชาย ใจดี', $names);
        $this->assertNotContains('สมหญิง รักเรียน', $names);
    }

    public function test_กรองตามหน่วยงานที่ฝั่งเซิร์ฟเวอร์(): void
    {
        $a = OrgUnit::factory()->create(['name' => 'ฝ่าย ก']);
        $b = OrgUnit::factory()->create(['name' => 'ฝ่าย ข']);
        User::factory()->create(['name' => 'คนฝ่าย ก', 'org_unit_id' => $a->id]);
        User::factory()->create(['name' => 'คนฝ่าย ข', 'org_unit_id' => $b->id]);

        $names = collect(
            $this->actingAs($this->viewer())->getJson("/api/admin/users?org_unit_id={$a->id}")->assertOk()->json('data')
        )->pluck('name');

        $this->assertContains('คนฝ่าย ก', $names);
        $this->assertNotContains('คนฝ่าย ข', $names);
    }

    public function test_ผู้ดูแลที่รับผิดชอบหน่วยงานเห็นเฉพาะบุคลากรในขอบเขตของตน(): void
    {
        /*
         * กติกาเดียวกับที่ `show()` ใช้มาตลอด — รายการกับรายละเอียดจึงตรงกัน
         * ไม่ใช่เห็นชื่อในตารางแล้วกดเปิดได้ 403
         */
        $mine = OrgUnit::factory()->create(['name' => 'ฝ่ายที่รับผิดชอบ']);
        $other = OrgUnit::factory()->create(['name' => 'ฝ่ายอื่น']);

        $head = $this->viewer();
        $head->forceFill(['org_unit_id' => $mine->id])->save();

        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $mine->id,
            'user_id' => $head->id,
            'assignment_type' => 'MANAGER',
            'scope' => 'UNIT',
            'effective_from' => now()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        User::factory()->create(['name' => 'ลูกน้องในฝ่าย', 'org_unit_id' => $mine->id]);
        User::factory()->create(['name' => 'คนนอกฝ่าย', 'org_unit_id' => $other->id]);

        $names = collect(
            $this->actingAs($head)->getJson('/api/admin/users')->assertOk()->json('data')
        )->pluck('name');

        $this->assertContains('ลูกน้องในฝ่าย', $names);
        $this->assertNotContains('คนนอกฝ่าย', $names);
        // ต้องเห็นทะเบียนของตนเองเสมอ แม้จะกรองตามขอบเขตแล้ว
        $this->assertContains($head->name, $names);
    }

    public function test_ผู้ที่ไม่ได้ถูกจำกัดขอบเขตยังเห็นทั้งองค์กรเหมือนเดิม(): void
    {
        $unit = OrgUnit::factory()->create(['name' => 'ฝ่ายใดฝ่ายหนึ่ง']);
        User::factory()->create(['name' => 'บุคลากร ก', 'org_unit_id' => $unit->id]);
        User::factory()->create(['name' => 'บุคลากร ข', 'org_unit_id' => null]);

        $names = collect(
            $this->actingAs($this->viewer())->getJson('/api/admin/users?per_page=100')->assertOk()->json('data')
        )->pluck('name');

        $this->assertContains('บุคลากร ก', $names);
        $this->assertContains('บุคลากร ข', $names);
    }

    public function test_รายการยังปิดบังเลขประจำตัวประชาชนเหมือนเดิม(): void
    {
        $staff = User::factory()->create(['name' => 'มีเลขบัตร']);
        $staff->profile()->create(['id_card_number' => '1234567890123', 'first_name' => 'มี', 'last_name' => 'เลขบัตร']);

        /*
         * ค้นด้วยเลขเต็ม 13 หลัก — server เทียบด้วยค่าแฮช จึงไม่ต้องส่งเลขของใคร
         * ออกไปเพื่อให้ค้นเจอ (และชื่อผู้ใช้ถูกซิงก์จากทะเบียนประวัติ จึงไม่ใช้ค้นที่นี่)
         */
        $row = collect(
            $this->actingAs($this->viewer())->getJson('/api/admin/users?id_card=1234567890123')->assertOk()->json('data')
        )->firstWhere('id', $staff->id);

        $this->assertNotNull($row, 'ค้นด้วยเลขบัตรเต็มต้องเจอบุคลากรรายนี้');

        $this->assertNotSame('1234567890123', $row['profile']['id_card_number']);
        $this->assertSame('0123', $row['profile']['id_card_last_four']);
        $this->assertArrayNotHasKey('id_card_number_hash', $row['profile']);
    }
}
