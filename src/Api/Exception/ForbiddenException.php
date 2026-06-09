<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 403 — the token is valid but the action is not allowed (e.g. a monitor-count limit was hit).
 */
final class ForbiddenException extends ApiException
{
}
