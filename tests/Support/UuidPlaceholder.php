<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

/**
 * The placeholder UUIDs the tests carry are all zeros but for an ordinal, so
 * they have no hex letters; a test of case handling, or of a pattern that must
 * match `a`–`f`, spells one with letters instead.
 */
final class UuidPlaceholder
{
    public static function withHexLetters(string $uuid): string
    {
        return strtr($uuid, '0', 'f');
    }
}
