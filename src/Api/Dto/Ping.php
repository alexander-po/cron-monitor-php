<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * A single ping in a monitor's history (`GET /api/v1/monitors/{uuid}/pings`).
 *
 * PII (remote IP, user-agent, body excerpt) is omitted by the API, so this DTO
 * carries only the kind, the receive time, and the optional runtime.
 * `runtimeMs` is null unless the ping reported a duration. `id` is the
 * backend's BIGINT identifier carried as a string (it can exceed PHP's int
 * range), matching the API's serialized type.
 */
final class Ping
{
    public function __construct(
        public readonly string $id,
        public readonly PingKind $kind,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly ?int $runtimeMs,
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
            Hydrator::string($data, 'id'),
            Hydrator::enum(PingKind::class, $data, 'kind'),
            Hydrator::dateTime($data, 'received_at'),
            Hydrator::nullableInt($data, 'runtime_ms'),
        );
    }
}
