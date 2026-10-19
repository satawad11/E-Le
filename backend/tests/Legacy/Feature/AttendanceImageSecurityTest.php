<?php

namespace Tests\Legacy\Feature;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AttendanceImageSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
    }

    public function test_check_in_rejects_a_non_image_payload_disguised_as_png(): void
    {
        $user = $this->attendanceUser();
        $payload = 'data:image/png;base64,'.base64_encode('<?php echo "unsafe";');

        $this->actingAs($user)
            ->postJson('/api/attendances/check-in', ['image' => $payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], Storage::disk('private_uploads')->allFiles());
    }

    public function test_check_in_rejects_an_image_larger_than_five_megabytes(): void
    {
        $user = $this->attendanceUser();
        $payload = 'data:image/png;base64,'.base64_encode(str_repeat('A', (5 * 1024 * 1024) + 1));

        $this->actingAs($user)
            ->postJson('/api/attendances/check-in', ['image' => $payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], Storage::disk('private_uploads')->allFiles());
    }

    public function test_check_in_uses_detected_mime_and_a_random_file_name(): void
    {
        $user = $this->attendanceUser();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        $response = $this->actingAs($user)
            ->postJson('/api/attendances/check-in', [
                'image' => 'data:image/jpeg;base64,'.base64_encode($png),
            ])
            ->assertOk();

        $path = $response->json('attendance.check_in_image');

        $this->assertMatchesRegularExpression('#^/storage/attendances/[0-9a-f-]{36}\.png$#', $path);
        Storage::disk('private_uploads')->assertExists(str_replace('/storage/', '', $path));
        $this->assertSame($path, Attendance::query()->sole()->check_in_image);
    }

    public function test_check_out_applies_the_same_mime_validation(): void
    {
        $user = $this->attendanceUser();
        $payload = 'data:image/jpeg;base64,'.base64_encode('<script>unsafe</script>');

        $this->actingAs($user)
            ->postJson('/api/attendances/check-out', ['image' => $payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], Storage::disk('private_uploads')->allFiles());
    }

    private function attendanceUser(): User
    {
        $permission = Permission::findOrCreate('attendance.checkin', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }
}
