<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Kind of alert recorded in a monitor's history
 * (`GET /api/v1/monitors/{uuid}/alerts`). A kind added server-side arrives at
 * {@see Alert::$kind} as its raw string rather than failing the read.
 */
enum AlertKind: string
{
    case Late = 'late';
    case Fail = 'fail';
    case Recovered = 'recovered';
}
