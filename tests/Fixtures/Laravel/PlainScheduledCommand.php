<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Laravel;

use Illuminate\Console\Command;

/**
 * Counterpart of {@see MonitoredScheduledCommand} with no `#[Monitor]`
 * attribute, used to assert that the resolver returns null for
 * unannotated commands rather than throwing or guessing.
 */
final class PlainScheduledCommand extends Command
{
    /** @var string */
    protected $signature = 'reports:plain';

    /** @var string */
    protected $description = 'Fixture command without cron-monitor attribute.';

    public function handle(): int
    {
        return 0;
    }
}
