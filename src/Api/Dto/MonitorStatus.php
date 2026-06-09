<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * A monitor's health state, as computed by the backend's scanner.
 *
 *  - `New`    — created, no ping received yet; the scanner does not alert
 *               on the first miss.
 *  - `Up`     — last ping arrived within the expected window.
 *  - `Late`   — the deadline plus grace elapsed without a ping.
 *  - `Down`   — an explicit `fail` ping was received.
 *  - `Paused` — user-disabled; the scanner skips it.
 *
 * Parsed strictly (see {@see Monitor::fromArray()}). The five states are
 * stable; if the backend ever introduces a sixth, that is a deliberate
 * contract change warranting an SDK bump rather than a value the SDK
 * silently swallows.
 */
enum MonitorStatus: string
{
    case New = 'new';
    case Up = 'up';
    case Late = 'late';
    case Down = 'down';
    case Paused = 'paused';
}
