<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Cli;

use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\TestCase;

/**
 * `vendor/bin/cron-monitor` has to work for someone who ran nothing but
 * `composer require cron-monitor/php-sdk`, which is what the README promises.
 *
 * So the CLI is exercised the way that user gets it: laid out under a
 * `vendor/cron-monitor/php-sdk/bin/` path, behind an autoloader that exposes
 * the package and its declared runtime dependencies and nothing else. Anything
 * the CLI reaches for beyond those — a dev-only HTTP client, say — fails here
 * exactly as it would fail in their cron job.
 */
final class CronMonitorCommandTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    private ?string $installRoot = null;

    protected function tearDown(): void
    {
        if (null !== $this->installRoot) {
            self::removeTree($this->installRoot);
            $this->installRoot = null;
        }
    }

    public function test_a_ping_succeeds_with_only_the_packages_composer_require_installs(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }
        $server = LocalHttpServer::start();
        if (null === $server) {
            self::markTestSkipped('Could not start the built-in PHP HTTP server.');
        }

        try {
            $cli = $this->installWithRuntimeDependenciesOnly();

            // Precondition: were the dev-only client reachable here, a CLI that
            // still required it would pass this test for the wrong reason.
            [$probeStatus, $probeOut] = self::execute([\PHP_BINARY, '-r', \sprintf(
                'require %s; var_export(class_exists(%s));',
                var_export(\dirname($cli, 4).'/autoload.php', true),
                var_export('GuzzleHttp\Client', true),
            )]);
            self::assertSame(0, $probeStatus);
            self::assertSame('false', $probeOut);

            [$status, $stdout, $stderr] = self::execute([
                \PHP_BINARY,
                $cli,
                'success',
                self::UUID,
                '--endpoint='.$server->baseUrl(),
                '--allow-insecure',
                '--body=done',
            ]);

            self::assertSame(0, $status, 'stderr: '.$stderr);
            self::assertStringStartsWith('ok success status=200', $stdout);
        } finally {
            $server->stop();
        }
    }

    public function test_usage_advertises_the_configured_default_endpoint(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }

        [$status, $stdout] = self::execute([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/cron-monitor', '--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString(Configuration::DEFAULT_ENDPOINT, $stdout);
    }

    public function test_signup_without_accepted_terms_names_the_documents_and_leaves_stdout_empty(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }

        $environment = getenv();
        unset($environment['CRON_MONITOR_ENDPOINT']);

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/cron-monitor', 'signup', 'you@example.com'], $environment);

        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString(Configuration::DEFAULT_ENDPOINT.'/terms', $stderr);
        self::assertStringContainsString(Configuration::DEFAULT_ENDPOINT.'/privacy', $stderr);
    }

    public function test_signup_refuses_a_plain_http_endpoint(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/cron-monitor', 'signup', 'you@example.com', '--accept-terms', '--endpoint=http://127.0.0.1:9']);

        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('Configuration error: Refusing to sign up over plain HTTP endpoint', $stderr);
    }

    public function test_signup_usage_errors_leave_stdout_empty_for_the_env_file(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }
        $cli = \dirname(__DIR__, 2).'/bin/cron-monitor';

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, $cli, 'signup']);
        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('Missing email address.', $stderr);
        self::assertStringContainsString('cron-monitor signup', $stderr);

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, $cli, 'signup', 'you@example.com', '--accept-terms', '--api-key=cmk_placeholder']);
        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('Unknown argument: --api-key={api_key}', $stderr);
        self::assertStringNotContainsString('cmk_placeholder', $stderr);

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, $cli, 'sigup', 'you@example.com']);
        self::assertSame(64, $status);
        self::assertSame('', $stdout, 'a mistyped subcommand appended to an env file must add nothing');
        self::assertStringStartsWith('Unknown command: sigup', $stderr);
    }

    public function test_php_diagnostics_while_loading_go_to_stderr(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }
        $cli = $this->installWithRuntimeDependenciesOnly();
        $autoload = \dirname($cli, 4).'/autoload.php';
        $loader = file_get_contents($autoload);
        self::assertIsString($loader);
        self::assertNotFalse(file_put_contents($autoload, str_replace('<?php ', '<?php trigger_error("load-time deprecation", E_USER_DEPRECATED); ', $loader)));

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-d', 'error_reporting=-1', $cli, 'signup', 'you@example.com']);

        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('load-time deprecation', $stderr);

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'error_reporting=-1', $cli, 'signup', 'you@example.com']);

        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringNotContainsString('load-time deprecation', $stderr, 'display that was off stays off');
    }

    public function test_echoed_arguments_are_redacted_and_usage_errors_stay_off_stdout(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }
        $cli = \dirname(__DIR__, 2).'/bin/cron-monitor';

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, $cli, 'cmk_placeholder']);
        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('Unknown command: {api_key}', $stderr);

        [$status, $stdout, $stderr] = self::execute([\PHP_BINARY, $cli, 'heartbeat', self::UUID, '--api_key=cmk_placeholder']);
        self::assertSame(64, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('Unknown argument: --api_key={api_key}', $stderr);

        [$status, $stdout] = self::execute([\PHP_BINARY, $cli]);
        self::assertSame(64, $status);
        self::assertSame('', $stdout);
    }

    public function test_a_signup_that_cannot_connect_leaves_stdout_empty_with_only_the_runtime_dependencies(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled in this environment.');
        }

        [$status, $stdout, $stderr] = self::execute([
            \PHP_BINARY,
            $this->installWithRuntimeDependenciesOnly(),
            'signup',
            'you@example.com',
            '--accept-terms',
            '--endpoint=https://127.0.0.1:9',
        ]);

        self::assertSame(2, $status, 'stderr: '.$stderr);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('The signup did not start:', $stderr);
    }

    /**
     * Lay the package out as Composer would, behind an autoloader carrying
     * only what `composer.json` requires at runtime.
     */
    private function installWithRuntimeDependenciesOnly(): string
    {
        $repo = \dirname(__DIR__, 2);
        $root = sys_get_temp_dir().'/cron-monitor-cli-'.bin2hex(random_bytes(6));
        $this->installRoot = $root;

        $packageBin = $root.'/vendor/cron-monitor/php-sdk/bin';
        self::assertTrue(mkdir($packageBin, 0o777, true));
        self::assertTrue(copy($repo.'/bin/cron-monitor', $packageBin.'/cron-monitor'));

        // Every `require` from composer.json, and nothing else. Two packages
        // share the `Psr\Http\Message\` prefix, so the map is a list rather
        // than a dictionary and each candidate directory is tried in turn.
        $prefixes = [
            ['CronMonitor\\', $repo.'/src/'],
            ['Psr\\Http\\Message\\', $repo.'/vendor/psr/http-message/src/'],
            ['Psr\\Http\\Message\\', $repo.'/vendor/psr/http-factory/src/'],
            ['Psr\\Http\\Client\\', $repo.'/vendor/psr/http-client/src/'],
            ['Psr\\Log\\', $repo.'/vendor/psr/log/src/'],
            ['Psr\\Clock\\', $repo.'/vendor/psr/clock/src/'],
            ['Nyholm\\Psr7\\', $repo.'/vendor/nyholm/psr7/src/'],
        ];
        $autoloader = '<?php $prefixes = '.var_export($prefixes, true).';'
            .'spl_autoload_register(static function (string $class) use ($prefixes): void {'
            .'    foreach ($prefixes as [$prefix, $dir]) {'
            .'        if (!str_starts_with($class, $prefix)) { continue; }'
            .'        $file = $dir.str_replace("\\\\", "/", substr($class, strlen($prefix))).".php";'
            .'        if (is_file($file)) { require $file; return; }'
            .'    }'
            .'});';
        self::assertNotFalse(file_put_contents($root.'/vendor/autoload.php', $autoloader));
        self::assertEveryRuntimeDependencyIsReachable($repo, $prefixes);

        return $packageBin.'/cron-monitor';
    }

    /**
     * A dependency added to composer.json but not to the map above would fail
     * this harness with a class-not-found, which reads as a CLI bug rather
     * than a stale fixture. Fail on the real cause instead.
     *
     * @param list<array{string, string}> $prefixes
     */
    private static function assertEveryRuntimeDependencyIsReachable(string $repo, array $prefixes): void
    {
        $raw = file_get_contents($repo.'/composer.json');
        self::assertIsString($raw);
        $composer = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $require = $composer['require'] ?? [];
        self::assertIsArray($require);

        $mapped = array_column($prefixes, 1);
        foreach (array_keys($require) as $package) {
            $package = (string) $package;
            if ('php' === $package || str_starts_with($package, 'ext-')) {
                continue;
            }
            self::assertContains(
                $repo.'/vendor/'.$package.'/src/',
                $mapped,
                \sprintf('%s is a runtime dependency but the CLI test autoloader does not expose it.', $package),
            );
        }
    }

    /**
     * @param list<string>               $command
     * @param array<string, string>|null $environment null inherits this process's environment
     *
     * @return array{int, string, string}
     */
    private static function execute(array $command, ?array $environment = null): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
