<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * A single alert in a monitor's history (`GET /api/v1/monitors/{uuid}/alerts`).
 *
 * `dispatchedTo` is whatever the server returns describing where the alert
 * was routed (or null if it was not dispatched); it is stored verbatim, like
 * {@see Channel::$config}. `kind` is the enum case when the SDK knows the
 * value and the server's verbatim string when it does not, so a kind added
 * server-side cannot make a history page unreadable.
 */
final class Alert
{
    /**
     * @param array<string, mixed>|null $dispatchedTo
     */
    public function __construct(
        public readonly string $id,
        public readonly AlertKind|string $kind,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?array $dispatchedTo,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        $dispatchedTo = $data['dispatched_to'] ?? null;
        if (null !== $dispatchedTo && !\is_array($dispatchedTo)) {
            throw new \UnexpectedValueException('Alert "dispatched_to" must be a JSON object or null.');
        }
        /** @var array<string, mixed>|null $dispatchedTo */

        return new self(
            Hydrator::string($data, 'id'),
            Hydrator::openEnum(AlertKind::class, $data, 'kind'),
            Hydrator::dateTime($data, 'created_at'),
            $dispatchedTo,
        );
    }
}
