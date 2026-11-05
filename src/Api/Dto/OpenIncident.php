<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * The incident a monitor has open ({@see Monitor::$openIncident}).
 *
 * No new late or fail alert fires while it is open; a successful run closes it. `kind` is
 * the alert that opened it: the enum case when the SDK knows the value, the
 * server's verbatim string when it does not. `since` is when that alert was
 * raised, or null when it cannot be found.
 */
final class OpenIncident
{
    public function __construct(
        public readonly AlertKind|string $kind,
        public readonly ?\DateTimeImmutable $since,
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
            Hydrator::openEnum(AlertKind::class, $data, 'kind'),
            Hydrator::nullableDateTime($data, 'since'),
        );
    }
}
