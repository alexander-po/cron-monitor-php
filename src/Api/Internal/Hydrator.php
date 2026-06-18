<?php

declare(strict_types=1);

namespace CronMonitor\Api\Internal;

/**
 * Strict array-to-scalar extraction helpers for hydrating API DTOs from
 * decoded JSON.
 *
 * Every accessor throws {@see \UnexpectedValueException} when a required
 * field is absent or has the wrong type. The API client layer catches these
 * and re-wraps them as a transport-level API exception, so a malformed
 * server response surfaces loudly instead of producing a half-built DTO
 * with silent nulls.
 *
 * @internal Not part of the SDK's public, SemVer-stable surface. Behaviour
 *           may change in any release.
 */
final class Hydrator
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw self::typeError($key, 'string', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw self::typeError($key, 'string|null', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw self::typeError($key, 'int', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value)) {
            throw self::typeError($key, 'int|null', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!\is_bool($value)) {
            throw self::typeError($key, 'bool', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    public static function arr(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw self::typeError($key, 'array', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function dateTime(array $data, string $key): \DateTimeImmutable
    {
        return self::parseTimestamp(self::string($data, $key), $key);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableDateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $raw = self::nullableString($data, $key);
        if (null === $raw || '' === $raw) {
            return null;
        }

        return self::parseTimestamp($raw, $key);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $enumClass
     *
     * @return T
     */
    public static function enum(string $enumClass, array $data, string $key): \BackedEnum
    {
        $raw = self::string($data, $key);
        $case = $enumClass::tryFrom($raw);
        if (null === $case) {
            throw new \UnexpectedValueException(\sprintf('Unexpected value %s for field "%s".', var_export($raw, true), $key));
        }

        return $case;
    }

    private static function parseTimestamp(string $value, string $key): \DateTimeImmutable
    {
        // RFC 3339 atoms, with or without fractional seconds. The permissive
        // constructor handles both `2026-01-01T00:00:00+00:00` and the
        // `.123` fractional form; we wrap a parse failure as a contract
        // violation rather than letting a raw \Exception escape.
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \UnexpectedValueException(\sprintf('Field "%s" is not a parseable RFC 3339 timestamp: %s.', $key, var_export($value, true)), 0, $e);
        }
    }

    private static function typeError(string $key, string $expected, mixed $actual): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('Field "%s" must be %s, got %s.', $key, $expected, get_debug_type($actual)));
    }
}
