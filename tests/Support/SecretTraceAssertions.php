<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Stack-frame arguments are what an error tracker reads when PHP records
 * them (`zend.exception_ignore_args=0`, PHP's development default), so a
 * secret must reach no frame of the whole exception chain, nor an SDK frame
 * of a backtrace a logger records.
 */
trait SecretTraceAssertions
{
    /**
     * @template T of \Throwable
     *
     * @param \Closure(): mixed $call
     * @param class-string<T>   $expected
     *
     * @return T
     */
    private function assertSecretStaysOutOfTraces(#[\SensitiveParameter] string $secret, \Closure $call, string $expected): \Throwable
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $call();
            self::fail(\sprintf('Expected %s.', $expected));
        } catch (\Throwable $e) {
            if (!$e instanceof $expected) {
                throw $e;
            }
            for ($link = $e; null !== $link; $link = $link->getPrevious()) {
                self::assertStringNotContainsString($secret, self::argumentsBelowTheTest($link));
            }
            self::assertStringNotContainsString(substr($secret, 0, 12), (string) $e);

            return $e;
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    /**
     * @param list<string> $mustInclude frames the path runs through, so a record
     *                                  that no longer reaches one fails rather
     *                                  than passing unchecked
     */
    private static function assertSecretStaysOutOfLoggedFrames(string $secret, BacktraceRecordingLogger $logger, array $mustInclude): void
    {
        self::assertNotSame([], $logger->records, 'nothing was logged, so the check would be vacuous');
        $checked = [];
        foreach ($logger->records as ['sdkFrames' => $frames]) {
            foreach ($frames as ['frame' => $frame, 'arguments' => $arguments]) {
                self::assertNotNull($arguments, 'frame arguments are not recorded, so the check would be vacuous');
                self::assertStringNotContainsStringIgnoringCase($secret, $arguments, $frame);
                $checked[$frame] = true;
            }
        }
        foreach ($mustInclude as $frame) {
            self::assertArrayHasKey($frame, $checked, 'the path no longer logs from inside '.$frame);
        }
    }

    private static function clientThatFailsEveryPing(LoggerInterface $logger): CronMonitorClient
    {
        $factory = new HttpFactory();
        $unreachable = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        return new CronMonitorClient(new Configuration('https://cronheart.com', retries: 0), $unreachable, $factory, $factory, $logger);
    }

    /**
     * `print_r()` of the exception the call throws, recorded without frame
     * arguments, so the dump shows only what the objects themselves carry.
     *
     * @param \Closure(): mixed        $call
     * @param class-string<\Throwable> $expected
     */
    private static function printedWithoutFrameArguments(\Closure $call, string $expected): string
    {
        $previous = ini_set('zend.exception_ignore_args', '1');
        try {
            $call();
        } catch (\Throwable $e) {
            if (!$e instanceof $expected) {
                throw $e;
            }

            return print_r($e, true);
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '0' : $previous);
        }

        self::fail(\sprintf('Expected %s.', $expected));
    }

    /**
     * The arguments of every frame between the throw and the test, built-in
     * functions included. Frames of any class under the tests' namespace, and
     * PHPUnit's, hold the fixtures, so the walk stops at the first one: a test
     * double that throws from inside the SDK's call must live outside it.
     */
    private static function argumentsBelowTheTest(\Throwable $e): string
    {
        $arguments = [];
        foreach ($e->getTrace() as $frame) {
            if (1 === preg_match('/^(CronMonitor\\\\Tests|PHPUnit)\\\\/', (string) ($frame['class'] ?? ''))) {
                break;
            }
            self::assertArrayHasKey('args', $frame, 'frame arguments are not recorded, so the check would be vacuous');
            $arguments[] = [($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'] => $frame['args'] ?? []];
        }
        self::assertNotSame([], $arguments, 'no frame below the test, so the check would be vacuous');

        return print_r($arguments, true);
    }
}
