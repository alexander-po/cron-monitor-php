<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * The account's live API rate-limit standing for its current plan tier (part
 * of {@see Account}). `limit` reflects the per-plan tier (e.g. Starter 120,
 * Growth 300, Scale 600 requests/min).
 */
final class RateLimitStanding
{
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::int($data, 'limit'),
            Hydrator::int($data, 'remaining'),
        );
    }
}
