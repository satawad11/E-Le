<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

App\Models\LeaveBalance::firstOrCreate(['user_id' => 1, 'leave_type_id' => 1, 'year' => date('Y')], ['quota' => 10, 'used' => 0]);
App\Models\LeaveBalance::firstOrCreate(['user_id' => 1, 'leave_type_id' => 2, 'year' => date('Y')], ['quota' => 10, 'used' => 0]);
App\Models\LeaveBalance::firstOrCreate(['user_id' => 2, 'leave_type_id' => 1, 'year' => date('Y')], ['quota' => 10, 'used' => 0]);
App\Models\LeaveBalance::firstOrCreate(['user_id' => 2, 'leave_type_id' => 2, 'year' => date('Y')], ['quota' => 10, 'used' => 0]);
echo "Done";
