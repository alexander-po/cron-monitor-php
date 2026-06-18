<?php

declare(strict_types=1);

namespace CronMonitor\Api;

use CronMonitor\Api\Dto\Account;
use CronMonitor\Api\Dto\Alert;
use CronMonitor\Api\Dto\AlertPage;
use CronMonitor\Api\Dto\Channel;
use CronMonitor\Api\Dto\ChannelPage;
use CronMonitor\Api\Dto\ChannelSecret;
use CronMonitor\Api\Dto\CreateChannelRequest;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorPage;
use CronMonitor\Api\Dto\Ping;
use CronMonitor\Api\Dto\PingPage;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Dto\TestChannelResult;
use CronMonitor\Api\Dto\UpdateMonitorRequest;
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
        $this->assertUuid($uuid);

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
     * Apply a partial update (`PATCH`) and return the updated monitor. The
     * UUID is validated locally and an empty patch is rejected before any
     * HTTP, mirroring {@see getMonitor}'s fail-fast. Retried: re-applying the
     * same field values is idempotent, so a replay after a dropped response
     * is safe.
     *
     * @throws ApiException
     */
    public function updateMonitor(string $uuid, UpdateMonitorRequest $request): Monitor
    {
        $this->assertUuid($uuid);
        if ($request->isEmpty()) {
            throw new \InvalidArgumentException('updateMonitor needs at least one field to change.');
        }

        $payload = $this->requestJson('PATCH', '/monitors/'.$uuid, $request->toArray(), true);

        return $this->hydrate(static fn (): Monitor => Monitor::fromArray($payload));
    }

    /**
     * Delete a monitor (the backend answers `204 No Content`). Retried:
     * deleting is idempotent in effect — the end state is "gone" either way.
     * The only observable difference on a replay after the server already
     * processed the first attempt is a `404` (surfaced as
     * {@see Exception\NotFoundException}) instead of a silent
     * success.
     *
     * @throws ApiException
     */
    public function deleteMonitor(string $uuid): void
    {
        $this->assertUuid($uuid);

        $this->requestNoContent('DELETE', '/monitors/'.$uuid, null, true);
    }

    /**
     * Pause a monitor (no alerts while paused) and return it. Retried:
     * pausing an already-paused monitor is a no-op, so a replay is safe.
     *
     * @throws ApiException
     */
    public function pauseMonitor(string $uuid): Monitor
    {
        return $this->transitionMonitor($uuid, '/pause', null);
    }

    /**
     * Resume a paused monitor and return it. Retried: resuming an already-
     * running monitor is a no-op, so a replay is safe.
     *
     * @throws ApiException
     */
    public function resumeMonitor(string $uuid): Monitor
    {
        return $this->transitionMonitor($uuid, '/resume', null);
    }

    /**
     * Snooze a monitor for a bounded duration and return it. Retried:
     * re-snoozing extends from "now" again, which is idempotent enough that a
     * replay after a dropped response is safe (it re-applies the same window).
     *
     * @throws ApiException
     */
    public function snoozeMonitor(string $uuid, SnoozeDuration $duration): Monitor
    {
        return $this->transitionMonitor($uuid, '/snooze', ['duration' => $duration->value]);
    }

    /**
     * Clear an active snooze and return the monitor. Retried: unsnoozing an
     * un-snoozed monitor is a no-op, so a replay is safe.
     *
     * @throws ApiException
     */
    public function unsnoozeMonitor(string $uuid): Monitor
    {
        return $this->transitionMonitor($uuid, '/unsnooze', null);
    }

    /**
     * Rotate the monitor's UUID, returning the monitor with its new UUID.
     *
     * **Never retried.** Rotation instantly invalidates the old ping URL with
     * no grace window and is not idempotent: a blind replay would either
     * rotate a second time or `422` because the echoed confirmation no longer
     * matches. The backend requires the caller to echo the current UUID as
     * `confirm`, which this method supplies.
     *
     * @throws ApiException
     */
    public function rotateMonitorUuid(string $uuid): Monitor
    {
        $this->assertUuid($uuid);

        $payload = $this->requestJson('POST', '/monitors/'.$uuid.'/rotate-uuid', ['confirm' => $uuid], false);

        return $this->hydrate(static fn (): Monitor => Monitor::fromArray($payload));
    }

    /**
     * One page of a monitor's ping history. Pings use opaque **cursor**
     * (keyset) pagination — pass the previous page's {@see PingPage::$nextCursor}
     * back as `$cursor`. `$limit` is clamped to [1, 100].
     *
     * @throws ApiException
     */
    public function listPings(string $uuid, int $limit = self::DEFAULT_LIST_LIMIT, ?string $cursor = null): PingPage
    {
        $this->assertUuid($uuid);
        $limit = max(1, min($limit, self::MAX_LIST_LIMIT));

        $query = ['limit' => $limit];
        if (null !== $cursor && '' !== $cursor) {
            $query['cursor'] = $cursor;
        }

        $payload = $this->requestJson('GET', '/monitors/'.$uuid.'/pings?'.http_build_query($query), null, true);

        return $this->hydrate(static fn (): PingPage => PingPage::fromArray($payload));
    }

    /**
     * Lazily walk a monitor's entire ping history across cursor pages. A
     * generator so deep history does not materialise in memory.
     *
     * The walk follows `next_cursor` until it is null. Two defenses bound a
     * non-conforming endpoint: a **cycle guard** throws
     * {@see ApiTransportException} if the server hands back the very cursor it
     * was just given (the keyset analogue of {@see allMonitors()}'s stale-
     * offset defense), and the {@see MAX_PAGES} cap stops a longer loop.
     *
     * @return iterable<Ping>
     *
     * @throws ApiException
     */
    public function allPings(string $uuid, int $pageSize = self::MAX_LIST_LIMIT): iterable
    {
        $pageSize = max(1, min($pageSize, self::MAX_LIST_LIMIT));
        $cursor = null;

        for ($requests = 0; $requests < self::MAX_PAGES; ++$requests) {
            $page = $this->listPings($uuid, $pageSize, $cursor);

            foreach ($page->data as $ping) {
                yield $ping;
            }

            if (null === $page->nextCursor) {
                return;
            }
            if ($page->nextCursor === $cursor) {
                throw new ApiTransportException('Ping pagination returned the same cursor it was given; the server may be returning an inconsistent listing.');
            }
            $cursor = $page->nextCursor;
        }

        throw new ApiTransportException(\sprintf('Ping pagination did not terminate within %d pages; the server may be returning an inconsistent listing.', self::MAX_PAGES));
    }

    /**
     * One page of a monitor's alert history. Alerts use offset pagination
     * exactly like {@see listMonitors()}; `$limit` is clamped to [1, 100] and a
     * negative `$offset` is a programmer error.
     *
     * @throws ApiException
     */
    public function listAlerts(string $uuid, int $offset = 0, int $limit = self::DEFAULT_LIST_LIMIT): AlertPage
    {
        $this->assertUuid($uuid);
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset must be >= 0.');
        }
        $limit = max(1, min($limit, self::MAX_LIST_LIMIT));

        $payload = $this->requestJson('GET', '/monitors/'.$uuid.'/alerts?'.http_build_query(['offset' => $offset, 'limit' => $limit]), null, true);

        return $this->hydrate(static fn (): AlertPage => AlertPage::fromArray($payload));
    }

    /**
     * Lazily walk a monitor's entire alert history across offset pages. The
     * termination and {@see MAX_PAGES} backstop mirror {@see allMonitors()}
     * verbatim: the walk stops on a short/empty page and is driven by the
     * locally-tracked offset, not the server-echoed one.
     *
     * @return iterable<Alert>
     *
     * @throws ApiException
     */
    public function allAlerts(string $uuid, int $pageSize = self::MAX_LIST_LIMIT): iterable
    {
        $pageSize = max(1, min($pageSize, self::MAX_LIST_LIMIT));
        $offset = 0;

        for ($requests = 0; $requests < self::MAX_PAGES; ++$requests) {
            $page = $this->listAlerts($uuid, $offset, $pageSize);
            $count = \count($page->data);

            foreach ($page->data as $alert) {
                yield $alert;
            }

            if ($count < $pageSize) {
                return;
            }
            $offset += $count;
        }

        throw new ApiTransportException(\sprintf('Alert pagination did not terminate within %d pages; the server may be returning an inconsistent listing.', self::MAX_PAGES));
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
     * Create a notification channel. Never retried — creates are not
     * idempotent, so a blind replay risks a duplicate channel.
     *
     * @throws ApiException
     */
    public function createChannel(CreateChannelRequest $request): Channel
    {
        $payload = $this->requestJson('POST', '/channels', $request->toArray(), false);

        return $this->hydrate(static fn (): Channel => Channel::fromArray($payload));
    }

    /**
     * Fetch a single channel by id. The masked `config` is returned verbatim
     * (secrets are redacted server-side).
     *
     * @throws ApiException
     */
    public function getChannel(int $id): Channel
    {
        $this->assertChannelId($id);

        $payload = $this->requestJson('GET', '/channels/'.$id, null, true);

        return $this->hydrate(static fn (): Channel => Channel::fromArray($payload));
    }

    /**
     * Rename a channel — the label is the only mutable field (the backend's
     * policy for a destination change is delete + create). Retried: re-applying
     * the same label is idempotent.
     *
     * @throws ApiException
     */
    public function updateChannel(int $id, string $label): Channel
    {
        $this->assertChannelId($id);
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Channel label must be a non-empty string.');
        }

        $payload = $this->requestJson('PATCH', '/channels/'.$id, ['label' => $label], true);

        return $this->hydrate(static fn (): Channel => Channel::fromArray($payload));
    }

    /**
     * Delete a channel (the backend answers `204 No Content`). Retried:
     * deleting is idempotent in effect; a replay after the server already
     * processed the first attempt surfaces as a `404`.
     *
     * @throws ApiException
     */
    public function deleteChannel(int $id): void
    {
        $this->assertChannelId($id);

        $this->requestNoContent('DELETE', '/channels/'.$id, null, true);
    }

    /**
     * Rotate a webhook channel's signing secret, returning the channel plus
     * the freshly-minted plaintext {@see ChannelSecret::$secret} — which the
     * backend reveals **once**. Capture it immediately.
     *
     * **Never retried.** Rotation is not idempotent: a replay would mint yet
     * another secret, and the first response (carrying the only copy of the
     * earlier secret) would be lost. Only webhook channels have a rotatable
     * secret; any other kind answers `422`.
     *
     * @throws ApiException
     */
    public function rotateChannelSecret(int $id): ChannelSecret
    {
        $this->assertChannelId($id);

        $payload = $this->requestJson('POST', '/channels/'.$id.'/rotate-secret', null, false);

        return $this->hydrate(static fn (): ChannelSecret => ChannelSecret::fromArray($payload));
    }

    /**
     * Send a test alert through a channel.
     *
     * **Never retried.** A test send has an external side effect (it actually
     * delivers) and consumes the per-user / per-IP test-send budget, so a
     * blind replay would double-send and burn rate budget. A well-formed
     * request whose downstream destination rejected the delivery answers
     * `502` ({@see Exception\UnexpectedResponseException}); an
     * unverified or transport-less channel answers `422`.
     *
     * @throws ApiException
     */
    public function testChannel(int $id): TestChannelResult
    {
        $this->assertChannelId($id);

        $payload = $this->requestJson('POST', '/channels/'.$id.'/test', null, false);

        return $this->hydrate(static fn (): TestChannelResult => TestChannelResult::fromArray($payload));
    }

    /**
     * The caller's account snapshot: plan, monitor budget, and live API
     * rate-limit standing in one read — so a client can surface "how close am
     * I to my limits?" without scraping rate-limit headers across requests.
     *
     * @throws ApiException
     */
    public function getAccount(): Account
    {
        $payload = $this->requestJson('GET', '/account', null, true);

        return $this->hydrate(static fn (): Account => Account::fromArray($payload));
    }

    /**
     * Shared path for the POST status transitions (pause / resume / snooze /
     * unsnooze): validate the UUID locally, POST to the sub-resource, and
     * hydrate the monitor the backend returns. All are retryable — each is an
     * idempotent transition whose replay is a no-op.
     *
     * @param array<string, mixed>|null $body
     *
     * @throws ApiException
     */
    private function transitionMonitor(string $uuid, string $subPath, ?array $body): Monitor
    {
        $this->assertUuid($uuid);

        $payload = $this->requestJson('POST', '/monitors/'.$uuid.$subPath, $body, true);

        return $this->hydrate(static fn (): Monitor => Monitor::fromArray($payload));
    }

    /**
     * Validate a monitor UUID locally before any HTTP request, giving a
     * friendly error instead of a server `404`.
     */
    private function assertUuid(string $uuid): void
    {
        if (1 !== preg_match(self::UUID_PATTERN, $uuid)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid monitor UUID.', $uuid));
        }
    }

    /**
     * Validate a channel id locally before any HTTP request — the backend
     * routes only match positive integers, so a non-positive id is a
     * programmer error worth catching with a friendly message.
     */
    private function assertChannelId(int $id): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Channel id must be a positive integer.');
        }
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
     * Send a request whose success carries no body — a `DELETE`'s `204 No
     * Content`. Mirrors {@see requestJson}'s non-2xx → {@see ExceptionFactory}
     * mapping but neither requires nor decodes a JSON body, so requestJson's
     * "must be a JSON object" invariant stays intact for the endpoints that do
     * return one.
     *
     * @param array<string, mixed>|null $body
     *
     * @throws ApiException
     */
    private function requestNoContent(string $method, string $path, ?array $body, bool $retryable): void
    {
        $response = $this->send($this->buildRequest($method, $path, $body), $retryable);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw ExceptionFactory::fromResponse($status, ProblemDetails::parse((string) $response->getBody(), $status), $this->retryAfterSeconds($response));
        }
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
