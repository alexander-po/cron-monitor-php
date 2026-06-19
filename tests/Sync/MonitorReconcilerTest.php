<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Sync;

use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Sync\MonitorReconciler;
use CronMonitor\Sync\ReconcilableJob;
use CronMonitor\Sync\ReconcileOutcome;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MonitorReconcilerTest extends TestCase
{
    private function reconciler(RecordingHttpClient $http): MonitorReconciler
    {
        $factory = new HttpFactory();
        $client = new MonitorApiClient(
            new Configuration('https://cronheart.com', apiKey: 'cmk_test_token'),
            $http,
            $factory,
            $factory,
        );

        return new MonitorReconciler($client);
    }

    /**
     * @param list<array{name: string, uuid: string}> $monitors
     */
    private static function listPage(array $monitors): Response
    {
        $rows = array_map(static fn (array $m): array => [
            'uuid' => $m['uuid'],
            'name' => $m['name'],
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 2 * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.$m['uuid'],
            'badge_url' => 'https://cronheart.com/badge/'.$m['uuid'].'.svg',
        ], $monitors);

        return self::json(200, ['data' => $rows, 'total' => \count($rows), 'limit' => 100, 'offset' => 0]);
    }

    private static function createdMonitor(string $name, string $uuid): Response
    {
        return self::json(201, [
            'uuid' => $uuid,
            'name' => $name,
            'schedule_kind' => 'cron',
            'schedule_expr' => '*/5 * * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-06-19T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.$uuid,
            'badge_url' => 'https://cronheart.com/badge/'.$uuid.'.svg',
        ]);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_dry_run_creates_nothing_and_classifies_each_job(): void
    {
        $http = new RecordingHttpClient([self::listPage([['name' => 'App\\Cron\\Daily', 'uuid' => '11111111-1111-4111-8111-111111111111']])]);
        $reconciler = $this->reconciler($http);

        $results = $reconciler->reconcile([
            new ReconcilableJob('App\\Cron\\Daily', '0 2 * * *'),
            new ReconcilableJob('App\\Cron\\Hourly', '0 * * * *'),
        ], apply: false);

        self::assertSame(ReconcileOutcome::Existing, $results[0]->outcome);
        self::assertSame('11111111-1111-4111-8111-111111111111', $results[0]->uuid);
        self::assertSame(ReconcileOutcome::WouldCreate, $results[1]->outcome);
        self::assertNull($results[1]->uuid);

        self::assertCount(1, $http->requests, 'dry run lists once and writes nothing');
        self::assertSame('GET', $http->requests[0]->getMethod());
    }

    public function test_apply_creates_only_the_missing_job(): void
    {
        $newUuid = '22222222-2222-4222-8222-222222222222';
        $http = new RecordingHttpClient([
            self::listPage([['name' => 'App\\Cron\\Daily', 'uuid' => '11111111-1111-4111-8111-111111111111']]),
            self::createdMonitor('App\\Cron\\Hourly', $newUuid),
        ]);
        $reconciler = $this->reconciler($http);

        $results = $reconciler->reconcile([
            new ReconcilableJob('App\\Cron\\Daily', '0 2 * * *'),
            new ReconcilableJob('App\\Cron\\Hourly', '0 * * * *'),
        ], apply: true);

        self::assertSame(ReconcileOutcome::Existing, $results[0]->outcome);
        self::assertSame(ReconcileOutcome::Created, $results[1]->outcome);
        self::assertSame($newUuid, $results[1]->uuid);

        self::assertCount(2, $http->requests, 'one list + one create (the existing job is skipped)');
        $post = $http->requests[1];
        self::assertSame('POST', $post->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors', (string) $post->getUri());
        self::assertNotSame('', $post->getHeaderLine('Idempotency-Key'), 'a create must carry a deterministic idempotency key');

        $body = json_decode((string) $http->bodies[1], true);
        self::assertSame('App\\Cron\\Hourly', $body['name']);
        self::assertSame('cron', $body['schedule_kind']);
        self::assertSame('0 * * * *', $body['schedule_expr']);
        self::assertSame([], $body['channel_ids']);
    }

    public function test_apply_threads_the_job_timezone_into_the_created_monitor(): void
    {
        $http = new RecordingHttpClient([
            self::listPage([]),
            self::createdMonitor('App\\Cron\\Hourly', '22222222-2222-4222-8222-222222222222'),
        ]);
        $reconciler = $this->reconciler($http);

        $reconciler->reconcile([new ReconcilableJob('App\\Cron\\Hourly', '0 9 * * *', 'America/New_York')], apply: true);

        $body = json_decode((string) $http->bodies[1], true);
        self::assertSame('America/New_York', $body['tz'], 'a non-UTC schedule must not be created as a UTC monitor');
    }

    public function test_apply_defaults_to_utc_when_the_job_has_no_timezone(): void
    {
        $http = new RecordingHttpClient([
            self::listPage([]),
            self::createdMonitor('App\\Cron\\Hourly', '22222222-2222-4222-8222-222222222222'),
        ]);
        $reconciler = $this->reconciler($http);

        $reconciler->reconcile([new ReconcilableJob('App\\Cron\\Hourly', '0 * * * *')], apply: true);

        $body = json_decode((string) $http->bodies[1], true);
        self::assertSame('UTC', $body['tz']);
    }

    public function test_same_name_jobs_are_flagged_as_conflict_and_not_created(): void
    {
        // Two jobs sharing a name cannot both be reconciled by name; both must
        // be reported as a conflict and neither created.
        $http = new RecordingHttpClient([self::listPage([])]);
        $reconciler = $this->reconciler($http);

        $results = $reconciler->reconcile([
            new ReconcilableJob('App\\Cron\\Dup', '0 9 * * *'),
            new ReconcilableJob('App\\Cron\\Dup', '0 21 * * *'),
        ], apply: true);

        self::assertSame(ReconcileOutcome::Conflict, $results[0]->outcome);
        self::assertSame(ReconcileOutcome::Conflict, $results[1]->outcome);
        self::assertNotNull($results[0]->error);
        self::assertCount(1, $http->requests, 'only the listing — no create for an ambiguous name');
    }

    public function test_apply_routes_created_monitors_to_a_channel(): void
    {
        $http = new RecordingHttpClient([
            self::listPage([]),
            self::createdMonitor('App\\Cron\\Hourly', '22222222-2222-4222-8222-222222222222'),
        ]);
        $reconciler = $this->reconciler($http);

        $reconciler->reconcile([new ReconcilableJob('App\\Cron\\Hourly', '0 * * * *')], apply: true, channelId: 5);

        $body = json_decode((string) $http->bodies[1], true);
        self::assertSame([5], $body['channel_ids']);
    }

    public function test_apply_continues_after_a_single_create_failure(): void
    {
        $http = new RecordingHttpClient([
            self::listPage([]),
            self::json(422, ['detail' => 'invalid cron', 'errors' => ['schedule_expr' => 'bad']]),
            self::createdMonitor('App\\Cron\\Ok', '33333333-3333-4333-8333-333333333333'),
        ]);
        $reconciler = $this->reconciler($http);

        $results = $reconciler->reconcile([
            new ReconcilableJob('App\\Cron\\Bad', 'not-a-cron'),
            new ReconcilableJob('App\\Cron\\Ok', '0 * * * *'),
        ], apply: true);

        self::assertSame(ReconcileOutcome::Failed, $results[0]->outcome);
        self::assertNotNull($results[0]->error);
        self::assertSame(ReconcileOutcome::Created, $results[1]->outcome);
        self::assertCount(3, $http->requests, 'a failed create must not abort the remaining jobs');
    }

    public function test_listing_failure_aborts_before_any_create(): void
    {
        $http = new RecordingHttpClient([self::json(401, ['title' => 'Unauthorized'])]);
        $reconciler = $this->reconciler($http);

        $this->expectException(AuthenticationException::class);
        try {
            $reconciler->reconcile([new ReconcilableJob('App\\Cron\\Hourly', '0 * * * *')], apply: true);
        } finally {
            self::assertCount(1, $http->requests, 'a failed listing must not lead to a blind create');
        }
    }
}
