<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * The account's plan tier and its monitor allowance (part of {@see Account}).
 *
 * `key` is the enum case when the SDK knows the tier and the server's
 * verbatim string when it does not, so a tier added server-side cannot make
 * the account unreadable.
 */
final class Plan
{
    public function __construct(
        public readonly PlanKey|string $key,
        public readonly string $label,
        public readonly int $monitorLimit,
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
            Hydrator::openEnum(PlanKey::class, $data, 'key'),
            Hydrator::string($data, 'label'),
            Hydrator::int($data, 'monitor_limit'),
        );
    }
}
