<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Client;

use CronMonitor\Client\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_default_endpoint_is_https_and_pointed_at_saas(): void
    {
        $config = Configuration::withDefaultEndpoint();

        self::assertSame('https://cron-monitor.io', $config->endpoint);
        self::assertSame(5.0, $config->timeoutSeconds);
        self::assertSame(1, $config->retries);
        self::assertNull($config->apiKey);
    }

    public function test_constructor_rejects_empty_endpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration('');
    }

    public function test_constructor_rejects_unknown_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('http or https');
        new Configuration('ftp://example.com');
    }

    public function test_constructor_rejects_plain_http_unless_explicitly_allowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to use plain HTTP');
        new Configuration('http://self-hosted.lan');
    }

    public function test_constructor_accepts_plain_http_when_allow_insecure_endpoint_is_true(): void
    {
        $config = new Configuration(
            endpoint: 'http://self-hosted.lan',
            allowInsecureEndpoint: true,
        );
        self::assertSame('http://self-hosted.lan', $config->endpoint);
    }

    public function test_constructor_rejects_zero_or_negative_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration('https://cron-monitor.io', timeoutSeconds: 0.0);
    }

    public function test_constructor_rejects_negative_retries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration('https://cron-monitor.io', retries: -1);
    }

    public function test_ping_url_strips_trailing_slash_from_endpoint(): void
    {
        $config = new Configuration('https://cron-monitor.io/');
        self::assertSame(
            'https://cron-monitor.io/ping/00000000-0000-4000-a000-000000000000',
            $config->pingUrl('00000000-0000-4000-a000-000000000000'),
        );
    }

    public function test_ping_url_appends_action_segment_when_provided(): void
    {
        $config = new Configuration('https://cron-monitor.io');
        self::assertSame(
            'https://cron-monitor.io/ping/00000000-0000-4000-a000-000000000000/start',
            $config->pingUrl('00000000-0000-4000-a000-000000000000', 'start'),
        );
    }

    public function test_ping_url_rejects_invalid_uuid(): void
    {
        $config = new Configuration('https://cron-monitor.io');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid cron-monitor UUID');
        $config->pingUrl('not-a-uuid');
    }

    public function test_ping_url_rejects_dangerous_action_segment(): void
    {
        $config = new Configuration('https://cron-monitor.io');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid ping action');
        // Path traversal attempt — must be rejected before being concatenated.
        $config->pingUrl('00000000-0000-4000-a000-000000000000', '../admin');
    }
}
