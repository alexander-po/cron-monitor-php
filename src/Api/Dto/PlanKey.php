<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Account plan tier, as reported by `GET /api/v1/account`. Mirrors the
 * backend's `Plan` keys; parsed strictly (an unknown tier is a contract
 * violation, like {@see ScheduleKind} / {@see MonitorStatus}).
 */
enum PlanKey: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Growth = 'growth';
    case Scale = 'scale';
}
