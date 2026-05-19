<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Console;

use CronMonitor\Attribute\Monitor;
use CronMonitor\Client\CronMonitorClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Wraps any `bin/console <name>` invocation in `start` / `success` / `fail`
 * pings when the command's name appears in the configured `commandMap`.
 *
 * This is the integration point for the most common Symfony deployment shape
 * — a console command dropped straight into crontab or a systemd timer,
 * without going through Scheduler + Messenger. The Messenger middleware in
 * this bundle covers Scheduler dispatches; this subscriber covers everything
 * else that runs through the Symfony Application kernel.
 *
 * Lifecycle pairing:
 *  - `ConsoleEvents::COMMAND`   → fire `start` if the command will actually
 *                                 run. We subscribe at a low priority so
 *                                 other listeners that may call
 *                                 `ConsoleCommandEvent::disableCommand()`
 *                                 run first; a disabled command must not
 *                                 produce a phantom start+fail pair.
 *  - `ConsoleEvents::ERROR`     → stash the thrown error so TERMINATE can
 *                                 fail-ping with a meaningful body. We do
 *                                 not ping here because ERROR may be
 *                                 followed by TERMINATE with the exit code
 *                                 still being normalised by listeners.
 *  - `ConsoleEvents::TERMINATE` → exit code 0 → `success`; non-zero → `fail`
 *                                 with either the stashed exception summary
 *                                 or a "non-zero exit" placeholder. Exit
 *                                 code 113 (`RETURN_CODE_DISABLED`) is
 *                                 treated as "command was disabled" and
 *                                 produces no ping.
 *
 * SDK errors are swallowed: a broken cron-monitor backend must never break
 * the user's command run. We defensively guard against the SDK throwing
 * even though its contract says it doesn't, because losing a production
 * job over a monitoring failure is exactly the failure mode we're paid
 * not to cause.
 */
final class MonitorConsoleSubscriber implements EventSubscriberInterface
{
    /**
     * Listener priority. Negative so user code that mutates the event
     * (disabling the command, rewriting the throwable, normalising the
     * exit code) runs before us and we observe the final intent.
     */
    private const PRIORITY = -255;

    /**
     * Defense in depth against `$errorByRun` growing without bound in
     * long-lived processes. Symfony's console kernel emits TERMINATE after
     * ERROR in every documented path, but pathological setups (e.g.
     * `Application::setCatchExceptions(false)` recovered by an outer
     * try/catch + loop) could orphan entries. A bound of 64 covers any
     * realistic concurrency for command-in-command setups while keeping
     * the worst-case memory footprint trivially small.
     */
    private const ERROR_STASH_LIMIT = 64;

    /**
     * Maps run-key → captured `\Throwable`, keyed by `spl_object_hash` of
     * the `InputInterface` instance. Symfony's console kernel passes the
     * same input object to every event in a run, so this lets TERMINATE
     * recover the throwable that ERROR saw without leaking state across
     * nested or sequential command invocations in long-lived workers (e.g.
     * `messenger:consume` calling sub-commands).
     *
     * @var array<string, \Throwable>
     */
    private array $errorByRun = [];

    /**
     * Per-class cache of UUIDs resolved from `#[Monitor]` attributes. Keyed
     * by Command FQCN. Long-lived processes such as `messenger:consume` may
     * dispatch thousands of console commands per worker lifetime; without a
     * cache each one re-runs `ReflectionClass::getAttributes(...)`, which is
     * cheap but not free.
     *
     * Cache misses are stored as `null` so the second invocation of an
     * attribute-less command doesn't re-reflect either.
     *
     * **Env-var lifecycle implication:** the `#[Monitor(env: 'VAR')]` form
     * reads the env value at first lookup and caches the resolved UUID.
     * A long-lived worker that has its UUID env var rotated mid-process
     * will keep using the cached value until the worker restarts. In
     * practice UUID rotation always implies a redeploy/restart anyway
     * (the new UUID has no consumer until the next ping), so the
     * worker-process lifetime is the right invalidation boundary.
     *
     * @var array<class-string<Command>, ?string>
     */
    private array $attributeUuidCache = [];

