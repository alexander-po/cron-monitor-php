<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorChannel;
use CronMonitor\Api\Dto\MonitorStatus;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Dto\UpdateMonitorRequest;
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

    public function test_snoozed_until_hydrates_when_present(): void
    {
        $monitor = Monitor::fromArray(self::payload(['snoozed_until' => '2026-01-03T09:00:00+00:00']));

        self::assertInstanceOf(\DateTimeImmutable::class, $monitor->snoozedUntil);
        self::assertSame('2026-01-03T09:00:00+00:00', $monitor->snoozedUntil->format(\DateTimeInterface::RFC3339));
    }

    public function test_snoozed_until_is_null_when_absent_or_null(): void
    {
        // BC guarantee: a response from an older backend without the
        // snoozed_until key (the default payload here omits it) still parses,
        // hydrating snoozedUntil to null.
        self::assertNull(Monitor::fromArray(self::payload())->snoozedUntil);
        self::assertNull(Monitor::fromArray(self::payload(['snoozed_until' => null]))->snoozedUntil);
    }

    public function test_channels_hydrate_in_the_order_the_backend_sent_them(): void
    {
        // The backend orders routing by channel id and a caller may diff the
        // list against a desired spec, so the SDK preserves the order it was
        // given rather than imposing its own. The fixture is deliberately in
        // NON-canonical order: an ascending one could not tell "preserved"
        // apart from "re-sorted numerically".
        $monitor = Monitor::fromArray(self::payload(['channels' => [
            ['id' => '11', 'kind' => 'slack', 'label' => 'deploys'],
            ['id' => '3', 'kind' => 'telegram', 'label' => 'ops-bot'],
        ]]));

        self::assertCount(2, $monitor->channels);
        self::assertContainsOnlyInstancesOf(MonitorChannel::class, $monitor->channels);
        self::assertSame(['11', '3'], array_map(static fn (MonitorChannel $c): string => $c->id, $monitor->channels));
        self::assertSame('deploys', $monitor->channels[0]->label);
        self::assertSame('telegram', $monitor->channels[1]->kind);
    }

    public function test_channels_are_empty_when_absent_or_empty(): void
    {
        // BC guarantee, and the reason this is not nullable: a backend older
        // than the routing-reads release omits the key entirely, and a monitor
        // that routes nowhere reports an empty list. Both hydrate to [] rather
        // than failing the read. An explicit null is treated as absent too —
        // the backend never sends it, and failing a whole monitor read over a
        // secondary field would be worse than reporting no routing.
        self::assertArrayNotHasKey('channels', self::payload(), 'this case rests on the shared fixture omitting the key');
        self::assertSame([], Monitor::fromArray(self::payload())->channels);
        self::assertSame([], Monitor::fromArray(self::payload(['channels' => []]))->channels);
        self::assertSame([], Monitor::fromArray(self::payload(['channels' => null]))->channels);
    }

    public function test_channel_ids_can_be_handed_straight_back_to_an_update(): void
    {
        // The round trip the field exists for: read one monitor's routing and
        // copy it onto another without converting anything. UpdateMonitorRequest
        // accepts int|string ids, so MonitorChannel::$id feeds straight in.
        $monitor = Monitor::fromArray(self::payload(['channels' => [
            ['id' => '3', 'kind' => 'telegram', 'label' => 'ops-bot'],
            ['id' => '11', 'kind' => 'slack', 'label' => 'deploys'],
        ]]));

        $body = (new UpdateMonitorRequest(
            channelIds: array_map(static fn (MonitorChannel $c): string => $c->id, $monitor->channels),
        ))->toArray();

        self::assertSame(['3', '11'], $body['channel_ids']);
    }

    public function test_empty_routing_must_not_be_copied_blindly_onto_another_monitor(): void
    {
        // The trap the README guards against: an empty `channel_ids` REPLACES
        // the target's routing with none, so feeding an unreported/empty list
        // into an update silently unroutes the target. Pinned here so the
        // hazard is a documented property, not a footnote nobody re-derives.
        $unrouted = Monitor::fromArray(self::payload());
        self::assertSame([], $unrouted->channels, 'precondition: nothing to copy');

        $body = (new UpdateMonitorRequest(
            channelIds: array_map(static fn (MonitorChannel $c): string => $c->id, $unrouted->channels),
        ))->toArray();

        self::assertSame([], $body['channel_ids']);
        self::assertArrayHasKey('channel_ids', $body, 'an empty list is still sent, i.e. it clears routing — hence the guard in the README');
    }

    public function test_a_malformed_channel_object_fails_the_read_rather_than_being_skipped(): void
    {
        // A well-formed list holding a malformed entry (no `kind`) must throw,
        // not quietly yield N-1 channels: a caller diffing routing would then
        // write back the shortened list and detach a live alert channel.
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['channels' => [
            ['id' => '3', 'kind' => 'telegram', 'label' => 'ops-bot'],
            ['id' => '11', 'label' => 'deploys'],
        ]]));
    }

    public function test_positional_construction_without_the_new_field_still_works(): void
    {
        // The CHANGELOG promises 1.0.0-era positional callers are unaffected.
        // Nothing inside the package constructs a Monitor directly, so without
        // this case that promise rests on an untested default: dropping `= []`
        // would keep the suite green and break every downstream caller with an
        // ArgumentCountError.
        $monitor = new Monitor(
            '550e8400-e29b-41d4-a716-446655440000',
            'Nightly report',
            ScheduleKind::Cron,
            '0 2 * * *',
            'UTC',
            60,
            MonitorStatus::Up,
            null,
            null,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'https://cronheart.com/ping/x',
            'https://cronheart.com/badge/x.svg',
        );

        self::assertSame([], $monitor->channels);
        self::assertNull($monitor->snoozedUntil);
    }

    public function test_non_list_channels_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['channels' => 'ops-bot']));
    }

    public function test_a_scalar_channel_entry_is_a_contract_violation(): void
    {
        // A bare id where an object belongs would otherwise hydrate into a
        // half-built channel with empty kind/label.
        $this->expectException(\UnexpectedValueException::class);
        Monitor::fromArray(self::payload(['channels' => ['3']]));
    }
}
