<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised on HTTP 422 — the backend rejected an otherwise well-formed
 * request because one or more fields are invalid (e.g. a cron expression
 * the SDK cannot evaluate locally).
 *
 * `$errors` maps the snake_case field name (matching the request JSON
 * keys) to its violation message, exactly as the backend returned them.
 * This is distinct from the local {@see \InvalidArgumentException} that
 * {@see \CronMonitor\Api\Dto\CreateMonitorRequest} throws for programmer
 * errors before any HTTP request is made.
 */
final class ValidationException extends ApiException
{
    /**
     * @param array<string, string> $errors field name => violation message
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        ?int $statusCode = null,
        ?string $detail = null,
        ?string $title = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $detail, $title, $previous);
    }
}
