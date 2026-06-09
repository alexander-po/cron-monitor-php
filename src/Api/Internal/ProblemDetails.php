<?php

declare(strict_types=1);

namespace CronMonitor\Api\Internal;

/**
 * A tolerantly-parsed RFC 7807 `application/problem+json` error body.
 *
 * The backend emits problem+json on every API error, but a misconfigured
 * proxy or an unexpected 5xx can return HTML or an empty body. Parsing is
 * therefore defensive: any decode failure or non-object payload collapses
 * to a `title` of `"HTTP {status}"` with no detail — the raw body is never
 * echoed (it could be large or sensitive).
 *
 * @internal not part of the SDK's public, SemVer-stable surface
 */
final class ProblemDetails
{
    /**
     * @param array<string, string> $errors
     */
    private function __construct(
        public readonly ?string $title,
        public readonly ?string $detail,
        public readonly array $errors,
        public readonly ?string $upgradeUrl,
        public readonly ?int $retryAfter,
    ) {
    }

    public static function parse(string $body, int $status): self
    {
        $fallbackTitle = \sprintf('HTTP %d', $status);

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self($fallbackTitle, null, [], null, null);
        }

        if (!\is_array($decoded)) {
            return new self($fallbackTitle, null, [], null, null);
        }

        return new self(
            self::nullableString($decoded, 'title') ?? $fallbackTitle,
            self::nullableString($decoded, 'detail'),
            self::stringMap($decoded['errors'] ?? null),
            self::nullableString($decoded, 'upgrade_url'),
            self::nullableInt($decoded['retry_after'] ?? null),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Coerce the `errors` object into `array<string, string>`, dropping any
     * entry whose value is not a string (the backend sends a flat field →
     * message map, but we stay defensive).
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $field => $message) {
            if (\is_string($field) && \is_string($message)) {
                $map[$field] = $message;
            }
        }

        return $map;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
