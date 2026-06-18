<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * The account's monitor allowance usage (part of {@see Account}).
 */
final class MonitorBudget
{
    public function __construct(
        public readonly int $used,
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
            Hydrator::int($data, 'used'),
            Hydrator::int($data, 'limit'),
            Hydrator::int($data, 'remaining'),
        );
    }
}
