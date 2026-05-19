<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

/**
 * Wraps `php -S 127.0.0.1:<port>` so curl-driven tests can round-trip
 * against a real HTTP listener without depending on a public service or a
 * third-party server library.
 *
 * Routing is delegated to `tests/Fixtures/curl_http_server.php`, which
 * echoes the parsed request back as JSON.
 */
final class LocalHttpServer
{
    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        public readonly int $port,
        private $process,
        private array $pipes,
    ) {
    }

    /**
     * Boot the server. Returns null when the environment cannot host it
     * (proc_open disabled, port allocation failure, slow CI runner) so
     * callers can `markTestSkipped` rather than fail the suite.
     */
    public static function start(): ?self
    {
        if (!\function_exists('proc_open')) {
            return null;
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!\is_resource($probe)) {
            return null;
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        if (false === $name) {
            return null;
        }
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $router = \dirname(__DIR__).'/Fixtures/curl_http_server.php';
        $cmd = [\PHP_BINARY, '-S', '127.0.0.1:'.$port, $router];
        $pipes = [];
        $process = @proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return null;
        }

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $cErrno, $cErr, 0.1);
            if (\is_resource($conn)) {
                fclose($conn);

                return new self($port, $process, $pipes);
            }
            usleep(50000);
        }

        // Server never opened — clean up and report failure so the caller
        // can skip the test gracefully.
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return null;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    public function stop(): void
    {
        if (!\is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process);
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($this->process);
    }
}
