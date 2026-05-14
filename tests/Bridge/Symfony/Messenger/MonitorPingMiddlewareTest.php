<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\Messenger;

use CronMonitor\Bridge\Symfony\Messenger\MonitorPingMiddleware;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class MonitorPingMiddlewareTest extends TestCase
{
    private const UUID = '22222222-2222-4222-8222-222222222222';

    public function test_skips_pinging_when_envelope_has_no_received_stamp(): void
    {
        $http = new RecordingHttpClient([]);
        $middleware = $this->buildMiddleware($http, [SampleMessage::class => self::UUID]);

        $envelope = new Envelope(new SampleMessage());
        $stack = $this->stackThatReturns($envelope);

        $result = $middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
        self::assertCount(0, $http->requests, 'Producer-side dispatch must not fire pings.');
    }

    public function test_skips_pinging_when_message_class_is_not_mapped(): void
    {
        $http = new RecordingHttpClient([]);
        $middleware = $this->buildMiddleware($http, []);

        $envelope = (new Envelope(new SampleMessage()))->with(new ReceivedStamp('async'));
        $stack = $this->stackThatReturns($envelope);

        $middleware->handle($envelope, $stack);

        self::assertCount(0, $http->requests);
    }

    public function test_emits_start_then_success_when_handler_returns_normally(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $middleware = $this->buildMiddleware($http, [SampleMessage::class => self::UUID]);

        $envelope = (new Envelope(new SampleMessage()))->with(new ReceivedStamp('async'));
        $stack = $this->stackThatReturns($envelope);

        $middleware->handle($envelope, $stack);

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
        self::assertStringEndsWith('/ping/'.self::UUID.'/success', (string) $http->requests[1]->getUri());
    }

    public function test_emits_start_then_fail_when_handler_throws_and_rethrows(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $middleware = $this->buildMiddleware($http, [SampleMessage::class => self::UUID]);

        $envelope = (new Envelope(new SampleMessage()))->with(new ReceivedStamp('async'));
        $stack = $this->stackThatThrows(new \RuntimeException('handler boom'));

        try {
            $middleware->handle($envelope, $stack);
            self::fail('Expected handler exception to bubble.');
        } catch (\RuntimeException $e) {
            self::assertSame('handler boom', $e->getMessage());
        }

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
        self::assertStringEndsWith('/ping/'.self::UUID.'/fail', (string) $http->requests[1]->getUri());
        // Body of the fail ping should carry the exception summary so an
        // operator triaging the dashboard sees the immediate cause.
        self::assertStringContainsString('RuntimeException: handler boom', (string) $http->requests[1]->getBody());
    }

    /**
     * @param array<class-string, string> $monitorMap
     */
    private function buildMiddleware(RecordingHttpClient $http, array $monitorMap): MonitorPingMiddleware
    {
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
        );

        return new MonitorPingMiddleware($client, $monitorMap);
    }

    private function stackThatReturns(Envelope $envelope): StackInterface
    {
        return new class($envelope) implements StackInterface {
            public function __construct(private readonly Envelope $envelope)
            {
            }

            public function next(): \Symfony\Component\Messenger\Middleware\MiddlewareInterface
            {
                return new class($this->envelope) implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
                    public function __construct(private readonly Envelope $envelope)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        return $this->envelope;
                    }
                };
            }
        };
    }

    private function stackThatThrows(\Throwable $error): StackInterface
    {
        return new class($error) implements StackInterface {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function next(): \Symfony\Component\Messenger\Middleware\MiddlewareInterface
            {
                return new class($this->error) implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
                    public function __construct(private readonly \Throwable $error)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        throw $this->error;
                    }
                };
            }
        };
    }
}

final class SampleMessage
{
}
