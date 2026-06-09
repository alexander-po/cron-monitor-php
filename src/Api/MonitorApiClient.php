<?php

declare(strict_types=1);

namespace CronMonitor\Api;

use CronMonitor\Api\Dto\ChannelPage;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorPage;
use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\Internal\ExceptionFactory;
use CronMonitor\Api\Internal\ProblemDetails;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CurlPsr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Authenticated client for the cronheart.com management API
 * (`/api/v1/...`): list / fetch / create monitors and list notification
 * channels.
 *
 * This is the deliberate counterpart to {@see \CronMonitor\Client\CronMonitorClient}.
 * The ping client never throws so it can never break a host cron job; this
 * client **throws** typed {@see ApiException}s, because it runs in admin /
 * CLI contexts where the caller wants to know — and act — when something
 * fails. Authentication is the Personal Access Token (`cmk_...`) carried in
 * {@see Configuration::$apiKey} and sent as `Authorization: Bearer`.
 *
 * It reuses the same PSR-18 transport, PSR-17 factories and `Configuration`
 * as the ping client, so a host application that already wired those gets
 * the API client for free.
 */
final class MonitorApiClient
{
    private const USER_AGENT = 'cron-monitor-php-sdk/1.0';

    private const API_PREFIX = '/api/v1';

    private const DEFAULT_LIST_LIMIT = 50;

    private const MAX_LIST_LIMIT = 100;

    /**
     * Hard cap on the number of page requests {@see allMonitors()} will
     * make before giving up. At the max page size of 100 this is 1,000,000
     * monitors — far beyond any real account — so it never truncates a
     * legitimate listing; it exists only to bound a non-conforming endpoint
     * that never returns a short/empty page.
     */
    private const MAX_PAGES = 10000;

