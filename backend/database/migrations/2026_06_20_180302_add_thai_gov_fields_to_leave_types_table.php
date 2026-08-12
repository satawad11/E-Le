<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('exclude_holidays')->default(true)->comment('If true, holidays and weekends are not counted as leave days');
            $table->integer('notice_period_days')->default(0)->comment('Number of days in advance required to request this leave');
            $table->boolean('allow_past_dates')->default(false)->comment('If true, can submit leave for past dates (e.g. sick leave)');
            $table->integer('require_attachment_after_days')->nullable()->comment('Require attachment if leave exceeds this many days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn([
                'exclude_holidays',
                'notice_period_days',
                'allow_past_dates',
                'require_attachment_after_days',
            ]);
        });
    }
};
