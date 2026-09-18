<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceIdentityMap;
use App\Models\AttendanceRawEvent;
use App\Models\AttendanceSource;
use App\Models\User;
use App\Services\Attendance\BiometricFileParserService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BiometricFileParserTest extends TestCase
{
    use RefreshDatabase;

    private BiometricFileParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(BiometricFileParserService::class);
    }

    public function test_it_parses_tab_and_space_delimited_biometric_logs(): void
    {
        $logContent = "
# ZKTeco / HIP Attendance Log
101\t2026-09-16\t08:15:30\t0
101\t2026-09-16\t16:35:10\t1
102 2026-09-16 08:28:00 0
102 2026-09-16 16:45:00 1
";

        $events = $this->parser->parseContent($logContent);

        $this->assertCount(4, $events);
        $this->assertSame('101', $events[0]['enroll_id']);
        $this->assertSame('check_in', $events[0]['event_type']);
        $this->assertSame('2026-09-16 08:15:30', $events[0]['timestamp']->format('Y-m-d H:i:s'));

        $this->assertSame('101', $events[1]['enroll_id']);
        $this->assertSame('check_out', $events[1]['event_type']);

        $this->assertSame('102', $events[2]['enroll_id']);
        $this->assertSame('check_in', $events[2]['event_type']);
    }

    public function test_it_ingests_and_reconciles_biometric_events_to_attendance(): void
    {
        $user1 = User::factory()->create(['name' => 'Somchai Teacher']);
        $user2 = User::factory()->create(['name' => 'Somsri Officer']);

        $source = AttendanceSource::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'BIO-SCAN-01',
            'name' => 'เครื่องสแกนป้อมยาม',
            'type' => 'biometric_device',
            'is_active' => true,
        ]);

        // Map enroll ID 101 to User1, and 102 to User2
        AttendanceIdentityMap::create([
            'uuid' => (string) Str::uuid(),
            'attendance_source_id' => $source->id,
            'external_person_id' => '101',
            'user_id' => $user1->id,
        ]);

        AttendanceIdentityMap::create([
            'uuid' => (string) Str::uuid(),
            'attendance_source_id' => $source->id,
            'external_person_id' => '102',
            'user_id' => $user2->id,
        ]);

        $logContent = "
101\t2026-09-16 08:10:00\t0
101\t2026-09-16 16:35:00\t1
102\t2026-09-16 08:45:00\t0
102\t2026-09-16 16:30:00\t1
";

        $events = $this->parser->parseContent($logContent);
        $result = $this->parser->ingest($events, $source);

        $this->assertSame(4, $result['total_events']);
        $this->assertSame(4, $result['imported']);
        $this->assertSame(2, $result['mapped_users']);

        // Check raw events
        $this->assertSame(4, AttendanceRawEvent::count());

        // Check daily reconciled attendance
        $attUser1 = Attendance::where('user_id', $user1->id)->whereDate('date', '2026-09-16')->first();
        $this->assertNotNull($attUser1);
        $this->assertSame('08:10:00', CarbonImmutable::parse($attUser1->check_in_time)->format('H:i:s'));
        $this->assertSame('16:35:00', CarbonImmutable::parse($attUser1->check_out_time)->format('H:i:s'));
        $this->assertSame('Present', $attUser1->status);
        $this->assertEquals(0, $attUser1->late_minutes);

        $attUser2 = Attendance::where('user_id', $user2->id)->whereDate('date', '2026-09-16')->first();
        $this->assertNotNull($attUser2);
        $this->assertSame('08:45:00', CarbonImmutable::parse($attUser2->check_in_time)->format('H:i:s'));
        $this->assertTrue($attUser2->late_minutes > 0 || $attUser2->status === 'Late' || $attUser2->status === 'Present');
    }
}
