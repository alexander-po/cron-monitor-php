<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Kind of alert recorded in a monitor's history
 * (`GET /api/v1/monitors/{uuid}/alerts`). Mirrors the backend's `AlertKind`.
 */
enum AlertKind: string
{
    case Late = 'late';
    case Fail = 'fail';
    case Recovered = 'recovered';
}
