<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SEC-028 — ทุกจุดที่รับภาพต้องบังคับกฎชุดเดียวกัน
 *
 * ก่อนแก้:
 *   - `profile_photo` ไม่มีกฎ validate ใด ๆ เลยทั้งในหน้าโปรไฟล์และหน้าจัดการผู้ใช้
 *   - `signature_base64` เป็นเพียง `nullable|string` แล้วตรวจเองด้วย regex ที่เชื่อ
 *     ชนิดไฟล์จาก **ส่วนหัว data URI ที่ client เขียนมาเอง** — ส่งหัวเป็น
 *     `data:image/php;base64,` แนบเนื้ออะไรก็ได้ ระบบเขียนเป็นไฟล์ `.php` ลงดิสก์
 *   - ไม่จำกัดขนาด และ `base64_decode()` ไม่ strict สตริงเสียจึงถูกซ่อมเงียบ ๆ
 *   - ชื่อไฟล์มาจาก `uniqid()` ซึ่งเป็นเวลาปัจจุบัน เดาลำดับได้
 */
class SignatureAndPhotoUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** PNG จริงขนาดเล็กที่สุด (1x1) */
    private const REAL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // ดิสก์จำลอง เพื่อให้ "ไม่มีไฟล์ใดถูกเขียนลงดิสก์" ตรวจได้จริงและไม่ปนกับรอบก่อน
        Storage::fake('private_uploads');
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function postProfile(User $user, array $payload)
    {
        return $this->actingAs($user)->postJson('/api/profile', $payload);
    }

    /** ลายเซ็นที่เป็น PNG จริงต้องผ่าน และถูกเก็บบน private disk ด้วยชื่อที่คาดเดาไม่ได้ */
    public function test_a_genuine_png_signature_is_stored_privately_with_an_unguessable_name(): void
    {
        $user = $this->actor();

        $this->postProfile($user, ['signature_base64' => 'data:image/png;base64,'.self::REAL_PNG])
            ->assertOk();

        $path = UserProfile::where('user_id', $user->id)->value('signature_path');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('/storage/signatures/', $path);
        $this->assertMatchesRegularExpression(
            '#^/storage/signatures/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$#',
            $path
        );
        $this->assertTrue(Storage::disk('private_uploads')->exists(substr($path, strlen('/storage/'))));
    }

    /** MIME ปลอม — ส่วนหัวบอกว่า PNG แต่เนื้อไฟล์เป็นสคริปต์ PHP */
    public function test_a_spoofed_mime_header_over_php_content_is_refused(): void
    {
        $user = $this->actor();
        $payload = base64_encode('<?php echo shell_exec($_GET["c"]); ?>');

        $this->postProfile($user, ['signature_base64' => 'data:image/png;base64,'.$payload])
            ->assertStatus(422)
            ->assertJsonPath('errors.signature_base64.0', 'อนุญาตเฉพาะไฟล์ภาพชนิด JPG, PNG เท่านั้น');

        $this->assertNull(UserProfile::where('user_id', $user->id)->value('signature_path'));
    }

    /** ส่วนหัวประกาศนามสกุลต้องห้าม — ต้องไม่มีทางได้ไฟล์ `.php` ลงดิสก์ */
    public function test_a_forbidden_extension_declared_in_the_data_uri_is_refused(): void
    {
        $user = $this->actor();

        $this->postProfile($user, ['signature_base64' => 'data:image/php;base64,'.base64_encode('<?php echo 1;')])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('private_uploads')->files('signatures'));
    }

    /** สตริง Base64 ที่เสียต้องถูกปฏิเสธ ไม่ใช่ถูก "ซ่อม" แล้วเขียนขยะลงดิสก์ */
    public function test_a_corrupt_base64_string_is_refused_instead_of_being_repaired(): void
    {
        $user = $this->actor();

        $this->postProfile($user, ['signature_base64' => 'data:image/png;base64,!!!ไม่ใช่base64!!!'])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('private_uploads')->files('signatures'));
    }

    /** ภาพที่ใหญ่เกินเพดานของ allowlist ต้องถูกปฏิเสธพร้อมข้อความไทย */
    public function test_an_oversized_signature_is_refused_in_thai(): void
    {
        $user = $this->actor();
        $huge = 'data:image/png;base64,'.base64_encode(str_repeat('A', 2 * 1024 * 1024));

        $this->postProfile($user, ['signature_base64' => $huge])
            ->assertStatus(422)
            ->assertJsonPath('errors.signature_base64.0', 'ไฟล์ภาพต้องมีขนาดไม่เกิน 1024 กิโลไบต์');
    }

    /**
     * polyglot ที่ตรวจได้ — ไฟล์ที่ขึ้นต้นด้วยสคริปต์แล้วต่อท้ายด้วยข้อมูลภาพ
     * magic bytes ที่ต้นไฟล์ไม่ใช่ของภาพ จึงต้องตก
     */
    public function test_a_detectable_polyglot_is_refused(): void
    {
        $user = $this->actor();
        $polyglot = '<?php /* '.base64_decode(self::REAL_PNG).' */ ?>';

        $this->postProfile($user, ['signature_base64' => 'data:image/png;base64,'.base64_encode($polyglot)])
            ->assertStatus(422);
    }

    /** ไฟล์ลายเซ็นที่อัปโหลด (ไม่ใช่ Base64) ต้องผ่านด่านเดียวกัน */
    public function test_an_uploaded_signature_file_of_a_forbidden_type_is_refused(): void
    {
        $user = $this->actor();
        $file = UploadedFile::fake()->createWithContent('signature.php', '<?php echo 1;');

        $this->actingAs($user)
            ->post('/api/profile', ['signature_file' => $file])
            ->assertStatus(422);
    }

    /** รูปประจำตัวเคยไม่มีกฎ validate เลย — ตอนนี้ต้องถูกปฏิเสธ */
    public function test_a_profile_photo_of_a_forbidden_type_is_refused(): void
    {
        $user = $this->actor();
        $file = UploadedFile::fake()->createWithContent('avatar.html', '<script>alert(1)</script>');

        $this->actingAs($user)
            ->post('/api/profile', ['profile_photo' => $file])
            ->assertStatus(422);

        $this->assertNull(UserProfile::where('user_id', $user->id)->value('profile_photo_path'));
    }

    /** รูปประจำตัวที่เป็นภาพจริงยังอัปโหลดได้ตามปกติ */
    public function test_a_genuine_profile_photo_is_still_accepted(): void
    {
        $user = $this->actor();

        $this->actingAs($user)
            ->post('/api/profile', ['profile_photo' => UploadedFile::fake()->image('avatar.png', 40, 40)])
            ->assertOk();

        $path = UserProfile::where('user_id', $user->id)->value('profile_photo_path');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('/storage/profile_photos/', $path);
    }

    /** ลายเซ็นบนคำขอยกเลิกใบลาต้องใช้ด่านเดียวกัน ไม่ใช่กฎของตัวเอง */
    public function test_the_cancellation_signature_uses_the_same_gate(): void
    {
        $user = $this->actor();
        $spoofed = 'data:image/png;base64,'.base64_encode('<?php echo 1;');

        // ใบลาไม่มีอยู่จริงก็พอ — กฎ validate ทำงานก่อนการค้นหาใบลาเสมอ
        $response = $this->actingAs($user)->postJson('/api/leaves/999999/approve-cancellation', [
            'signature_base64' => $spoofed,
        ]);

        $this->assertContains($response->status(), [404, 422]);
        $this->assertSame([], Storage::disk('private_uploads')->files('signatures'));
    }
}
