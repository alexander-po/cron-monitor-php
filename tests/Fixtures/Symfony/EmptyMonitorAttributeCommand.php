<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Symfony;

use CronMonitor\Attribute\Monitor;
use Symfony\Component\Console\Command\Command;

/**
 * Fixture command carrying a deliberately empty `#[Monitor(uuid: '')]`.
 *
 * Exercises the edge case where an attribute UUID is structurally present
 * but resolves to an empty string — the subscriber must treat this as
 * "unmapped" rather than passing the empty UUID downstream where the
 * SDK's UUID-v4 validation would throw on every invocation.
 *
 * Lives in its own file so the empty literal can be expressed at the
 * attribute level rather than being mutated at runtime (attributes are
 * read by Reflection on the class itself, not on the instance, so the
 * attribute value is fixed at class load time anyway — but a dedicated
 * class keeps the test's intent obvious).
 */
#[Monitor(uuid: '')]
final class EmptyMonitorAttributeCommand extends Command
{
}
