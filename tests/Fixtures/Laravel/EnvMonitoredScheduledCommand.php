<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Laravel;

use CronMonitor\Attribute\Monitor;
use Illuminate\Console\Command;

/**
 * Fixture for the env-sourced `#[Monitor]` form on the Laravel side.
 *
 * Parallels `EnvMonitoredAttributedCommand` from the Symfony fixtures —
 * same attribute shape, same env-var-name-on-class pattern, separate
 * fixture so the two bridges' tests are independent.
 */
#[Monitor(env: 'CRON_MONITOR_TEST_LARAVEL_UUID')]
final class EnvMonitoredScheduledCommand extends Command
{
    public const ENV_VAR = 'CRON_MONITOR_TEST_LARAVEL_UUID';

    /** @var string */
    protected $signature = 'reports:env';

    /** @var string */
    protected $description = 'Fixture command with env-sourced cron-monitor attribute.';

    public function handle(): int
    {
        return 0;
    }
}
