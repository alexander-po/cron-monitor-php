<?php

declare(strict_types=1);

namespace CronMonitor\Sync;

/**
 * The outcome of reconciling one {@see ReconcilableJob}: what happened, and —
 * for an existing or freshly-created monitor — its UUID, or the error message
 * when a create failed.
 */
final class ReconcileResult
{
    private function __construct(
        public readonly ReconcilableJob $job,
        public readonly ReconcileOutcome $outcome,
        public readonly ?string $uuid = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function existing(ReconcilableJob $job, string $uuid): self
    {
        return new self($job, ReconcileOutcome::Existing, uuid: $uuid);
    }

    public static function created(ReconcilableJob $job, string $uuid): self
    {
        return new self($job, ReconcileOutcome::Created, uuid: $uuid);
    }

    public static function wouldCreate(ReconcilableJob $job): self
    {
        return new self($job, ReconcileOutcome::WouldCreate);
    }

    public static function failed(ReconcilableJob $job, string $error): self
    {
        return new self($job, ReconcileOutcome::Failed, error: $error);
    }
}
