<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Messenger;

use CronMonitor\Client\CronMonitorClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Wraps Messenger handlers in `start` / `success` / `fail` pings when the
 * envelope's message FQCN appears in the configured `monitorMap`.
 *
 * This middleware fires only on the **consumer** side — it relies on
 * `ReceivedStamp`/`ConsumedByWorkerStamp` to skip producer-side dispatching
 * (which would otherwise double-ping every send + handle round-trip when
 * the transport is `sync://`). The cost is a single instance check per
 * envelope, which is negligible compared to the wrapped handler.
 *
 * Failures from the SDK are swallowed: the SDK itself never throws, but the
 * middleware also defensively guards against future PSR-18 client surprises
 * so that a broken cron-monitor backend never breaks the user's job.
 */
final class MonitorPingMiddleware implements MiddlewareInterface
{
    /**
     * @param array<class-string, string> $monitorMap message FQCN => monitor UUID
     */
    public function __construct(
        private readonly CronMonitorClient $client,
        private readonly array $monitorMap,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only trigger pings during consumption — `ReceivedStamp` is added by
        // the worker before middlewares run on the receive path. Dispatching
        // (`MessageBusInterface::dispatch`) does not have this stamp, and we
        // do not want to ping for "scheduled" or "queued" but not yet
        // processed jobs.
        $isWorkerPath = null !== $envelope->last(ReceivedStamp::class)
            || null !== $envelope->last(ConsumedByWorkerStamp::class);

        if (!$isWorkerPath) {
            return $stack->next()->handle($envelope, $stack);
        }

        $message = $envelope->getMessage();
        $uuid = $this->monitorMap[$message::class] ?? null;
        // Empty-string mapping (`'App\Message\Foo' => '%env(FOO_UUID)%'`
        // with FOO_UUID blank in dev/test) is treated as "unmapped" so the
        // SDK's UUID-v4 validator does not throw on every consumed
        // envelope. See the matching guard in `MonitorConsoleSubscriber`.
        if (null === $uuid || '' === $uuid) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->safePing(fn () => $this->client->start($uuid));

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $handlerError) {
            $this->safePing(fn () => $this->client->fail(
                $uuid,
                $this->summariseError($handlerError),
            ));
            throw $handlerError;
        }

        $this->safePing(fn () => $this->client->success($uuid));

        return $envelope;
    }

    /**
     * @param callable():mixed $callback
     */
    private function safePing(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $sdkError) {
            // The SDK contract says it does not throw, but we still defend
            // against rogue PSR-18 stacks that violate that contract.
            $this->logger->warning('cron-monitor middleware swallowed SDK error', [
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
