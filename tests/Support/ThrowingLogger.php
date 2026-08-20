<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that throws on every record — a monolog handler with a full
 * disk, an unwritable path, or a failing remote transport all surface this
 * way, and the ping client's own observability call must not be what breaks
 * the host job.
 */
final class ThrowingLogger extends AbstractLogger
{
    public int $calls = 0;

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        ++$this->calls;

        throw new \RuntimeException('logger backend is unavailable');
    }
}
