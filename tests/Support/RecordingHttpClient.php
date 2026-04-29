<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal PSR-18 stub that records every outgoing request and replays a
 * pre-set list of responses (or exceptions) in FIFO order.
 *
 * Kept in `tests/Support/` so that any future client subclass can grab it
 * without depending on a third-party PSR-18 mocking library — Guzzle's
 * MockHandler would also work, but it pulls the full Guzzle test surface
 * into a unit test that should stay HTTP-implementation-agnostic.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue;

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ([] === $this->queue) {
            throw new \LogicException('RecordingHttpClient queue is empty.');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            if (!$next instanceof ClientExceptionInterface) {
                throw new \LogicException('Queued throwable must implement ClientExceptionInterface.');
            }
            throw $next;
        }

        return $next;
    }
}
