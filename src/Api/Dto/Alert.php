<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * A single alert in a monitor's history (`GET /api/v1/monitors/{uuid}/alerts`).
 *
 * `dispatchedTo` is whatever the backend returns describing where the alert
 * was routed (or null if it was not dispatched); it is stored verbatim, like
 * {@see Channel::$config}.
 */
final class Alert
{
    /**
     * @param array<string, mixed>|null $dispatchedTo
     */
    public function __construct(
        public readonly string $id,
        public readonly AlertKind $kind,
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
            Hydrator::enum(AlertKind::class, $data, 'kind'),
            Hydrator::dateTime($data, 'created_at'),
            $dispatchedTo,
        );
    }
}
