<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * A notification channel (email / telegram / slack / discord / webhook).
 *
 * `kind` is kept as a plain string rather than an enum: the set of channel
 * kinds is the most likely part of the API to grow, and the SDK does not
 * branch on it, so an enum would add parse-fragility for no consumer
 * benefit.
 *
 * `config` is whatever the backend returns for the channel's transport
 * settings, with secret credentials already masked server-side (e.g.
 * webhook URLs / secrets come back as `***`). It is stored verbatim.
 *
 * `id` is the backend's BIGINT identifier carried as a string (it can exceed
 * PHP's int range), matching the API's serialized type.
 */
final class Channel
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly bool $verified,
        public readonly array $config,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        $config = $data['config'] ?? [];
        if (!\is_array($config)) {
            throw new \UnexpectedValueException('Channel "config" must be a JSON object.');
        }
        /** @var array<string, mixed> $config */

        return new self(
            Hydrator::string($data, 'id'),
            Hydrator::string($data, 'kind'),
            Hydrator::string($data, 'label'),
            Hydrator::bool($data, 'verified'),
            $config,
            Hydrator::dateTime($data, 'created_at'),
        );
    }
}
