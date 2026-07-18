<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class EncryptSensitiveDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:encrypt-sensitive-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encrypt existing plaintext sensitive data in database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting encryption of sensitive data...');

        // 1. Encrypt User Profiles
        $profiles = UserProfile::all();
        $this->withProgressBar($profiles, function ($profile) {
            $rawProfile = DB::table('user_profiles')->where('id', $profile->id)->first();
            
            $updates = [];
            
            if ($rawProfile->id_card_number) {
                // Already encrypted (starts with eyJ) — decrypt to get plaintext for hashing
                if (str_starts_with($rawProfile->id_card_number, 'eyJ')) {
                    try {
                        $plainId = Crypt::decryptString($rawProfile->id_card_number);
                        // Always regenerate hash in case APP_KEY changed
                        $updates['id_card_number_hash'] = hash('sha256', $plainId . config('app.key'));
                    } catch (DecryptException $e) {
                        // Cannot decrypt — skip hash update
                    }
                } else {
                    // Plaintext — encrypt and hash
                    $updates['id_card_number'] = Crypt::encryptString($rawProfile->id_card_number);
                    $updates['id_card_number_hash'] = hash('sha256', $rawProfile->id_card_number . config('app.key'));
                }
            }

            if ($rawProfile->passport_number && !str_starts_with($rawProfile->passport_number, 'eyJ')) {
                $updates['passport_number'] = Crypt::encryptString($rawProfile->passport_number);
            }

            if (!empty($updates)) {
                DB::table('user_profiles')->where('id', $profile->id)->update($updates);
            }
        });
        
        $this->newLine();
        $this->info('UserProfile encryption complete.');

        // 2. Encrypt User Phone Numbers
        $users = User::all();
        $this->withProgressBar($users, function ($user) {
            $rawUser = DB::table('users')->where('id', $user->id)->first();
            if ($rawUser->phone && !str_starts_with($rawUser->phone, 'eyJ')) {
                DB::table('users')->where('id', $user->id)->update([
                    'phone' => Crypt::encryptString($rawUser->phone)
                ]);
            }
        });

        $this->newLine();
        $this->info('User phone encryption complete.');
    }
}
