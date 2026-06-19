<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Dto\UpdateMonitorRequest;
use CronMonitor\Api\Exception\NotFoundException;
use CronMonitor\Api\Exception\UnexpectedResponseException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MonitorLifecycleApiTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    private function client(RecordingHttpClient $http, int $retries = 0): MonitorApiClient
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function monitorRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => self::UUID,
            'name' => 'Nightly report',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.self::UUID,
            'badge_url' => 'https://cronheart.com/badge/'.self::UUID.'.svg',
        ], $overrides);
    }

    private static function jsonResponse(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_update_monitor_patches_only_changed_fields(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow(['name' => 'Renamed']))]);
        $client = $this->client($http);

        $request = new UpdateMonitorRequest(name: 'Renamed', graceSeconds: 120);
        $monitor = $client->updateMonitor(self::UUID, $request);

        self::assertInstanceOf(Monitor::class, $monitor);
        self::assertSame('Renamed', $monitor->name);

        $sent = $http->requests[0];
        self::assertSame('PATCH', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID, (string) $sent->getUri());
        self::assertSame('Bearer cmk_test_token', $sent->getHeaderLine('Authorization'));
        self::assertSame(['name' => 'Renamed', 'grace_seconds' => 120], json_decode((string) $sent->getBody(), true));
    }

    public function test_update_monitor_rejects_empty_patch_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->updateMonitor(self::UUID, new UpdateMonitorRequest());
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_update_monitor_rejects_malformed_uuid_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->updateMonitor('not-a-uuid', new UpdateMonitorRequest(name: 'X'));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_update_monitor_is_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(200, self::monitorRow()),
        ]);
        $client = $this->client($http, retries: 1);

        $client->updateMonitor(self::UUID, new UpdateMonitorRequest(name: 'X'));

        self::assertCount(2, $http->requests, 'PATCH is idempotent and must be retried');
    }

    public function test_delete_monitor_succeeds_on_204(): void
    {
        $http = new RecordingHttpClient([new Response(204, [], '')]);
        $client = $this->client($http);

        $client->deleteMonitor(self::UUID);

        $sent = $http->requests[0];
        self::assertSame('DELETE', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID, (string) $sent->getUri());
    }

    public function test_delete_monitor_maps_404(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(404, ['title' => 'Not Found'])]);
        $client = $this->client($http);

        $this->expectException(NotFoundException::class);
        $client->deleteMonitor(self::UUID);
    }

    public function test_delete_monitor_is_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            new Response(204, [], ''),
        ]);
        $client = $this->client($http, retries: 1);

        $client->deleteMonitor(self::UUID);

        self::assertCount(2, $http->requests);
    }

    public function test_delete_monitor_rejects_malformed_uuid_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->deleteMonitor('nope');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function test_pause_monitor_posts_with_no_body_and_returns_monitor(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow(['status' => 'paused']))]);
        $client = $this->client($http);

        $monitor = $client->pauseMonitor(self::UUID);

        self::assertInstanceOf(Monitor::class, $monitor);
        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/pause', (string) $sent->getUri());
        self::assertSame('', (string) $sent->getBody());
        self::assertFalse($sent->hasHeader('Content-Type'));
    }

    public function test_resume_monitor_posts(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow())]);
        $client = $this->client($http);

        $client->resumeMonitor(self::UUID);

        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/resume', (string) $http->requests[0]->getUri());
    }

    public function test_snooze_monitor_sends_duration_value(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow(['snoozed_until' => '2026-06-19T12:00:00+00:00']))]);
        $client = $this->client($http);

        $monitor = $client->snoozeMonitor(self::UUID, SnoozeDuration::OneHour);

        self::assertNotNull($monitor->snoozedUntil);
        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/snooze', (string) $sent->getUri());
        self::assertSame(['duration' => '1h'], json_decode((string) $sent->getBody(), true));
    }

    public function test_snooze_monitor_maps_422_invalid_duration(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(422, [
            'detail' => 'Invalid duration.',
            'errors' => ['duration' => 'duration must be one of: 1h, 4h, 1d, 1w.'],
        ])]);
        $client = $this->client($http);

        $this->expectException(ValidationException::class);
        $client->snoozeMonitor(self::UUID, SnoozeDuration::OneHour);
    }

    public function test_unsnooze_monitor_posts(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow())]);
        $client = $this->client($http);

        $client->unsnoozeMonitor(self::UUID);

        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/unsnooze', (string) $http->requests[0]->getUri());
    }

    public function test_status_transitions_are_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(200, self::monitorRow(['status' => 'paused'])),
        ]);
        $client = $this->client($http, retries: 1);

        $client->pauseMonitor(self::UUID);

        self::assertCount(2, $http->requests);
    }

    public function test_rotate_monitor_uuid_confirms_with_current_uuid(): void
    {
        $newUuid = '11111111-1111-4111-8111-111111111111';
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow(['uuid' => $newUuid]))]);
        $client = $this->client($http);

        $monitor = $client->rotateMonitorUuid(self::UUID);

        self::assertSame($newUuid, $monitor->uuid);
        $sent = $http->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/'.self::UUID.'/rotate-uuid', (string) $sent->getUri());
        self::assertSame(['confirm' => self::UUID], json_decode((string) $sent->getBody(), true));
    }

    public function test_rotate_monitor_uuid_sends_a_lowercase_confirm(): void
    {
        // An uppercase-but-valid UUID passes local validation and the backend
        // route, but the backend's confirm check is case-sensitive against the
        // canonical lowercase — so the client must lowercase the confirm value.
        $http = new RecordingHttpClient([self::jsonResponse(200, self::monitorRow())]);
        $client = $this->client($http);

        $client->rotateMonitorUuid(strtoupper(self::UUID));

        self::assertSame(['confirm' => self::UUID], json_decode((string) $http->requests[0]->getBody(), true));
    }

    public function test_rotate_monitor_uuid_is_not_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(503, []),
        ]);
        $client = $this->client($http, retries: 3);

        try {
            $client->rotateMonitorUuid(self::UUID);
            self::fail('Expected an exception.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(503, $e->statusCode);
        }
        self::assertCount(1, $http->requests, 'rotation must never be retried');
    }

    public function test_rotate_monitor_uuid_rejects_malformed_uuid_without_http(): void
    {
        $http = new RecordingHttpClient([]);
        $client = $this->client($http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->rotateMonitorUuid('nope');
        } finally {
            self::assertSame([], $http->requests);
        }
    }
}
