<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Fixtures\Symfony;

use CronMonitor\Attribute\Monitor;
use Symfony\Component\Console\Command\Command;

/**
 * Named fixture command used by the Symfony console subscriber tests to
 * exercise `#[Monitor]` attribute discovery.
 *
 * Why a named class instead of an anonymous one in the test method:
 * `MonitoredAttributedCommand::class` is a stable cache key, which matters
 * for the test that asserts the subscriber's per-class attribute cache
 * holds exactly one entry. Anonymous classes would produce file/line-based
 * synthetic names that obscure that assertion.
 *
 * The UUID literal in the attribute and the `UUID` constant are kept in
 * lockstep by hand. We could write `#[Monitor(uuid: self::UUID)]`
 * (attribute arguments resolve through Reflection lazily, so a class
 * constant on the same class is allowed in PHP 8.1+) but the parse-order
 * makes it visually confusing — the attribute appears to read a constant
 * that the class body has not declared yet. Duplicating the literal
 * removes that surprise without losing readability inside the test
 * assertions, which reference `MonitoredAttributedCommand::UUID`.
 */
#[Monitor(uuid: '22222222-2222-4222-8222-222222222222')]
final class MonitoredAttributedCommand extends Command
{
    public const UUID = '22222222-2222-4222-8222-222222222222';
}
