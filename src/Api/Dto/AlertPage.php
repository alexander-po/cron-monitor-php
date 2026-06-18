<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * One page of a monitor's alert history (`GET /api/v1/monitors/{uuid}/alerts`),
 * offset-paginated exactly like {@see MonitorPage}.
 */
final class AlertPage
{
    /**
     * @param list<Alert> $data
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
        $alerts = [];
        foreach (Hydrator::arr($payload, 'data') as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Each alert entry must be a JSON object.');
            }
            /** @var array<string, mixed> $row */
            $alerts[] = Alert::fromArray($row);
        }

        return new self(
            $alerts,
            Hydrator::int($payload, 'total'),
            Hydrator::int($payload, 'limit'),
            Hydrator::int($payload, 'offset'),
        );
    }
}
