<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SarabanSettingSeeder extends Seeder
{
    /**
     * Signing and acknowledgement default to requiring the user's password.
     * DocumentWorkflowService also defaults to "required" when the row is
     * missing, but seeding it makes the value visible in the settings UI.
     */
    public function run(): void
    {
        $defaults = [
            'require_pin_for_signature' => '1',
            'require_pin_for_circular_ack' => '1',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
