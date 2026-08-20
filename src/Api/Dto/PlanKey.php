<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Account plan tier, as reported by `GET /api/v1/account`. A tier added
 * server-side arrives at {@see Plan::$key} as its raw string rather than
 * failing the read, like {@see ScheduleKind} / {@see MonitorStatus}.
 */
enum PlanKey: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Growth = 'growth';
    case Scale = 'scale';
}
