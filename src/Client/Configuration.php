<?php

declare(strict_types=1);

namespace CronMonitor\Client;

/**
 * Immutable configuration value object for the SDK.
 *
 * The constructor enforces invariants that bite hardest in production:
 *  - non-empty endpoint (a misconfigured `null` here turns every ping into a
 *    silent network exception inside the user's scheduler);
 *  - HTTPS in production unless the caller explicitly opts into HTTP for a
 *    self-hosted instance behind a private VPC.
 *
 * Use `withDefaultEndpoint()` for the canonical SaaS install.
 */
final class Configuration
{
    public const DEFAULT_ENDPOINT = 'https://cronheart.com';

    public const DEFAULT_TIMEOUT_SECONDS = 5.0;

    /**
     * Per-ping retry budget. Pings are idempotent so retries are safe; we
     * default to a small budget because the scheduler context typically has
     * its own timeout (e.g. a Symfony Messenger middleware), and stacking
     * retries on top of that turns a single late job into a 30 s stall.
     */
    public const DEFAULT_RETRIES = 1;

    public function __construct(
        public readonly string $endpoint,
        public readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public readonly int $retries = self::DEFAULT_RETRIES,
        /**
         * Personal Access Token (`cmk_…`) for the authenticated management
         * API, carried as `Authorization: Bearer` by
         * {@see \CronMonitor\Api\MonitorApiClient} (list / fetch / create
         * monitors). The public `/ping/<uuid>` flow does not require it —
         * the per-monitor UUID is the only credential there — so anonymous
         * ping-only installs leave this null. Create a token in the
         * cronheart.com dashboard (Settings → API Tokens).
         */
        public readonly ?string $apiKey = null,
        /**
         * When `false`, the SDK will not allow plain-HTTP endpoints. The
         * default is `false` because every accidental HTTP misconfiguration
         * leaks the per-monitor UUID over the network in clear text. Self-
         * hosted users behind a VPN can flip it on with eyes open.
         */
        public readonly bool $allowInsecureEndpoint = false,
    ) {
        if ('' === trim($endpoint)) {
            throw new \InvalidArgumentException('Cron-monitor endpoint must be a non-empty string.');
        }

        $scheme = parse_url($endpoint, \PHP_URL_SCHEME);
        if (!\is_string($scheme) || !\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException(\sprintf('Cron-monitor endpoint must use http or https scheme, got %s.', var_export($scheme, true)));
        }

        if (!$allowInsecureEndpoint && 'https' !== $scheme) {
            throw new \InvalidArgumentException(\sprintf('Refusing to use plain HTTP endpoint %s. Pass allowInsecureEndpoint: true to override (self-hosted only).', $endpoint));
        }

        if ($timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('timeoutSeconds must be > 0.');
        }

        if ($retries < 0) {
            throw new \InvalidArgumentException('retries must be >= 0.');
        }
    }

    public static function withDefaultEndpoint(
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $retries = self::DEFAULT_RETRIES,
        ?string $apiKey = null,
    ): self {
        return new self(self::DEFAULT_ENDPOINT, $timeoutSeconds, $retries, $apiKey);
    }

    /**
     * Build the absolute URL for a `/ping/{uuid}[/{action}]` call. UUIDs are
     * validated before being concatenated to defend against accidental injection
     * (e.g. a monitor identifier being read from user-controlled config).
     */
    public function pingUrl(string $monitorUuid, ?string $action = null): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $monitorUuid)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid cron-monitor UUID.', $monitorUuid));
        }

        $base = rtrim($this->endpoint, '/').'/ping/'.$monitorUuid;
        if (null === $action) {
            return $base;
        }

        // Mirror the server's route requirement: `action: [a-zA-Z0-9_-]{1,16}`
        // (see `cron-monitor` backend `PingController::action()` route
        // attribute). A client-side reject is friendlier than a server 404 and
        // also defends against accidental path-injection if a future caller
        // ever read the action segment from user input.
        if (1 !== preg_match('/^[a-z0-9_-]{1,16}$/i', $action)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid ping action.', $action));
        }

        return $base.'/'.$action;
    }
}
