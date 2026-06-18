<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * Snapshot of the authenticated account (`GET /api/v1/account`): the current
 * plan, the monitor budget, and the live API rate-limit standing.
 */
final class Account
{
    public function __construct(
        public readonly Plan $plan,
        public readonly MonitorBudget $monitorBudget,
        public readonly RateLimitStanding $apiRateLimit,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        $plan = Hydrator::arr($data, 'plan');
        $budget = Hydrator::arr($data, 'monitor_budget');
        $rate = Hydrator::arr($data, 'api_rate_limit');
        /** @var array<string, mixed> $plan */
        /** @var array<string, mixed> $budget */
        /** @var array<string, mixed> $rate */

        return new self(
            Plan::fromArray($plan),
            MonitorBudget::fromArray($budget),
            RateLimitStanding::fromArray($rate),
        );
    }
}
