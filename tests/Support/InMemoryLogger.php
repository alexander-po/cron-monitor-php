<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that just appends every record into an array. Used in tests
 * that need to assert observability output (warnings on retry exhaustion,
 * errors on bad UUIDs) without pulling in monolog as a dev dep.
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
