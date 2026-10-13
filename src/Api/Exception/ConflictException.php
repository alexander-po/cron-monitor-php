<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 409 — the request conflicts with current state (e.g. a channel kind is disabled or its delivery is turned off on the service).
 */
final class ConflictException extends ApiException
{
}
