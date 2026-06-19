<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\ScheduleKind;
use PHPUnit\Framework\TestCase;

final class CreateMonitorRequestTest extends TestCase
{
    public function test_to_array_uses_snake_case_keys_and_enum_value(): void
    {
        $request = new CreateMonitorRequest(
            name: 'Nightly report',
            scheduleKind: ScheduleKind::Cron,
            scheduleExpr: '0 2 * * *',
            tz: 'Europe/Warsaw',
            graceSeconds: 120,
            channelIds: ['3', '7'],
        );

        self::assertSame([
            'name' => 'Nightly report',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'Europe/Warsaw',
            'grace_seconds' => 120,
            'channel_ids' => ['3', '7'],
        ], $request->toArray());
    }

    public function test_int_channel_ids_are_accepted_and_normalised_to_strings(): void
    {
        // 1.0.0 callers passed channelIds as ints; that must keep working,
        // emitting the same string-on-the-wire form the backend accepts.
        $request = new CreateMonitorRequest('Nightly', ScheduleKind::Cron, '0 2 * * *', channelIds: [3, 7]);

        self::assertSame(['3', '7'], $request->toArray()['channel_ids']);
    }

    public function test_defaults_are_utc_sixty_seconds_and_no_channels(): void
    {
        $request = new CreateMonitorRequest('Heartbeat', ScheduleKind::Interval, '300');

        $array = $request->toArray();
        self::assertSame('UTC', $array['tz']);
        self::assertSame(60, $array['grace_seconds']);
        self::assertSame([], $array['channel_ids']);
    }

    public function test_rejects_blank_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest('   ', ScheduleKind::Cron, '0 2 * * *');
    }

    public function test_rejects_blank_schedule_expression(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest('Nightly', ScheduleKind::Cron, '');
    }

    public function test_rejects_negative_grace_seconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest('Nightly', ScheduleKind::Cron, '0 2 * * *', graceSeconds: -1);
    }

    public function test_rejects_non_positive_channel_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateMonitorRequest('Nightly', ScheduleKind::Cron, '0 2 * * *', channelIds: ['0']);
    }
}
