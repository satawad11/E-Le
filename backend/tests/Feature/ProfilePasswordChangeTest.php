<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SEC-024 — เปลี่ยนรหัสผ่านของตนเอง
 *
 * ก่อนแก้: ช่อง `password` ปนอยู่ในคำขออัปเดตโปรไฟล์ทั่วไป ไม่ต้องรู้รหัสเดิม
 * ไม่มีช่องยืนยัน · ใครยึด token ไปได้ก็ยึดบัญชีต่อได้ทันที และเจ้าของบัญชี
 * ที่พิมพ์รหัสใหม่ผิดจะล็อกตัวเองออกจากระบบถาวรโดยไม่มีทางรู้ว่าพิมพ์อะไรไป
 */
class ProfilePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'current-password-2569';

    private const NEW = 'brand-new-password-2569';

    private function user(): User
    {
        return User::factory()->create(['password' => self::CURRENT]);
    }

    public function test_password_changes_when_the_current_password_is_correct(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว ระบบได้ออกจากระบบในอุปกรณ์อื่นทั้งหมดแล้ว');

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
    }

    public function test_a_wrong_current_password_is_refused_in_thai(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => 'not-the-current-password',
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW.'-typo',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'การยืนยันรหัสผ่านใหม่ไม่ตรงกับรหัสผ่านใหม่');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_omitting_the_current_password_is_refused(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'กรุณากรอกรหัสผ่านปัจจุบัน');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_new_password_cannot_repeat_the_current_one(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => self::CURRENT,
                'password_confirmation' => self::CURRENT,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม');
    }

    public function test_a_short_new_password_is_refused_in_thai(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => 'sh0rt',
                'password_confirmation' => 'sh0rt',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 12 ตัวอักษร');
    }

    /** โทเคนที่ออกไว้ก่อนหน้าต้องใช้ไม่ได้อีกหลังเปลี่ยนรหัสผ่าน */
    public function test_tokens_issued_before_the_change_stop_working(): void
    {
        $user = $this->user();
        $stolen = $user->createToken('อุปกรณ์ที่ถูกยึด')->plainTextToken;

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertOk();

        // ล้าง guard ที่ถูก resolve ค้างไว้จากคำขอก่อนหน้า มิฉะนั้นคำขอถัดไปจะใช้ผู้ใช้เดิมที่ cache ไว้
        $this->app['auth']->forgetGuards();

        $this->withToken($stolen)->getJson('/api/user')->assertStatus(401);

        // เหลือใบเดียวคือใบใหม่ที่ออกให้อุปกรณ์ซึ่งยืนยันรหัสผ่านเดิมสำเร็จ
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** อุปกรณ์ที่เปลี่ยนรหัสผ่านสำเร็จได้โทเคนใบใหม่กลับไปใช้ต่อทันที */
    public function test_the_device_that_changed_the_password_receives_a_fresh_token(): void
    {
        $user = $this->user();
        $original = $user->createToken('อุปกรณ์เดิม')->plainTextToken;

        $response = $this->withToken($original)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertOk();

        $fresh = $response->json('access_token');

        $this->assertNotNull($fresh);
        $this->assertNotSame($original, $fresh);
        $this->app['auth']->forgetGuards();
        $this->withToken($fresh)->getJson('/api/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($original)->getJson('/api/user')->assertStatus(401);
    }

    /** audit ต้องบันทึกการเปลี่ยนรหัสผ่าน แต่ต้องไม่มีรหัสผ่านหรือ hash อยู่ในนั้น */
    public function test_the_change_is_audited_without_storing_any_password_material(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertOk();

        $entry = AuditLog::where('action', 'user.password_changed')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame((string) $user->id, (string) $entry->resource_id);

        $serialized = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::CURRENT, $serialized);
        $this->assertStringNotContainsString(self::NEW, $serialized);
        $this->assertStringNotContainsString('$2y$', $serialized);
    }

    /** การแก้โปรไฟล์ทั่วไปต้องไม่ต้องใช้รหัสผ่าน และต้องเปลี่ยนรหัสผ่านผ่านทางนี้ไม่ได้ */
    public function test_the_general_profile_update_neither_requires_nor_accepts_a_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/profile', ['phone' => '0812345678'])
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/api/profile', ['phone' => '0812345678', 'password' => self::NEW])
            ->assertStatus(422);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }
}
