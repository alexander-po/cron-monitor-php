<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Client;

use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\InMemoryLogger;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class CronMonitorClientTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    public function test_heartbeat_posts_to_canonical_url_and_returns_delivered_result(): void
    {
        $http = new RecordingHttpClient([new Response(200)]);
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
        );

        $result = $client->heartbeat(self::UUID);

        self::assertTrue($result->delivered);
        self::assertSame(200, $result->statusCode);
        self::assertSame(1, $result->attempts);
        self::assertCount(1, $http->requests);

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://cronheart.com/ping/'.self::UUID,
            (string) $request->getUri(),
        );
        self::assertStringContainsString(
            'cron-monitor-php-sdk',
            $request->getHeaderLine('User-Agent'),
        );
    }

    public function test_start_appends_start_action_segment(): void
    {
        $http = new RecordingHttpClient([new Response(204)]);
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
        );

        $result = $client->start(self::UUID);

        self::assertTrue($result->delivered);
        self::assertSame(
            'https://cronheart.com/ping/'.self::UUID.'/start',
            (string) $http->requests[0]->getUri(),
        );
    }

    public function test_body_is_truncated_to_server_cap_with_marker(): void
    {
        $http = new RecordingHttpClient([new Response(200)]);
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
        );

        $hugeBody = str_repeat('A', 30000);
        $client->success(self::UUID, $hugeBody);

        $sent = (string) $http->requests[0]->getBody();
        // Server (cron-monitor backend `Ping::BODY_EXCERPT_MAX_BYTES`) caps
        // at 10_000 bytes; the SDK matches exactly so the wire payload has no
        // bytes the server will silently truncate.
        self::assertSame(10000, \strlen($sent));
        self::assertStringEndsWith('[truncated by SDK]', $sent);
    }

    public function test_4xx_response_does_not_retry(): void
    {
        $http = new RecordingHttpClient([new Response(404)]);
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com', retries: 3),
            $http,
            $factory,
            $factory,
        );

        $result = $client->heartbeat(self::UUID);

        self::assertFalse($result->delivered);
        self::assertSame(404, $result->statusCode);
        self::assertSame(1, $result->attempts);
        self::assertCount(1, $http->requests);
    }

    public function test_5xx_response_retries_up_to_budget_then_returns_failed(): void
    {
        $http = new RecordingHttpClient([
            new Response(503),
            new Response(503),
            new Response(503),
        ]);
        $factory = new HttpFactory();
        $logger = new InMemoryLogger();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com', retries: 2),
            $http,
            $factory,
            $factory,
            $logger,
        );

        $result = $client->heartbeat(self::UUID);

        self::assertFalse($result->delivered);
        self::assertSame(503, $result->statusCode);
        self::assertSame(3, $result->attempts);
        self::assertCount(3, $http->requests);

        // The SDK must log a warning so operators can see the failure
        // even though the host job continued.
        $warnings = array_filter(
            $logger->records,
            static fn (array $r) => 'warning' === $r['level'],
        );
        self::assertCount(1, $warnings);
    }

    public function test_network_exception_is_swallowed_into_failed_result(): void
    {
        $exception = new class extends \RuntimeException implements ClientExceptionInterface {};
        $http = new RecordingHttpClient([$exception]);
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com', retries: 0),
            $http,
            $factory,
            $factory,
        );

        $result = $client->heartbeat(self::UUID);

        self::assertFalse($result->delivered);
        self::assertNull($result->statusCode);
        self::assertSame(1, $result->attempts);
    }

    public function test_invalid_uuid_returns_failed_result_without_calling_http(): void
    {
        $http = new RecordingHttpClient([]);
        $factory = new HttpFactory();
        $logger = new InMemoryLogger();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
            $logger,
        );

        $result = $client->ping('not-a-uuid', null, null);

        self::assertFalse($result->delivered);
        self::assertSame(0, $result->attempts);
        self::assertCount(0, $http->requests);
        // The bad-UUID branch logs at error level, not warning, because
        // it is a programmer error rather than a transient failure.
        $errors = array_filter(
            $logger->records,
            static fn (array $r) => 'error' === $r['level'],
        );
        self::assertCount(1, $errors);
    }

    public function test_authorization_header_is_attached_when_api_key_is_configured(): void
    {
        $http = new RecordingHttpClient([new Response(200)]);
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com', apiKey: 'sk_test_123'),
            $http,
            $factory,
            $factory,
        );

        $client->heartbeat(self::UUID);

        /** @var RequestInterface $request */
        $request = $http->requests[0];
        self::assertSame('Bearer sk_test_123', $request->getHeaderLine('Authorization'));
    }
}
