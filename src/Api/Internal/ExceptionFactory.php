<?php

declare(strict_types=1);

namespace CronMonitor\Api\Internal;

use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\ConflictException;
use CronMonitor\Api\Exception\ForbiddenException;
use CronMonitor\Api\Exception\NotFoundException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\Exception\UnexpectedResponseException;
use CronMonitor\Api\Exception\ValidationException;

/**
 * Maps an HTTP error status + parsed {@see ProblemDetails} to the matching
 * {@see ApiException} subclass.
 *
 * @internal not part of the SDK's public, SemVer-stable surface
 */
final class ExceptionFactory
{
    /**
     * @param int|null $retryAfterHeader value of the `Retry-After` response
     *                                   header, if present; takes precedence
     *                                   over the body's `retry_after` for 429s
     */
    public static function fromResponse(int $status, ProblemDetails $problem, ?int $retryAfterHeader = null): ApiException
    {
        $message = $problem->detail ?? $problem->title ?? \sprintf('HTTP %d', $status);

        return match ($status) {
            401 => new AuthenticationException($message, $status, $problem->detail, $problem->title),
            402 => new PlanRestrictionException($message, $problem->upgradeUrl, $status, $problem->detail, $problem->title),
            403 => new ForbiddenException($message, $status, $problem->detail, $problem->title),
            404 => new NotFoundException($message, $status, $problem->detail, $problem->title),
            409 => new ConflictException($message, $status, $problem->detail, $problem->title),
            422 => new ValidationException($message, $problem->errors, $status, $problem->detail, $problem->title),
            429 => new RateLimitException($message, $retryAfterHeader ?? $problem->retryAfter, $status, $problem->detail, $problem->title),
            default => new UnexpectedResponseException($message, $status, $problem->detail, $problem->title),
        };
    }
}
