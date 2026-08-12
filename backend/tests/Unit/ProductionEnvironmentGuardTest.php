<?php

namespace Tests\Unit;

use App\Support\ProductionEnvironmentGuard;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductionEnvironmentGuardTest extends TestCase
{
    public function test_it_rejects_debug_mode_in_production(): void
    {
        $this->expectException(LogicException::class);

        ProductionEnvironmentGuard::validate('production', true);
    }

    #[DataProvider('safeEnvironmentProvider')]
    public function test_it_accepts_safe_environment_combinations(string $environment, bool $debug): void
    {
        ProductionEnvironmentGuard::validate($environment, $debug);

        $this->addToAssertionCount(1);
    }

    public static function safeEnvironmentProvider(): array
    {
        return [
            'production without debug' => ['production', false],
            'local with debug' => ['local', true],
            'testing with debug' => ['testing', true],
        ];
    }
}
