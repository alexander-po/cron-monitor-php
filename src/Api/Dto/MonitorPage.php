<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * One page of a monitors listing (`GET /api/v1/monitors`).
 *
 * The backend paginates with `offset`/`limit`; `total` is the count across
 * all pages. {@see hasMore()} encapsulates the "is there another page?"
 * arithmetic so callers iterating page-by-page do not re-derive it.
 */
final class MonitorPage
{
    /**
     * @param list<Monitor> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->offset + \count($this->data) < $this->total;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \UnexpectedValueException when the envelope or a row is malformed
     */
    public static function fromArray(array $payload): self
    {
        $monitors = [];
        foreach (Hydrator::arr($payload, 'data') as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Each monitor entry must be a JSON object.');
            }
            /** @var array<string, mixed> $row */
            $monitors[] = Monitor::fromArray($row);
        }

        return new self(
            $monitors,
            Hydrator::int($payload, 'total'),
            Hydrator::int($payload, 'limit'),
            Hydrator::int($payload, 'offset'),
        );
    }
}
