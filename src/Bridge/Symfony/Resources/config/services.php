<?php

declare(strict_types=1);

use CronMonitor\Bridge\Symfony\Console\MonitorConsoleSubscriber;
use CronMonitor\Bridge\Symfony\Console\SyncCommand;
use CronMonitor\Bridge\Symfony\Messenger\MonitorPingMiddleware;
use CronMonitor\Bridge\Symfony\Scheduler\ScheduleInventory;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Service definitions for the Symfony bundle bridge. Configuration values
    // are injected by `CronMonitorExtension::load()` after the YAML has been
    // resolved, so here we only declare the wiring shape.
    $services = $container->services()
        ->defaults()
            ->autowire(false)
            ->autoconfigure(false)
            ->public(false);

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
