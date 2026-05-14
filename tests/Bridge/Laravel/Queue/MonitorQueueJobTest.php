<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Laravel\Queue;

use CronMonitor\Bridge\Laravel\Queue\MonitorQueueJob;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class MonitorQueueJobTest extends TestCase
{
    private const UUID = '44444444-4444-4444-8444-444444444444';

    protected function tearDown(): void
    {
        // `app()` (the shim in `tests/bootstrap.php`) resolves through the
        // global container singleton. Reset it between tests so a binding
        // made in one test does not leak into the next and mask `withUuid`'s
        // unbound-container branch.
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_with_uuid_resolves_client_from_container_when_bound(): void
    {
        // Models the real production wiring: the Laravel service provider's
        // `register()` method binds `CronMonitorClient` as a singleton. The
        // `withUuid()` static factory must reach for the same binding so
        // users can write `middleware()` without threading the client.
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $client = $this->buildClient($http);

        $container = new Container();
        $container->instance(CronMonitorClient::class, $client);
        Container::setInstance($container);

        $middleware = MonitorQueueJob::withUuid(self::UUID);
        $middleware->handle(new \stdClass(), static fn () => 'ok');

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
        self::assertStringEndsWith('/ping/'.self::UUID.'/success', (string) $http->requests[1]->getUri());
    }

    public function test_with_uuid_falls_back_to_noop_when_container_cannot_resolve_client(): void
    {
        // Pathological setup: the service provider was never registered, or
        // the worker boots before the container is ready. `app()` throws a
        // BindingResolutionException because `CronMonitorClient` has
        // unresolvable interface dependencies. The static factory must
        // catch and return a no-op middleware — throwing here would crash
        // the queue worker before the user's `handle()` ran.
        Container::setInstance(new Container()); // empty container, no bindings

        $middleware = MonitorQueueJob::withUuid(self::UUID);
        $result = $middleware->handle(new \stdClass(), static fn () => 'still ran');

        self::assertSame('still ran', $result);
    }

    public function test_returning_handler_emits_start_then_success(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $middleware = $this->buildMiddleware($http);

        $sawJob = null;
        $result = $middleware->handle(
            new \stdClass(),
            static function (object $job) use (&$sawJob): string {
                $sawJob = $job;

                return 'handler-result';
            },
        );

        self::assertSame('handler-result', $result);
        self::assertInstanceOf(\stdClass::class, $sawJob);
        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
        self::assertStringEndsWith('/ping/'.self::UUID.'/success', (string) $http->requests[1]->getUri());
    }

    public function test_throwing_handler_emits_fail_with_exception_body_and_rethrows(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $middleware = $this->buildMiddleware($http);

        try {
            $middleware->handle(new \stdClass(), static function (): never {
                throw new \RuntimeException('queue handler boom');
            });
            self::fail('Expected handler exception to bubble.');
        } catch (\RuntimeException $e) {
            self::assertSame('queue handler boom', $e->getMessage());
        }

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
        self::assertStringEndsWith('/ping/'.self::UUID.'/fail', (string) $http->requests[1]->getUri());
        self::assertStringContainsString(
            'RuntimeException: queue handler boom',
            (string) $http->requests[1]->getBody(),
        );
    }

    public function test_null_client_falls_back_to_no_op_pipeline(): void
    {
        // When `withUuid()` cannot resolve the SDK client (unbound, partially
        // booted container, etc.) it stores a null client. The middleware
        // must still let the job's `handle()` run — the SDK contract is to
        // never break the host job, even when we cannot monitor it.
        $middleware = new MonitorQueueJob(null, self::UUID);

        $sawJob = null;
        $result = $middleware->handle(
            new \stdClass(),
            static function (object $job) use (&$sawJob): string {
                $sawJob = $job;

                return 'still-ran';
            },
        );

        self::assertSame('still-ran', $result);
        self::assertInstanceOf(\stdClass::class, $sawJob);
    }

    public function test_sdk_failure_is_swallowed_and_does_not_break_the_job(): void
    {
        // Simulate the worst case: the PSR-18 client throws on every request.
        // The middleware must still let the wrapped handler run and propagate
        // its return value — losing a real job over a monitoring outage is
        // exactly the failure mode this SDK exists to prevent.
        $exception = new class extends \RuntimeException implements ClientExceptionInterface {};
        $http = new RecordingHttpClient([$exception, $exception]);
        $middleware = $this->buildMiddleware($http);

        $result = $middleware->handle(new \stdClass(), static fn () => 'still ran');

        self::assertSame('still ran', $result);
    }

    private function buildMiddleware(RecordingHttpClient $http): MonitorQueueJob
    {
        return new MonitorQueueJob($this->buildClient($http), self::UUID);
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
}
