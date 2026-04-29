<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Console;

use CronMonitor\Bridge\Symfony\Scheduler\ScheduleInventory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the Symfony Scheduler jobs the bundle can see and prints the
 * `messages:` mapping snippet that the user should paste into
 * `config/packages/cron_monitor.yaml`.
 *
 * Why a "list + suggest" command rather than a fully-automated push:
 *  - the cron-monitor server requires per-user authentication for monitor
 *    creation, so a silent push from a `bin/console` invocation that runs
 *    in CI / a deploy script would either mint anonymous monitors or fail.
 *    Both are worse than asking the developer to copy three lines of YAML
 *    once.
 *  - the developer is the source of truth for monitor names, grace periods,
 *    and channel routing. The command's output deliberately stops at the
 *    point where a human decision is required.
 */
#[AsCommand(
    name: 'cron-monitor:sync',
    description: 'List Symfony Scheduler jobs and emit a cron_monitor bundle messages map.',
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly ScheduleInventory $inventory,
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
                'Output format: table | yaml | json',
                'table',
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

        $format = (string) $input->getOption('format');

        return match ($format) {
            'json' => $this->writeJson($io, $jobs),
            'yaml' => $this->writeYaml($io, $jobs),
            'table' => $this->writeTable($io, $jobs),
            default => $this->invalidFormat($io, $format),
        };
    }

    /**
     * @param list<\CronMonitor\Bridge\Symfony\Scheduler\ScheduledJob> $jobs
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
     * @param list<\CronMonitor\Bridge\Symfony\Scheduler\ScheduledJob> $jobs
     */
    private function writeJson(SymfonyStyle $io, array $jobs): int
    {
        $rows = array_map(static fn ($job) => $job->toArray(), $jobs);
        $io->writeln(json_encode($rows, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @param list<\CronMonitor\Bridge\Symfony\Scheduler\ScheduledJob> $jobs
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
