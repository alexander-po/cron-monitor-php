<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorStatus;
use CronMonitor\Api\Dto\ScheduleKind;
use PHPUnit\Framework\TestCase;

final class MonitorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Nightly report',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => '2026-01-02T02:00:00+00:00',
            'last_ping_at' => '2026-01-01T02:00:15+00:00',
            'created_at' => '2025-12-31T12:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/550e8400-e29b-41d4-a716-446655440000',
            'badge_url' => 'https://cronheart.com/badge/550e8400-e29b-41d4-a716-446655440000.svg',
        ], $overrides);
    }

    public function test_from_array_hydrates_all_fields(): void
    {
        $monitor = Monitor::fromArray(self::payload());

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $monitor->uuid);
        self::assertSame('Nightly report', $monitor->name);
        self::assertSame(ScheduleKind::Cron, $monitor->scheduleKind);
        self::assertSame('0 2 * * *', $monitor->scheduleExpr);
        self::assertSame('UTC', $monitor->tz);
        self::assertSame(60, $monitor->graceSeconds);
        self::assertSame(MonitorStatus::Up, $monitor->status);
        self::assertInstanceOf(\DateTimeImmutable::class, $monitor->nextExpectedAt);
        self::assertSame('2026-01-02T02:00:00+00:00', $monitor->nextExpectedAt->format(\DateTimeInterface::RFC3339));
        self::assertInstanceOf(\DateTimeImmutable::class, $monitor->lastPingAt);
        self::assertSame('2025-12-31T12:00:00+00:00', $monitor->createdAt->format(\DateTimeInterface::RFC3339));
        self::assertStringContainsString('/ping/', $monitor->pingUrl);
        self::assertStringContainsString('/badge/', $monitor->badgeUrl);
    }

    public function test_null_timestamps_become_null(): void
    {
        $monitor = Monitor::fromArray(self::payload([
            'next_expected_at' => null,
            'last_ping_at' => null,
        ]));

        self::assertNull($monitor->nextExpectedAt);
        self::assertNull($monitor->lastPingAt);
    }

    public function test_fractional_second_timestamps_parse(): void
    {
        $monitor = Monitor::fromArray(self::payload([
            'created_at' => '2025-12-31T12:00:00.123456+00:00',
        ]));

        self::assertSame(123456, (int) $monitor->createdAt->format('u'));
    }

    public function test_unknown_status_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['status' => 'degraded']));
    }

    public function test_unknown_schedule_kind_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['schedule_kind' => 'quartz']));
    }

    public function test_missing_required_field_is_a_contract_violation(): void
    {
        $payload = self::payload();
        unset($payload['uuid']);

        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray($payload);
    }

    public function test_wrong_type_for_grace_seconds_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['grace_seconds' => '60']));
    }

    public function test_malformed_timestamp_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['created_at' => 'not-a-date']));
    }
}
