<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Kind of ping recorded in a monitor's history
 * (`GET /api/v1/monitors/{uuid}/pings`). A kind added server-side arrives at
 * {@see Ping::$kind} as its raw string rather than failing the read.
 */
enum PingKind: string
{
    case Heartbeat = 'heartbeat';
    case Start = 'start';
    case Success = 'success';
    case Fail = 'fail';
}
