<?php

namespace Tests\Unit;

use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_name_with_title_attribute_returns_correctly()
    {
        $user = User::factory()->create([
            'name' => 'ธราเชฏฐ์ สุคนธ์',
        ]);

        $profile = new UserProfile([
            'title' => 'นาย',
        ]);
        $user->setRelation('profile', $profile);

        $this->assertEquals('นายธราเชฏฐ์ สุคนธ์', $user->full_name_with_title);
    }

    public function test_full_name_with_title_when_name_already_starts_with_title()
    {
        $user = User::factory()->create([
            'name' => 'นายธราเชฏฐ์ สุคนธ์',
        ]);

        $profile = new UserProfile([
            'title' => 'นาย',
        ]);
        $user->setRelation('profile', $profile);

        $this->assertEquals('นายธราเชฏฐ์ สุคนธ์', $user->full_name_with_title);
    }

    /**
     * `department()` เป็นชื่อพ้องของ `orgUnit()`
     *
     * ผังองค์กรเหลือชุดเดียว (`org_units`) — คงชื่อเดิมไว้เพื่อไม่ให้ผู้เรียกที่
     * eager-load `user.department` พังพร้อมกันทั้งระบบ
     */
    public function test_user_has_department_relation()
    {
        $user = new User;
        $this->assertInstanceOf(BelongsTo::class, $user->department());
        $this->assertInstanceOf(OrgUnit::class, $user->department()->getRelated());
        $this->assertInstanceOf(OrgUnit::class, $user->orgUnit()->getRelated());
    }

    public function test_user_has_profile_relation()
    {
        $user = new User;
        $this->assertInstanceOf(HasOne::class, $user->profile());
        $this->assertInstanceOf(UserProfile::class, $user->profile()->getRelated());
    }

    public function test_user_has_attendances_relation()
    {
        $user = new User;
        $this->assertInstanceOf(HasMany::class, $user->attendances());
    }

    public function test_user_has_leaves_relation()
    {
        $user = new User;
        $this->assertInstanceOf(HasMany::class, $user->leaves());
    }

    public function test_user_get_role_attribute_returns_first_role()
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin']);

        $user->assignRole($role);

        $this->assertEquals('Admin', $user->role->name);
    }
}
