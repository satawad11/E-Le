<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('changes');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $secretKeys = config('system_settings.secret_keys', []);
        DB::table('settings')->whereIn('key', $secretKeys)->orderBy('id')->each(function ($setting) {
            if ($setting->value !== null && $setting->value !== '' && !Str::startsWith($setting->value, 'enc:')) {
                DB::table('settings')->where('id', $setting->id)->update([
                    'value' => 'enc:' . Crypt::encryptString($setting->value),
                    'type' => 'secret',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_audit_logs');
    }
};
