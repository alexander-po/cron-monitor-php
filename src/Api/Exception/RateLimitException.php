<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 429 — the per-token rate limit was exceeded.
 *
 * `$retryAfter` is the number of seconds to wait before retrying, taken
 * from the `Retry-After` response header when present and otherwise from
 * the RFC 7807 `retry_after` body extension. The client does not sleep or
 * auto-retry on 429 — that decision is left to the caller, who can read
 * this field to implement backoff.
 */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        ?int $statusCode = null,
        ?string $detail = null,
        ?string $title = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $detail, $title, $previous);
    }
}
