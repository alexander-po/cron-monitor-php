<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Mirrors the Symfony `cron-monitor:sync` command for Laravel users.
 *
 * Reads the bound `Schedule` instance, lists every defined event, and emits
 * a `config/cron-monitor.php` snippet that the developer can paste over the
 * `monitors` map. Same "stop at the human decision boundary" philosophy as
 * the Symfony version.
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
    protected $signature = 'cron-monitor:sync {--format=table : table | json}';

    /**
     * @var string
     */
    protected $description = 'List Laravel scheduler events and emit a cron-monitor config snippet.';

    public function handle(Schedule $schedule): int
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
