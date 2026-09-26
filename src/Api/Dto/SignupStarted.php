<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * Result of `POST /api/v1/signup`: the backend mailed the address one
 * confirmation link, with the same answer whether or not the address already
 * has an account.
 *
 * Show `$userCode` to the person, who types it on the linked page. Keep
 * `$deviceCode` out of output and logs: whoever holds it can claim the token
 * once the person confirms.
 */
final class SignupStarted
{
    public function __construct(
        public readonly string $deviceCode,
        public readonly string $userCode,
        public readonly int $expiresIn,
        public readonly int $interval,
        public readonly ?string $hint,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        return new self(
            Hydrator::string($data, 'device_code'),
            Hydrator::string($data, 'user_code'),
            Hydrator::int($data, 'expires_in'),
            Hydrator::int($data, 'interval'),
            Hydrator::nullableString($data, 'hint'),
        );
    }
}
