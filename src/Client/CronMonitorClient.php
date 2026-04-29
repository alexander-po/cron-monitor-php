<?php

declare(strict_types=1);

namespace CronMonitor\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Framework-agnostic ping client.
 *
 * Hot-path properties:
 *  - never throws on network or HTTP errors. The whole point of cron-monitor
 *    is to detect when scheduled jobs go silent; if our SDK threw and broke
 *    the user's job, the very class of failure we're meant to flag would be
 *    *caused* by us. All errors are swallowed into a logged warning + a
 *    `PingResult::failed(...)` return value.
 *  - body is bounded to 10 KB before sending. The server enforces a 10 KB
 *    cap server-side, but truncating client-side avoids paying egress cost
 *    on the inevitable enthusiastic user who tries to send 5 MB of stdout.
 *  - retries are bounded by `Configuration::retries`. Pings are idempotent
 *    on `(uuid, body_hash)` server-side, so retrying is safe.
 */
final class CronMonitorClient
{
    /**
     * Server enforces 10 KB on `body_excerpt`. Client-side cap mirrors that
     * to avoid spending bandwidth on bytes the server will throw away.
     */
    private const BODY_CAP_BYTES = 10240;

    private const USER_AGENT = 'cron-monitor-php-sdk/0.1';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Generic heartbeat: `POST /ping/{uuid}` with no action segment.
     * Use this for the simplest one-shot "I ran, I'm alive" cron jobs.
     */
    public function heartbeat(string $monitorUuid, ?string $body = null): PingResult
    {
        return $this->ping($monitorUuid, null, $body);
    }

    /**
     * Mark the start of a run. The server pairs `start` + `success`/`fail`
     * to compute job duration without the client tracking time.
     */
    public function start(string $monitorUuid): PingResult
    {
        return $this->ping($monitorUuid, 'start', null);
    }

    public function success(string $monitorUuid, ?string $body = null): PingResult
    {
        return $this->ping($monitorUuid, 'success', $body);
    }

    public function fail(string $monitorUuid, ?string $body = null): PingResult
    {
        return $this->ping($monitorUuid, 'fail', $body);
    }

    public function ping(string $monitorUuid, ?string $action, ?string $body): PingResult
    {
        try {
            $url = $this->configuration->pingUrl($monitorUuid, $action);
        } catch (\InvalidArgumentException $e) {
            // A bad UUID is a programmer error, not a network error — log it
            // loudly but still return a result so the host job continues.
            $this->logger->error('cron-monitor ping URL build failed', [
                'monitor_uuid' => $monitorUuid,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return PingResult::failed(null, $e->getMessage(), 0);
        }

        $payload = null === $body ? '' : $this->capBody($body);

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->streamFactory->createStream($payload));

        if (null !== $this->configuration->apiKey) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->configuration->apiKey);
        }

        $maxAttempts = $this->configuration->retries + 1;
        $lastError = null;
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                $response = $this->httpClient->sendRequest($request);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return PingResult::delivered($status, $attempt);
                }
                // 4xx is almost certainly a permanent error (bad UUID, plan
                // limits) — retrying will not help, so break out early.
                if ($status >= 400 && $status < 500) {
                    return PingResult::failed($status, \sprintf('HTTP %d', $status), $attempt);
                }
                $lastStatus = $status;
                $lastError = \sprintf('HTTP %d', $status);
            } catch (ClientExceptionInterface $e) {
                $lastError = $e->getMessage();
                $lastStatus = null;
            }
        }

        $this->logger->warning('cron-monitor ping failed', [
            'monitor_uuid' => $monitorUuid,
            'action' => $action,
            'attempts' => $maxAttempts,
            'last_status' => $lastStatus,
            'last_error' => $lastError,
        ]);

        return PingResult::failed($lastStatus, $lastError ?? 'unknown error', $maxAttempts);
    }

    private function capBody(string $body): string
    {
        if (\strlen($body) <= self::BODY_CAP_BYTES) {
            return $body;
        }

        // Cut on byte boundary; the server treats body as binary-safe text
        // and will not choke on a mid-multibyte split, but a short suffix
        // marker tells operators where the truncation happened. Marker is
        // 19 bytes ("\n[truncated by SDK]") — keep the total payload at
        // exactly BODY_CAP_BYTES so test assertions on size hold tightly.
        return substr($body, 0, self::BODY_CAP_BYTES - 19)."\n[truncated by SDK]";
    }
}
