<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Symfony;

use CronMonitor\Attribute\Monitor;
use Symfony\Component\Console\Command\Command;

/**
 * Fixture for the env-sourced `#[Monitor]` form on the Symfony side.
 *
 * The env-var name lives on the class via the attribute; the test
 * controls its value at runtime through `$_ENV` / `putenv()`.
 *
 * The constant `ENV_VAR` is mirrored from the attribute literal — see
 * `MonitoredAttributedCommand` for the reasoning behind manual sync
 * over `self::ENV_VAR` references inside an attribute argument.
 */
#[Monitor(env: 'CRON_MONITOR_TEST_SYMFONY_UUID')]
final class EnvMonitoredAttributedCommand extends Command
{
    public const ENV_VAR = 'CRON_MONITOR_TEST_SYMFONY_UUID';
}
