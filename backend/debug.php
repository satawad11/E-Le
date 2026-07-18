<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::all(['id','name','email','role_id']);
echo json_encode($users, JSON_PRETTY_PRINT);
echo "\n\nRoles:\n";
echo json_encode(App\Models\Role::all()->toArray(), JSON_PRETTY_PRINT);
