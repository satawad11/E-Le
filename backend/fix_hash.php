<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$profiles = \App\Models\UserProfile::all();
foreach ($profiles as $profile) {
    if ($profile->id_card_number) {
        $profile->id_card_number_hash = hash('sha256', $profile->id_card_number . config('app.key'));
        $profile->saveQuietly();
    }
}
echo "Hashes generated successfully.\n";
