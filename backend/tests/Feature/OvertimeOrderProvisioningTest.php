<?php

namespace Tests\Feature;

use App\Models\Overtime\OvertimeOrder;
use App\Models\User;
use Database\Seeders\OrgUnitBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ครอบช่องว่างที่ `OvertimeCriticalPathTest` มองไม่เห็น
 *
 * เทสต์ตัวนั้นสร้าง org unit เองและส่ง `org_unit_id` มาตรง ๆ ทุกครั้ง
 * จึงไม่เคยเจอสภาพของระบบที่ "ติดตั้งใหม่จริง" ซึ่งผู้ใช้ไม่มีหน่วยงานต้นสังกัด
 * และทำให้ออกคำสั่ง OT ไม่ได้เลยแม้แต่ Super Admin
 */
class OvertimeOrderProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_gives_every_user_a_home_org_unit(): void
    {
        $this->seed();

        $this->assertSame(
            0,
            DB::table('users')->whereNull('org_unit_id')->count(),
            'ผู้ใช้ทุกคนต้องมีหน่วยงานต้นสังกัดในผังหลังติดตั้ง'
        );

        // ผังต้องมีมากกว่ายอดผัง — ไม่งั้นทุกคนกองอยู่ที่รากเดียวกัน
        $this->assertGreaterThan(1, DB::table('org_units')->count());
    }

    /**
     * เคสที่พังจริงก่อนหน้านี้: `OvertimeOrderService::create()` เรียก
     * `canSeeOrgUnit()` ด้วยหน่วยงานต้นสังกัดของผู้ออกคำสั่ง ซึ่งเป็น null
     * บนระบบที่ติดตั้งใหม่ → คืน false เสมอ → 403 ทุกครั้ง
     */
    public function test_issuing_an_order_works_on_a_freshly_installed_system(): void
    {
        $this->seed();

        $actor = User::whereNotNull('org_unit_id')->firstOrFail();

        $response = $this->actingAs($actor)->postJson('/api/overtime/orders', [
            'title' => 'ปฏิบัติงานนอกเวลาราชการเพื่อจัดเตรียมเอกสาร',
            'scheduled_start' => '2026-09-01 17:00:00',
            'scheduled_end' => '2026-09-01 20:00:00',
        ])->assertCreated();

        $order = OvertimeOrder::where('uuid', $response->json('order.uuid'))->firstOrFail();

        $this->assertSame('pending', $order->status);
        $this->assertNotNull($order->org_unit_id, 'คำสั่งต้องผูกกับหน่วยงานต้นสังกัดของผู้ออกคำสั่ง');
        $this->assertSame((int) $actor->org_unit_id, (int) $order->org_unit_id);
    }

    /**
     * อาการที่ผู้ใช้เห็นตอนยังไม่มีหน้าจอออกคำสั่ง — ปุ่มลงเวลา OT กดแล้วล้มเสมอ
     * เก็บกฎนี้ไว้เพื่อไม่ให้ใครเผลอปลดเงื่อนไข "ต้องมีคำสั่งที่อนุมัติแล้ว" ออก
     */
    public function test_ot_check_in_is_refused_without_an_approved_order(): void
    {
        $this->seed();

        $actor = User::whereNotNull('org_unit_id')->firstOrFail();

        $this->actingAs($actor)
            ->postJson('/api/attendances/ot-check-in')
            ->assertStatus(400)
            ->assertJsonPath('message', 'คุณไม่มีคำสั่ง OT ที่ได้รับอนุมัติในช่วงเวลานี้');
    }

    public function test_the_org_unit_backfill_is_idempotent(): void
    {
        $this->seed();

        $before = DB::table('org_units')->count();

        $this->seed(OrgUnitBackfillSeeder::class);

        $this->assertSame($before, DB::table('org_units')->count(), 'รันซ้ำต้องไม่สร้างหน่วยงานซ้ำ');
    }
}
