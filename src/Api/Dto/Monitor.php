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
 * `\DateTimeImmutable`. `nextExpectedAt` / `lastPingAt` / `snoozedUntil` are
 * null until the monitor has a computed deadline / its first ping / an active
 * snooze.
 *
 * `channels` is the monitor's alert routing — which notification channels it
 * delivers to, in the id order the backend sends. It reports **attachment,
 * not deliverability**: verification, an active snooze, `paused`, and an
 * operator's per-kind switch each suppress delivery independently, so a
 * non-empty list is not a promise that an alert reaches anyone (see
 * {@see MonitorChannel}).
 *
 * Fields added after 1.0.0 are appended in release order and defaulted, so
 * adding one stays binary-compatible for existing positional callers, and a
 * response from a backend that predates the field (older deployments /
 * fixtures) still hydrates: `snoozed_until` to null, `channels` to an empty
 * list — as does an explicit `"channels": null`, treated the same as absent.
 * An empty `channels` therefore means either "routes nowhere" or "this backend
 * does not report routing" — indistinguishable on purpose, mirroring how
 * `snoozedUntil` already treats an absent key. Consequence worth knowing
 * before automating: never feed an empty list straight into
 * {@see UpdateMonitorRequest::$channelIds}, because an empty `channel_ids`
 * *replaces* the target's routing with none rather than leaving it alone.
 */
final class Monitor
{
    /**
     * @param list<MonitorChannel> $channels
     */
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
        public readonly ?\DateTimeImmutable $snoozedUntil = null,
        public readonly array $channels = [],
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
            Hydrator::nullableDateTime($data, 'snoozed_until'),
            self::channelsFrom($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<MonitorChannel>
     *
     * @throws \UnexpectedValueException when the routing list or an entry is malformed
     */
    private static function channelsFrom(array $data): array
    {
        // Absent on a backend older than the routing-reads release; treated as
        // "no routing reported" rather than failing the whole monitor read.
        $rows = $data['channels'] ?? [];
        if (!\is_array($rows)) {
            throw new \UnexpectedValueException('Monitor "channels" must be a JSON array.');
        }

        $channels = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Each monitor channel entry must be a JSON object.');
            }
            /** @var array<string, mixed> $row */
            $channels[] = MonitorChannel::fromArray($row);
        }

        return $channels;
    }
}
