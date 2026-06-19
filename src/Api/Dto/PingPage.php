<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * One page of a monitor's ping history.
 *
 * Unlike the offset-paginated monitor and alert listings, pings use opaque
 * **cursor** pagination: pass {@see $nextCursor} back as the `cursor` query
 * param to fetch the next page. {@see hasMore()} is true while a next cursor
 * is present.
 */
final class PingPage
{
    /**
     * @param list<Ping> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextCursor,
    ) {
    }

    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \UnexpectedValueException when the envelope or a row is malformed
     */
    public static function fromArray(array $payload): self
    {
        $pings = [];
        foreach (Hydrator::arr($payload, 'data') as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Each ping entry must be a JSON object.');
            }
            /** @var array<string, mixed> $row */
            $pings[] = Ping::fromArray($row);
        }

        return new self($pings, Hydrator::nullableString($payload, 'next_cursor'));
    }
}
