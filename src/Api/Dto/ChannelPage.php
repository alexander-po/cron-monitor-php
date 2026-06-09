<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * The notification-channels listing (`GET /api/v1/channels`).
 *
 * Unlike {@see MonitorPage}, the channels endpoint returns the full set in
 * one response (`data` + `total`, no `offset`/`limit`), so this DTO stays
 * honest to that wire shape and omits pagination fields.
 */
final class ChannelPage
{
    /**
     * @param list<Channel> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \UnexpectedValueException when the envelope or a row is malformed
     */
    public static function fromArray(array $payload): self
    {
        $channels = [];
        foreach (Hydrator::arr($payload, 'data') as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Each channel entry must be a JSON object.');
            }
            /** @var array<string, mixed> $row */
            $channels[] = Channel::fromArray($row);
        }

        return new self($channels, Hydrator::int($payload, 'total'));
    }
}
