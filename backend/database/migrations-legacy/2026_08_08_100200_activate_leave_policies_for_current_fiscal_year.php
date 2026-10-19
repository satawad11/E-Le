<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_policies')->where('effective_from', '2026-10-01')->update(['effective_from' => '2025-10-01']);
    }

    public function down(): void
    {
        DB::table('leave_policies')->where('effective_from', '2025-10-01')->update(['effective_from' => '2026-10-01']);
    }
};
