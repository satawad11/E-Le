<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ThaiApiErrorResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * REDESIGN-2569 · มาตรฐานภาษาไทย 100% (docs/redesign-2569/07-THAI-LANGUAGE-STANDARD.md)
 *
 * ข้อความที่ผู้ใช้เห็นจาก backend ต้องเป็นภาษาไทย: validation, ชื่อฟิลด์, error ของ API
 */
class ThaiLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function assertThai(string $text, string $context = ''): void
    {
        $this->assertMatchesRegularExpression('/\p{Thai}/u', $text, trim("ข้อความต้องเป็นภาษาไทย {$context}: {$text}"));
        $this->assertStringNotContainsString('The ', $text, $context);
    }

    public function test_application_locale_is_thai(): void
    {
        $this->assertSame('th', app()->getLocale());
        $this->assertSame('th', config('app.fallback_locale'));
    }

    public function test_every_validation_rule_message_is_thai(): void
    {
        $lines = require lang_path('th/validation.php');
        unset($lines['custom'], $lines['attributes']);

        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        unset($english['custom'], $english['attributes']);

        $this->assertSame([], array_diff(array_keys($english), array_keys($lines)), 'ต้องแปลกฎ validation ของ Laravel ครบทุกกฎ');

        array_walk_recursive($lines, fn ($message, $rule) => $this->assertThai($message, "validation.{$rule}"));
        $this->assertThai(__('validation.required', ['attribute' => __('validation.attributes.start_date')]));
        $this->assertSame('กรุณาระบุวันที่เริ่ม', __('validation.required', ['attribute' => __('validation.attributes.start_date')]));
    }

    public function test_auth_password_and_pagination_lines_are_thai(): void
    {
        foreach (['auth', 'passwords', 'pagination'] as $file) {
            foreach (require lang_path("th/{$file}.php") as $key => $message) {
                $this->assertThai($message, "{$file}.{$key}");
            }
        }
    }

    /**
     * ทุกฟิลด์ที่ controller ตรวจต้องมีชื่อภาษาไทย มิฉะนั้น Laravel จะแสดงชื่อภาษาอังกฤษ เช่น "start date"
     */
    public function test_every_validated_field_has_a_thai_attribute_name(): void
    {
        $attributes = (require lang_path('th/validation.php'))['attributes'];
        $pattern = "/'([a-z_][a-z0-9_]*(?:\.\*)?(?:\.[a-z_]+)?)'\s*=>\s*(?:\[|'(?:required|nullable|sometimes|array|string|integer|numeric|boolean|date|file|in:|exists|email|image|max|min|uuid|filled|present|accepted|regex|confirmed|after|before|mimes|json|digits|url|prohibited|bail))/";

        $missing = [];
        foreach (Finder::create()->files()->in(app_path('Http'))->name('*.php') as $file) {
            preg_match_all($pattern, $file->getContents(), $matches);
            foreach ($matches[1] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    $missing[$field] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $missing, 'เพิ่มชื่อฟิลด์ภาษาไทยใน lang/th/validation.php → attributes');
    }

    public function test_unauthenticated_api_error_is_thai(): void
    {
        $this->getJson('/api/user')->assertUnauthorized()
            ->assertJsonPath('message', ThaiApiErrorResponse::MESSAGES[401]);
    }

    public function test_forbidden_api_error_is_thai(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/leave-policies')
            ->assertForbidden()
            ->assertJsonPath('message', ThaiApiErrorResponse::MESSAGES[403]);
    }

    public function test_missing_model_does_not_leak_the_model_name(): void
    {
        Permission::findOrCreate('leave.policy.manage', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('leave.policy.manage');

        $response = $this->actingAs($user)->putJson('/api/admin/leave-policies/999999', [])->assertNotFound();

        $response->assertJsonPath('message', ThaiApiErrorResponse::MESSAGES[404]);
        $this->assertStringNotContainsString('App\\Models', (string) $response->getContent());
    }

    public function test_validation_errors_use_thai_messages_and_field_names(): void
    {
        Permission::findOrCreate('leave.policy.manage', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('leave.policy.manage');

        $response = $this->actingAs($user)->postJson('/api/admin/leave-policies', [])->assertUnprocessable();

        $response->assertJsonPath('errors.personnel_type_id.0', 'กรุณาระบุประเภทบุคลากร');
        $response->assertJsonPath('errors.effective_from.0', 'กรุณาระบุวันที่เริ่มมีผล');
        $this->assertThai($response->json('message'), 'message ของ 422');
    }

    public function test_login_validation_is_thai(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'กรุณาระบุอีเมล')
            ->assertJsonPath('errors.password.0', 'กรุณาระบุรหัสผ่าน');
    }

    public function test_thai_messages_written_by_the_application_are_kept(): void
    {
        $request = Request::create('/api/leaves/1', 'DELETE');

        $kept = ThaiApiErrorResponse::apply(new JsonResponse(['message' => 'ห้ามพิจารณาคำขอของตนเอง'], 403), $request);
        $this->assertSame('ห้ามพิจารณาคำขอของตนเอง', $kept->getData(true)['message']);

        $replaced = ThaiApiErrorResponse::apply(new JsonResponse(['message' => 'Server Error'], 500), $request);
        $this->assertSame(ThaiApiErrorResponse::MESSAGES[500], $replaced->getData(true)['message']);

        $web = ThaiApiErrorResponse::apply(new JsonResponse(['message' => 'Server Error'], 500), Request::create('/login'));
        $this->assertSame('Server Error', $web->getData(true)['message'], 'แตะเฉพาะ API');
    }
}
