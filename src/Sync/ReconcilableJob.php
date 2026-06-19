<?php

declare(strict_types=1);

namespace CronMonitor\Sync;

/**
 * The framework-neutral shape both scheduler bridges flatten their jobs into
 * before handing them to {@see MonitorReconciler}: the monitor name to
 * reconcile by, the cron expression to create the monitor with, and the IANA
 * timezone that expression is evaluated in.
 *
 * `name` is the reconciliation key — the reconciler matches it against the
 * `name` of existing monitors. Each bridge maps its own job identity onto it
 * (the Symfony message class, the Laravel command string), so the contract is
 * "one monitor per distinct job name".
 *
 * `tz` carries the schedule's timezone when the bridge can determine it (the
 * Laravel scheduler exposes a per-event timezone). It MUST be threaded into the
 * created monitor: a `0 9 * * *` cron created as UTC when the job actually runs
 * at 09:00 in another zone makes the monitor expect the ping hours early and
 * alert falsely every day. When null, the monitor is created in UTC.
 */
final class ReconcilableJob
{
    public function __construct(
        public readonly string $name,
        public readonly string $cronExpr,
        public readonly ?string $tz = null,
    ) {
    }
}
