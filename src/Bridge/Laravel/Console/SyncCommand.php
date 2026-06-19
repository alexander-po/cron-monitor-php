<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel\Console;

use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Sync\MonitorReconciler;
use CronMonitor\Sync\ReconcilableJob;
use CronMonitor\Sync\ReconcileOutcome;
use CronMonitor\Sync\ReconcileResult;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Mirrors the Symfony `cron-monitor:sync` command for Laravel users.
 *
 * Reads the bound `Schedule` instance, lists every defined event, and either
 * emits a `config/cron-monitor.php` snippet to paste over the `monitors` map
 * (the default) or — with `--apply` / `--dry-run` — reconciles those events
 * against the account's monitors via the management API, creating the missing
 * ones. Same "stop at the human decision boundary" philosophy as the Symfony
 * version: the default mode touches no network, `--apply` is the opt-in.
 */
final class SyncCommand extends Command
{
    /**
     * Laravel's older base command type binds `$signature` as a string,
     * not a typed property — we keep the legacy shape so this class works
     * on Laravel 10.x where the property is implicitly `mixed`.
     *
     * @var string
     */
    protected $signature = 'cron-monitor:sync
        {--format=table : table | json (default list mode)}
        {--apply : Create the monitors that do not yet exist, via the management API}
        {--dry-run : Preview which monitors --apply would create, without creating any}
        {--channel= : Notification channel id to route created monitors to}';

    /**
     * @var string
     */
    protected $description = 'List Laravel scheduler events and emit a cron-monitor config snippet, or reconcile them against the API.';

    public function handle(Schedule $schedule, MonitorApiClient $apiClient, Configuration $configuration): int
    {
        $events = $schedule->events();

        if ([] === $events) {
            $this->info('No scheduled events defined. Add commands in routes/console.php first.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'command' => $this->describeCommand($event),
                'expression' => $event->getExpression(),
                'timezone' => $this->describeTimezone($event),
            ];
        }

        if ((bool) $this->option('apply') || (bool) $this->option('dry-run')) {
            // --dry-run wins when both are given: never write on a hedged invocation.
            $apply = (bool) $this->option('apply') && !(bool) $this->option('dry-run');

            return $this->reconcile($rows, $apply, $apiClient, $configuration);
        }

        // Laravel's `option()` returns `string|array|bool|null`; the
        // signature on this command is a single VALUE_REQUIRED option, so
        // the runtime type is always string|null, but PHPStan cannot infer
        // that from the framework signature alone.
        $rawFormat = $this->option('format');
        $format = \is_string($rawFormat) ? $rawFormat : 'table';
        if ('json' === $format) {
            $this->line(json_encode($rows, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Command', 'Expression', 'Timezone'], $rows);

        $this->newLine();
        $this->info('Suggested config/cron-monitor.php snippet:');
        $this->line('return [');
        $this->line("    'monitors' => [");
        foreach ($rows as $row) {
            $this->line(\sprintf('        // %s @ %s', $row['command'], $row['expression']));
            $this->line(\sprintf("        '%s' => '<paste-monitor-uuid-here>',", $row['command']));
        }
        $this->line('    ],');
        $this->line('];');

        return self::SUCCESS;
    }

    /**
     * @param list<array{command: string, expression: string, timezone: string}> $rows
     */
    private function reconcile(array $rows, bool $apply, MonitorApiClient $apiClient, Configuration $configuration): int
    {
        if (null === $configuration->apiKey) {
            $this->error('cron-monitor:sync --apply/--dry-run needs an API token. Set cron-monitor.api_key (env CRON_MONITOR_API_KEY).');

            return self::FAILURE;
        }

        $channelId = null;
        $rawChannel = $this->option('channel');
        if (null !== $rawChannel) {
            if (!\is_string($rawChannel) || 1 !== preg_match('/^[1-9]\d*$/', $rawChannel)) {
                $this->error('--channel must be a positive integer channel id.');

                return self::INVALID;
            }
            $channelId = $rawChannel;
        }

        // Closure / unnamed events have no stable command name (describeCommand
        // returns a synthetic "<…>" placeholder), so they cannot be reconciled
        // by name — every run would either collapse them onto one bogus monitor
        // or recreate them. Skip them with a visible note; the developer can
        // monitor a closure explicitly via the ->monitor('uuid') macro.
        $jobs = [];
        $skipped = [];
        foreach ($rows as $row) {
            if (str_starts_with($row['command'], '<')) {
                $skipped[] = $row['command'];
            } else {
                // describeTimezone returns a concrete IANA zone or the
                // 'app default' sentinel (the scheduler resolves that to the
                // app's config timezone at run time, which the SDK can't see).
                // Pass a concrete zone through so the monitor is created in it;
                // leave it null for the sentinel so the monitor defaults to UTC.
                $tz = 'app default' === $row['timezone'] ? null : $row['timezone'];
                $jobs[] = new ReconcilableJob($row['command'], $row['expression'], $tz);
            }
        }

        $results = [];
        if ([] !== $jobs) {
            try {
                $results = (new MonitorReconciler($apiClient))->reconcile($jobs, $apply, $channelId);
            } catch (ApiException $e) {
                $this->error('Could not list existing monitors: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        return $this->renderReconcile($results, $skipped, $apply);
    }

    /**
     * @param list<ReconcileResult> $results
     * @param list<string>          $skipped
     */
    private function renderReconcile(array $results, array $skipped, bool $apply): int
    {
        $tableRows = array_map(
            static fn (ReconcileResult $r): array => [
                $r->job->name,
                $r->outcome->value,
                $r->uuid ?? $r->error ?? '',
            ],
            $results,
        );
        foreach ($skipped as $name) {
            $tableRows[] = [$name, 'skipped', 'no stable command name'];
        }

        $this->table(['Job', 'Status', 'Monitor'], $tableRows);

        if (!$apply) {
            $this->info('Dry run: no monitors were created. Re-run with --apply to create the would-create rows.');

            return self::SUCCESS;
        }

        $created = array_filter($results, static fn (ReconcileResult $r): bool => ReconcileOutcome::Created === $r->outcome);
        $failed = array_filter($results, static fn (ReconcileResult $r): bool => ReconcileOutcome::Failed === $r->outcome);

        if ([] !== $created) {
            $this->newLine();
            $this->info('Wire each created monitor into config/cron-monitor.php:');
            $this->line("    'monitors' => [");
            foreach ($created as $r) {
                $this->line(\sprintf("        '%s' => '%s',", $r->job->name, (string) $r->uuid));
            }
            $this->line('    ],');
        }

        if ([] !== $failed) {
            $this->warn(\sprintf('%d monitor(s) could not be created — see the table above.', \count($failed)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * `Event::$command` is documented as `string`, but for closure-based
     * scheduled work the value is `null`. Stringifying a closure event yields
     * the empty string, which makes the table output less useful — fall back
     * to a synthetic name so users still see the row.
     */
    private function describeCommand(\Illuminate\Console\Scheduling\Event $event): string
    {
        $command = $event->command;
        if (\is_string($command) && '' !== trim($command)) {
            return trim($command);
        }

        if ($event instanceof \Illuminate\Console\Scheduling\CallbackEvent) {
            return '<closure callback>';
        }

        return '<unnamed event>';
    }

    private function describeTimezone(\Illuminate\Console\Scheduling\Event $event): string
    {
        $tz = $event->timezone;
        if ($tz instanceof \DateTimeZone) {
            return $tz->getName();
        }
        if (\is_string($tz) && '' !== $tz) {
            return $tz;
        }

        return 'app default';
    }
}
