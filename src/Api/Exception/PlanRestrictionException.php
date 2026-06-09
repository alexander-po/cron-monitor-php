<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 402 — the account's plan does not include API access.
 *
 * The backend gates the management API behind the Starter tier and up.
 * `$upgradeUrl` carries the RFC 7807 `upgrade_url` extension when present,
 * so a UI can link the operator straight to the upgrade page.
 */
final class PlanRestrictionException extends ApiException
{
    public function __construct(
        string $message,
        public readonly ?string $upgradeUrl = null,
        ?int $statusCode = null,
        ?string $detail = null,
        ?string $title = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $detail, $title, $previous);
    }
}
