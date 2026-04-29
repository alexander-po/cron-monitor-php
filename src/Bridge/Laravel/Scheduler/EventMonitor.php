<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel\Scheduler;

use CronMonitor\Client\CronMonitorClient;
use Illuminate\Console\Scheduling\Event;

/**
 * Helper that installs the cron-monitor lifecycle hooks on a Laravel
 * scheduler `Event`.
 *
 * Why this exists as a separate class:
 *  - keeps the macro body in {@see \CronMonitor\Bridge\Laravel\CronMonitorServiceProvider}
 *    a single line, which is what makes the `->monitor('uuid')` ergonomics
 *    feel native to Laravel users;
 *  - the `before` / `onSuccess` / `onFailure` hooks need access to the same
 *    `$uuid` and the same `$client` instance, and stuffing closures inline
 *    in the provider would obscure the actual lifecycle logic.
 *
 * The closures wrap their bodies in a try/catch — Laravel's scheduler will
 * happily rethrow exceptions from these hooks and abort subsequent commands
 * in the same run, which would punish the user for our SDK being slow.
 */
final class EventMonitor
{
    public static function install(Event $event, CronMonitorClient $client, string $monitorUuid): Event
    {
        return $event
            ->before(static function () use ($client, $monitorUuid): void {
                self::safe(static fn () => $client->start($monitorUuid));
            })
            ->onSuccess(static function () use ($client, $monitorUuid): void {
                self::safe(static fn () => $client->success($monitorUuid));
            })
            ->onFailure(static function () use ($client, $monitorUuid): void {
                // Best-effort failure ping. We deliberately do not pass the
                // exception body — Laravel's `onFailure` callback signature
                // does not include the exception in older versions, and
                // duplicating reflection across versions to extract it is
                // not worth the surface for a marginal log-quality win.
                self::safe(static fn () => $client->fail($monitorUuid));
            });
    }

    /**
     * @param callable():mixed $callback
     */
    private static function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Intentionally swallow — the SDK contract says it does not
            // throw, and any defect here must not break the user's job.
        }
    }
}
