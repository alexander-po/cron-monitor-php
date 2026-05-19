<?php

declare(strict_types=1);

namespace CronMonitor\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Zero-dependency PSR-18 client backed by ext-curl.
 *
 * Exists so that `composer require cron-monitor/php-sdk` is the only step a
 * plain-PHP cron job needs — without forcing the user to also pull in Guzzle
 * or symfony/http-client just to send a ~50-byte ping. PSR-17 message
 * factories are supplied by the bundled `nyholm/psr7` hard dependency.
 *
 * Scope: intentionally minimal. The SDK only sends `POST /ping/{uuid}` with a
 * short text body, so this client targets that profile — no redirect handling,
 * no cookies, no streaming uploads, no proxy auth. If your app needs a full
 * PSR-18 implementation, use Guzzle or symfony/http-client and pass it to
 * `CronMonitorClient` directly.
 *
 * Failure mode: a libcurl transport error becomes a `CurlException`
 * (`NetworkExceptionInterface`), which `CronMonitorClient::ping()` catches and
 * folds into `PingResult::failed(...)`. The host job is never broken.
 */
final class CurlPsr18Client implements ClientInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly float $timeoutSeconds = Configuration::DEFAULT_TIMEOUT_SECONDS,
    ) {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('CurlPsr18Client requires the curl PHP extension.');
        }

        if ($timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('timeoutSeconds must be > 0.');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();
        if (false === $handle) {
            throw new CurlException('curl_init() failed.', $request);
        }

        $responseHeaders = [];
        $timeoutMs = (int) ($this->timeoutSeconds * 1000);

        $options = [
            \CURLOPT_URL => (string) $request->getUri(),
            \CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            \CURLOPT_HTTPHEADER => $this->flattenHeaders($request),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER => false,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            \CURLOPT_TIMEOUT_MS => $timeoutMs,
            \CURLOPT_HEADERFUNCTION => static function ($_handle, string $headerLine) use (&$responseHeaders): int {
                $length = \strlen($headerLine);
                $trimmed = trim($headerLine);
                if ('' === $trimmed || !str_contains($trimmed, ':')) {
                    return $length;
                }
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[$name][] = ltrim($value);

                return $length;
            },
        ];

        $body = (string) $request->getBody();
        if ('' !== $body) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if (!\is_string($responseBody)) {
            $message = \sprintf('cURL error (%d): %s', curl_errno($handle), curl_error($handle));
            curl_close($handle);
            throw new CurlException($message, $request);
        }

        $statusCode = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $response = $this->responseFactory->createResponse($statusCode);
        foreach ($responseHeaders as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response->withBody($this->streamFactory->createStream($responseBody));
    }

    /**
     * @return list<string>
     */
    private function flattenHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name.': '.$value;
            }
        }

        return $headers;
    }
}
