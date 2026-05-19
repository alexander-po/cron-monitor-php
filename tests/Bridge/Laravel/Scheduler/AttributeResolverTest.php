<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Laravel\Scheduler;

use CronMonitor\Bridge\Laravel\Scheduler\AttributeResolver;
use CronMonitor\Tests\Fixtures\Laravel\EmptyMonitorScheduledCommand;
use CronMonitor\Tests\Fixtures\Laravel\MonitoredScheduledCommand;
use CronMonitor\Tests\Fixtures\Laravel\PlainScheduledCommand;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class AttributeResolverTest extends TestCase
{
    /**
     * @dataProvider commandStringProvider
     */
    public function test_extract_command_name_handles_common_shell_formats(
        string $line,
        ?string $expected,
    ): void {
        self::assertSame($expected, AttributeResolver::extractCommandName($line));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function commandStringProvider(): array
    {
        return [
            'unix single-quoted format Laravel produces by default' => [
                "'/usr/bin/php' 'artisan' reports:nightly",
                'reports:nightly',
            ],
            'unix format with arguments after the name' => [
                "'/usr/bin/php' 'artisan' reports:nightly --tenant=acme --verbose",
                'reports:nightly',
            ],
            'unquoted shell format (some custom binaries)' => [
                'php artisan reports:nightly',
                'reports:nightly',
            ],
            'windows double-quoted format' => [
                '"C:\\php\\php.exe" "artisan" reports:nightly',
                'reports:nightly',
            ],
            'Schedule::exec payload that happens to contain `artisan` substring without bounding' => [
                'rsync /var/partisanproject/ /backup/',
                null,
            ],
            'Schedule::exec payload with no artisan marker at all' => [
                'rsync /src /dst',
                null,
            ],
            'empty string' => [
                '',
                null,
            ],
        ];
    }

    public function test_resolves_uuid_from_attribute_when_command_is_registered(): void
    {
        $command = new MonitoredScheduledCommand();
        $event = $this->buildEvent("'/usr/bin/php' 'artisan' reports:nightly");

        $uuid = AttributeResolver::resolveUuid(
            $event,
            $this->locator(['reports:nightly' => $command]),
        );

        self::assertSame(MonitoredScheduledCommand::UUID, $uuid);
    }

    public function test_returns_null_when_command_class_has_no_monitor_attribute(): void
    {
        $command = new PlainScheduledCommand();
        $event = $this->buildEvent("'/usr/bin/php' 'artisan' reports:plain");

        $uuid = AttributeResolver::resolveUuid(
            $event,
            $this->locator(['reports:plain' => $command]),
        );

        self::assertNull($uuid);
    }

    public function test_empty_attribute_uuid_is_treated_as_unmapped(): void
    {
        // Mirrors the Symfony resolver's empty-string-as-suppression
        // policy. A deliberate `#[Monitor(uuid: '')]` must not flow into
        // the SDK; the resolver returns null so the macro short-circuits
        // before EventMonitor installs any hooks.
        $command = new EmptyMonitorScheduledCommand();
        $event = $this->buildEvent("'/usr/bin/php' 'artisan' reports:empty");

        $uuid = AttributeResolver::resolveUuid(
            $event,
            $this->locator(['reports:empty' => $command]),
        );

        self::assertNull($uuid);
    }

    public function test_returns_null_for_schedule_exec_events_without_artisan_marker(): void
    {
        // `Schedule::exec('rsync /src /dst')->monitor()` is a legal call
        // shape — the user just registered the macro at the wrong call
        // site. The resolver must silently return null rather than
        // resolving against an unrelated registered command.
        $command = new MonitoredScheduledCommand();
        $event = $this->buildEvent('rsync /src /dst');

        $uuid = AttributeResolver::resolveUuid(
            $event,
            $this->locator(['reports:nightly' => $command]),
        );

        self::assertNull($uuid);
    }

    public function test_returns_null_when_command_locator_cannot_find_the_name(): void
    {
        // Defensive against `find()` throwing `CommandNotFoundException`,
        // a misspelled scheduler entry, or any other reason the kernel
        // cannot resolve the name. The locator returns null and the
        // resolver follows suit — the host job is not affected.
        $event = $this->buildEvent("'/usr/bin/php' 'artisan' reports:does-not-exist");

        $uuid = AttributeResolver::resolveUuid(
            $event,
            $this->locator([]),
        );

        self::assertNull($uuid);
    }

    public function test_returns_null_when_locator_is_missing(): void
    {
        // Macro is invoked during `routes/console.php` parsing; if the
        // container has not yet bound the kernel (testing harness, custom
        // application bootstrap, etc.), the macro passes `null` for the
        // locator and the resolver degrades gracefully.
        $event = $this->buildEvent("'/usr/bin/php' 'artisan' reports:nightly");

        self::assertNull(AttributeResolver::resolveUuid($event, null));
    }

    private function buildEvent(string $command): Event
    {
        $mutex = new CacheEventMutex(new class implements CacheFactory {
            public function store($name = null): Repository
            {
                throw new \LogicException('cache should not be touched in these tests');
            }
        });

        return new Event($mutex, $command, null);
    }

    /**
     * Build a name-keyed command locator. Returning `null` for unknown
     * names mirrors what the production macro does when the kernel's
     * `Artisan::find()` throws or returns nothing.
     *
     * @param array<string, SymfonyCommand> $byName
     *
     * @return callable(string): ?SymfonyCommand
     */
    private function locator(array $byName): callable
    {
        return static fn (string $name): ?SymfonyCommand => $byName[$name] ?? null;
    }
}