    /**
     * Canonical UUID v4 shape. Intentionally a private copy of the literal
     * in {@see Configuration::pingUrl()} rather than a shared constant: this
     * keeps the API layer from touching the ping client's `Configuration`
     * at all. If the canonical pattern ever changes, change both.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Zero-config factory mirroring {@see \CronMonitor\Client\CronMonitorClient::create()}:
     * SaaS endpoint + bundled cURL transport + nyholm PSR-17 factories.
     *
     *     MonitorApiClient::create(
     *         Configuration::withDefaultEndpoint(apiKey: 'cmk_...')
     *     )->listMonitors();
     *
     * An API token is required for every call — pass a `Configuration` whose
     * `apiKey` is set, or the backend will answer `401`.
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
     * One page of the caller's monitors. `$limit` is clamped to [1, 100]
     * (the backend's maximum); a negative `$offset` is a programmer error.
     *
     * @throws ApiException
     */
    public function listMonitors(int $offset = 0, int $limit = self::DEFAULT_LIST_LIMIT): MonitorPage
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset must be >= 0.');
        }
        $limit = max(1, min($limit, self::MAX_LIST_LIMIT));

        $payload = $this->requestJson('GET', '/monitors?'.http_build_query(['offset' => $offset, 'limit' => $limit]), null, true);

        return $this->hydrate(static fn (): MonitorPage => MonitorPage::fromArray($payload));
    }

    /**
     * Fetch a single monitor by UUID. The UUID is validated locally before
     * any HTTP request to give a friendly error instead of a server `404`.
     *
     * @throws ApiException
     */
    public function getMonitor(string $uuid): Monitor
    {
        if (1 !== preg_match(self::UUID_PATTERN, $uuid)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid monitor UUID.', $uuid));
        }

        $payload = $this->requestJson('GET', '/monitors/'.$uuid, null, true);

        return $this->hydrate(static fn (): Monitor => Monitor::fromArray($payload));
    }

    /**
     * Create a monitor. Never retried — creates are not idempotent and the
     * wire contract has no idempotency key, so a retry risks a duplicate.
     *
     * @throws ApiException
     */
    public function createMonitor(CreateMonitorRequest $request): Monitor
    {
        $payload = $this->requestJson('POST', '/monitors', $request->toArray(), false);

        return $this->hydrate(static fn (): Monitor => Monitor::fromArray($payload));
    }

    /**
     * Lazily walk every monitor across all pages. A generator so a large
     * account does not materialise in memory.
     *
     * Termination is driven by the locally-tracked offset and the page
     * size — NOT by the server-echoed offset in {@see MonitorPage::hasMore()}.
     * The walk stops as soon as a page comes back short (fewer than
     * `$pageSize` rows — the canonical last-page signal) or empty, so a
     * backend that echoes a stale offset or reports an inflated `total`
     * cannot keep it looping. As a final backstop against an endpoint that
     * never returns a short page, the walk is capped at {@see MAX_PAGES}
     * requests; exceeding it throws {@see ApiTransportException} rather than
     * spinning forever (and never silently truncates a realistic account —
     * the cap is 1,000,000 monitors).
     *
     * @return iterable<Monitor>
     *
     * @throws ApiException
     */
    public function allMonitors(int $pageSize = self::MAX_LIST_LIMIT): iterable
    {
        $pageSize = max(1, min($pageSize, self::MAX_LIST_LIMIT));
        $offset = 0;

        for ($requests = 0; $requests < self::MAX_PAGES; ++$requests) {
            $page = $this->listMonitors($offset, $pageSize);
            $count = \count($page->data);

            foreach ($page->data as $monitor) {
                yield $monitor;
            }

            if ($count < $pageSize) {
                return;
            }
            $offset += $count;
        }

        throw new ApiTransportException(\sprintf('Monitor pagination did not terminate within %d pages; the server may be returning an inconsistent listing.', self::MAX_PAGES));
    }

    /**
     * List the caller's notification channels (the channel ids feed
     * {@see CreateMonitorRequest::$channelIds}).
     *
     * @throws ApiException
     */
    public function listChannels(): ChannelPage
    {
        $payload = $this->requestJson('GET', '/channels', null, true);

        return $this->hydrate(static fn (): ChannelPage => ChannelPage::fromArray($payload));
    }

    /**
     * Send a JSON request and return the decoded object body, or throw the
     * mapped {@see ApiException} on any non-2xx / transport / decode failure.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function requestJson(string $method, string $path, ?array $body, bool $retryable): array
    {
        $response = $this->send($this->buildRequest($method, $path, $body), $retryable);
        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw ExceptionFactory::fromResponse($status, ProblemDetails::parse($rawBody, $status), $this->retryAfterSeconds($response));
        }

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiTransportException(\sprintf('Could not decode the API response as JSON (HTTP %d).', $status), $status, null, null, $e);
        }

        if (!\is_array($decoded)) {
            throw new ApiTransportException(\sprintf('Expected a JSON object from the API (HTTP %d).', $status), $status);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @throws ApiTransportException when the request body cannot be JSON-encoded
     */
    private function buildRequest(string $method, string $path, ?array $body): RequestInterface
    {
        $url = rtrim($this->configuration->endpoint, '/').self::API_PREFIX.$path;

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withHeader('Accept', 'application/json');

        if (null !== $this->configuration->apiKey) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->configuration->apiKey);
        }

        if (null !== $body) {
            // JSON_THROW_ON_ERROR can fire here — most plausibly when a
            // caller-supplied string (a monitor name / schedule_expr read
            // from a filename, a legacy-encoded DB column, request input)
            // contains invalid UTF-8. Re-wrap as an ApiException so the
            // documented "callers only catch ApiException" contract holds;
            // deliberately do NOT echo $body into the message (it carries
            // the monitor name).
            try {
                $json = json_encode($body, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ApiTransportException('Could not encode the request body as JSON; a string field (e.g. the monitor name or schedule) may contain invalid UTF-8.', null, null, null, $e);
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        return $request;
    }

    /**
     * Send with a bounded retry budget. Idempotent GETs retry on transport
     * failure and `5xx` within `Configuration::retries`; non-retryable
     * requests (POST creates) get exactly one attempt. `4xx` and `2xx`
     * always return immediately for the caller to map. `429` is returned
     * as-is (not retried) so the caller can read `Retry-After`.
     *
     * @throws ApiTransportException when every attempt fails at the transport level
     */
    private function send(RequestInterface $request, bool $retryable): ResponseInterface
    {
        $maxAttempts = $retryable ? $this->configuration->retries + 1 : 1;
        $lastTransport = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $lastTransport = $e;

                continue;
            }

            if ($retryable && $response->getStatusCode() >= 500 && $attempt < $maxAttempts) {
                continue;
            }

            return $response;
        }

        $this->logger->warning('cron-monitor API request failed at transport level', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'attempts' => $maxAttempts,
        ]);

        throw new ApiTransportException(\sprintf('cron-monitor API request to %s failed: %s', (string) $request->getUri(), null !== $lastTransport ? $lastTransport->getMessage() : 'unknown transport error'), null, null, null, $lastTransport);
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ('' !== $value && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Run a DTO hydrator, converting a malformed-response
     * {@see \UnexpectedValueException} into an {@see ApiTransportException}
     * so callers only ever have to catch {@see ApiException}.
     *
     * @template T of object
     *
     * @param \Closure(): T $hydrator
     *
     * @return T
     *
     * @throws ApiTransportException
     */
    private function hydrate(\Closure $hydrator): object
    {
        try {
            return $hydrator();
        } catch (\UnexpectedValueException $e) {
            throw new ApiTransportException('The API returned a response the SDK could not interpret: '.$e->getMessage(), null, null, null, $e);
        }
    }
}
