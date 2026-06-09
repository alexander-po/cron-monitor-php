<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 401 — the Bearer token is missing, malformed, revoked, or expired.
 */
final class AuthenticationException extends ApiException
{
}
