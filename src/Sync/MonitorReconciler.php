<?php

declare(strict_types=1);

namespace CronMonitor\Sync;

use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\MonitorApiClient;

/**
 * Shared reconcile-and-create engine behind the `cron-monitor:sync --apply`
 * command on both the Symfony and Laravel bridges. It owns the logic; each
 * bridge only enumerates its scheduler jobs into {@see ReconcilableJob}s.
 *
 * Reconciliation is **by name**: every existing monitor is indexed by its
 * `name`, and a job whose name already maps to a monitor is left alone. A job
 * with no matching monitor is created (when `--apply` is on) as a cron monitor
 * named after the job. The by-name contract is simple and predictable, but it
 * means renaming a scheduled job creates a second monitor rather than updating
 * the first — callers should rename on the dashboard too, or delete the orphan.
 *
 * Creates carry a deterministic idempotency key derived from the job, so a
 * second `--apply` run that races ahead of the first (before the new monitor
 * shows up in the listing) still cannot mint a duplicate within the backend's
 * dedup window.
 */
final class MonitorReconciler
{
    public function __construct(
        private readonly MonitorApiClient $client,
    ) {
    }

    /**
     * Reconcile each job against the caller's existing monitors.
     *
     * @param list<ReconcilableJob> $jobs
     *
     * @return list<ReconcileResult>
     *
     * @throws ApiException when the existing-monitor listing itself fails —
     *                      reconciliation aborts rather than risk creating
     *                      duplicates against an unknown current state. A
     *                      failure of a single create under `--apply` does NOT
     *                      throw: it is captured as a {@see ReconcileOutcome::Failed}
     *                      result so the remaining jobs still reconcile.
     */
    public function reconcile(array $jobs, bool $apply, ?int $channelId = null, int $graceSeconds = 60): array
    {
        $existing = $this->existingByName();

        // Reconciliation is by name, so two jobs sharing a name cannot both
        // become monitors — silently creating one and reporting the other
        // "Existing" would leave the second schedule unmonitored. Flag every
        // occurrence of a duplicated name as a conflict for the caller to
        // disambiguate, and create none of them.
        $nameCounts = array_count_values(array_map(static fn (ReconcilableJob $j): string => $j->name, $jobs));

        $results = [];
        foreach ($jobs as $job) {
            if (($nameCounts[$job->name] ?? 0) > 1) {
                $results[] = ReconcileResult::conflict($job, \sprintf('%d scheduled jobs share the name "%s"; give them distinct names or create them manually.', $nameCounts[$job->name], $job->name));

                continue;
            }

            if (isset($existing[$job->name])) {
                $results[] = ReconcileResult::existing($job, $existing[$job->name]);

                continue;
            }

            if (!$apply) {
                $results[] = ReconcileResult::wouldCreate($job);

                continue;
            }

            try {
                $monitor = $this->create($job, $channelId, $graceSeconds);
                $existing[$job->name] = $monitor->uuid;
                $results[] = ReconcileResult::created($job, $monitor->uuid);
            } catch (ApiException $e) {
                $results[] = ReconcileResult::failed($job, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * @return array<string, string> existing monitor name => UUID
     *
     * @throws ApiException
     */
    private function existingByName(): array
    {
        $map = [];
        foreach ($this->client->allMonitors() as $monitor) {
            $map[$monitor->name] = $monitor->uuid;
        }

        return $map;
    }

    /**
     * @throws ApiException
     */
    private function create(ReconcilableJob $job, ?int $channelId, int $graceSeconds): Monitor
    {
        $request = new CreateMonitorRequest(
            $job->name,
            ScheduleKind::Cron,
            $job->cronExpr,
            tz: $job->tz ?? 'UTC',
            graceSeconds: $graceSeconds,
            channelIds: null === $channelId ? [] : [$channelId],
        );

        return $this->client->createMonitor($request, $this->idempotencyKey($request));
    }

    /**
     * A key derived from the whole create body, so it is stable across
     * identical re-runs (the backend replays rather than duplicates) yet
     * changes the moment any field does — matching the backend's full-body
     * request fingerprint, so a re-run that, say, changes the routed channel
     * is a fresh create rather than a `409` against a key bound to a different
     * body. Derived generically from {@see CreateMonitorRequest::toArray()} so
     * it cannot silently drift from the wire body when a field is added.
     */
    private function idempotencyKey(CreateMonitorRequest $request): string
    {
        $parts = [];
        foreach ($request->toArray() as $key => $value) {
            $parts[] = $key.'='.(\is_array($value) ? implode(',', $value) : (string) $value);
        }

        return 'sync-'.hash('sha256', implode("\n", $parts));
    }
}
