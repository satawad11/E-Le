<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_only_receives_allowlisted_public_settings(): void
    {
        $user = User::factory()->create();
        Setting::create(['key' => 'institution_name', 'value' => 'วิทยาลัยทดสอบ']);
        Setting::create(['key' => 'telegram_bot_token', 'value' => 'top-secret']);
        Setting::create(['key' => 'mail_password', 'value' => 'mail-secret']);

        $this->actingAs($user)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('institution_name', 'วิทยาลัยทดสอบ')
            ->assertJsonMissingPath('telegram_bot_token')
            ->assertJsonMissingPath('mail_password');
    }

    public function test_secret_values_are_encrypted_at_rest_and_decrypted_for_runtime_use(): void
    {
        $setting = Setting::create(['key' => 'telegram_bot_token', 'value' => 'top-secret']);

        $stored = DB::table('settings')->where('id', $setting->id)->value('value');

        $this->assertStringStartsWith('enc:', $stored);
        $this->assertStringNotContainsString('top-secret', $stored);
        $this->assertSame('top-secret', $setting->fresh()->value);
    }

    public function test_admin_settings_mask_secrets_and_report_configuration_state(): void
    {
        $admin = $this->superAdmin();
        Setting::create(['key' => 'telegram_bot_token', 'value' => 'top-secret']);

        $this->actingAs($admin)->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('telegram_bot_token', null)
            ->assertJsonPath('telegram_bot_token_configured', true)
            ->assertJsonMissing(['top-secret']);
    }

    public function test_unknown_settings_are_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/settings', ['unexpected_setting' => 'value'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings');
    }

    public function test_canonical_values_sync_legacy_aliases_and_are_audited(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/admin/settings', [
            'institution_name' => 'วิทยาลัยใหม่',
            'document_number_out' => 'ศธ 0001',
        ])->assertOk();

        $this->assertDatabaseHas('settings', ['key' => 'institution_name', 'value' => 'วิทยาลัยใหม่']);
        $this->assertDatabaseHas('settings', ['key' => 'school_name', 'value' => 'วิทยาลัยใหม่']);
        $this->assertDatabaseHas('settings', ['key' => 'doc_number_prefix', 'value' => 'ศธ 0001']);
        $this->assertDatabaseCount('setting_audit_logs', 1);
    }

    /*
     * CLEANUP-01 (D8) — ปุ่มทดสอบการแจ้งเตือนต้องเดินผ่าน adapter ของ Notification Center
     *
     * เดิมเรียก provider ตรง ๆ จึงตอบ "สำเร็จ" ได้ทั้งที่ผู้รับยังไม่ได้ผูกบัญชี
     */
    public function test_notification_test_button_refuses_a_channel_the_recipient_has_not_linked(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['telegram_chat_id' => null])->save();
        Setting::create(['key' => 'telegram_bot_token', 'value' => 'token']);

        $this->actingAs($admin)->postJson('/api/admin/settings/test-notification/telegram')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channel');
    }

    public function test_notification_test_button_rejects_an_unknown_channel(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/settings/test-notification/carrier-pigeon')
            ->assertNotFound();
    }

    public function test_notification_test_button_sends_through_the_email_adapter(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/settings/test-notification/email')
            ->assertOk();

        Mail::assertSentCount(1);
    }

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin'], ['guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
