<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\CreateChannelRequest;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Exception\ConflictException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyApiTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    private function client(RecordingHttpClient $http, int $retries = 3): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: $retries),
            $http,
            $factory,
            $factory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function monitorRow(): array
    {
        return [
            'uuid' => self::UUID,
            'name' => 'Nightly report',
            'schedule_kind' => 'interval',
            'schedule_expr' => '300',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.self::UUID,
            'badge_url' => 'https://cronheart.com/badge/'.self::UUID.'.svg',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function channelRow(): array
    {
        return [
            'id' => '7',
            'kind' => 'email',
            'label' => 'My inbox',
            'verified' => false,
            'config' => ['address' => 'me@example.test'],
            'created_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    private static function jsonResponse(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_create_monitor_without_key_sends_no_header_and_is_not_retried(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(503, [])]);
        $client = $this->client($http);

        try {
            $client->createMonitor(new CreateMonitorRequest('X', ScheduleKind::Interval, '300'));
            self::fail('Expected an exception.');
        } catch (\Throwable) {
        }

        self::assertCount(1, $http->requests, 'an unkeyed create must not be retried');
        self::assertFalse($http->requests[0]->hasHeader('Idempotency-Key'));
    }

    public function test_create_monitor_with_key_sets_header_and_is_retried(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(201, self::monitorRow()),
        ]);
        $client = $this->client($http, retries: 1);

        $monitor = $client->createMonitor(
            new CreateMonitorRequest('X', ScheduleKind::Interval, '300'),
            'idem-key-abc',
        );

        self::assertSame(self::UUID, $monitor->uuid);
        self::assertCount(2, $http->requests, 'a keyed create is safe to retry');
        self::assertSame('idem-key-abc', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame('idem-key-abc', $http->requests[1]->getHeaderLine('Idempotency-Key'), 'the retry must carry the same key so the backend can dedup');

        // Dedup hinges on an identical request fingerprint, which includes the
        // body: the replayed attempt must carry the SAME, non-empty body bytes
        // (proving send() rewinds the stream between attempts).
        self::assertNotSame('', $http->bodies[1]);
        self::assertSame($http->bodies[0], $http->bodies[1], 'the retry must resend the identical body so the server fingerprint matches');
    }

    public function test_create_channel_with_key_sets_header_and_is_retried(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(201, self::channelRow()),
        ]);
        $client = $this->client($http, retries: 1);

        $client->createChannel(CreateChannelRequest::email('My inbox', 'me@example.test'), 'chan-key-1');

        self::assertCount(2, $http->requests);
        self::assertSame('chan-key-1', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame('chan-key-1', $http->requests[1]->getHeaderLine('Idempotency-Key'));
    }

    public function test_create_channel_without_key_is_not_retried(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(503, []), self::jsonResponse(503, [])]);
        $client = $this->client($http);

        try {
            $client->createChannel(CreateChannelRequest::email('X', 'me@example.test'));
            self::fail('Expected an exception.');
        } catch (\Throwable) {
        }

        self::assertCount(1, $http->requests);
        self::assertFalse($http->requests[0]->hasHeader('Idempotency-Key'));
    }

    public function test_create_monitor_rejects_blank_key_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->createMonitor(new CreateMonitorRequest('X', ScheduleKind::Interval, '300'), '   ');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_create_monitor_rejects_key_with_control_characters_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->createMonitor(new CreateMonitorRequest('X', ScheduleKind::Interval, '300'), "key\r\nX-Injected: 1");
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_409_under_same_key_maps_to_conflict(): void
    {
        // The backend answers 409 when a key is reused with a different request
        // body, or while an earlier request under the key is still in progress.
        $http = new RecordingHttpClient([self::jsonResponse(409, [
            'title' => 'Conflict',
            'detail' => 'This Idempotency-Key was already used with a different request.',
        ])]);
        $client = $this->client($http);

        try {
            $client->createMonitor(new CreateMonitorRequest('X', ScheduleKind::Interval, '300'), 'reused-key');
            self::fail('Expected an exception.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->statusCode);
        }
        self::assertCount(1, $http->requests, '409 is a definitive answer and must not be retried');
    }
}
