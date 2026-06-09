<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Exception;

use CronMonitor\Api\Internal\ProblemDetails;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function test_parses_full_problem_json(): void
    {
        $problem = ProblemDetails::parse((string) json_encode([
            'type' => 'about:blank',
            'title' => 'Unprocessable Entity',
            'status' => 422,
            'detail' => 'One or more fields are invalid.',
            'errors' => ['name' => 'too short', 'schedule_expr' => 'invalid cron'],
        ]), 422);

        self::assertSame('Unprocessable Entity', $problem->title);
        self::assertSame('One or more fields are invalid.', $problem->detail);
        self::assertSame(['name' => 'too short', 'schedule_expr' => 'invalid cron'], $problem->errors);
        self::assertNull($problem->upgradeUrl);
        self::assertNull($problem->retryAfter);
    }

    public function test_extracts_upgrade_url_and_retry_after(): void
    {
        $problem = ProblemDetails::parse((string) json_encode([
            'title' => 'Payment Required',
            'upgrade_url' => 'https://cronheart.com/billing/upgrade',
            'retry_after' => 30,
        ]), 402);

        self::assertSame('https://cronheart.com/billing/upgrade', $problem->upgradeUrl);
        self::assertSame(30, $problem->retryAfter);
    }

    public function test_retry_after_as_numeric_string_is_coerced(): void
    {
        $problem = ProblemDetails::parse((string) json_encode(['retry_after' => '45']), 429);

        self::assertSame(45, $problem->retryAfter);
    }

    public function test_non_json_body_falls_back_to_http_status_title_without_leaking_body(): void
    {
        $problem = ProblemDetails::parse('<html><body>502 Bad Gateway</body></html>', 502);

        self::assertSame('HTTP 502', $problem->title);
        self::assertNull($problem->detail);
        self::assertSame([], $problem->errors);
    }

    public function test_empty_body_falls_back_to_http_status_title(): void
    {
        $problem = ProblemDetails::parse('', 500);

        self::assertSame('HTTP 500', $problem->title);
    }

    public function test_non_string_error_values_are_dropped(): void
    {
        $problem = ProblemDetails::parse((string) json_encode([
            'errors' => ['name' => 'too short', 'tags' => ['not', 'a', 'string']],
        ]), 422);

        self::assertSame(['name' => 'too short'], $problem->errors);
    }
}
