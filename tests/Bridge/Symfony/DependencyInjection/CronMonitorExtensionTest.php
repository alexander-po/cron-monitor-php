<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\DependencyInjection;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Bridge\Symfony\Console\MonitorConsoleSubscriber;
use CronMonitor\Bridge\Symfony\DependencyInjection\CronMonitorExtension;
use CronMonitor\Bridge\Symfony\Messenger\MonitorPingMiddleware;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CronMonitorExtensionTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000000';

    public function test_every_service_with_a_logger_builds_in_a_container_without_one(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new CronMonitorExtension());
        $container->loadFromExtension('cron_monitor', ['commands' => ['app:reports:nightly' => self::UUID]]);
        $container->register(ClientInterface::class, RecordingHttpClient::class)->setArguments([[]]);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $definition->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile();

        self::assertFalse($container->has(LoggerInterface::class));
        foreach ([CronMonitorClient::class, MonitorApiClient::class, MonitorPingMiddleware::class, MonitorConsoleSubscriber::class] as $id) {
            $service = $container->get($id);
            self::assertInstanceOf($id, $service);
            self::assertInstanceOf(NullLogger::class, (new \ReflectionProperty($service, 'logger'))->getValue($service), $id);
        }
    }
}
