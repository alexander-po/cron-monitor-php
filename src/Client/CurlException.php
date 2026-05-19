<?php

declare(strict_types=1);

namespace CronMonitor\Client;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Wraps a libcurl transport failure as a PSR-18 network exception.
 *
 * `CronMonitorClient::ping()` catches `ClientExceptionInterface` and converts
 * the failure into a `PingResult::failed(...)` — so this exception is the
 * bridge from "curl_exec returned false" to the SDK's no-throw contract.
 */
final class CurlException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
