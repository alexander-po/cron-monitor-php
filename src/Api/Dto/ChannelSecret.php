<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * Result of `POST /api/v1/channels/{id}/rotate-secret` (webhook channels): the
 * channel plus its freshly-minted plaintext `secret`.
 *
 * The plaintext is returned **once** and never again — capture it immediately.
 * It is deliberately a separate DTO from {@see Channel} (whose normal get/list
 * responses never carry a plaintext secret), so the ephemeral value is explicit
 * and cannot leak through an ordinary channel read.
 */
final class ChannelSecret
{
    public function __construct(
        public readonly Channel $channel,
        public readonly string $secret,
    ) {
    }

    /**
     * @param array<string, mixed> $data the rotate-secret response — the channel
     *                                   fields plus a top-level `secret`
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Channel::fromArray($data),
            Hydrator::string($data, 'secret'),
        );
    }
}
