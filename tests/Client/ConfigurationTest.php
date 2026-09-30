<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Client;

use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Tests\Support\SecretTraceAssertions;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    use SecretTraceAssertions;

    private const API_KEY = 'cmk_Qx7Tr4Lm9Vb2Nc6Hp1Zw';
    private const UUID = '0e9a3f5c-7b21-4d8e-9c6a-2f4b8d1e7a35';

    public function test_default_endpoint_is_https_and_pointed_at_saas(): void
    {
        $config = Configuration::withDefaultEndpoint();

        self::assertSame('https://cronheart.com', $config->endpoint);
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
        new Configuration('https://cronheart.com', timeoutSeconds: 0.0);
    }

    public function test_constructor_rejects_negative_retries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration('https://cronheart.com', retries: -1);
    }

    public function test_constructor_rejects_api_key_with_control_characters(): void
    {
        // A CR/LF in the token would make the PSR-7 Authorization header throw
        // inside a ping — fail fast at construction instead, where a throw is
        // expected, so it can never break a running cron job.
        $this->expectException(\InvalidArgumentException::class);
        new Configuration('https://cronheart.com', apiKey: "cmk_secret\nX-Injected: 1");
    }

    public function test_constructor_accepts_a_clean_api_key(): void
    {
        $config = new Configuration('https://cronheart.com', apiKey: 'cmk_clean_token_value');
        self::assertSame('cmk_clean_token_value', $config->apiKey);
    }

    public function test_ping_url_strips_trailing_slash_from_endpoint(): void
    {
        $config = new Configuration('https://cronheart.com/');
        self::assertSame(
            'https://cronheart.com/ping/00000000-0000-4000-a000-000000000000',
            $config->pingUrl('00000000-0000-4000-a000-000000000000'),
        );
    }

    public function test_ping_url_rejects_invalid_uuid(): void
    {
        $config = new Configuration('https://cronheart.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid cron-monitor UUID');
        $config->pingUrl('not-a-uuid');
    }

    public function test_ping_url_rejects_a_uuid_with_a_trailing_newline(): void
    {
        $config = new Configuration('https://cronheart.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid cron-monitor UUID');
        $config->pingUrl("00000000-0000-4000-a000-000000000000\n");
    }

    public function test_ping_url_rejects_dangerous_action_segment(): void
    {
        $config = new Configuration('https://cronheart.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The ping action is not valid');
        // Path traversal attempt — must be rejected before being concatenated.
        $config->pingUrl('00000000-0000-4000-a000-000000000000', '../admin');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedActions(): iterable
    {
        foreach (['run', 'RUN', 'start', 'Start', 'success', 'SUCCESS', 'ok', 'OK', 'fail', 'Fail'] as $action) {
            yield $action => [$action];
        }
        yield 'exit code 0' => ['0'];
        yield 'exit code 137' => ['137'];
        yield 'sixteen digits' => ['1234567890123456'];
    }

    #[DataProvider('storedActions')]
    public function test_ping_url_accepts_every_action_the_service_stores(string $action): void
    {
        $config = new Configuration('https://cronheart.com');

        self::assertSame(
            'https://cronheart.com/ping/00000000-0000-4000-a000-000000000000/'.$action,
            $config->pingUrl('00000000-0000-4000-a000-000000000000', $action),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unstoredActions(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown word' => ['foo'];
        yield 'near miss' => ['failed'];
        yield 'heartbeat is the bare URL' => ['heartbeat'];
        yield 'suffixed' => ['start_'];
        yield 'negative exit code' => ['-1'];
        yield 'seventeen digits' => ['12345678901234567'];
        yield 'trailing newline' => ["start\n"];
        yield 'exit code with a trailing newline' => ["0\n"];
    }

    #[DataProvider('unstoredActions')]
    public function test_ping_url_rejects_an_action_the_service_does_not_store(string $action): void
    {
        $config = new Configuration('https://cronheart.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The ping action is not valid');
        $config->pingUrl('00000000-0000-4000-a000-000000000000', $action);
    }

    public function test_a_rejected_ping_action_is_not_echoed(): void
    {
        $config = new Configuration('https://cronheart.com');

        try {
            $config->pingUrl('00000000-0000-4000-a000-000000000000', self::UUID);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The ping action is not valid (expected run, start, success, ok or fail, case-insensitive, or 1 to 16 digits such as an exit code).', $e->getMessage());
        }
    }

    public function test_constructor_rejects_an_api_key_over_plain_http(): void
    {
        // The insecure escape hatch exists for anonymous ping-only self-hosted
        // installs, where the per-monitor UUID is the only credential on the
        // wire. A `cmk_...` token is account-wide, and putting one in cleartext
        // is a different order of mistake — so the two may not be combined.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key');

        new Configuration('http://cron.internal', apiKey: 'cmk_live_token', allowInsecureEndpoint: true);
    }

    public function test_constructor_accepts_plain_http_without_an_api_key(): void
    {
        $config = new Configuration('http://cron.internal', allowInsecureEndpoint: true);

        self::assertNull($config->apiKey);
    }

    public function test_constructor_accepts_an_api_key_over_https_with_the_insecure_flag_set(): void
    {
        $config = new Configuration('https://cron.internal', apiKey: 'cmk_live_token', allowInsecureEndpoint: true);

        self::assertSame('cmk_live_token', $config->apiKey);
    }

    public function test_a_rejected_api_key_stays_out_of_trace_arguments(): void
    {
        $this->assertSecretStaysOutOfTraces(self::API_KEY, static fn () => new Configuration('http://self-hosted.lan', apiKey: self::API_KEY, allowInsecureEndpoint: true), \InvalidArgumentException::class);
        $this->assertSecretStaysOutOfTraces(self::API_KEY, static fn () => Configuration::withDefaultEndpoint(apiKey: self::API_KEY."\n"), \InvalidArgumentException::class);
    }

    public function test_a_rejected_ping_action_keeps_the_monitor_uuid_out_of_trace_arguments(): void
    {
        $configuration = Configuration::withDefaultEndpoint();

        $this->assertSecretStaysOutOfTraces(self::UUID, static fn () => $configuration->pingUrl(self::UUID, 'not an action'), \InvalidArgumentException::class);
    }

    public function test_a_client_that_cannot_be_built_keeps_the_api_key_out_of_trace_arguments(): void
    {
        $configuration = new Configuration('https://cronheart.com', apiKey: self::API_KEY);
        $factory = new Psr17Factory();
        $notAClient = self::misWiredDependency();

        $builds = [
            static fn () => new MonitorApiClient($configuration, $notAClient, $factory, $factory),
            static fn () => MonitorApiClient::create($configuration, $notAClient),
            static fn () => new CronMonitorClient($configuration, $notAClient, $factory, $factory),
            static fn () => CronMonitorClient::create($configuration, $notAClient),
        ];

        foreach ($builds as $build) {
            $this->assertSecretStaysOutOfTraces(self::API_KEY, $build, \TypeError::class);
        }
        self::assertStringNotContainsString(self::API_KEY, print_r(MonitorApiClient::create($configuration), true));
        self::assertStringNotContainsString(self::API_KEY, print_r(CronMonitorClient::create($configuration), true));
    }
}
