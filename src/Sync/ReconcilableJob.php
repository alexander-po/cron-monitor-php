<?php

declare(strict_types=1);

namespace CronMonitor\Sync;

/**
 * The framework-neutral shape both scheduler bridges flatten their jobs into
 * before handing them to {@see MonitorReconciler}: the monitor name to
 * reconcile by and the cron expression to create the monitor with.
 *
 * `name` is the reconciliation key — the reconciler matches it against the
 * `name` of existing monitors. Each bridge maps its own job identity onto it
 * (the Symfony message class, the Laravel command string), so the contract is
 * "one monitor per distinct job name".
 */
final class ReconcilableJob
{
    public function __construct(
        public readonly string $name,
        public readonly string $cronExpr,
    ) {
    }
}
