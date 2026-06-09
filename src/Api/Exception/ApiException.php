<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Base class for every error the management API client raises.
 *
 * Unlike the ping client (`CronMonitor\Client\CronMonitorClient`), which
 * never throws so it can never break a host cron job, the API client is
 * used in admin UIs and CLI tooling where the caller *wants* to know that
 * something failed. Catch this base type to handle any API failure
 * uniformly, or a specific subclass (e.g. {@see ValidationException}) to
 * branch on the failure mode.
 *
 * `$statusCode` is the HTTP status, or null for transport / decode
 * failures (see {@see ApiTransportException}). `$detail` / `$title` carry
 * the RFC 7807 `application/problem+json` fields when the backend supplied
 * them.
 */
abstract class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $detail = null,
        public readonly ?string $title = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
