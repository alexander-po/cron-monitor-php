<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that breaks PSR-18's own contract: the throwable it raises
 * does not implement {@see \Psr\Http\Client\ClientExceptionInterface}.
 *
 * Deliberately non-conforming, and separate from {@see RecordingHttpClient}
 * (which enforces conformance) so that the transport misbehaviour under test
 * is visible in the test's setup rather than hidden in a shared helper. Real
 * stacks do this — a decorator whose own middleware lets a \RuntimeException
 * or a \TypeError escape — and the ping client has to survive it.
 */
final class ThrowingHttpClient implements ClientInterface
{
    public int $calls = 0;

    public function __construct(private readonly \Throwable $error)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        throw $this->error;
    }
}
