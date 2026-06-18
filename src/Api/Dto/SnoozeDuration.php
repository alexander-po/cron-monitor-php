<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Allowed snooze windows for `POST /api/v1/monitors/{uuid}/snooze`.
 *
 * Request-side only: a backed enum gives compile-time safety and removes the
 * need for a runtime guard on the snooze body. Mirrors the backend's
 * `SnoozeDuration` set.
 */
enum SnoozeDuration: string
{
    case OneHour = '1h';
    case FourHours = '4h';
    case OneDay = '1d';
    case OneWeek = '1w';
}
