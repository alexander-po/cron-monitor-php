<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\DependencyInjection;

use CronMonitor\Bridge\Symfony\Messenger\MonitorPingMiddleware;
use CronMonitor\Client\Configuration as ClientConfiguration;
use CronMonitor\Client\CronMonitorClient;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class CronMonitorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        // Static factory binding: rather than newing up CronMonitorClient
        // ourselves, we mutate the service definition with the resolved
        // configuration values so end-users can still decorate the client
        // via the standard service compiler passes.
        $container
            ->getDefinition(ClientConfiguration::class)
            ->setArguments([
                $config['endpoint'],
                $config['timeout_seconds'],
                $config['retries'],
                $config['api_key'],
                $config['allow_insecure_endpoint'],
            ]);

        // Index 1 is the `$monitorMap` argument in MonitorPingMiddleware; the
        // services.php seed value of `[]` is overwritten with the resolved
        // user config here so end users only ever see the YAML knob.
        $container
            ->getDefinition(MonitorPingMiddleware::class)
            ->replaceArgument(1, $config['messages']);
    }

    public function getAlias(): string
    {
        return 'cron_monitor';
    }
}
