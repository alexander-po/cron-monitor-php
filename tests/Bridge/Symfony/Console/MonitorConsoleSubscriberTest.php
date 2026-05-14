<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\Console;

use CronMonitor\Bridge\Symfony\Console\MonitorConsoleSubscriber;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class MonitorConsoleSubscriberTest extends TestCase
{
    private const UUID = '33333333-3333-4333-8333-333333333333';

    public function test_subscribes_to_command_error_and_terminate_events(): void
    {
        $events = MonitorConsoleSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
        self::assertArrayHasKey(ConsoleEvents::ERROR, $events);
        self::assertArrayHasKey(ConsoleEvents::TERMINATE, $events);
    }

    public function test_disabled_command_produces_no_pings(): void
    {
        // When another listener calls `$event->disableCommand()`, Symfony
        // skips the command body and emits TERMINATE with exit code 113
        // (RETURN_CODE_DISABLED). The subscriber must not mistake that for a
        // failure — neither `start` nor `fail` should fire.
        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:reports:nightly');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $commandEvent = new ConsoleCommandEvent($command, $input, $output);
        $commandEvent->disableCommand();
        $subscriber->onCommand($commandEvent);
        $subscriber->onTerminate(new ConsoleTerminateEvent(
            $command,
            $input,
            $output,
            ConsoleCommandEvent::RETURN_CODE_DISABLED,
        ));

        self::assertCount(0, $http->requests);
    }

    public function test_error_on_unmapped_command_does_not_stash_state(): void
    {
        // Unmapped commands must not leave entries in the internal error
        // stash. In long-lived workers (messenger:consume, queue:work) an
        // unbounded stash would slowly leak memory.
        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:not:mapped');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onError(new ConsoleErrorEvent($input, $output, new \RuntimeException('boom'), $command));

        $reflection = new \ReflectionObject($subscriber);
        $property = $reflection->getProperty('errorByRun');
        $property->setAccessible(true);
        self::assertSame([], $property->getValue($subscriber));
    }

    public function test_error_stash_is_bounded_against_orphans(): void
    {
        // Pathological case: many ERROR events arrive without matching
        // TERMINATE (e.g. an outer try/catch loop around `Application::run`
        // with `setCatchExceptions(false)`). The stash bound prevents
        // unbounded growth.
        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:reports:nightly');
        $output = new NullOutput();

        // Fire more than ERROR_STASH_LIMIT (64) error events with distinct
        // inputs so no `unset()` paths overlap.
        for ($i = 0; $i < 100; ++$i) {
            $subscriber->onError(new ConsoleErrorEvent(
                new ArrayInput([]),
                $output,
                new \RuntimeException('boom '.$i),
                $command,
            ));
        }

        $reflection = new \ReflectionObject($subscriber);
        $property = $reflection->getProperty('errorByRun');
        $property->setAccessible(true);
        self::assertLessThanOrEqual(64, \count($property->getValue($subscriber)));
    }

    public function test_zero_exit_emits_start_then_success(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:reports:nightly');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
        self::assertStringEndsWith('/ping/'.self::UUID.'/success', (string) $http->requests[1]->getUri());
    }

    public function test_non_zero_exit_without_error_emits_fail_with_exit_code_body(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:reports:nightly');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 17));

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/fail', (string) $http->requests[1]->getUri());
        self::assertStringContainsString('non-zero status 17', (string) $http->requests[1]->getBody());
    }

    public function test_error_then_terminate_emits_fail_with_exception_summary(): void
    {
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:reports:nightly');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onError(new ConsoleErrorEvent($input, $output, new \RuntimeException('reports blew up'), $command));
        // Even if the kernel normalises exit code to 0, the stashed throwable
        // should still drive a fail-ping — a thrown command did not succeed.
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/fail', (string) $http->requests[1]->getUri());
        self::assertStringContainsString('RuntimeException: reports blew up', (string) $http->requests[1]->getBody());
    }

    public function test_unmapped_command_is_ignored(): void
    {
        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:something:else');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(0, $http->requests);
    }

    public function test_concurrent_runs_do_not_share_error_state(): void
    {
        // Long-lived workers (e.g. messenger:consume) can dispatch sub-commands
        // back-to-back through the same Application instance. The subscriber
        // must key its stashed throwables by InputInterface identity so a
        // failure in run A does not poison run B's success path.
        $http = new RecordingHttpClient([
            new Response(200), new Response(200), // run A: start + fail
            new Response(200), new Response(200), // run B: start + success
        ]);
        $subscriber = $this->buildSubscriber($http, ['app:reports:nightly' => self::UUID]);

        $command = $this->commandNamed('app:reports:nightly');
        $output = new NullOutput();

        $inputA = new ArrayInput([]);
        $inputB = new ArrayInput([]);

        $subscriber->onCommand(new ConsoleCommandEvent($command, $inputA, $output));
        $subscriber->onError(new ConsoleErrorEvent($inputA, $output, new \RuntimeException('A failed'), $command));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $inputA, $output, 1));

        $subscriber->onCommand(new ConsoleCommandEvent($command, $inputB, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $inputB, $output, 0));

        self::assertCount(4, $http->requests);
        self::assertStringEndsWith('/fail', (string) $http->requests[1]->getUri());
        self::assertStringEndsWith('/success', (string) $http->requests[3]->getUri());
    }

    /**
     * @param array<string, string> $commandMap
     */
    private function buildSubscriber(RecordingHttpClient $http, array $commandMap): MonitorConsoleSubscriber
    {
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
        );

        return new MonitorConsoleSubscriber($client, $commandMap);
    }

    private function commandNamed(string $name): Command
    {
        $command = new Command();
        $command->setName($name);

        return $command;
    }
}
