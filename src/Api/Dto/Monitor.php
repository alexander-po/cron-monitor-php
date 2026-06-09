<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * An immutable snapshot of a monitor as returned by the management API
 * (`GET /api/v1/monitors`, `GET /api/v1/monitors/{uuid}`, and the `201`
 * body of a create).
 *
 * Timestamps are parsed from the backend's RFC 3339 atoms into
 * `\DateTimeImmutable`. `nextExpectedAt` / `lastPingAt` are null until the
 * monitor has a computed deadline / its first ping.
 */
final class Monitor
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ScheduleKind $scheduleKind,
        public readonly string $scheduleExpr,
        public readonly string $tz,
        public readonly int $graceSeconds,
        public readonly MonitorStatus $status,
        public readonly ?\DateTimeImmutable $nextExpectedAt,
        public readonly ?\DateTimeImmutable $lastPingAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $pingUrl,
        public readonly string $badgeUrl,
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
            Hydrator::string($data, 'uuid'),
            Hydrator::string($data, 'name'),
            Hydrator::enum(ScheduleKind::class, $data, 'schedule_kind'),
            Hydrator::string($data, 'schedule_expr'),
            Hydrator::string($data, 'tz'),
            Hydrator::int($data, 'grace_seconds'),
            Hydrator::enum(MonitorStatus::class, $data, 'status'),
            Hydrator::nullableDateTime($data, 'next_expected_at'),
            Hydrator::nullableDateTime($data, 'last_ping_at'),
            Hydrator::dateTime($data, 'created_at'),
            Hydrator::string($data, 'ping_url'),
            Hydrator::string($data, 'badge_url'),
        );
    }
}
