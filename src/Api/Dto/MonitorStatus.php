<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * A monitor's health state, as computed by the server's scanner.
 *
 *  - `New`    — created, no ping received yet; the scanner does not alert
 *               on the first miss.
 *  - `Up`     — last ping arrived within the expected window.
 *  - `Late`   — the deadline plus grace elapsed without a ping.
 *  - `Down`   — an explicit `fail` ping was received.
 *  - `Paused` — user-disabled; the scanner skips it.
 *
 * A sixth state added server-side arrives at {@see Monitor::$status} as its
 * raw string rather than failing the read — a new state must not take down
 * every installed SDK version at once, least of all for callers that never
 * branch on it. Test with `instanceof` (or `match` with a default) before
 * acting on the value.
 */
enum MonitorStatus: string
{
    case New = 'new';
    case Up = 'up';
    case Late = 'late';
    case Down = 'down';
    case Paused = 'paused';
}
