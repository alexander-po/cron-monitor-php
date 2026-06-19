<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Laravel\Console;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Bridge\Laravel\Console\SyncCommand;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function tester(RecordingHttpClient $http, Schedule $schedule, string $apiKey = 'cmk_test'): CommandTester
    {
        $factory = new HttpFactory();
        $configuration = new Configuration('https://cronheart.com', apiKey: '' === $apiKey ? null : $apiKey);
        $client = new MonitorApiClient($configuration, $http, $factory, $factory);

        // Laravel's Command::run reaches for $laravel->runningUnitTests(),
        // which lives on the Foundation Application, not the bare container we
        // ship as a dev dep — so shim it on a subclass.
        $container = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->instance(Schedule::class, $schedule);
        $container->instance(MonitorApiClient::class, $client);
        $container->instance(Configuration::class, $configuration);
        Container::setInstance($container);

        $command = new SyncCommand();
        $command->setLaravel($container);

        return new CommandTester($command);
    }

    private function scheduleWith(string $command, string $expression = '0 * * * *'): Schedule
    {
        $event = (new Event($this->noopMutex(), $command))->cron($expression);

        return $this->scheduleOf($event);
    }

    private function scheduleInTimezone(string $command, string $tz): Schedule
    {
        $event = (new Event($this->noopMutex(), $command))->cron('0 9 * * *')->timezone($tz);

        return $this->scheduleOf($event);
    }

    private function scheduleWithClosure(): Schedule
    {
        $mutex = $this->noopMutex();
        $event = (new CallbackEvent($mutex, static fn (): null => null))->cron('0 * * * *');

        return $this->scheduleOf($event);
    }

    private function noopMutex(): EventMutex
    {
        return new class implements EventMutex {
            public function create(Event $event): bool
            {
                return true;
            }

            public function exists(Event $event): bool
            {
                return false;
            }

            public function forget(Event $event): void
            {
            }
        };
    }

    private function scheduleOf(Event $event): Schedule
    {
        return new class([$event]) extends Schedule {
            /**
             * @param list<Event> $evts
             */
            public function __construct(private readonly array $evts)
            {
            }

            public function events()
            {
                return $this->evts;
            }
        };
    }

    private static function listPage(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => [], 'total' => 0, 'limit' => 100, 'offset' => 0,
        ]));
    }

    private static function createdMonitor(string $uuid): Response
    {
        return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode([
            'uuid' => $uuid,
            'name' => 'reports:run',
            'schedule_kind' => 'cron',
            'schedule_expr' => '0 * * * *',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-06-19T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.$uuid,
            'badge_url' => 'https://cronheart.com/badge/'.$uuid.'.svg',
        ]));
    }

    public function test_default_mode_prints_snippet_and_makes_no_http_call(): void
    {
        $http = new RecordingHttpClient([]);
        $tester = $this->tester($http, $this->scheduleWith('reports:run'));

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString("'monitors' => [", $tester->getDisplay());
        self::assertSame([], $http->requests);
    }

    public function test_dry_run_lists_but_creates_nothing(): void
    {
        $http = new RecordingHttpClient([self::listPage()]);
        $tester = $this->tester($http, $this->scheduleWith('reports:run'));

        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('would-create', $tester->getDisplay());
        self::assertCount(1, $http->requests);
        self::assertSame('GET', $http->requests[0]->getMethod());
    }

    public function test_apply_creates_missing_monitor(): void
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        $http = new RecordingHttpClient([self::listPage(), self::createdMonitor($uuid)]);
        $tester = $this->tester($http, $this->scheduleWith('reports:run'));

        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString($uuid, $tester->getDisplay());
        self::assertCount(2, $http->requests);
        self::assertSame('POST', $http->requests[1]->getMethod());
        $body = json_decode((string) $http->bodies[1], true);
        self::assertSame('reports:run', $body['name']);
        self::assertSame('0 * * * *', $body['schedule_expr']);
    }

    public function test_apply_without_api_key_errors_and_makes_no_http(): void
    {
        $http = new RecordingHttpClient([]);
        $tester = $this->tester($http, $this->scheduleWith('reports:run'), apiKey: '');

        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('API token', $tester->getDisplay());
        self::assertSame([], $http->requests);
    }

    public function test_invalid_channel_is_rejected_before_any_http(): void
    {
        $http = new RecordingHttpClient([]);
        $tester = $this->tester($http, $this->scheduleWith('reports:run'));

        $exit = $tester->execute(['--apply' => true, '--channel' => 'abc']);

        self::assertSame(2, $exit);
        self::assertSame([], $http->requests);
    }

    public function test_apply_threads_the_event_timezone_into_the_created_monitor(): void
    {
        $uuid = '33333333-3333-4333-8333-333333333333';
        $http = new RecordingHttpClient([self::listPage(), self::createdMonitor($uuid)]);
        $tester = $this->tester($http, $this->scheduleInTimezone('reports:run', 'America/New_York'));

        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(0, $exit);
        $body = json_decode((string) $http->bodies[1], true);
        self::assertSame('America/New_York', $body['tz'], 'a non-UTC scheduled event must create a non-UTC monitor');
    }

    public function test_apply_skips_a_closure_event_without_creating_it(): void
    {
        // A closure scheduled job has no stable command name, so it cannot be
        // reconciled by name; it must be reported skipped, never created. With
        // the only job skipped there is nothing to reconcile, so no HTTP.
        $http = new RecordingHttpClient([]);
        $tester = $this->tester($http, $this->scheduleWithClosure());

        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('skipped', $tester->getDisplay());
        self::assertSame([], $http->requests, 'a closure event must not reach the API');
    }
}
