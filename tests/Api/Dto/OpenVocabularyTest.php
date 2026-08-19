<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Alert;
use CronMonitor\Api\Dto\AlertKind;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorStatus;
use CronMonitor\Api\Dto\Ping;
use CronMonitor\Api\Dto\PingKind;
use CronMonitor\Api\Dto\Plan;
use CronMonitor\Api\Dto\PlanKey;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Dto\Vocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Vocabulary is open on read and closed on write.
 *
 * The server's vocabularies grow — a sixth monitor status, a new alert kind —
 * and a reader that rejects the value it does not recognise turns that growth
 * into an outage for every SDK version already installed, including for the
 * callers who never branch on the field. So a response DTO keeps the raw
 * string instead. What stays strict is the *type*: a status that arrives as a
 * number is a malformed response and still fails the read loudly, and a
 * request DTO still takes nothing but a real enum case, so a value the SDK
 * merely passed through can never be written back as if it were understood.
 */
final class OpenVocabularyTest extends TestCase
{
    public function test_an_unknown_monitor_status_hydrates_as_its_raw_string(): void
    {
        $monitor = Monitor::fromArray(self::monitorRow(['status' => 'quarantined']));

        self::assertSame('quarantined', $monitor->status);
        self::assertSame('Nightly report', $monitor->name);
    }

    public function test_an_unknown_schedule_kind_hydrates_as_its_raw_string(): void
    {
        $monitor = Monitor::fromArray(self::monitorRow(['schedule_kind' => 'solar_eclipse']));

        self::assertSame('solar_eclipse', $monitor->scheduleKind);
    }

    public function test_a_known_monitor_status_still_hydrates_as_the_enum_case(): void
    {
        $monitor = Monitor::fromArray(self::monitorRow());

        self::assertSame(MonitorStatus::Up, $monitor->status);
        self::assertSame(ScheduleKind::Cron, $monitor->scheduleKind);
    }

    public function test_an_unknown_ping_kind_hydrates_as_its_raw_string(): void
    {
        $ping = Ping::fromArray([
            'id' => '42',
            'kind' => 'warmup',
            'received_at' => '2026-01-01T00:00:00+00:00',
            'runtime_ms' => null,
        ]);

        self::assertSame('warmup', $ping->kind);
    }

    public function test_a_known_ping_kind_still_hydrates_as_the_enum_case(): void
    {
        $ping = Ping::fromArray([
            'id' => '42',
            'kind' => 'success',
            'received_at' => '2026-01-01T00:00:00+00:00',
            'runtime_ms' => 120,
        ]);

        self::assertSame(PingKind::Success, $ping->kind);
    }

    public function test_an_unknown_alert_kind_hydrates_as_its_raw_string(): void
    {
        $alert = Alert::fromArray([
            'id' => '7',
            'kind' => 'flapping',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'dispatched_to' => null,
        ]);

        self::assertSame('flapping', $alert->kind);
        self::assertNotInstanceOf(AlertKind::class, $alert->kind);
    }

    public function test_an_unknown_plan_key_hydrates_as_its_raw_string(): void
    {
        $plan = Plan::fromArray(['key' => 'enterprise', 'label' => 'Enterprise', 'monitor_limit' => 5000]);

        self::assertSame('enterprise', $plan->key);
    }

    public function test_a_known_plan_key_still_hydrates_as_the_enum_case(): void
    {
        $plan = Plan::fromArray(['key' => 'starter', 'label' => 'Starter', 'monitor_limit' => 50]);

        self::assertSame(PlanKey::Starter, $plan->key);
    }

    public function test_vocabulary_value_yields_the_string_from_either_half(): void
    {
        // Displaying the value is the commonest thing callers do with these
        // fields, and `->value` fatals on the string half.
        $known = Monitor::fromArray(self::monitorRow());
        $unknown = Monitor::fromArray(self::monitorRow(['status' => 'quarantined']));

        self::assertSame('up', Vocabulary::value($known->status));
        self::assertSame('quarantined', Vocabulary::value($unknown->status));
    }

    public function test_a_wrongly_typed_status_still_fails_the_read(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('must be string');

        Monitor::fromArray(self::monitorRow(['status' => 42]));
    }

    public function test_a_request_dto_refuses_a_vocabulary_string(): void
    {
        // The write side has no tolerance to inherit: a schedule kind the SDK
        // only passed through on a read must not travel back out as if the SDK
        // had understood it. Constructed reflectively so the type error is
        // raised at runtime rather than caught by static analysis first.
        $this->expectException(\TypeError::class);

        (new \ReflectionClass(CreateMonitorRequest::class))
            ->newInstanceArgs(['Nightly report', 'solar_eclipse', '0 2 * * *']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function monitorRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Nightly report',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/550e8400-e29b-41d4-a716-446655440000',
            'badge_url' => 'https://cronheart.com/badge/550e8400-e29b-41d4-a716-446655440000.svg',
        ], $overrides);
    }
}
