<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Laravel\Queue;

use CronMonitor\Bridge\Laravel\Queue\MonitorQueueJob;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class MonitorQueueJobTest extends TestCase
{
    private const UUID = '44444444-4444-4444-8444-444444444444';

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
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cron-monitor.io', retries: 0),
            $http,
            $factory,
            $factory,
        );

        return new MonitorQueueJob($client, self::UUID);
    }
}
