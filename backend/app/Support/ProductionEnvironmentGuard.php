<?php

namespace App\Support;

use LogicException;

final class ProductionEnvironmentGuard
{
    public static function validate(string $environment, bool $debug): void
    {
        if ($environment === 'production' && $debug) {
            throw new LogicException('APP_DEBUG ต้องเป็น false เมื่อ APP_ENV เป็น production');
        }
    }
}
