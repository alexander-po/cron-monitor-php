<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel\Queue;

use CronMonitor\Client\CronMonitorClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Job middleware that wraps a Laravel queue job in `start` / `success` /
 * `fail` cron-monitor pings.
 *
 * Pattern follows the standard Laravel job-middleware contract (`handle($job,
 * $next)`), which means no framework code, no event-dispatcher wiring, and
 * no compile-time dependency on `illuminate/queue` from this SDK. The user
 * already has those classes available in their Laravel install — we just
 * slot into the documented extension point.
 *
 * Usage in a job class:
 * ```php
 * public function middleware(): array
 * {
 *     return [MonitorQueueJob::withUuid('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx')];
 * }
 * ```
 *
 * The middleware is intentionally permissive about the `$job` argument type:
 * Laravel passes the unwrapped command object (e.g. the user's job instance),
 * which has no shared base class, so accepting `mixed` is the only
 * version-stable signature. The `$next` closure is the standard pipeline
 * continuation.
 *
 * **Retry / release caveat.** Laravel job middleware sees a job that calls
 * `release()` or returns normally as the same control flow — both come back
 * through `$next` without throwing. This middleware reports both as
 * `success`. If your job releases itself back to the queue for retry, the
 * cron-monitor dashboard will show a success for that attempt; only an
 * uncaught throwable produces a `fail` ping. For most cron-style jobs (a
 * nightly report, a daily sync) the distinction does not matter — they
 * either succeed end-to-end or throw. If you depend on the difference,
 * prefer wiring the cron-monitor client directly inside your job's
 * `failed()` method.
 *
 * The SDK never throws on pings, but the middleware also defensively guards
 * the ping callbacks: a misbehaving PSR-18 stack must not abort the job that
 * the user is paying us to make more reliable, not less.
 */
final class MonitorQueueJob
{
    public function __construct(
        private readonly ?CronMonitorClient $client,
        private readonly string $monitorUuid,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Shortcut for the common case where the SDK client is already bound in
     * the Laravel service container (which the bundled service provider does
     * at register-time). Lets users write:
     *
     * ```php
     * return [MonitorQueueJob::withUuid('uuid-here')];
     * ```
     *
     * without threading the client through their job constructor.
     *
     * `app()` is resolved at job-execution time, not at middleware-array build
     * time, so this works under serialised queue dispatch too — the queue
     * worker rehydrates the job and rebuilds the middleware list in-process.
     *
     * If the container cannot resolve the client — unbound singleton,
     * partially booted application, the `cron-monitor` service provider
     * unregistered — we return a no-op middleware rather than throwing.
     * The middleware list is built by Laravel at job-handle time, and a
     * throw here would crash the worker before the user's `handle()`
     * method ever ran. That violates the SDK's "never break the host job"
     * contract.
     *
     * The catch is intentionally `\Throwable` (not `\Exception`): the
     * Laravel container raises `BindingResolutionException` for the
     * happy-path failure, but unrecoverable constructor problems
     * surface as `\Error` (`TypeError`, `ArgumentCountError`,
     * `UnhandledMatchError`) — all of which must equally not break
     * the host job.
     */
    public static function withUuid(string $monitorUuid): self
    {
        try {
            /** @var CronMonitorClient $client */
            $client = app(CronMonitorClient::class);

            return new self($client, $monitorUuid);
        } catch (\Throwable) {
            return new self(null, $monitorUuid);
        }
    }

    /**
     * @param callable(mixed): mixed $next
     */
    public function handle(mixed $job, callable $next): mixed
    {
        if (null === $this->client) {
            // No-op fallback when the container failed to resolve the SDK
            // client. We must not throw — the job still has to run.
            return $next($job);
        }

        $client = $this->client;
        $uuid = $this->monitorUuid;

        $this->safePing(static fn () => $client->start($uuid));

        try {
            $result = $next($job);
        } catch (\Throwable $handlerError) {
            $this->safePing(fn () => $client->fail(
                $uuid,
                $this->summariseError($handlerError),
            ));
            throw $handlerError;
        }

        $this->safePing(static fn () => $client->success($uuid));

        return $result;
    }

    /**
     * @param callable():mixed $callback
     */
    private function safePing(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $sdkError) {
            // SDK contract says it does not throw, but if some custom PSR-18
            // client surfaces an error anyway, we must not break the host job.
            $this->logger->warning('cron-monitor queue middleware swallowed SDK error', [
                'exception' => $sdkError::class,
                'message' => $sdkError->getMessage(),
            ]);
        }
    }

    private function summariseError(\Throwable $error): string
    {
        return \sprintf(
            "%s: %s\n  at %s:%d",
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
        );
    }
}
