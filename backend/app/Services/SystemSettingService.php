<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemSettingService
{
    public function runtimeValues(): Collection
    {
        $values = Setting::all()->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value]);
        foreach (config('system_settings.aliases', []) as $canonical => $aliases) {
            if (! $values->has($canonical)) {
                foreach ($aliases as $alias) {
                    if ($values->has($alias)) {
                        $values->put($canonical, $values->get($alias));
                        break;
                    }
                }
            }
        }

        return $values;
    }

    public function publicValues(): array
    {
        return $this->runtimeValues()->only(config('system_settings.public_keys', []))->all();
    }

    public function adminValues(): array
    {
        $values = $this->runtimeValues()->all();

        foreach (config('system_settings.secret_keys', []) as $key) {
            $values[$key.'_configured'] = filled($values[$key] ?? null);
            $values[$key] = null;
        }

        return $values;
    }

    public function updateMany(array $values, ?User $actor = null, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $secrets = config('system_settings.secret_keys', []);
        $requestedKeys = array_keys($values);
        foreach ($secrets as $secret) {
            if (array_key_exists($secret, $values) && blank($values[$secret])) {
                unset($values[$secret]);
                $requestedKeys = array_values(array_diff($requestedKeys, [$secret]));
            }
        }

        foreach (config('system_settings.aliases', []) as $canonical => $aliases) {
            if (array_key_exists($canonical, $values)) {
                foreach ($aliases as $alias) {
                    $values[$alias] = $values[$canonical];
                }
            }
        }

        $changes = DB::transaction(function () use ($values, $requestedKeys, $secrets, $actor, $ipAddress, $userAgent) {
            $changes = [];
            foreach ($values as $key => $value) {
                $setting = Setting::firstOrNew(['key' => $key]);
                $before = $setting->exists ? $setting->value : null;
                $after = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
                if ((string) $before === (string) $after) {
                    continue;
                }
                $setting->value = $after;
                $setting->type = in_array($key, $secrets, true) ? 'secret' : $this->typeOf($value);
                $setting->save();

                if (in_array($key, $requestedKeys, true)) {
                    $changes[$key] = in_array($key, $secrets, true)
                        ? ['before' => filled($before) ? '[configured]' : null, 'after' => '[changed]']
                        : ['before' => $before, 'after' => $after];
                }
            }

            if ($changes !== []) {
                DB::table('setting_audit_logs')->insert([
                    'user_id' => $actor?->id,
                    'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $changes;
        });

        Cache::forget('app_settings');

        return $changes;
    }

    public function clearSecret(string $key, ?User $actor, ?string $ipAddress, ?string $userAgent): void
    {
        abort_unless(in_array($key, config('system_settings.secret_keys', []), true), 404);
        $setting = Setting::where('key', $key)->first();
        if (! $setting) {
            return;
        }
        $setting->delete();
        DB::table('setting_audit_logs')->insert([
            'user_id' => $actor?->id,
            'changes' => json_encode([$key => ['before' => '[configured]', 'after' => null]]),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::forget('app_settings');
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
