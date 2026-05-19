<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Laravel;

use CronMonitor\Attribute\Monitor;
use Illuminate\Console\Command;

/**
 * Fixture command with an intentionally empty `#[Monitor(uuid: '')]`.
 *
 * Mirrors `tests/Fixtures/Symfony/EmptyMonitorAttributeCommand.php` —
 * exercises the edge case where an attribute is present but its UUID
 * resolves to empty (e.g. an env-var expansion that came back blank in
 * dev). The resolver must treat this as "do not monitor" rather than
 * letting the empty UUID flow into the SDK's validator.
 */
#[Monitor(uuid: '')]
final class EmptyMonitorScheduledCommand extends Command
{
    /** @var string */
    protected $signature = 'reports:empty';

    /** @var string */
    protected $description = 'Fixture command with deliberately empty cron-monitor attribute.';

    public function handle(): int
    {
        return 0;
    }
}
