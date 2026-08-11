<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;

try {
    $user = User::first();
    auth()->login($user);

    $controller = app(\App\Http\Controllers\Api\RoleController::class);
    $response = $controller->index(new Request());
    echo "SUCCESS INDEX: " . substr($response->getContent(), 0, 100) . "...\n";

    $response2 = $controller->permissions(new Request());
    echo "SUCCESS PERMS: " . substr($response2->getContent(), 0, 100) . "...\n";
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
