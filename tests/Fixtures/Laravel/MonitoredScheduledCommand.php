<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Laravel;

use CronMonitor\Attribute\Monitor;
use Illuminate\Console\Command;

/**
 * Fixture Artisan command used by the Laravel scheduler attribute tests.
 *
 * The attribute UUID literal and the `UUID` constant are kept in lockstep
 * by hand — see the Symfony fixture's notes; the same parse-order
 * argument applies here.
 */
#[Monitor(uuid: '77777777-7777-4777-8777-777777777777')]
final class MonitoredScheduledCommand extends Command
{
    public const UUID = '77777777-7777-4777-8777-777777777777';

    /** @var string */
    protected $signature = 'reports:nightly';

    /** @var string */
    protected $description = 'Fixture command for cron-monitor attribute tests.';

    public function handle(): int
    {
        return 0;
    }
}
