<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Laravel\Scheduler;

use CronMonitor\Bridge\Laravel\Scheduler\EventMonitor;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the `EventMonitor` helper that backs the `->monitor('uuid')`
 * macro installed by the Laravel service provider. The tests construct a
 * real `Illuminate\Console\Scheduling\Event` instance, install the
 * cron-monitor hooks, then trigger the same callback lists that Laravel's
 * scheduler invokes — `beforeCallbacks`, `successCallbacks`,
 * `failureCallbacks`. This mirrors what happens at runtime without
 * standing up the whole console kernel.
 */
final class EventMonitorTest extends TestCase
{
    private const UUID = '55555555-5555-4555-8555-555555555555';

    public function test_install_returns_the_same_event_for_fluent_chaining(): void
    {
        $http = new RecordingHttpClient([]);
        $event = $this->buildEvent();

        $returned = EventMonitor::install($event, $this->buildClient($http), self::UUID);

        self::assertSame($event, $returned, 'install() must return the input event so callers can chain further scheduler API.');
    }

    public function test_before_callback_fires_start_ping(): void
    {
        $http = new RecordingHttpClient([new Response(200)]);
        $event = EventMonitor::install($this->buildEvent(), $this->buildClient($http), self::UUID);

        $this->invokeBefore($event);

        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
    }

    public function test_success_callback_is_registered_and_fires_success_ping_when_invoked(): void
    {
        $http = new RecordingHttpClient([new Response(200)]);
        $event = EventMonitor::install($this->buildEvent(), $this->buildClient($http), self::UUID);

        // Laravel routes `onSuccess` through an `afterCallbacks` wrapper that
        // calls `$container->call($ourCallback)` — that path depends on
        // Laravel's BoundMethod (PHP 8.2+) internals which we don't want
        // this unit test to span. Instead we extract our registered closure
        // out of the wrapper's `use` clause and invoke it directly, the
        // same end-effect Laravel would produce on a success exit.
        $callbacks = $this->extractRegisteredCallbacks($event, 'afterCallbacks');
        self::assertCount(2, $callbacks, 'install() must register both onSuccess and onFailure callbacks.');

        // Invoking the first registered after-callback corresponds to the
        // onSuccess hook (registration order: onSuccess -> onFailure).
        $callbacks[0]();

        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/success', (string) $http->requests[0]->getUri());
    }

    public function test_failure_callback_is_registered_and_fires_fail_ping_when_invoked(): void
    {
        $http = new RecordingHttpClient([new Response(200)]);
        $event = EventMonitor::install($this->buildEvent(), $this->buildClient($http), self::UUID);

        $callbacks = $this->extractRegisteredCallbacks($event, 'afterCallbacks');
        // Second registered after-callback corresponds to onFailure.
        $callbacks[1]();

        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/fail', (string) $http->requests[0]->getUri());
    }

    public function test_sdk_failure_is_swallowed_so_scheduler_does_not_abort_subsequent_commands(): void
    {
        // Laravel's scheduler rethrows exceptions from before/after callbacks
        // and stops running subsequent commands. The cron-monitor SDK MUST
        // NOT cause that — a broken monitor backend punishing every
        // following job in the same cron run would invert the value the SDK
        // is meant to provide.
        $boom = new class extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
        $http = new RecordingHttpClient([$boom, $boom, $boom]);
        $event = EventMonitor::install($this->buildEvent(), $this->buildClient($http), self::UUID);

        $this->invokeBefore($event);
        $afterCallbacks = $this->extractRegisteredCallbacks($event, 'afterCallbacks');
        foreach ($afterCallbacks as $callback) {
            $callback();
        }

        self::assertCount(3, $http->requests);
    }

    private function buildEvent(): Event
    {
        // The scheduler `Event` constructor needs an `EventMutex`. We pass a
        // real `CacheEventMutex` backed by a noop cache factory — the mutex
        // is never consulted in these tests because we invoke the registered
        // callbacks directly, but the constructor type-hint requires it.
        $mutex = new CacheEventMutex(new class implements CacheFactory {
            public function store($name = null): Repository
            {
                throw new \LogicException('cache should not be touched in these tests');
            }
        });

        return new Event($mutex, 'reports:nightly', null);
    }

    private function buildClient(RecordingHttpClient $http): CronMonitorClient
    {
        $factory = new HttpFactory();

        return new CronMonitorClient(
            new Configuration('https://cronheart.com', retries: 0),
            $http,
            $factory,
            $factory,
        );
    }

    /**
     * Invoke every registered `beforeCallbacks` closure. Production Laravel
     * passes a Container in (`$container->call($callback)`); our cron-monitor
     * closures are zero-arg `static fn` lambdas, so direct invocation is
     * the same observable behaviour minus the auto-injected dependencies.
     */
    private function invokeBefore(Event $event): void
    {
        $reflection = new \ReflectionObject($event);
        $prop = $reflection->getProperty('beforeCallbacks');
        $prop->setAccessible(true);
        /** @var array<int, \Closure> $callbacks */
        $callbacks = $prop->getValue($event);
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    /**
     * Extract our cron-monitor callbacks from the named callback list on
     * `Event`. Laravel wraps `onSuccess` / `onFailure` user callbacks in an
     * outer `function (Container) use ($callback) { ... }` whose `$callback`
     * is the closure we passed to `EventMonitor::install()`. We pull that
     * inner closure out via `getStaticVariables()` rather than invoking the
     * wrapper directly — that lets the test stay framework-version agnostic
     * (Laravel 11's BoundMethod machinery requires PHP 8.2's
     * `ReflectionFunction::isAnonymous()`, which is not available on PHP 8.1
     * and is irrelevant to what we're actually verifying).
     *
     * @return list<\Closure>
     */
    private function extractRegisteredCallbacks(Event $event, string $property): array
    {
        $reflection = new \ReflectionObject($event);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        /** @var array<int, \Closure> $wrappers */
        $wrappers = $prop->getValue($event);

        $inner = [];
        foreach ($wrappers as $wrapper) {
            $vars = (new \ReflectionFunction($wrapper))->getStaticVariables();
            if (isset($vars['callback']) && $vars['callback'] instanceof \Closure) {
                $inner[] = $vars['callback'];
            }
        }

        return $inner;
    }
}
