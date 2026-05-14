<?php

declare(strict_types=1);

use CronMonitor\Bridge\Symfony\Console\MonitorConsoleSubscriber;
use CronMonitor\Bridge\Symfony\Console\SyncCommand;
use CronMonitor\Bridge\Symfony\Messenger\MonitorPingMiddleware;
use CronMonitor\Bridge\Symfony\Scheduler\ScheduleInventory;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Psr18Client;

// `service()` and `tagged_iterator()` are namespaced helpers exported by
// the configurator component — without an explicit `use function` import,
// PHP resolves them to the global namespace and the container compile
// fails with `Call to undefined function service()` the first time any
// consumer's kernel boots this bundle. The SDK's own test suite never
// triggered this because it builds the container via PHPUnit's symfony
// kernel fixture, which short-circuits services.php loading; an actual
// `composer require` + cache:clear inside a host project surfaces it
// immediately.
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    // Service definitions for the Symfony bundle bridge. Configuration values
    // are injected by `CronMonitorExtension::load()` after the YAML has been
    // resolved, so here we only declare the wiring shape.
    $services = $container->services()
        ->defaults()
            ->autowire(false)
            ->autoconfigure(false)
            ->public(false);

    // Drop-in PSR-17 factories — nyholm/psr7 is a hard dep of this package
    // (see composer.json `require`), so the class is guaranteed to be
    // autoloadable. We bind the two interfaces the SDK consumes (Request
    // and Stream factories) to a single Psr17Factory instance under a
    // namespaced service id. If the consumer already wires these
    // interfaces — via nyholm/psr7's Flex recipe, a manual binding to
    // guzzlehttp/psr7's HttpFactory, slim/psr7, etc. — their alias is
    // applied AFTER bundle extensions load and wins (Symfony resolves
    // alias chains to the last definition). The default exists so
    // `composer require cron-monitor/php-sdk` is genuinely drop-in for
    // Symfony projects: no second `composer require nyholm/psr7` step.
    $services->set('cron_monitor.psr17_factory', Psr17Factory::class);
    $services->alias(RequestFactoryInterface::class, 'cron_monitor.psr17_factory');
    $services->alias(StreamFactoryInterface::class, 'cron_monitor.psr17_factory');

    // Drop-in PSR-18 client — only when symfony/http-client is installed.
    // It's not a hard dep of this package (a few hundred KB is heavier than
    // we want to force on Laravel-only consumers or those who already use
    // guzzle), but it IS present in the vast majority of Symfony 7
    // projects and ships a ready-made PSR-18 adapter. If the class is
    // missing, the consumer must bind ClientInterface themselves and the
    // container compile produces a clear "non-existent service
    // Psr\Http\Client\ClientInterface" error naming the missing piece.
    if (class_exists(Psr18Client::class)) {
        $services->set('cron_monitor.psr18_client', Psr18Client::class);
        $services->alias(ClientInterface::class, 'cron_monitor.psr18_client');
    }

    $services->set(Configuration::class)
        ->args(['', 0.0, 0, null, false]); // overridden by CronMonitorExtension::load

    $services->set(CronMonitorClient::class)
        ->args([
            service(Configuration::class),
            service(ClientInterface::class),
            service(RequestFactoryInterface::class),
            service(StreamFactoryInterface::class),
            service(LoggerInterface::class)->ignoreOnInvalid(),
        ])
        ->public(); // public so user code can grab it via `$container->get(...)`

    $services->set(MonitorPingMiddleware::class)
        ->args([
            service(CronMonitorClient::class),
            [], // overridden by CronMonitorExtension::load (positional arg #2)
            service(LoggerInterface::class)->ignoreOnInvalid(),
        ]);

    // Console subscriber wraps `bin/console <name>` invocations whose command
    // name appears in the configured `commands:` map. Tagged as a kernel
    // event subscriber so Symfony's EventDispatcher picks it up — no extra
    // wiring required on the user's side.
    $services->set(MonitorConsoleSubscriber::class)
        ->args([
            service(CronMonitorClient::class),
            [], // overridden by CronMonitorExtension::load
            service(LoggerInterface::class)->ignoreOnInvalid(),
        ])
        ->tag('kernel.event_subscriber');

    // The Scheduler bridge collects every service tagged
    // `scheduler.schedule_provider` (Symfony's own tag). Users get this for
    // free as long as their ScheduleProvider is registered with the
    // standard tag — no extra wiring required.
    $services->set(ScheduleInventory::class)
        ->args([tagged_iterator('scheduler.schedule_provider')]);

    $services->set(SyncCommand::class)
        ->args([service(ScheduleInventory::class)])
        ->tag('console.command');
};
