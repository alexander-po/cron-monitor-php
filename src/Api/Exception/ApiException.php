<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

use CronMonitor\Api\Internal\SecretRedactor;

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
 *
 * `getPrevious()` still returns the original throwable untouched, so callers
 * can branch on its type; it is the *rendered* form that is scrubbed, since
 * that is the copy that gets pasted into issue trackers and chat.
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

    /**
     * The standard rendering, minus the credentials. PHP walks the whole
     * `previous` chain here, and a transport exception one level down still
     * quotes the resolved request URI — which for a monitor route carries the
     * UUID this client is careful to keep out of its own message.
     */
    public function __toString(): string
    {
        return SecretRedactor::redact(parent::__toString());
    }
}
