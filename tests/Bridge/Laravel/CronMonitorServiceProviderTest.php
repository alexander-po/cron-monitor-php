<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Laravel;

use CronMonitor\Bridge\Laravel\Console\SyncCommand;
use CronMonitor\Bridge\Laravel\CronMonitorServiceProvider;
use CronMonitor\Tests\Fixtures\Laravel\MonitoredScheduledCommand;
use CronMonitor\Tests\Support\RecordingHttpClient;
use CronMonitor\Tests\Support\UnusedEventMutex;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CronMonitorServiceProviderTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000000';
    private const TOKEN = 'cmk_0000000000000000';

    protected function tearDown(): void
    {
        Event::flushMacros();
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_the_monitor_macro_installs_hooks_that_ping_the_uuid(): void
    {
        $container = self::bootProvider(['endpoint' => 'https://cronheart.com']);
        $http = new RecordingHttpClient([new Response(200)]);
        $container->instance(ClientInterface::class, $http);
        $event = new Event(new UnusedEventMutex(), 'reports:nightly', null);

        self::assertSame($event, self::monitor($event, self::UUID));
        $before = self::hooks($event, 'beforeCallbacks');
        self::assertCount(1, $before);
        self::assertCount(2, self::hooks($event, 'afterCallbacks'));
        self::assertInstanceOf(\Closure::class, $before[0]);
        $before[0]();

        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/ping/'.self::UUID.'/start', (string) $http->requests[0]->getUri());
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('settingsTheClientRejects')]
    public function test_a_client_that_cannot_be_built_leaves_the_event_unmonitored_instead_of_throwing(array $settings): void
    {
        self::bootProvider($settings);
        $event = new Event(new UnusedEventMutex(), 'reports:nightly', null);

        self::assertSame($event, self::monitor($event, self::UUID));
        self::assertCount(0, self::hooks($event, 'beforeCallbacks'));
        self::assertCount(0, self::hooks($event, 'afterCallbacks'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function settingsTheClientRejects(): iterable
    {
        yield 'an http endpoint' => [['endpoint' => 'http://cron.internal']];
        yield 'an API key env() read as a boolean' => [['endpoint' => 'https://cronheart.com', 'api_key' => true]];
    }

    public function test_an_event_that_rejects_a_hook_is_returned_with_the_hooks_it_accepted(): void
    {
        self::bootProvider(['endpoint' => 'https://cronheart.com']);
        $event = new class(new UnusedEventMutex(), 'reports:nightly') extends Event {
            public bool $asked = false;

            public function onFailure(\Closure $callback): never
            {
                $this->asked = true;

                throw new \LogicException('this event takes no failure hook');
            }
        };

        self::assertSame($event, self::monitor($event, self::UUID));
        self::assertTrue($event->asked);
        self::assertCount(1, self::hooks($event, 'beforeCallbacks'));
        self::assertCount(1, self::hooks($event, 'afterCallbacks'));
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('settingsTheSyncReports')]
    public function test_sync_fails_naming_a_configuration_that_cannot_be_built(array $settings, string $problem): void
    {
        $container = self::bootProvider($settings);
        $container->instance(Schedule::class, new class extends Schedule {
            public function __construct()
            {
            }
        });
        $artisan = new Artisan($container, new Dispatcher($container), 'test');
        $artisan->add(new SyncCommand());
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

        $exit = self::runLikeTheConsoleKernel($artisan, new ArrayInput(['command' => 'cron-monitor:sync']), $output);

        self::assertSame(1, $exit);
        $unwrapped = (string) preg_replace('/\s+/', '', $output->fetch());
        self::assertStringContainsString(str_replace(' ', '', $problem), $unwrapped);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 12), $unwrapped);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function settingsTheSyncReports(): iterable
    {
        yield 'an API key with a trailing newline' => [['endpoint' => 'https://cronheart.com', 'api_key' => self::TOKEN."\n"], 'API key must not contain control characters'];
        yield 'an API key env() read as a boolean' => [['endpoint' => 'https://cronheart.com', 'api_key' => true], '($apiKey) must be of type ?string'];
    }

    public function test_a_uuid_read_from_the_attribute_stays_out_of_the_frames_that_build_the_client(): void
    {
        $container = self::bootProvider(['endpoint' => 'https://cronheart.com']);
        $artisan = new Artisan($container, new Dispatcher($container), 'test');
        $artisan->add(new MonitoredScheduledCommand());
        $container->instance(ConsoleKernelContract::class, new class($artisan) {
            public function __construct(private readonly Artisan $artisan)
            {
            }

            public function getArtisan(): Artisan
            {
                return $this->artisan;
            }
        });
        $printed = null;
        $container->singleton(ClientInterface::class, static function () use (&$printed): ClientInterface {
            $printed = self::argumentsOutsideTheTests(debug_backtrace());

            return new RecordingHttpClient([]);
        });
        $event = new Event(new UnusedEventMutex(), "'/usr/bin/php' 'artisan' reports:nightly", null);

        self::monitor($event, null);

        self::assertCount(1, self::hooks($event, 'beforeCallbacks'), 'the macro did not fall back to the attribute');
        self::assertIsString($printed);
        self::assertMatchesRegularExpression('/'.preg_quote(Event::class.'->', '/').'\S*\{closure/', $printed, 'the macro frame was not checked');
        self::assertStringContainsString(CronMonitorServiceProvider::class.'::resolveSdkDependencies', $printed);
        self::assertStringNotContainsString(MonitoredScheduledCommand::UUID, $printed);
    }

    /**
     * The container reports a cached configuration, as `config:cache` leaves
     * production, so `register()` reads the settings as given instead of
     * merging in the published defaults, whose `env()` calls need
     * vlucas/phpdotenv, which the dev dependencies do not install.
     *
     * @param array<string, mixed> $settings
     */
    private static function bootProvider(array $settings): Container
    {
        $container = new class extends Container implements CachesConfiguration {
            public function runningInConsole(): bool
            {
                return false;
            }

            public function runningUnitTests(): bool
            {
                return true;
            }

            public function configurationIsCached(): bool
            {
                return true;
            }

            public function getCachedConfigPath(): never
            {
                throw new \LogicException('the provider does not read the cache file');
            }

            public function getCachedServicesPath(): never
            {
                throw new \LogicException('the provider does not read the cache file');
            }
        };
        $container->instance(ClientInterface::class, new RecordingHttpClient([]));
        $container->instance('config', new class(['cron-monitor' => $settings]) {
            /**
             * @param array<string, mixed> $items
             */
            public function __construct(private readonly array $items)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }
        });
        Container::setInstance($container);

        /** @var \Illuminate\Contracts\Foundation\Application $application */
        $application = $container;
        $provider = new CronMonitorServiceProvider($application);
        $provider->register();
        $provider->boot();

        return $container;
    }

    /**
     * Artisan leaves an exception to the framework's console kernel, which the
     * dev dependencies do not install; the kernel reports it, renders it with
     * Symfony's renderer and exits 1, and this does the last two.
     */
    private static function runLikeTheConsoleKernel(Artisan $artisan, ArrayInput $input, OutputInterface $output): int
    {
        try {
            return $artisan->run($input, $output);
        } catch (\Throwable $e) {
            (new SymfonyApplication())->renderThrowable($e, $output);

            return 1;
        }
    }

    /**
     * The call `->monitor($uuid)` makes, spelled out because PHPStan does not
     * know Laravel's macros.
     */
    private static function monitor(Event $event, ?string $uuid): mixed
    {
        return $event->__call('monitor', [$uuid]);
    }

    /**
     * @return array<mixed>
     */
    private static function hooks(Event $event, string $property): array
    {
        $hooks = (new \ReflectionProperty(Event::class, $property))->getValue($event);
        self::assertIsArray($hooks);

        return $hooks;
    }

    /**
     * @param list<array{function: string, class?: string, type?: string, args?: array<mixed>}> $backtrace
     */
    private static function argumentsOutsideTheTests(array $backtrace): string
    {
        $arguments = [];
        foreach ($backtrace as $frame) {
            $class = $frame['class'] ?? '';
            if (1 !== preg_match('/^(CronMonitor\\\\Tests|PHPUnit)\\\\/', $class)) {
                $arguments[] = [$class.($frame['type'] ?? '').$frame['function'] => $frame['args'] ?? []];
            }
        }

        return print_r($arguments, true);
    }
}
