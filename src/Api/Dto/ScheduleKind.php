<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * How a monitor's schedule is expressed.
 *
 * Mirrors the backend's `schedule_kind` enum. The set is foundational and
 * not expected to grow, so {@see Monitor::fromArray()} parses it strictly:
 * an unrecognised value is treated as a contract violation rather than
 * silently coerced.
 */
enum ScheduleKind: string
{
    case Cron = 'cron';
    case Interval = 'interval';
    case Simple = 'simple';
}
