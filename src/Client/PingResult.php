<?php

declare(strict_types=1);

namespace CronMonitor\Client;

/**
 * Outcome of a single ping attempt.
 *
 * Pings are fire-and-forget from the user's perspective — a failure must
 * never break the scheduler's own job. The result object exists so callers
 * who care (logging middleware, dashboards) can introspect the failure mode
 * without parsing exceptions.
 */
final class PingResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly ?int $statusCode,
        public readonly ?string $errorMessage,
        public readonly int $attempts,
    ) {
    }

    public static function delivered(int $statusCode, int $attempts): self
    {
        return new self(true, $statusCode, null, $attempts);
    }

    public static function failed(?int $statusCode, string $errorMessage, int $attempts): self
    {
        return new self(false, $statusCode, $errorMessage, $attempts);
    }
}
