<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 402 — the server declined the call on the account's plan.
 *
 * Every plan includes the management API (Free at 30 requests a minute per
 * account, the paid tiers above that), so cronheart.com no longer answers
 * 402 for API access itself and this class is not an "upgrade to Starter"
 * signal. It stays so the status mapping is stable: a self-hosted or older
 * backend, or a plan-gated feature added later, can still send one, with
 * `$upgradeUrl` carrying the RFC 7807 `upgrade_url` extension when present.
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
