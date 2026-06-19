<?php

declare(strict_types=1);

namespace CronMonitor\Sync;

/**
 * What {@see MonitorReconciler} did (or would do) with a single job.
 */
enum ReconcileOutcome: string
{
    /** A monitor with this name already exists; nothing was created. */
    case Existing = 'exists';

    /** A monitor was created for this job. */
    case Created = 'created';

    /** No monitor exists and `--apply` was off — it would be created. */
    case WouldCreate = 'would-create';

    /** A create was attempted under `--apply` but the API rejected it. */
    case Failed = 'failed';

    /** Multiple jobs share this name, so it cannot be reconciled by name. */
    case Conflict = 'conflict';
}
