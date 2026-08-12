<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecureFileAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
    }

    public function test_user_can_open_own_profile_photo_but_another_user_cannot(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->create(['profile_photo_path' => '/storage/profile_photos/owner.png']);
        $stranger = User::factory()->create();
        Storage::disk('private_uploads')->put('profile_photos/owner.png', 'image');

        $this->actingAs($owner)
            ->get('/api/files/profile_photos/owner.png')
            ->assertOk();

        $this->actingAs($stranger)
            ->get('/api/files/profile_photos/owner.png')
            ->assertForbidden();
    }

    public function test_user_can_open_own_attendance_image_but_another_user_cannot(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $attendance = Attendance::query()->create([
            'user_id' => $owner->id,
            'date' => '2026-08-13',
            'status' => 'Present',
        ]);
        $attendance->check_in_image = '/storage/attendances/owner.png';
        $attendance->save();
        Storage::disk('private_uploads')->put('attendances/owner.png', 'image');

        $this->actingAs($owner)
            ->get('/api/files/attendances/owner.png')
            ->assertOk();

        $this->actingAs($stranger)
            ->get('/api/files/attendances/owner.png')
            ->assertForbidden();
    }

    public function test_unassociated_private_file_is_denied_even_to_an_authenticated_user(): void
    {
        $user = User::factory()->create();
        Storage::disk('private_uploads')->put('signatures/orphan.png', 'image');

        $this->actingAs($user)
            ->get('/api/files/signatures/orphan.png')
            ->assertForbidden();
    }
}
