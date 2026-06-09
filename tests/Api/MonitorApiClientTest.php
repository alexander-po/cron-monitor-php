<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\NotFoundException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\Exception\UnexpectedResponseException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\LocalHttpServer;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MonitorApiClientTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function client(array $queue, ?Configuration $configuration = null): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            $configuration ?? new Configuration('https://cronheart.com', apiKey: 'cmk_test_token'),
            new RecordingHttpClient($queue),
            $factory,
            $factory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function monitorRow(string $uuid = self::UUID): array
    {
        return [
            'uuid' => $uuid,
            'name' => 'Nightly report',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.$uuid,
            'badge_url' => 'https://cronheart.com/badge/'.$uuid.'.svg',
        ];
    }

    private static function jsonResponse(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_list_monitors_sends_bearer_and_accept_and_parses_page(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, [
            'data' => [self::monitorRow()],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ])]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_test_token'), $http, $factory, $factory);

        $page = $client->listMonitors();

        self::assertCount(1, $page->data);
        self::assertSame(1, $page->total);
        self::assertInstanceOf(Monitor::class, $page->data[0]);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors?offset=0&limit=50', (string) $request->getUri());
        self::assertSame('Bearer cmk_test_token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringContainsString('cron-monitor-php-sdk', $request->getHeaderLine('User-Agent'));
    }

    public function test_list_monitors_clamps_limit_to_max(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, ['data' => [], 'total' => 0, 'limit' => 100, 'offset' => 0])]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        $client->listMonitors(0, 500);

        self::assertStringContainsString('limit=100', (string) $http->requests[0]->getUri());
    }

    public function test_list_monitors_rejects_negative_offset_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->listMonitors(-1);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_get_monitor_parses_single_object(): void
    {
        $client = $this->client([self::jsonResponse(200, self::monitorRow())]);

        $monitor = $client->getMonitor(self::UUID);

        self::assertSame(self::UUID, $monitor->uuid);
        self::assertSame(ScheduleKind::Cron, $monitor->scheduleKind);
    }

    public function test_get_monitor_rejects_malformed_uuid_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->getMonitor('not-a-uuid');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_create_monitor_posts_snake_case_body(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(201, self::monitorRow())]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        $request = new CreateMonitorRequest('Nightly report', ScheduleKind::Cron, '0 2 * * *', channelIds: [3]);
        $monitor = $client->createMonitor($request);

        self::assertSame(self::UUID, $monitor->uuid);

        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors', (string) $sent->getUri());
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame($request->toArray(), json_decode((string) $sent->getBody(), true));
    }

    public function test_create_monitor_is_not_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(503, [])]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x', retries: 3), $http, $factory, $factory);

        try {
            $client->createMonitor(new CreateMonitorRequest('X', ScheduleKind::Interval, '300'));
            self::fail('Expected an exception.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(503, $e->statusCode);
        }
        self::assertCount(1, $http->requests, 'POST must not be retried');
    }

    public function test_get_retries_on_server_error_then_succeeds(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(200, self::monitorRow()),
        ]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x', retries: 1), $http, $factory, $factory);

        $monitor = $client->getMonitor(self::UUID);

        self::assertSame(self::UUID, $monitor->uuid);
        self::assertCount(2, $http->requests);
    }

    public function test_get_retries_on_transport_failure_within_budget(): void
    {
        $transportError = new class('boom') extends \RuntimeException implements ClientExceptionInterface {};
        $http = new RecordingHttpClient([
            $transportError,
            self::jsonResponse(200, self::monitorRow()),
        ]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x', retries: 1), $http, $factory, $factory);

        $monitor = $client->getMonitor(self::UUID);

        self::assertSame(self::UUID, $monitor->uuid);
        self::assertCount(2, $http->requests);
    }

    public function test_exhausted_transport_failures_become_transport_exception(): void
    {
        $transportError = new class('down') extends \RuntimeException implements ClientExceptionInterface {};
        $client = $this->client([$transportError], new Configuration('https://cronheart.com', apiKey: 'cmk_x', retries: 0));

        $this->expectException(ApiTransportException::class);
        $client->listChannels();
    }

    public function test_maps_401_to_authentication_exception(): void
    {
        $client = $this->client([self::jsonResponse(401, ['title' => 'Unauthorized', 'detail' => 'Token revoked.'])]);

        try {
            $client->listMonitors();
            self::fail('Expected an exception.');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('Token revoked.', $e->getMessage());
        }
    }

    public function test_maps_402_to_plan_restriction_with_upgrade_url(): void
    {
        $client = $this->client([self::jsonResponse(402, [
            'title' => 'Payment Required',
            'upgrade_url' => 'https://cronheart.com/billing/upgrade',
        ])]);

        try {
            $client->listMonitors();
            self::fail('Expected an exception.');
        } catch (PlanRestrictionException $e) {
            self::assertSame('https://cronheart.com/billing/upgrade', $e->upgradeUrl);
        }
    }

    public function test_maps_404_to_not_found(): void
    {
        $client = $this->client([self::jsonResponse(404, ['title' => 'Not Found'])]);

        $this->expectException(NotFoundException::class);
        $client->getMonitor(self::UUID);
    }

    public function test_maps_422_to_validation_with_field_errors(): void
    {
        $client = $this->client([self::jsonResponse(422, [
            'detail' => 'Invalid.',
            'errors' => ['schedule_expr' => 'invalid cron'],
        ])]);

        try {
            $client->createMonitor(new CreateMonitorRequest('X', ScheduleKind::Cron, 'nonsense'));
            self::fail('Expected an exception.');
        } catch (ValidationException $e) {
            self::assertSame(['schedule_expr' => 'invalid cron'], $e->errors);
        }
    }

    public function test_maps_429_to_rate_limit_preferring_header(): void
    {
        $http = new RecordingHttpClient([
            new Response(429, ['Retry-After' => '30', 'Content-Type' => 'application/json'], (string) json_encode(['retry_after' => 10])),
        ]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        try {
            $client->listMonitors();
            self::fail('Expected an exception.');
        } catch (RateLimitException $e) {
            self::assertSame(30, $e->retryAfter);
        }
    }

    public function test_non_json_success_body_becomes_transport_exception(): void
    {
        $client = $this->client([new Response(200, [], '<html>not json</html>')]);

        $this->expectException(ApiTransportException::class);
        $client->listMonitors();
    }

    public function test_all_monitors_walks_pages_and_stops(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(200, [
                'data' => [self::monitorRow('11111111-1111-4111-8111-111111111111'), self::monitorRow('22222222-2222-4222-8222-222222222222')],
                'total' => 3,
                'limit' => 2,
                'offset' => 0,
            ]),
            self::jsonResponse(200, [
                'data' => [self::monitorRow('33333333-3333-4333-8333-333333333333')],
                'total' => 3,
                'limit' => 2,
                'offset' => 2,
            ]),
        ]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        $uuids = [];
        foreach ($client->allMonitors(2) as $monitor) {
            $uuids[] = $monitor->uuid;
        }

        self::assertCount(3, $uuids);
        self::assertCount(2, $http->requests, 'should stop after the second (last) page');
    }

    public function test_omits_authorization_header_when_no_token(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, ['data' => [], 'total' => 0, 'limit' => 50, 'offset' => 0])]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com'), $http, $factory, $factory);

        $client->listMonitors();

        self::assertFalse($http->requests[0]->hasHeader('Authorization'));
    }

    public function test_create_factory_round_trips_against_a_real_server(): void
    {
        $server = LocalHttpServer::start();
        if (null === $server) {
            self::markTestSkipped('LocalHttpServer could not boot in this environment.');
        }

        try {
            $client = MonitorApiClient::create(new Configuration(
                $server->baseUrl(),
                apiKey: 'cmk_smoke',
                allowInsecureEndpoint: true,
            ));

            $page = $client->listMonitors();

            self::assertCount(1, $page->data);
            self::assertSame('Smoke monitor', $page->data[0]->name);
        } finally {
            $server->stop();
        }
    }

    public function test_create_monitor_with_invalid_utf8_throws_api_exception_not_json_exception(): void
    {
        $http = new RecordingHttpClient([]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        // A monitor name carrying an invalid UTF-8 byte makes json_encode
        // throw \JsonException; that must surface as an ApiException (the
        // documented catch-all), not leak the raw \JsonException, and no
        // HTTP request must be attempted.
        try {
            $client->createMonitor(new CreateMonitorRequest("bad\xB1name", ScheduleKind::Cron, '0 2 * * *'));
            self::fail('Expected an exception.');
        } catch (ApiTransportException $e) {
            self::assertStringNotContainsString("\xB1", $e->getMessage(), 'the invalid body must not be echoed into the message');
        }
        self::assertSame([], $http->requests, 'encode failure must short-circuit before any HTTP call');
    }

    public function test_all_monitors_terminates_on_short_page_despite_stale_server_offset(): void
    {
        // The server echoes offset=0 on every page (a stale/ignored offset)
        // and a total implying more rows. Old hasMore()-driven logic would
        // never stop; the short final page must terminate the walk. If it
        // did not, the FIFO queue would be exhausted and RecordingHttpClient
        // would throw on a third request.
        $http = new RecordingHttpClient([
            self::jsonResponse(200, [
                'data' => [self::monitorRow('11111111-1111-4111-8111-111111111111'), self::monitorRow('22222222-2222-4222-8222-222222222222')],
                'total' => 99,
                'limit' => 2,
                'offset' => 0,
            ]),
            self::jsonResponse(200, [
                'data' => [self::monitorRow('33333333-3333-4333-8333-333333333333')],
                'total' => 99,
                'limit' => 2,
                'offset' => 0,
            ]),
        ]);
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $http, $factory, $factory);

        $uuids = [];
        foreach ($client->allMonitors(2) as $monitor) {
            $uuids[] = $monitor->uuid;
        }

        self::assertCount(3, $uuids);
        self::assertCount(2, $http->requests);
    }

    public function test_all_monitors_caps_runaway_pagination(): void
    {
        $row = self::monitorRow();
        // A non-conforming endpoint that returns a full (never short) page
        // forever with an inflated total. The MAX_PAGES backstop must stop
        // it with an ApiException rather than letting it spin endlessly.
        $alwaysFull = new class($row) implements ClientInterface {
            /**
             * @param array<string, mixed> $row
             */
            public function __construct(private readonly array $row)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'data' => [$this->row],
                    'total' => 999999999,
                    'limit' => 1,
                    'offset' => 0,
                ]));
            }
        };
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $alwaysFull, $factory, $factory);

        $this->expectException(ApiTransportException::class);
        $seen = 0;
        foreach ($client->allMonitors(1) as $monitor) {
            if (++$seen > 20000) {
                self::fail('allMonitors did not hit its page cap');
            }
        }
    }
}
