<?php

declare(strict_types=1);

namespace CronMonitor\Attribute;

/**
 * Marks a command class (Symfony Console / Laravel Artisan) as a
 * cron-monitor target, without forcing the user to maintain a parallel
 * `command-name → uuid` map in YAML or a config array.
 *
 * Two construction forms:
 *
 *     #[Monitor(uuid: '550e8400-e29b-41d4-a716-446655440000')]
 *
 *     #[Monitor(env: 'CRON_MONITOR_REPORTS_NIGHTLY_UUID')]
 *
 * The `uuid:` form takes a literal string and is convenient for local
 * dev / one-off scripts. The `env:` form names an environment variable
 * that the SDK resolves at runtime — recommended for production, where
 * the per-monitor UUID is a write capability secret that should not
 * live in git history.
 *
 * Why `env:` instead of `uuid: getenv('FOO')`: PHP limits attribute
 * argument expressions to compile-time constants — function calls are
 * a parse error. `'%env(FOO)%'` arrives at the resolver as a literal
 * string because Symfony's env-placeholder expansion does not look
 * inside attribute payloads. The two-parameter shape sidesteps both
 * limitations cleanly.
 *
 * Constructor invariant: exactly one of `uuid:` / `env:` must be
 * provided. Both or neither throws `\InvalidArgumentException`. The
 * SDK's resolvers catch that into "no monitoring for this command" so
 * a misuse never breaks the host job, but the loud throw at attribute
 * instantiation time gives test suites a chance to surface the mistake.
 *
 * Precedence (unchanged): explicit YAML / config maps still win over
 * an attribute value, because users sometimes want to override an
 * attribute UUID per environment (e.g. blank in dev, populated from
 * an env var in prod).
 *
 * Lives at the package root namespace — not under `Bridge/Symfony/` —
 * because the same attribute is honoured by the Laravel scheduler
 * bridge as well. Keeping it framework-neutral keeps a single source
 * of truth.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Monitor
{
    public function __construct(
        public readonly ?string $uuid = null,
        public readonly ?string $env = null,
    ) {
        $hasUuid = null !== $uuid;
        $hasEnv = null !== $env;

        if ($hasUuid === $hasEnv) {
            throw new \InvalidArgumentException(\sprintf('%s requires exactly one of uuid: or env: — got %s.', self::class, $hasUuid ? 'both' : 'neither'));
        }
    }

    /**
     * Returns the UUID to ping with, resolving the `env:` form against
     * the process environment if that is the carrier. Returns `null`
     * for any "do not monitor right now" signal — an empty literal
     * uuid, a missing env var, or an env var that resolves to empty
     * — so callers can short-circuit before the SDK's UUID-v4
     * validator rejects an invalid value.
     *
     * Env lookup checks `$_ENV` → `$_SERVER` → `getenv()` in that
     * order. PHP exposes process environment slightly differently
     * across SAPIs and `php.ini` settings (`variables_order`,
     * disabled `getenv`, container env injection patterns); the
     * triple-fallback covers the realistic combinations.
     *
     * **Stability**: the lookup ladder ($_ENV → $_SERVER → getenv) is
     * part of the SDK's stable public contract. Integrators calling
     * `resolveUuid()` directly (rare, but legal) can rely on that
     * order; changes to the ladder are a breaking-change concern that
     * would require a major-version bump.
     */
    public function resolveUuid(): ?string
    {
        if (null !== $this->uuid) {
            return '' === $this->uuid ? null : $this->uuid;
        }

        // Constructor invariant guarantees $this->env is non-null here.
        \assert(null !== $this->env);

        $value = $_ENV[$this->env] ?? $_SERVER[$this->env] ?? getenv($this->env);
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
