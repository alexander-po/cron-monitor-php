<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * A notification channel (email / telegram / slack / discord / webhook).
 *
 * `kind` is a plain string rather than an `Enum|string` like the other open
 * vocabularies ({@see MonitorStatus}). Channel kinds are the part of the API
 * most likely to grow, the SDK never branches on one, and every consumer that
 * has ever read this field has read a string — so narrowing it now would break
 * them to express something they do not use. Compare it against
 * {@see ChannelKind}'s values when you need to.
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
