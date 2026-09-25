<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * The confirmed end of a terminal signup: the account, its default project
 * (`$project`) and this token now exist. The backend returns `$token` once; a
 * later poll for the same request is a 410, so only `$token` is required to
 * read the answer.
 */
final class SignupToken
{
    public function __construct(
        public readonly string $token,
        public readonly ?string $tokenPrefix,
        public readonly ?string $project,
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
            Hydrator::string($data, 'token'),
            Hydrator::nullableString($data, 'token_prefix'),
            Hydrator::nullableString($data, 'project'),
        );
    }
}
