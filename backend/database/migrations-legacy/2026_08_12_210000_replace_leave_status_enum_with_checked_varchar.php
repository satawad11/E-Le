<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_STATUSES = [
        'Draft',
        'Pending',
        'Approved',
        'Rejected',
        'Cancelled',
        'Voided',
    ];

    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table): void {
            $table->string('status', 20)->default('Draft')->change();
        });

        if (DB::getDriverName() === 'mysql') {
            $statuses = implode("','", self::ALLOWED_STATUSES);
            DB::statement("ALTER TABLE leaves ADD CONSTRAINT leaves_status_check CHECK (status IN ('{$statuses}'))");
        }
    }

    public function down(): void
    {
        // Forward-only: restoring ENUM would reject rows already marked Voided.
    }
};
