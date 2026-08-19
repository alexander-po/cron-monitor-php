<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * How a monitor's schedule is expressed.
 *
 * A kind added server-side arrives at {@see Monitor::$scheduleKind} as its
 * raw string rather than failing the read. Writing one still takes a real
 * case, so a value the SDK only passed through cannot be written back.
 */
enum ScheduleKind: string
{
    case Cron = 'cron';
    case Interval = 'interval';
    case Simple = 'simple';
}
