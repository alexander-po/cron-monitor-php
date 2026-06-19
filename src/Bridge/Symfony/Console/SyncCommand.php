<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Console;

use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Bridge\Symfony\Scheduler\ScheduledJob;
use CronMonitor\Bridge\Symfony\Scheduler\ScheduleInventory;
use CronMonitor\Client\Configuration;
use CronMonitor\Sync\MonitorReconciler;
use CronMonitor\Sync\ReconcilableJob;
use CronMonitor\Sync\ReconcileOutcome;
use CronMonitor\Sync\ReconcileResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the Symfony Scheduler jobs the bundle can see, and either prints the
 * `messages:` mapping snippet to paste into `config/packages/cron_monitor.yaml`
 * (the default) or — with `--apply` / `--dry-run` — reconciles those jobs
 * against the account's monitors via the management API, creating the missing
 * ones.
 *
 * The default "list + suggest" mode stays the safe, zero-credential path: it
 * touches no network and asks the developer to wire UUIDs by hand. `--apply`
 * is the opt-in automation now that the API exposes authenticated create +
 * idempotency; `--dry-run` previews the same reconciliation without writing.
 */
#[AsCommand(
    name: 'cron-monitor:sync',
    description: 'List Symfony Scheduler jobs and emit a cron_monitor messages map, or reconcile them against the API.',
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly ScheduleInventory $inventory,
        private readonly ?MonitorApiClient $apiClient = null,
        private readonly ?Configuration $configuration = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format for the default list mode: table | yaml | json',
                'table',
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Create the monitors that do not yet exist, via the management API.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview which monitors --apply would create, without creating any.',
            )
            ->addOption(
                'channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Notification channel id to route created monitors to.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobs = $this->inventory->jobs();

        if ([] === $jobs) {
            $io->note('No scheduled jobs discovered. Make sure your ScheduleProvider services are tagged scheduler.schedule_provider.');

            return Command::SUCCESS;
        }

        $apply = (bool) $input->getOption('apply');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($apply || $dryRun) {
            // --dry-run wins when both are given: never write on a hedged invocation.
            return $this->reconcile($io, $jobs, apply: $apply && !$dryRun, channelOption: $input->getOption('channel'));
        }

        $format = (string) $input->getOption('format');

        return match ($format) {
            'json' => $this->writeJson($io, $jobs),
            'yaml' => $this->writeYaml($io, $jobs),
            'table' => $this->writeTable($io, $jobs),
            default => $this->invalidFormat($io, $format),
        };
    }

    /**
     * @param list<ScheduledJob> $jobs
     */
    private function reconcile(SymfonyStyle $io, array $jobs, bool $apply, mixed $channelOption): int
    {
        if (null === $this->apiClient || null === $this->configuration?->apiKey) {
            $io->error('cron-monitor:sync --apply/--dry-run needs an API token. Set cron_monitor.api_key (env CRON_MONITOR_API_KEY) and make sure a PSR-18 client is available.');

            return Command::FAILURE;
        }

        $channelId = null;
        if (null !== $channelOption) {
            if (!\is_string($channelOption) || 1 !== preg_match('/^[1-9]\d*$/', $channelOption)) {
                $io->error('--channel must be a positive integer channel id.');

                return Command::INVALID;
            }
            $channelId = (int) $channelOption;
        }

        // Only cron-triggered jobs can be auto-created: the management API
        // creates cron monitors, and a Symfony trigger may instead be a
        // periodical/interval one whose __toString ("every 1 hour") is not a
        // cron expression. Sending that as a cron schedule_expr would 422 for
        // every interval job. Skip those with a visible note rather than
        // firing doomed creates; the developer can create them by hand.
        $reconcilable = [];
        $skipped = [];
        foreach ($jobs as $job) {
            if ($this->looksLikeCron($job->trigger)) {
                $reconcilable[] = new ReconcilableJob($job->messageClass, $job->trigger);
            } else {
                $skipped[] = $job;
            }
        }

        $results = [];
        if ([] !== $reconcilable) {
            try {
                $results = (new MonitorReconciler($this->apiClient))->reconcile($reconcilable, $apply, $channelId);
            } catch (ApiException $e) {
                $io->error('Could not list existing monitors: '.$e->getMessage());

                return Command::FAILURE;
            }
        }

        return $this->renderReconcile($io, $results, $skipped, $apply);
    }

    /**
     * @param list<ReconcileResult> $results
     * @param list<ScheduledJob>    $skipped
     */
    private function renderReconcile(SymfonyStyle $io, array $results, array $skipped, bool $apply): int
    {
        $rows = array_map(
            static fn (ReconcileResult $r): array => [
                $r->job->name,
                $r->outcome->value,
                $r->uuid ?? $r->error ?? '',
            ],
            $results,
        );
        foreach ($skipped as $job) {
            $rows[] = [$job->messageClass, 'skipped', 'not a cron trigger: '.$job->trigger];
        }

        $io->table(['Job', 'Status', 'Monitor'], $rows);

        if (!$apply) {
            $io->note('Dry run: no monitors were created. Re-run with --apply to create the would-create rows.');

            return Command::SUCCESS;
        }

        $created = array_filter($results, static fn (ReconcileResult $r): bool => ReconcileOutcome::Created === $r->outcome);
        $failed = array_filter($results, static fn (ReconcileResult $r): bool => ReconcileOutcome::Failed === $r->outcome);

        if ([] !== $created) {
            $io->section('Wire each created monitor into config/packages/cron_monitor.yaml');
            $io->writeln('cron_monitor:');
            $io->writeln('    messages:');
            foreach ($created as $r) {
                $io->writeln(\sprintf("        '%s': '%s'", $r->job->name, (string) $r->uuid));
            }
            $io->newLine();
        }

        if ([] !== $failed) {
            $io->warning(\sprintf('%d monitor(s) could not be created — see the table above.', \count($failed)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * A conservative cron-expression sniff used to decide which jobs `--apply`
     * can create. The backend validates standard 5-field cron, so the gate
     * requires exactly 5 whitespace-separated cron-token fields; a Symfony
     * periodical trigger's human description ("every 1 hour") never matches
     * that grammar and is correctly skipped, and a 6-field/seconds expression
     * is skipped too rather than sent as a create the backend would reject.
     *
     * Note: created monitors are always UTC — the Symfony Scheduler triggers
     * expose no public timezone, so a non-UTC cron synced via `--apply` must
     * have its timezone set on the dashboard. (The Laravel bridge does carry
     * the per-event timezone through.)
     */
    private function looksLikeCron(string $trigger): bool
    {
        $fields = preg_split('/\s+/', trim($trigger)) ?: [];
        if (5 !== \count($fields)) {
            return false;
        }
        foreach ($fields as $field) {
            // Cron tokens: digits, wildcards, step/range/list separators, and
            // the alphabetic month/day names (JAN, MON, …). Periodical
            // descriptions are 1–4 words or carry parens, so the field-count
            // gate above already excludes them.
            if (1 !== preg_match('~^[a-z0-9*/,\-]+$~i', $field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<ScheduledJob> $jobs
     */
    private function writeTable(SymfonyStyle $io, array $jobs): int
    {
        $rows = array_map(
            static fn ($job) => [$job->messageClass, $job->trigger, $job->providerClass],
            $jobs,
        );
        $io->table(['Message', 'Trigger', 'Provider'], $rows);

        $io->section('Suggested config/packages/cron_monitor.yaml');
        $io->writeln('cron_monitor:');
        $io->writeln('    messages:');
        foreach ($jobs as $job) {
            $io->writeln(\sprintf("        '%s': '<paste-monitor-uuid-here>'", $job->messageClass));
        }
        $io->newLine();

        return Command::SUCCESS;
    }

    /**
     * @param list<ScheduledJob> $jobs
     */
    private function writeJson(SymfonyStyle $io, array $jobs): int
    {
        $rows = array_map(static fn ($job) => $job->toArray(), $jobs);
        $io->writeln(json_encode($rows, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @param list<ScheduledJob> $jobs
     */
    private function writeYaml(SymfonyStyle $io, array $jobs): int
    {
        $io->writeln('cron_monitor:');
        $io->writeln('    messages:');
        foreach ($jobs as $job) {
            $io->writeln(\sprintf("        '%s': '<paste-monitor-uuid-here>'", $job->messageClass));
        }

        return Command::SUCCESS;
    }

    private function invalidFormat(SymfonyStyle $io, string $format): int
    {
        $io->error(\sprintf('Unknown --format=%s. Allowed: table, yaml, json.', $format));

        return Command::INVALID;
    }
}