    /**
     * @param array<string, string> $commandMap command name (as registered, e.g. "app:reports:nightly") => monitor UUID
     */
    public function __construct(
        private readonly CronMonitorClient $client,
        private readonly array $commandMap,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', self::PRIORITY],
            ConsoleEvents::ERROR => ['onError', self::PRIORITY],
            ConsoleEvents::TERMINATE => ['onTerminate', self::PRIORITY],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $uuid = $this->resolveUuid($event->getCommand());
        if (null === $uuid) {
            return;
        }

        // Another listener may have called `disableCommand()`. We run at a
        // low priority so we see the final state — sending `start` here
        // would otherwise be paired with a phantom `fail` carrying exit code
        // 113 (RETURN_CODE_DISABLED), which is a false positive on the
        // monitoring dashboard.
        if (!$event->commandShouldRun()) {
            return;
        }

        $this->safePing(fn () => $this->client->start($uuid));
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $uuid = $this->resolveUuid($event->getCommand());
        if (null === $uuid) {
            return;
        }

        // Stash for TERMINATE; do not ping here. The kernel always fires
        // TERMINATE after ERROR, so deferring keeps the lifecycle to a
        // single "start + (success|fail)" pair per run.
        $runKey = spl_object_hash($event->getInput());
        $this->errorByRun[$runKey] = $event->getError();
        $this->trimErrorStash();
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $runKey = spl_object_hash($event->getInput());
        // Always clear any stashed error for this run, even when the command
        // is unmapped. Defends against memory accumulation if a command
        // resolved to a mapped name on ERROR but to something else (or to
        // null) on TERMINATE — a pathological case, but the cost of the
        // unconditional `unset` is negligible.
        $error = $this->errorByRun[$runKey] ?? null;
        unset($this->errorByRun[$runKey]);

        $uuid = $this->resolveUuid($event->getCommand());
        if (null === $uuid) {
            return;
        }

        $exitCode = $event->getExitCode();

        // Command was disabled by another listener — we suppressed `start`
        // above, so suppress the terminal ping too. The dead-man-switch on
        // the server side will alert if the operator was expecting this
        // monitor to fire and it never did.
        if (ConsoleCommandEvent::RETURN_CODE_DISABLED === $exitCode && null === $error) {
            return;
        }

        if (0 === $exitCode && null === $error) {
            $this->safePing(fn () => $this->client->success($uuid));

            return;
        }

        $body = null !== $error
            ? $this->summariseError($error)
            : \sprintf('command exited with non-zero status %d', $exitCode);

        $this->safePing(fn () => $this->client->fail($uuid, $body));
    }

    private function resolveUuid(?Command $command): ?string
    {
        if (null === $command) {
            return null;
        }

        // The explicit YAML / config map wins over the attribute. Users
        // sometimes want to override an attribute-set UUID per environment
        // — e.g. blank in dev to silence the monitor, populated from an env
        // var in prod — and an explicit override is more discoverable than
        // having to remove the attribute itself.
        $commandName = $command->getName();
        if (null !== $commandName && '' !== $commandName) {
            $mapped = $this->commandMap[$commandName] ?? null;
            // Treat an empty-string mapping (the common shape when the
            // UUID comes from an env var that is intentionally blank
            // outside prod, e.g. `'app:reports:nightly' => '%env(MY_UUID)%'`)
            // as an *explicit* "do not monitor this in this environment".
            // The empty string is a deliberate user choice; it must shadow
            // any attribute on the class so deploy-time configuration wins
            // over compile-time declaration.
            if (\array_key_exists($commandName, $this->commandMap)) {
                return ('' === $mapped) ? null : $mapped;
            }
        }

        // Attribute fallback: scan the command class for `#[Monitor]`. The
        // result (UUID or null) is cached per-class for the lifetime of the
        // subscriber so long-lived workers don't re-reflect on every
        // command dispatch.
        return $this->resolveAttributeUuid($command);
    }

    private function resolveAttributeUuid(Command $command): ?string
    {
        $class = $command::class;
        if (\array_key_exists($class, $this->attributeUuidCache)) {
            return $this->attributeUuidCache[$class];
        }

        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Monitor::class);
        if ([] === $attributes) {
            return $this->attributeUuidCache[$class] = null;
        }

        // `newInstance()` runs the attribute constructor, which can throw
        // `\InvalidArgumentException` on a misused `#[Monitor]` (e.g. both
        // `uuid:` and `env:` set, or neither). `resolveUuid()` itself
        // does not throw, but defending the boundary keeps the SDK's
        // never-break-the-host-job contract intact even if a future
        // attribute change adds new invariants.
        try {
            $uuid = $attributes[0]->newInstance()->resolveUuid();
        } catch (\Throwable $error) {
            $this->logger->warning('cron-monitor: #[Monitor] attribute could not be instantiated', [
                'command' => $command->getName(),
                'class' => $class,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);

            return $this->attributeUuidCache[$class] = null;
        }

        // `resolveUuid()` already collapses empty literals / missing env
        // vars to null, so no extra empty-string guard is needed here.
        return $this->attributeUuidCache[$class] = $uuid;
    }

    /**
     * Drop the oldest stashed errors when the map exceeds `ERROR_STASH_LIMIT`.
     * In normal operation `unset()` on TERMINATE keeps the map at most 1-2
     * deep; this is a defense-in-depth bound, not a hot-path concern.
     */
    private function trimErrorStash(): void
    {
        while (\count($this->errorByRun) > self::ERROR_STASH_LIMIT) {
            array_shift($this->errorByRun);
        }
    }

    /**
     * @param callable():mixed $callback
     */
    private function safePing(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $sdkError) {
            $this->logger->warning('cron-monitor console subscriber swallowed SDK error', [
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
