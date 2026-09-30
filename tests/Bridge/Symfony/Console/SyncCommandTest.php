<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\Console;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Bridge\Symfony\Console\SyncCommand;
use CronMonitor\Bridge\Symfony\DependencyInjection\CronMonitorExtension;
use CronMonitor\Bridge\Symfony\Scheduler\ScheduleInventory;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class SyncCommandTest extends TestCase
{
    /**
     * Builds an inventory of one job whose trigger stringifies to `$triggerStr`.
     * A custom TriggerInterface sidesteps the optional dragonmantank/cron-
     * expression dep that RecurringMessage::cron would require, while letting
     * a test choose a real cron expression (so the reconciler treats it as a
     * cron job) or a periodical-style description (so it is skipped).
     */
    private function inventory(string $triggerStr = '0 * * * *'): ScheduleInventory
    {
        $trigger = new class($triggerStr) implements TriggerInterface {
            public function __construct(private readonly string $repr)
            {
            }

            public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
            {
                return null;
            }

            public function __toString(): string
            {
                return $this->repr;
            }
        };

        $schedule = (new Schedule())->add(RecurringMessage::trigger($trigger, new \stdClass()));

        $provider = new class($schedule) implements ScheduleProviderInterface {
            public function __construct(private readonly Schedule $schedule)
            {
            }

            public function getSchedule(): Schedule
            {
                return $this->schedule;
            }
        };

        return new ScheduleInventory([$provider]);
    }

    private function apiClient(RecordingHttpClient $http, string $apiKey = 'cmk_test'): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', apiKey: $apiKey),
            $http,
            $factory,
            $factory,
        );
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
            'name' => 'stdClass',
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
        $command = new SyncCommand($this->inventory(), $this->apiClient($http), new Configuration('https://cronheart.com', apiKey: 'cmk_test'));

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('cron_monitor:', $tester->getDisplay());
        self::assertStringContainsString('<paste-monitor-uuid-here>', $tester->getDisplay());
        self::assertSame([], $http->requests, 'the default list mode must not touch the network');
    }

    public function test_dry_run_lists_but_creates_nothing(): void
    {
        $http = new RecordingHttpClient([self::listPage()]);
        $command = new SyncCommand($this->inventory(), $this->apiClient($http), new Configuration('https://cronheart.com', apiKey: 'cmk_test'));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('would-create', $tester->getDisplay());
        self::assertCount(1, $http->requests, 'dry-run lists once and creates nothing');
        self::assertSame('GET', $http->requests[0]->getMethod());
    }

    public function test_apply_creates_missing_monitor_and_prints_uuid(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000001';
        $http = new RecordingHttpClient([self::listPage(), self::createdMonitor($uuid)]);
        $command = new SyncCommand($this->inventory(), $this->apiClient($http), new Configuration('https://cronheart.com', apiKey: 'cmk_test'));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString($uuid, $tester->getDisplay());
        self::assertCount(2, $http->requests);
        self::assertSame('POST', $http->requests[1]->getMethod());
    }

    public function test_apply_without_api_key_errors_with_guidance_and_no_http(): void
    {
        $http = new RecordingHttpClient([]);
        $command = new SyncCommand($this->inventory(), $this->apiClient($http, ''), new Configuration('https://cronheart.com'));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('API token', $tester->getDisplay());
        self::assertSame([], $http->requests);
    }

    public function test_a_configuration_that_cannot_be_built_fails_the_command_naming_the_problem(): void
    {
        $token = 'cmk_0000000000000000';
        $container = new ContainerBuilder();
        $container->registerExtension(new CronMonitorExtension());
        $container->loadFromExtension('cron_monitor', ['api_key' => $token."\n"]);
        $container->register(ClientInterface::class, RecordingHttpClient::class)->setArguments([[]]);
        $container->register(LoggerInterface::class, NullLogger::class);
        $container->addCompilerPass(new AddConsoleCommandPass());
        $container->compile();
        $commandLoader = $container->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $commandLoader);
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader($commandLoader);

        $tester = new ApplicationTester($application);
        $exit = $tester->run(['command' => 'cron-monitor:sync'], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]);

        self::assertSame(1, $exit);
        $unwrapped = (string) preg_replace('/\s+/', '', $tester->getDisplay());
        self::assertStringContainsString(str_replace(' ', '', 'API key must not contain control characters'), $unwrapped);
        self::assertStringNotContainsString(substr($token, 0, 12), $unwrapped);
    }

    public function test_apply_skips_a_non_cron_trigger_without_creating_it(): void
    {
        // A periodical trigger ("every 1 hour") is not a cron expression; it
        // must be reported skipped, never sent as a cron schedule_expr. With
        // the only job skipped there is nothing to reconcile, so no HTTP at all.
        $http = new RecordingHttpClient([]);
        $command = new SyncCommand($this->inventory('every 1 hour'), $this->apiClient($http), new Configuration('https://cronheart.com', apiKey: 'cmk_test'));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('skipped', $tester->getDisplay());
        self::assertSame([], $http->requests, 'a non-cron trigger must not reach the API');
    }

    public function test_invalid_channel_is_rejected_before_any_http(): void
    {
        $http = new RecordingHttpClient([]);
        $command = new SyncCommand($this->inventory(), $this->apiClient($http), new Configuration('https://cronheart.com', apiKey: 'cmk_test'));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--apply' => true, '--channel' => 'abc']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('--channel', $tester->getDisplay());
        self::assertSame([], $http->requests);
    }

    public function test_a_channel_with_a_trailing_newline_reaches_the_create_as_given(): void
    {
        $http = new RecordingHttpClient([self::listPage(), self::createdMonitor('00000000-0000-0000-0000-000000000002')]);
        $command = new SyncCommand($this->inventory(), $this->apiClient($http), new Configuration('https://cronheart.com', apiKey: 'cmk_test'));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--apply' => true, '--channel' => "7\n"]);

        self::assertSame(0, $exit);
        self::assertSame(["7\n"], json_decode($http->bodies[1], true)['channel_ids'] ?? null);
    }
}
