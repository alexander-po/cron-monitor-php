<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * Result of `POST /api/v1/channels/{id}/test`: whether the test alert was
 * delivered, whether that delivery newly verified the channel, and the
 * (possibly now-verified) channel.
 *
 * The backend does not return a 200 with `delivered: false` — a failed
 * delivery surfaces as an exception instead (HTTP 502 →
 * {@see \CronMonitor\Api\Exception\UnexpectedResponseException}; an unverified
 * or transport-less channel → 422
 * {@see \CronMonitor\Api\Exception\ValidationException}).
 */
final class TestChannelResult
{
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $newlyVerified,
        public readonly Channel $channel,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        $channel = Hydrator::arr($data, 'channel');
        /** @var array<string, mixed> $channel */

        return new self(
            Hydrator::bool($data, 'delivered'),
            Hydrator::bool($data, 'newly_verified'),
            Channel::fromArray($channel),
        );
    }
}
