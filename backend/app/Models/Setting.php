<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
    ];

    protected function value(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$this->isSecret() || blank($value) || !Str::startsWith($value, 'enc:')) {
                    return $value;
                }

                try {
                    return Crypt::decryptString(Str::after($value, 'enc:'));
                } catch (DecryptException) {
                    report(new \RuntimeException("Unable to decrypt system setting [{$this->key}]."));
                    return null;
                }
            },
            set: function ($value) {
                if (!$this->isSecret() || blank($value) || Str::startsWith((string) $value, 'enc:')) {
                    return $value;
                }

                return 'enc:' . Crypt::encryptString((string) $value);
            },
        );
    }

    private function isSecret(): bool
    {
        return in_array($this->key, config('system_settings.secret_keys', []), true);
    }
}

