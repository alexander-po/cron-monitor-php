<?php

declare(strict_types=1);

namespace CronMonitor\Client;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
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
     * The server caps a ping's stored body excerpt at 10 000 bytes (the
     * backend's ping entity enforces it). This client-side cap mirrors that
     * exact value so we don't waste bandwidth on bytes the server would
     * silently truncate. See CLAUDE.md's wire-anchor table — change one side
     * and the other moves with it.
     */
    private const BODY_CAP_BYTES = 10000;

    private const USER_AGENT = 'cron-monitor-php-sdk/1.4';

    private const TRUNCATION_MARKER = "\n[truncated by SDK]";

    private const API_KEY_PATTERN = 'cmk_[A-Za-z0-9_-]+';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Zero-config factory for the common case: SaaS endpoint, no API key, and
     * a built-in cURL transport so the caller does not have to wire up
     * Guzzle / symfony/http-client to send a single ping.
     *
     *     CronMonitorClient::create()->success($uuid);
     *
     * Pass a `Configuration` for self-hosted endpoints, a custom timeout, or
     * an API key. PSR-17 factories come from the bundled `nyholm/psr7`.
     */
    public static function create(
        ?Configuration $configuration = null,
        ?LoggerInterface $logger = null,
    ): self {
        $configuration ??= Configuration::withDefaultEndpoint();
        $factory = new Psr17Factory();

        return new self(
            $configuration,
            new CurlPsr18Client($factory, $factory, $configuration->timeoutSeconds),
            $factory,
            $factory,
            $logger ?? new NullLogger(),
        );
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
            return $this->dispatch($monitorUuid, $action, $body);
        } catch (\Throwable $e) {
            // Last line of defence for the no-throw contract: PSR-18 sanctions
            // only ClientExceptionInterface and PSR-17 sanctions nothing at
            // all, but a host job must not die because a stack disagrees.
            $error = self::scrub($e->getMessage(), $monitorUuid);
            $this->log(LogLevel::WARNING, 'cron-monitor ping failed', [
                'monitor_uuid_hash' => self::hashUuid($monitorUuid),
                'action' => $action,
                'attempts' => 0,
                'last_status' => null,
                'last_error' => $error,
                // Fail-open means an SDK bug of our own never surfaces a stack
                // trace, so the class is the only thing distinguishing one from
                // a hostile transport.
                'error_class' => $e::class,
            ]);

            return PingResult::failed(null, $error, 0);
        }
    }

    private function dispatch(string $monitorUuid, ?string $action, ?string $body): PingResult
    {
        try {
            $url = $this->configuration->pingUrl($monitorUuid, $action);
        } catch (\InvalidArgumentException $e) {
            // A bad UUID is a programmer error, not a network error — log it
            // loudly but still return a result so the host job continues.
            $error = self::scrub($e->getMessage(), $monitorUuid);
            $this->log(LogLevel::ERROR, 'cron-monitor ping URL build failed', [
                'monitor_uuid_hash' => self::hashUuid($monitorUuid),
                'action' => $action,
                'error' => $error,
            ]);

            return PingResult::failed(null, $error, 0);
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
            } catch (\Throwable $e) {
                $lastError = self::scrub($e->getMessage(), $monitorUuid);
                $lastStatus = null;
            }
        }

        $this->log(LogLevel::WARNING, 'cron-monitor ping failed', [
            'monitor_uuid_hash' => self::hashUuid($monitorUuid),
            'action' => $action,
            'attempts' => $maxAttempts,
            'last_status' => $lastStatus,
            'last_error' => $lastError,
        ]);

        return PingResult::failed($lastStatus, $lastError ?? 'unknown error', $maxAttempts);
    }

    /**
     * Emit an observability record without letting the host's own logger
     * become the failure: a PSR-3 backend with a full disk or an unreachable
     * collector throws, and that throw would break the very job this SDK
     * exists to keep running.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
        }
    }

    /**
     * The monitor UUID is a bearer credential — on the ping endpoint it is the
     * only one — so it must never be written to the host's logs, which outlive
     * the job and travel wherever logs are shipped. The server logs this same
     * truncated SHA-256, so an SDK line and a server line still join on one
     * monitor during an incident.
     *
     * Lower-cased first, because the UUID is accepted case-insensitively while
     * the server stores one canonical spelling: without this, the same monitor
     * written two ways yields two unrelated keys and the join it exists for
     * silently stops working.
     */
    private static function hashUuid(string $monitorUuid): string
    {
        return substr(hash('sha256', strtolower($monitorUuid)), 0, 16);
    }

    /**
     * Remove credentials a third party has already embedded in text: PSR-18
     * clients routinely append the failing request URI to their own exception
     * messages, and a transport that echoes request headers would carry the
     * token too.
     *
     * The UUID pass runs only for a well-formed identifier — a short or empty
     * one would otherwise match all over the message and leave the operator an
     * unreadable diagnostic on precisely the misconfiguration path.
     */
    private static function scrub(string $message, string $monitorUuid): string
    {
        $scrubbed = preg_replace('/'.self::API_KEY_PATTERN.'/', '{api_key}', $message);
        if (!\is_string($scrubbed)) {
            $scrubbed = $message;
        }

        if (1 !== preg_match('/^'.Configuration::UUID_PATTERN.'$/i', $monitorUuid)) {
            return $scrubbed;
        }

        return str_ireplace($monitorUuid, self::hashUuid($monitorUuid), $scrubbed);
    }

    private function capBody(string $body): string
    {
        if (\strlen($body) <= self::BODY_CAP_BYTES) {
            return $body;
        }

        // A suffix marker tells operators where the truncation happened, so
        // the slice has to leave room for it and stay inside the cap.
        $slice = substr($body, 0, self::BODY_CAP_BYTES - \strlen(self::TRUNCATION_MARKER));

        return self::trimToCodePointBoundary($slice).self::TRUNCATION_MARKER;
    }

    /**
     * Drop a trailing UTF-8 sequence the byte cut left incomplete: `substr`
     * counts bytes, so a multi-byte character straddling the cap would go out
     * as a lone lead byte and make the whole excerpt invalid UTF-8.
     *
     * A body that was never valid UTF-8 — captured stderr is not guaranteed to
     * be text — comes back untouched, because the scan reads only the final
     * bytes and gives up after four.
     */
    private static function trimToCodePointBoundary(string $slice): string
    {
        $length = \strlen($slice);

        for ($back = 1; $back <= 4 && $back <= $length; ++$back) {
            $byte = \ord($slice[$length - $back]);
            if (0x80 === ($byte & 0xC0)) {
                continue;
            }

            $sequenceLength = match (true) {
                $byte < 0x80 => 1,
                0xC0 === ($byte & 0xE0) => 2,
                0xE0 === ($byte & 0xF0) => 3,
                0xF0 === ($byte & 0xF8) => 4,
                default => 1,
            };

            return $sequenceLength <= $back ? $slice : substr($slice, 0, $length - $back);
        }

        return $slice;
    }
}
