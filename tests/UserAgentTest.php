<?php

declare(strict_types=1);

namespace CronMonitor\Tests;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\CronMonitorClient;
use PHPUnit\Framework\TestCase;

/**
 * The two clients each hard-code their own `User-Agent`, and the server reads
 * that string to tell which SDK version a customer is running. Two constants
 * with no link between them drift silently — one gets bumped at release time
 * and the other keeps reporting the previous version forever.
 *
 * Pinning both to the package's declared branch alias makes a forgotten bump
 * a failing test rather than a wrong answer in a support thread. The alias is
 * the minor being developed, not the last one released — so it moves at the
 * *start* of a cycle and the constants move with it. That is the intent: a dev
 * build should report the version it will ship as, not the previous one.
 */
final class UserAgentTest extends TestCase
{
    public function test_both_clients_send_the_same_user_agent(): void
    {
        self::assertSame(self::userAgentOf(CronMonitorClient::class), self::userAgentOf(MonitorApiClient::class));
    }

    public function test_user_agent_matches_the_declared_package_version(): void
    {
        self::assertSame(
            'cron-monitor-php-sdk/'.self::declaredVersion(),
            self::userAgentOf(CronMonitorClient::class),
        );
    }

    /**
     * @param class-string $class
     */
    private static function userAgentOf(string $class): string
    {
        $value = (new \ReflectionClass($class))->getConstant('USER_AGENT');
        self::assertIsString($value);

        return $value;
    }

    private static function declaredVersion(): string
    {
        $raw = file_get_contents(\dirname(__DIR__).'/composer.json');
        self::assertIsString($raw);
        $composer = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $alias = $composer['extra']['branch-alias']['dev-main'] ?? null;
        self::assertIsString($alias);
        self::assertSame(1, preg_match('/^(\d+\.\d+)\.x-dev$/', $alias, $matches));
        $version = $matches[1] ?? null;
        self::assertIsString($version);

        return $version;
    }
}
