<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * The wire value of an open vocabulary field, whichever half it arrived as.
 *
 * A read DTO carries the enum case when the SDK knows the value and the
 * server's verbatim string when it does not (see {@see MonitorStatus}), so
 * reaching for `->value` is only safe after a narrowing check. This is the
 * one-expression form for the common case — displaying or logging the value,
 * or comparing it against a raw string:
 *
 *     Vocabulary::value($monitor->status);   // 'up' — or 'quarantined'
 *
 * Branch on `instanceof MonitorStatus` (or `match` with a `default`) when the
 * *behaviour* differs; use this when only the string is wanted.
 */
final class Vocabulary
{
    public static function value(\BackedEnum|string $vocabulary): string
    {
        return $vocabulary instanceof \BackedEnum ? (string) $vocabulary->value : $vocabulary;
    }
}
