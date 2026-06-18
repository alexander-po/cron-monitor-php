<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Internal;

use CronMonitor\Api\Internal\Hydrator;
use PHPUnit\Framework\TestCase;

final class HydratorTest extends TestCase
{
    public function test_nullable_int_returns_int_when_present(): void
    {
        self::assertSame(42, Hydrator::nullableInt(['runtime_ms' => 42], 'runtime_ms'));
    }

    public function test_nullable_int_returns_null_when_null_or_absent(): void
    {
        self::assertNull(Hydrator::nullableInt(['runtime_ms' => null], 'runtime_ms'));
        self::assertNull(Hydrator::nullableInt([], 'runtime_ms'));
    }

    public function test_nullable_int_rejects_non_int(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Hydrator::nullableInt(['runtime_ms' => '42'], 'runtime_ms');
    }
}
