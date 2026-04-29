<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony;

use CronMonitor\Bridge\Symfony\DependencyInjection\CronMonitorExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle entry-point. Symfony auto-discovers it via the `bundles.php`
 * file (Flex recipe ships a one-liner registering the class).
 *
 * The bundle wires up a single PSR-18-compatible client and a Messenger
 * middleware that wraps registered scheduled message handlers in
 * `start` / `success` / `fail` pings.
 */
final class CronMonitorBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new CronMonitorExtension();
        }

        return $this->extension ?: null;
    }

    public function getPath(): string
    {
        return \dirname(__DIR__, 2).'/Bridge/Symfony';
    }
}
