<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Attribute;

use CronMonitor\Attribute\Monitor;
use PHPUnit\Framework\TestCase;

final class MonitorTest extends TestCase
{
    private const TEST_ENV_VAR = 'CRON_MONITOR_TEST_UUID_FOR_MONITOR_TEST';

    private const TEST_UUID = '88888888-8888-4888-8888-888888888888';

    protected function tearDown(): void
    {
        // Tests mutate the process environment; restore it so other
        // tests do not accidentally see leftover values. Cover all
        // three lookup paths the resolver checks.
        unset($_ENV[self::TEST_ENV_VAR], $_SERVER[self::TEST_ENV_VAR]);
        putenv(self::TEST_ENV_VAR);
    }

    public function test_uuid_form_constructs(): void
    {
        $monitor = new Monitor(uuid: self::TEST_UUID);

        self::assertSame(self::TEST_UUID, $monitor->uuid);
        self::assertNull($monitor->env);
    }

    public function test_env_form_constructs(): void
    {
        $monitor = new Monitor(env: self::TEST_ENV_VAR);

        self::assertNull($monitor->uuid);
        self::assertSame(self::TEST_ENV_VAR, $monitor->env);
    }

    public function test_constructor_rejects_both_uuid_and_env(): void
    {
        // Loud throw at attribute instantiation gives test suites a
        // chance to surface the misuse before it silently disables
        // monitoring in production.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one of uuid: or env: — got both');

        new Monitor(uuid: self::TEST_UUID, env: self::TEST_ENV_VAR);
    }

    public function test_constructor_rejects_neither_uuid_nor_env(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one of uuid: or env: — got neither');

        new Monitor();
    }

    public function test_resolve_uuid_returns_literal_when_uuid_form(): void
    {
        $monitor = new Monitor(uuid: self::TEST_UUID);

        self::assertSame(self::TEST_UUID, $monitor->resolveUuid());
    }

    public function test_resolve_uuid_returns_null_for_empty_uuid_literal(): void
    {
        // Mirrors the YAML / config-map empty-string-as-suppression
        // policy. Lets users deliberately disable monitoring per
        // environment without removing the attribute from source.
        $monitor = new Monitor(uuid: '');

        self::assertNull($monitor->resolveUuid());
    }

    public function test_resolve_uuid_reads_env_from_dollar_env_superglobal(): void
    {
        $_ENV[self::TEST_ENV_VAR] = self::TEST_UUID;

        $monitor = new Monitor(env: self::TEST_ENV_VAR);

        self::assertSame(self::TEST_UUID, $monitor->resolveUuid());
    }

    public function test_resolve_uuid_falls_back_to_dollar_server_when_env_missing(): void
    {
        // FPM and a few exotic SAPIs populate $_SERVER but not $_ENV
        // depending on `variables_order`. The fallback must cover that.
        unset($_ENV[self::TEST_ENV_VAR]);
        $_SERVER[self::TEST_ENV_VAR] = self::TEST_UUID;

        $monitor = new Monitor(env: self::TEST_ENV_VAR);

        self::assertSame(self::TEST_UUID, $monitor->resolveUuid());
    }

    public function test_resolve_uuid_falls_back_to_getenv_when_superglobals_missing(): void
    {
        // Container-injected env (Kubernetes, Docker `-e FOO=bar`) shows
        // up via `getenv()` even when `variables_order` excludes E and
        // strips the superglobals. Final fallback.
        unset($_ENV[self::TEST_ENV_VAR], $_SERVER[self::TEST_ENV_VAR]);
        putenv(self::TEST_ENV_VAR.'='.self::TEST_UUID);

        $monitor = new Monitor(env: self::TEST_ENV_VAR);

        self::assertSame(self::TEST_UUID, $monitor->resolveUuid());
    }

    public function test_resolve_uuid_returns_null_when_env_var_is_missing_everywhere(): void
    {
        unset($_ENV[self::TEST_ENV_VAR], $_SERVER[self::TEST_ENV_VAR]);
        putenv(self::TEST_ENV_VAR);

        $monitor = new Monitor(env: self::TEST_ENV_VAR);

        self::assertNull($monitor->resolveUuid());
    }

    public function test_resolve_uuid_returns_null_when_env_var_is_empty_string(): void
    {
        // Deliberate empty-string env var is the "do not monitor in
        // this environment" signal. The SDK must not try to use it as
        // a UUID and trigger the validator.
        $_ENV[self::TEST_ENV_VAR] = '';

        $monitor = new Monitor(env: self::TEST_ENV_VAR);

        self::assertNull($monitor->resolveUuid());
    }
}
