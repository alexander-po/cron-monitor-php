<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Laravel;

use CronMonitor\Bridge\Laravel\Console\SyncCommand;
use CronMonitor\Bridge\Laravel\Scheduler\EventMonitor;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Client\CurlPsr18Client;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

        $this->app->singleton(CronMonitorClient::class, static function (Container $app) {
            // Resolve PSR-18 / PSR-17 dependencies. Laravel does not bind
            // these by default, so we fall back to the bundled cURL transport
            // + nyholm/psr7 factories — that way `composer require` is the
            // only step needed, with no Guzzle dependency. Users who already
            // bind a PSR-18 client or PSR-17 factories in the container get
            // their bindings honoured.
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

            return new CronMonitorClient(
                $config,
                $http,
                $factory,
                $streamFactory,
                $logger,
            );
        });
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
        Event::macro('monitor', function (string $monitorUuid): Event {
            /** @var Event $this */
            $client = app(CronMonitorClient::class);

            return EventMonitor::install($this, $client, $monitorUuid);
        });
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            Configuration::class,
            CronMonitorClient::class,
        ];
    }

    private function configPath(): string
    {
        return \dirname(__DIR__, 3).'/config/cron-monitor.php';
    }
}
