<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Dto\UpdateMonitorRequest;
use PHPUnit\Framework\TestCase;

final class UpdateMonitorRequestTest extends TestCase
{
    public function test_to_array_includes_only_provided_fields(): void
    {
        $req = new UpdateMonitorRequest(name: 'Renamed', graceSeconds: 120);

        self::assertSame(['name' => 'Renamed', 'grace_seconds' => 120], $req->toArray());
    }

    public function test_to_array_maps_schedule_kind_and_channels(): void
    {
        $req = new UpdateMonitorRequest(scheduleKind: ScheduleKind::Interval, scheduleExpr: '300', channelIds: ['3', '4']);

        self::assertSame(['schedule_kind' => 'interval', 'schedule_expr' => '300', 'channel_ids' => ['3', '4']], $req->toArray());
    }

    public function test_is_empty_when_no_fields_are_set(): void
    {
        self::assertTrue((new UpdateMonitorRequest())->isEmpty());
        self::assertSame([], (new UpdateMonitorRequest())->toArray());
    }

    public function test_empty_channel_ids_is_a_real_patch_that_clears_routing(): void
    {
        $req = new UpdateMonitorRequest(channelIds: []);

        self::assertFalse($req->isEmpty());
        self::assertSame(['channel_ids' => []], $req->toArray());
    }

    public function test_blank_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpdateMonitorRequest(name: '  ');
    }

    public function test_blank_schedule_expr_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpdateMonitorRequest(scheduleExpr: '');
    }

    public function test_negative_grace_seconds_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpdateMonitorRequest(graceSeconds: -1);
    }

    public function test_non_positive_channel_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpdateMonitorRequest(channelIds: ['0']);
    }
}
