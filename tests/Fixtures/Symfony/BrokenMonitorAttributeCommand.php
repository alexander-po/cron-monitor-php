<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Symfony;

use CronMonitor\Attribute\Monitor;
use Symfony\Component\Console\Command\Command;

/**
 * Fixture command whose `#[Monitor]` deliberately violates the
 * "exactly one of uuid: or env:" invariant — both are set.
 *
 * Exists to exercise the bridge-level swallow: `newInstance()`'s throw
 * from the attribute constructor must be caught by the subscriber and
 * folded into "no monitoring", with a warning log line surfacing the
 * misuse. The host job (running this command) must complete normally.
 *
 * This is what users do when they migrate from the literal `uuid:`
 * form to the env-sourced form and forget to remove the old literal.
 * The intent is obvious in code review, but at runtime the SDK has
 * to handle it gracefully.
 */
#[Monitor(uuid: '99999999-9999-4999-8999-999999999999', env: 'CRON_MONITOR_BOTH_SET_FIXTURE')]
final class BrokenMonitorAttributeCommand extends Command
{
}
