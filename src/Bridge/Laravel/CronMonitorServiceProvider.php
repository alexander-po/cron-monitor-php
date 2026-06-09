<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Bridge\Laravel\Console\SyncCommand;
use CronMonitor\Bridge\Laravel\Scheduler\AttributeResolver;
use CronMonitor\Bridge\Laravel\Scheduler\EventMonitor;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Client\CurlPsr18Client;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Laravel auto-discovered service provider.
 *
 * Registration shape (kept deliberately small):
 *  - publish a `config/cron-monitor.php` file with the same knobs as the
 *    Symfony YAML (endpoint, timeout, retries, api_key, monitors map);
 *  - bind {@see CronMonitorClient} as a singleton in the container;
 *  - register the {@see SyncCommand} so `artisan cron-monitor:sync` works
 *    without extra wiring;
 *  - install an {@see EventMonitor} macro on `Schedule\Event` so users can
 *    write `->monitor('uuid')` in `routes/console.php`.
 */
final class CronMonitorServiceProvider extends ServiceProvider
{
    private const CONFIG_KEY = 'cron-monitor';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), self::CONFIG_KEY);

        $this->app->singleton(Configuration::class, static function (Container $app) {
            $config = (array) $app->make('config')->get(self::CONFIG_KEY, []);

            return new Configuration(
                endpoint: (string) ($config['endpoint'] ?? Configuration::DEFAULT_ENDPOINT),
                timeoutSeconds: (float) ($config['timeout_seconds'] ?? Configuration::DEFAULT_TIMEOUT_SECONDS),
                retries: (int) ($config['retries'] ?? Configuration::DEFAULT_RETRIES),
                apiKey: $config['api_key'] ?? null,
                allowInsecureEndpoint: (bool) ($config['allow_insecure_endpoint'] ?? false),
            );
        });

        $this->app->singleton(CronMonitorClient::class, static function (Container $app): CronMonitorClient {
            [$config, $http, $factory, $streamFactory, $logger] = self::resolveSdkDependencies($app);

            return new CronMonitorClient($config, $http, $factory, $streamFactory, $logger);
        });

        // Authenticated management-API client. Reuses the exact same
        // dependency-resolution as the ping client; the API token rides on
        // Configuration::apiKey (the `api_key` config value, defaulting to
        // env(CRON_MONITOR_API_KEY)).
        $this->app->singleton(MonitorApiClient::class, static function (Container $app): MonitorApiClient {
            [$config, $http, $factory, $streamFactory, $logger] = self::resolveSdkDependencies($app);

            return new MonitorApiClient($config, $http, $factory, $streamFactory, $logger);
        });
    }

    /**
     * Resolve the PSR-18 / PSR-17 / PSR-3 dependencies both SDK clients
     * share. Laravel does not bind these by default, so we fall back to the
     * bundled cURL transport + nyholm/psr7 factories — that way
     * `composer require` is the only step needed, with no Guzzle dependency.
     * Users who already bind a PSR-18 client or PSR-17 factories in the
     * container get their bindings honoured.
     *
     * @return array{Configuration, ClientInterface, RequestFactoryInterface, StreamFactoryInterface, LoggerInterface}
     */
    private static function resolveSdkDependencies(Container $app): array
    {
        $config = $app->make(Configuration::class);
        $default = new Psr17Factory();

        $factory = $app->bound(RequestFactoryInterface::class)
            ? $app->make(RequestFactoryInterface::class)
            : $default;

        $streamFactory = $app->bound(StreamFactoryInterface::class)
            ? $app->make(StreamFactoryInterface::class)
            : (
                $factory instanceof StreamFactoryInterface ? $factory : $default
            );

        $http = $app->bound(ClientInterface::class)
            ? $app->make(ClientInterface::class)
            : new CurlPsr18Client($default, $default, $config->timeoutSeconds);

        $logger = $app->bound(LoggerInterface::class)
            ? $app->make(LoggerInterface::class)
            : new NullLogger();

        return [$config, $http, $factory, $streamFactory, $logger];
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('cron-monitor.php'),
            ], 'cron-monitor-config');

            $this->commands([SyncCommand::class]);
        }

        // Install the `monitor()` macro on Event so users can write
        // `$schedule->command('reports:run')->daily()->monitor('uuid')`.
        // The macro hooks `before` / `onSuccess` / `onFailure` which Laravel
        // itself wraps around the closure that runs the command, so the
        // pings always fire on the same boundary as the job execution.
        //
        // When called without a UUID (`->monitor()`), the macro falls back
        // to reading the `#[Monitor]` attribute on the Artisan command
        // class behind the event — keeping the UUID next to the code
        // instead of duplicating it at the scheduler call site. An empty
        // string is treated as explicit suppression so per-environment
        // overrides (`->monitor(env('MY_UUID', ''))`) work without
        // throwing.
        Event::macro('monitor', function (?string $monitorUuid = null): Event {
            /** @var Event $this */
            if (null === $monitorUuid) {
                $monitorUuid = AttributeResolver::resolveUuid(
                    $this,
                    CronMonitorServiceProvider::artisanCommandLocator(),
                );
            }

            if (null === $monitorUuid || '' === $monitorUuid) {
                // Nothing to install. Returning $this preserves fluent
                // chaining so the rest of the user's scheduler config
                // (`->withoutOverlapping()`, `->runInBackground()`, etc.)
                // still applies.
                return $this;
            }

            $client = app(CronMonitorClient::class);

            return EventMonitor::install($this, $client, $monitorUuid);
        });
    }

    /**
     * Build the command-name → Command lookup used by the `monitor()`
     * macro's attribute fallback. The closure is built fresh on every
     * macro call because the kernel binding may change during the
     * application lifecycle (most notably in tests). All failure modes
     * — kernel not bound, kernel without `getArtisan()`, artisan unable
     * to resolve the name — collapse to `null` so the macro can short-
     * circuit without breaking the host job.
     *
     * Exposed as a public static helper so the macro closure (which
     * Laravel rebinds onto `Event`) can call it without dragging extra
     * `use` references through `$this`.
     */
    public static function artisanCommandLocator(): \Closure
    {
        return static function (string $commandName): ?SymfonyCommand {
            if (!\function_exists('app')) {
                return null;
            }
            $container = app();
            if (!$container->bound(ConsoleKernelContract::class)) {
                return null;
            }
            $kernel = $container->make(ConsoleKernelContract::class);
            // `Container::make()` is typed loosely — narrow to an object
            // before `method_exists` so PHPStan can prove the dynamic call
            // is safe. `getArtisan()` is on the concrete
            // `Illuminate\Foundation\Console\Kernel`, NOT on the
            // contract, hence the duck-type rather than `instanceof`.
            if (!\is_object($kernel) || !method_exists($kernel, 'getArtisan')) {
                return null;
            }
            $artisan = $kernel->getArtisan();
            if (!$artisan instanceof Artisan) {
                return null;
            }
            try {
                return $artisan->find($commandName);
            } catch (\Throwable $error) {
                // `find()` throws `CommandNotFoundException` for unknown
                // names; that — like any other failure here — must not
                // surface as a fatal at scheduler-binding time. We do
                // log it (best-effort) so a misconfigured kernel or a
                // misspelled scheduled command name leaves an audit
                // trail instead of silently disabling monitoring.
                if ($container->bound(LoggerInterface::class)) {
                    try {
                        $container->make(LoggerInterface::class)->warning(
                            'cron-monitor: Artisan kernel could not resolve scheduled command for #[Monitor] attribute lookup',
                            [
                                'command' => $commandName,
                                'exception' => $error::class,
                                'message' => $error->getMessage(),
                            ],
                        );
                    } catch (\Throwable) {
                        // Logger itself blew up — we are out of options.
                        // The original safety contract still holds: no
                        // exception escapes this closure.
                    }
                }

                return null;
            }
        };
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            Configuration::class,
            CronMonitorClient::class,
            MonitorApiClient::class,
        ];
    }

    private function configPath(): string
    {
        return \dirname(__DIR__, 3).'/config/cron-monitor.php';
    }
}
