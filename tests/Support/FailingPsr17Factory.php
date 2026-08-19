<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-17 factory pair that throws from exactly one of its two entry points
 * and delegates the rest to the bundled nyholm/psr7 implementation.
 *
 * Message construction sits on the ping hot path just as much as the send
 * does, so a factory that throws has to fold into a failed result rather than
 * escape into the host job.
 */
final class FailingPsr17Factory implements RequestFactoryInterface, StreamFactoryInterface
{
    private readonly Psr17Factory $delegate;

    public function __construct(
        private readonly \Throwable $error,
        private readonly bool $failOnStream = false,
    ) {
        $this->delegate = new Psr17Factory();
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        if (!$this->failOnStream) {
            throw $this->error;
        }

        return $this->delegate->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        if ($this->failOnStream) {
            throw $this->error;
        }

        return $this->delegate->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->delegate->createStreamFromFile($filename, $mode);
    }

    /**
     * @param resource $resource
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->delegate->createStreamFromResource($resource);
    }
}
