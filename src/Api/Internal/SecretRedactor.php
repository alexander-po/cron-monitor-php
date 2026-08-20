<?php

declare(strict_types=1);

namespace CronMonitor\Api\Internal;

/**
 * Strips the two credentials that can end up quoted in text the SDK did not
 * write: a monitor UUID, which is the bearer credential on that monitor's
 * ping endpoint, and an API token, which authenticates the whole account.
 *
 * They arrive from two directions. A PSR-18 client routinely appends the
 * failing request URI to its own exception message, and a transport that
 * echoes request headers would carry the token — so anything the SDK quotes
 * back, or renders in an exception's string form, passes through here first.
 *
 * @internal Not part of the SDK's public, SemVer-stable surface. Behaviour
 *           may change in any release.
 */
final class SecretRedactor
{
    /**
     * Canonical UUID v4 shape. Intentionally a private copy of the literal in
     * {@see \CronMonitor\Client\Configuration::pingUrl()} rather than a shared
     * constant, keeping the API layer from reaching into the ping client's
     * configuration. If the canonical pattern ever changes, change both.
     */
    public const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const API_KEY_PATTERN = 'cmk_[A-Za-z0-9_-]+';

    public static function redact(string $text): string
    {
        $redacted = preg_replace(
            ['/'.self::UUID_PATTERN.'/i', '/'.self::API_KEY_PATTERN.'/'],
            ['{uuid}', '{api_key}'],
            $text,
        );

        return \is_string($redacted) ? $redacted : $text;
    }
}
