<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * Result of `POST /api/v1/channels/{id}/test`: whether the test alert was
 * delivered, whether that delivery newly verified the channel, and the
 * (possibly now-verified) channel.
 *
 * A send that does not succeed surfaces as an exception from
 * {@see \CronMonitor\Api\MonitorApiClient::testChannel()}: a delivery to the
 * destination that failed answers `502`
 * ({@see \CronMonitor\Api\Exception\ChannelDeliveryException}); while the
 * channel's kind is disabled or its delivery is turned off on the service, the
 * send is refused with `409` ({@see \CronMonitor\Api\Exception\ConflictException})
 * and nothing is sent; a test the service refuses as invalid answers `422`
 * ({@see \CronMonitor\Api\Exception\ValidationException}), with the reason in
 * `detail`.
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
