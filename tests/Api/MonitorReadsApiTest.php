<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\AlertKind;
use CronMonitor\Api\Dto\PingKind;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MonitorReadsApiTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    private function client(RecordingHttpClient $http): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', apiKey: 'cmk_test_token'),
            $http,
            $factory,
            $factory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function pingRow(string $id, ?int $runtimeMs = null): array
    {
        return [
            'id' => $id,
            'kind' => 'success',
            'received_at' => '2026-06-18T02:00:00+00:00',
            'runtime_ms' => $runtimeMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function alertRow(string $id): array
    {
        return [
            'id' => $id,
            'kind' => 'late',
            'created_at' => '2026-06-18T02:05:00+00:00',
            'dispatched_to' => ['email:ops'],
        ];
    }

    private static function jsonResponse(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_list_pings_sends_limit_and_parses_cursor_page(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, [
            'data' => [self::pingRow('100', 1500), self::pingRow('99')],
            'next_cursor' => 'eyJjIjoxfQ',
        ])]);
        $client = $this->client($http);

        $page = $client->listPings(self::UUID);

        self::assertCount(2, $page->data);
        self::assertSame('100', $page->data[0]->id);
        self::assertSame(PingKind::Success, $page->data[0]->kind);
        self::assertSame(1500, $page->data[0]->runtimeMs);
        self::assertNull($page->data[1]->runtimeMs);
        self::assertTrue($page->hasMore());
        self::assertSame('eyJjIjoxfQ', $page->nextCursor);

        $sent = $http->requests[0];
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/pings?limit=50', (string) $sent->getUri());
        self::assertSame('Bearer cmk_test_token', $sent->getHeaderLine('Authorization'));
    }

    public function test_list_pings_includes_cursor_and_clamps_limit(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, ['data' => [], 'next_cursor' => null])]);
        $client = $this->client($http);

        $page = $client->listPings(self::UUID, 500, 'CURSORTOKEN');

        self::assertFalse($page->hasMore());
        $uri = (string) $http->requests[0]->getUri();
        self::assertStringContainsString('limit=100', $uri);
        self::assertStringContainsString('cursor=CURSORTOKEN', $uri);
    }

    public function test_list_pings_rejects_malformed_uuid_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->listPings('nope');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_all_pings_walks_cursor_pages_and_stops(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(200, ['data' => [self::pingRow('3'), self::pingRow('2')], 'next_cursor' => 'page2cursor']),
            self::jsonResponse(200, ['data' => [self::pingRow('1')], 'next_cursor' => null]),
        ]);
        $client = $this->client($http);

        $ids = [];
        foreach ($client->allPings(self::UUID, 2) as $ping) {
            $ids[] = $ping->id;
        }

        self::assertSame(['3', '2', '1'], $ids);
        self::assertCount(2, $http->requests);
        self::assertStringNotContainsString('cursor=', (string) $http->requests[0]->getUri());
        self::assertStringContainsString('cursor=page2cursor', (string) $http->requests[1]->getUri());
    }

    public function test_all_pings_cycle_guard_rejects_a_repeated_cursor(): void
    {
        // The server hands back the same cursor it was just given on the
        // second page — a non-advancing cursor that would otherwise loop
        // forever. The cycle guard must stop it after exactly two requests.
        $http = new RecordingHttpClient([
            self::jsonResponse(200, ['data' => [self::pingRow('2')], 'next_cursor' => 'stuck']),
            self::jsonResponse(200, ['data' => [self::pingRow('1')], 'next_cursor' => 'stuck']),
        ]);
        $client = $this->client($http);

        $this->expectException(ApiTransportException::class);
        try {
            iterator_to_array($client->allPings(self::UUID, 1));
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function test_all_pings_caps_runaway_pagination(): void
    {
        // A non-conforming endpoint that always returns a fresh, distinct
        // cursor (so the cycle guard never fires) must still be bounded by
        // MAX_PAGES rather than spinning endlessly.
        $everAdvancing = new class implements \Psr\Http\Client\ClientInterface {
            private int $n = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->n;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'data' => [['id' => (string) $this->n, 'kind' => 'heartbeat', 'received_at' => '2026-06-18T02:00:00+00:00', 'runtime_ms' => null]],
                    'next_cursor' => 'cursor-'.$this->n,
                ]));
            }
        };
        $factory = new HttpFactory();
        $client = new MonitorApiClient(new Configuration('https://cronheart.com', apiKey: 'cmk_x'), $everAdvancing, $factory, $factory);

        $this->expectException(ApiTransportException::class);
        $seen = 0;
        foreach ($client->allPings(self::UUID, 1) as $ping) {
            if (++$seen > 20000) {
                self::fail('allPings did not hit its page cap');
            }
        }
    }

    public function test_list_alerts_offset_pagination(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, [
            'data' => [self::alertRow('5')],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ])]);
        $client = $this->client($http);

        $page = $client->listAlerts(self::UUID);

        self::assertCount(1, $page->data);
        self::assertSame('5', $page->data[0]->id);
        self::assertSame(AlertKind::Late, $page->data[0]->kind);
        self::assertSame(['email:ops'], $page->data[0]->dispatchedTo);
        self::assertSame(1, $page->total);
        self::assertFalse($page->hasMore());

        $sent = $http->requests[0];
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/alerts?offset=0&limit=50', (string) $sent->getUri());
        self::assertSame('Bearer cmk_test_token', $sent->getHeaderLine('Authorization'));
    }

    public function test_list_alerts_rejects_negative_offset_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->listAlerts(self::UUID, -1);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_list_alerts_rejects_malformed_uuid_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->listAlerts('nope');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_all_alerts_walks_pages_and_stops(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(200, ['data' => [self::alertRow('3'), self::alertRow('2')], 'total' => 3, 'limit' => 2, 'offset' => 0]),
            self::jsonResponse(200, ['data' => [self::alertRow('1')], 'total' => 3, 'limit' => 2, 'offset' => 2]),
        ]);
        $client = $this->client($http);

        $ids = [];
        foreach ($client->allAlerts(self::UUID, 2) as $alert) {
            $ids[] = $alert->id;
        }

        self::assertSame(['3', '2', '1'], $ids);
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('offset=2', (string) $http->requests[1]->getUri());
    }

    public function test_all_alerts_terminates_on_short_page_despite_stale_offset(): void
    {
        // The server echoes offset=0 on every page and an inflated total;
        // termination must come from the short final page, not the echoed
        // offset (mirrors the allMonitors stale-offset defense).
        $http = new RecordingHttpClient([
            self::jsonResponse(200, ['data' => [self::alertRow('3'), self::alertRow('2')], 'total' => 99, 'limit' => 2, 'offset' => 0]),
            self::jsonResponse(200, ['data' => [self::alertRow('1')], 'total' => 99, 'limit' => 2, 'offset' => 0]),
        ]);
        $client = $this->client($http);

        $ids = [];
        foreach ($client->allAlerts(self::UUID, 2) as $alert) {
            $ids[] = $alert->id;
        }

        self::assertSame(['3', '2', '1'], $ids);
        self::assertCount(2, $http->requests);
    }
}
