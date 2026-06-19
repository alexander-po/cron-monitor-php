<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Kind of ping recorded in a monitor's history
 * (`GET /api/v1/monitors/{uuid}/pings`). Mirrors the backend's `PingKind`.
 */
enum PingKind: string
{
    case Heartbeat = 'heartbeat';
    case Start = 'start';
    case Success = 'success';
    case Fail = 'fail';
}
