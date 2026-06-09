<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 409 — the request conflicts with current state (e.g. a notification transport is disabled).
 */
final class ConflictException extends ApiException
{
}
