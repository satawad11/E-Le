<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('email', 'superadmin@example.com')->first();
if ($user) {
    echo "User found: " . $user->name . " (Email: " . $user->email . ")\n";
    $check = \Illuminate\Support\Facades\Hash::check('1111111111111', $user->password);
    echo "Password '1111111111111' check result: " . ($check ? 'SUCCESS' : 'FAILED') . "\n";
} else {
    echo "User superadmin@example.com NOT found!\n";
}
