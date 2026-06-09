<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 404 — the resource does not exist. Cross-tenant reads are masked as 404 by the backend, so this can also mean 'exists but not yours'.
 */
final class NotFoundException extends ApiException
{
}
