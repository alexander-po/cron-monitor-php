<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel\Scheduler;

use CronMonitor\Attribute\Monitor;
use Illuminate\Console\Scheduling\Event;
use Symfony\Component\Console\Command\Command;

/**
 * Resolves a cron-monitor UUID from a `#[Monitor]` attribute on the
 * Artisan command class behind a scheduled `Event`.
 *
 * Why this is non-trivial in Laravel: an `Event` does not retain a
 * reference to the command class that produced it. `Schedule::command()`
 * normalises both `Schedule::command('reports:nightly')` and
 * `Schedule::command(GenerateNightlyReportCommand::class)` into the
 * same shape — `$event->command` is a formatted shell string like
 * `"'/usr/bin/php' 'artisan' reports:nightly --foo"`. We therefore have
 * to:
 *   1. Parse the command name out of `$event->command`;
 *   2. Resolve the name to a `Command` instance via the Artisan kernel;
 *   3. Reflect on the command's class for `#[Monitor]`.
 *
 * Step 2 is the messy one — the Laravel concrete kernel exposes
 * `getArtisan()` but the contract does not, the kernel may not be bound
 * yet at macro-eval time, and `find()` throws on unregistered names.
 * Rather than encoding all of that here, this resolver takes a
 * **command-locator callable** that returns the `Command` instance for
 * a given name (or null on any failure). The macro that owns the kernel
 * access builds that closure once; the resolver stays pure (no Laravel
 * imports beyond `Event` itself) and trivially unit-testable.
 *
 * Every failure mode returns `null` and the macro silently skips
 * monitoring — keeping the SDK's never-break-the-host-job contract.
 *
 * No per-class reflection cache, unlike the Symfony console subscriber
 * (see `Bridge/Symfony/Console/MonitorConsoleSubscriber::resolveAttributeUuid`).
 * The two lifecycles differ: the Symfony subscriber fires on every
 * command run (including in long-lived `messenger:consume` workers
 * dispatching thousands of sub-commands), so amortising reflection is
 * worthwhile. The Laravel macro runs **once per scheduled task at app
 * boot** — `Schedule::command(...)->monitor()` installs lifecycle hooks
 * during `routes/console.php` parsing and the resolver is not called
 * again at run time. A cache here would add complexity for zero
 * measurable benefit.
 */
final class AttributeResolver
{
    /**
     * Returns the UUID from a `#[Monitor]` attribute on the command class
     * that backs `$event`, or `null` if no such attribute can be resolved
     * for any reason. Never throws.
     *
     * @param (callable(string): ?Command)|null $commandLocator returns the
     *                                                          Command for a given
     *                                                          name, or null when
     *                                                          the kernel cannot
     *                                                          resolve it
     */
    public static function resolveUuid(Event $event, ?callable $commandLocator): ?string
    {
        if (null === $commandLocator) {
            return null;
        }

        $commandName = self::extractCommandName($event->command ?? '');
        if (null === $commandName) {
            return null;
        }

        $command = $commandLocator($commandName);
        if (null === $command) {
            return null;
        }

        $reflection = new \ReflectionClass($command::class);
        $attributes = $reflection->getAttributes(Monitor::class);
        if ([] === $attributes) {
            return null;
        }

        // `newInstance()` runs the attribute constructor, which can throw
        // `\InvalidArgumentException` on a misused `#[Monitor]` (e.g. both
        // `uuid:` and `env:` set, or neither). Both that throw and the
        // env-resolution itself happen inside this boundary, and the SDK's
        // never-break-the-host-job contract requires that we collapse any
        // failure to `null` rather than letting it surface during the
        // scheduler-binding pass.
        try {
            return $attributes[0]->newInstance()->resolveUuid();
        } catch (\Throwable) {
            // Best-effort: a misused attribute is a programmer error
            // worth surfacing, but not at the cost of breaking the
            // host scheduler. The Symfony subscriber side logs the
            // throw; the Laravel side has no logger handle at this
            // pure-function call site, so we stay silent here.
            return null;
        }
    }

    /**
     * Pulls the command name token out of the shell-formatted string
     * `Illuminate\Console\Application::formatCommandString()` produces:
     *
     *   '/usr/bin/php' 'artisan' reports:nightly --opt=value
     *
     * On Windows the quoting may differ; we accept single, double, or no
     * quoting around the `artisan` token. Anything else returns null
     * (e.g. `Schedule::exec('rsync /src /dst')`, which has no `artisan`
     * marker at all, must not match).
     */
    public static function extractCommandName(string $line): ?string
    {
        // The regex demands that `artisan` appears as its own token —
        // bounded by quotes or whitespace on both sides — so the substring
        // `artisan` inside an unrelated `exec` payload (e.g. a path that
        // happens to contain that letter sequence) does not match.
        if (1 !== preg_match("/(?:^|\\s|['\"])artisan(?:['\"]|\\s)+(\\S+)/", $line, $m)) {
            return null;
        }

        return $m[1];
    }
}
