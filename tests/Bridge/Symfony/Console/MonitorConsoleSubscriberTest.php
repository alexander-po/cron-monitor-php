<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\Console;

use CronMonitor\Bridge\Symfony\Console\MonitorConsoleSubscriber;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Fixtures\Symfony\BrokenMonitorAttributeCommand;
use CronMonitor\Tests\Fixtures\Symfony\EmptyMonitorAttributeCommand;
use CronMonitor\Tests\Fixtures\Symfony\EnvMonitoredAttributedCommand;
use CronMonitor\Tests\Fixtures\Symfony\MonitoredAttributedCommand;
use CronMonitor\Tests\Support\InMemoryLogger;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    public function test_monitor_attribute_drives_pings_without_yaml_entry(): void
    {
        // The attribute path covers the dominant "single command, single
        // UUID" case where the user does not want to duplicate the mapping
        // in YAML. `start` / `success` must fire with the attribute UUID
        // even though `commandMap` is empty.
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $subscriber = $this->buildSubscriber($http, []);

        $command = new MonitoredAttributedCommand();
        $command->setName('app:via-attribute');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith(
            '/ping/'.MonitoredAttributedCommand::UUID.'/start',
            (string) $http->requests[0]->getUri(),
        );
        self::assertStringEndsWith(
            '/ping/'.MonitoredAttributedCommand::UUID.'/success',
            (string) $http->requests[1]->getUri(),
        );
    }

    public function test_yaml_map_overrides_attribute_uuid(): void
    {
        // Explicit YAML wins over the attribute. Use case: an attribute on
        // the class declares the prod UUID, but per-env YAML overrides it
        // (e.g. a different cronheart project for staging).
        $stagingUuid = '99999999-9999-4999-8999-999999999999';
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $subscriber = $this->buildSubscriber($http, [
            'app:via-attribute' => $stagingUuid,
        ]);

        $command = new MonitoredAttributedCommand();
        $command->setName('app:via-attribute');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith(
            '/ping/'.$stagingUuid.'/start',
            (string) $http->requests[0]->getUri(),
        );
        // And the attribute UUID was *not* used — proves YAML truly shadowed
        // it rather than the two firing in parallel.
        self::assertStringNotContainsString(
            MonitoredAttributedCommand::UUID,
            (string) $http->requests[0]->getUri(),
        );
    }

    public function test_yaml_empty_string_suppresses_attribute_for_this_environment(): void
    {
        // A common ops pattern: `'app:via-attribute' => '%env(MY_UUID)%'`
        // with MY_UUID blank in dev. The empty string is an *explicit*
        // "don't monitor here" override and must shadow any class-level
        // attribute — otherwise the user can never turn off attribute-set
        // commands per environment without removing the attribute from the
        // source.
        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, [
            'app:via-attribute' => '',
        ]);

        $command = new MonitoredAttributedCommand();
        $command->setName('app:via-attribute');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(0, $http->requests);
    }

    public function test_attribute_resolved_command_emits_fail_with_exception_summary(): void
    {
        // Symmetry test against the YAML-path failure case
        // (`test_error_then_terminate_emits_fail_with_exception_summary`).
        // Attribute resolution must drive the same start/fail lifecycle —
        // attribute being the discovery channel does not change anything
        // about how the subscriber handles a thrown command.
        $http = new RecordingHttpClient([new Response(200), new Response(200)]);
        $subscriber = $this->buildSubscriber($http, []);

        $command = new MonitoredAttributedCommand();
        $command->setName('app:via-attribute');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onError(new ConsoleErrorEvent($input, $output, new \RuntimeException('reports blew up'), $command));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 1));

        self::assertCount(2, $http->requests);
        self::assertStringEndsWith(
            '/ping/'.MonitoredAttributedCommand::UUID.'/fail',
            (string) $http->requests[1]->getUri(),
        );
        self::assertStringContainsString('RuntimeException: reports blew up', (string) $http->requests[1]->getBody());
    }

    public function test_empty_string_attribute_uuid_is_treated_as_unmapped(): void
    {
        // The attribute branch mirrors the YAML branch's empty-string
        // policy: a deliberate `#[Monitor(uuid: '')]` (rare, but plausible
        // when expanding an env var that resolves to empty at attribute
        // construction time) must NOT throw at ping time. The subscriber
        // should silently skip monitoring for that command.
        $command = new EmptyMonitorAttributeCommand();
        $command->setName('app:empty-attr');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, []);

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(0, $http->requests);
    }

    public function test_env_attribute_resolves_uuid_from_environment(): void
    {
        // The `env:` attribute form is the prod-recommended pattern: the
        // UUID is a write capability secret that should live in env, not
        // in git. `#[Monitor(uuid: getenv(...))]` is a PHP parse error
        // (attribute args must be compile-time constant), so we carry
        // the env-var *name* on the class and resolve at runtime.
        $envUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $_ENV[EnvMonitoredAttributedCommand::ENV_VAR] = $envUuid;

        try {
            $http = new RecordingHttpClient([new Response(200), new Response(200)]);
            $subscriber = $this->buildSubscriber($http, []);

            $command = new EnvMonitoredAttributedCommand();
            $command->setName('app:via-env-attribute');
            $input = new ArrayInput([]);
            $output = new NullOutput();

            $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
            $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

            self::assertCount(2, $http->requests);
            self::assertStringEndsWith(
                '/ping/'.$envUuid.'/start',
                (string) $http->requests[0]->getUri(),
            );
            self::assertStringEndsWith(
                '/ping/'.$envUuid.'/success',
                (string) $http->requests[1]->getUri(),
            );
        } finally {
            unset($_ENV[EnvMonitoredAttributedCommand::ENV_VAR]);
        }
    }

    public function test_env_attribute_with_missing_env_var_emits_no_pings(): void
    {
        // Production wiring pattern: same attribute deployed across
        // environments, env var set in prod and absent in dev. The
        // missing env var must shadow the attribute the same way an
        // empty YAML map entry does — silently no-op, not throw.
        unset($_ENV[EnvMonitoredAttributedCommand::ENV_VAR], $_SERVER[EnvMonitoredAttributedCommand::ENV_VAR]);
        putenv(EnvMonitoredAttributedCommand::ENV_VAR);

        $http = new RecordingHttpClient([]);
        $subscriber = $this->buildSubscriber($http, []);

        $command = new EnvMonitoredAttributedCommand();
        $command->setName('app:via-env-attribute');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertCount(0, $http->requests);
    }

    public function test_env_attribute_with_empty_env_var_emits_no_pings(): void
    {
        // Deliberate empty-string env var = "do not monitor in this
        // environment". Same suppression semantics as the existing
        // empty-literal `#[Monitor(uuid: '')]` and empty YAML map entry.
        $_ENV[EnvMonitoredAttributedCommand::ENV_VAR] = '';

        try {
            $http = new RecordingHttpClient([]);
            $subscriber = $this->buildSubscriber($http, []);

            $command = new EnvMonitoredAttributedCommand();
            $command->setName('app:via-env-attribute');
            $input = new ArrayInput([]);
            $output = new NullOutput();

            $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
            $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

            self::assertCount(0, $http->requests);
        } finally {
            unset($_ENV[EnvMonitoredAttributedCommand::ENV_VAR]);
        }
    }

    public function test_invariant_violation_is_swallowed_and_logged_as_warning(): void
    {
        // Realistic scenario: a developer migrates a command from
        // `#[Monitor(uuid: '...')]` to `#[Monitor(env: '...')]` and
        // forgets to remove the old literal. The attribute constructor
        // throws on `newInstance()`; the subscriber must catch that,
        // emit a warning so operators see the misuse, and continue
        // — the command itself must still run.
        $http = new RecordingHttpClient([]);
        $logger = new InMemoryLogger();
        $subscriber = $this->buildSubscriber($http, [], $logger);

        $command = new BrokenMonitorAttributeCommand();
        $command->setName('app:broken-attribute');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        // No pings fired — the attribute could not resolve to a UUID.
        self::assertCount(0, $http->requests);

        // The misuse was surfaced at warning level so operators have
        // an audit trail rather than silent "monitoring just stopped".
        $warnings = array_filter(
            $logger->records,
            static fn (array $r) => 'warning' === $r['level'],
        );
        self::assertCount(1, $warnings);

        $warning = array_values($warnings)[0];
        self::assertStringContainsString('#[Monitor] attribute could not be instantiated', $warning['message']);
        self::assertSame(BrokenMonitorAttributeCommand::class, $warning['context']['class']);
        self::assertSame(\InvalidArgumentException::class, $warning['context']['exception']);
    }

    public function test_attribute_uuid_is_cached_per_class(): void
    {
        // Long-lived workers (messenger:consume, queue:work) dispatch the
        // same command class many times. Reflection should run once per
        // class and the cache should serve subsequent lookups.
        $http = new RecordingHttpClient([
            new Response(200), new Response(200), // run 1
            new Response(200), new Response(200), // run 2
        ]);
        $subscriber = $this->buildSubscriber($http, []);

        $output = new NullOutput();
        for ($i = 0; $i < 2; ++$i) {
            $command = new MonitoredAttributedCommand();
            $command->setName('app:via-attribute');
            $input = new ArrayInput([]);
            $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
            $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));
        }

        self::assertCount(4, $http->requests);

        $reflection = new \ReflectionObject($subscriber);
        $property = $reflection->getProperty('attributeUuidCache');
        $property->setAccessible(true);
        /** @var array<string, ?string> $cache */
        $cache = $property->getValue($subscriber);
        // Exactly one cache entry — the command class. Multiple entries
        // would imply we're re-reflecting per instance instead of per
        // class.
        self::assertCount(1, $cache);
        self::assertSame(MonitoredAttributedCommand::UUID, $cache[MonitoredAttributedCommand::class]);
    }

    /**
     * @param array<string, string> $commandMap
     */
    private function buildSubscriber(
        RecordingHttpClient $http,
        array $commandMap,
        ?LoggerInterface $logger = null,
    ): MonitorConsoleSubscriber {
        $factory = new HttpFactory();
        $client = new CronMonitorClient(
            new Configuration('https://cronheart.com'),
            $http,
            $factory,
            $factory,
        );

        return null === $logger
            ? new MonitorConsoleSubscriber($client, $commandMap)
            : new MonitorConsoleSubscriber($client, $commandMap, $logger);
    }

    private function commandNamed(string $name): Command
    {
        $command = new Command();
        $command->setName($name);

        return $command;
    }
}
