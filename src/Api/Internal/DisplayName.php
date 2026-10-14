<?php

declare(strict_types=1);

namespace CronMonitor\Api\Internal;

/**
 * The client-side form of the service's rules for a monitor name and a channel
 * label: the forbidden characters and the length bounds, so most values the
 * service would answer with `422` are refused before a request exists (the
 * service trims more kinds of blank than PHP's `trim()` does). The minimum is
 * checked on the trimmed value and the maximum on the value as sent, as the
 * public OpenAPI describes them, so no value the service takes is refused
 * here. A name that is not valid UTF-8 is left alone: the request body cannot
 * be encoded, and the transport refuses it there.
 *
 * @internal
 */
final class DisplayName
{
    private const MONITOR_NAME_MIN = 2;
    private const MONITOR_NAME_MAX = 120;
    private const CHANNEL_LABEL_MIN = 2;
    private const CHANNEL_LABEL_MAX = 80;

    private const FORBIDDEN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function assertMonitorName(string $name, string $blankMessage): void
    {
        self::assert($name, 'Monitor name', $blankMessage, self::MONITOR_NAME_MIN, self::MONITOR_NAME_MAX);
    }

    public static function assertChannelLabel(string $label): void
    {
        self::assert($label, 'Channel label', 'Channel label must be a non-empty string.', self::CHANNEL_LABEL_MIN, self::CHANNEL_LABEL_MAX);
    }

    private static function assert(string $value, string $subject, string $blankMessage, int $min, int $max): void
    {
        if (1 === preg_match(self::FORBIDDEN, $value, $found)) {
            throw new \InvalidArgumentException(\sprintf('%s holds U+%04X; a control character, a line break or a direction override is not allowed.', $subject, self::codePoint($found[0])));
        }
        if ('' === trim($value)) {
            throw new \InvalidArgumentException($blankMessage);
        }
        $length = preg_match_all('/./su', $value);
        $trimmedLength = preg_match_all('/./su', trim($value));
        if (false === $length || false === $trimmedLength) {
            return;
        }
        if ($trimmedLength < $min || $length > $max) {
            throw new \InvalidArgumentException(\sprintf('%s must be between %d and %d characters.', $subject, $min, $max));
        }
    }

    private static function codePoint(string $character): int
    {
        $bytes = array_values(unpack('C*', $character) ?: [0]);

        return match (\count($bytes)) {
            1 => $bytes[0],
            2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
            default => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
        };
    }
}
