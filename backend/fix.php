<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (\App\Models\UserProfile::all() as $profile) {
    if ($profile->id_card_number) {
        $profile->id_card_number_hash = hash('sha256', $profile->id_card_number . config('app.key'));
        $profile->save();
        echo "Updated profile {$profile->id}\n";
    }
}
