<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Exception;

use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\ConflictException;
use CronMonitor\Api\Exception\ForbiddenException;
use CronMonitor\Api\Exception\NotFoundException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\Exception\UnexpectedResponseException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\Internal\ExceptionFactory;
use CronMonitor\Api\Internal\ProblemDetails;
use PHPUnit\Framework\TestCase;

final class ExceptionFactoryTest extends TestCase
{
    private static function problem(string $json, int $status): ProblemDetails
    {
        return ProblemDetails::parse($json, $status);
    }

    public function test_maps_each_status_to_its_exception(): void
    {
        $cases = [
            401 => AuthenticationException::class,
            402 => PlanRestrictionException::class,
            403 => ForbiddenException::class,
            404 => NotFoundException::class,
            409 => ConflictException::class,
            422 => ValidationException::class,
            429 => RateLimitException::class,
            400 => UnexpectedResponseException::class,
            500 => UnexpectedResponseException::class,
            // 502 is the channel-test delivery failure (backend Bad Gateway);
            // it has no dedicated subclass and must fall to the default arm.
            502 => UnexpectedResponseException::class,
            418 => UnexpectedResponseException::class,
        ];

        foreach ($cases as $status => $expected) {
            $exception = ExceptionFactory::fromResponse($status, self::problem('{}', $status));
            self::assertInstanceOf($expected, $exception, "status {$status}");
            self::assertInstanceOf(ApiException::class, $exception);
            self::assertSame($status, $exception->statusCode);
        }
    }

    public function test_message_prefers_detail_then_title_then_http_status(): void
    {
        $withDetail = ExceptionFactory::fromResponse(404, self::problem((string) json_encode(['title' => 'Not Found', 'detail' => 'No such monitor.']), 404));
        self::assertSame('No such monitor.', $withDetail->getMessage());

        $withTitleOnly = ExceptionFactory::fromResponse(404, self::problem((string) json_encode(['title' => 'Not Found']), 404));
        self::assertSame('Not Found', $withTitleOnly->getMessage());

        $bare = ExceptionFactory::fromResponse(500, self::problem('not json', 500));
        self::assertSame('HTTP 500', $bare->getMessage());
    }

    public function test_plan_restriction_carries_upgrade_url(): void
    {
        $exception = ExceptionFactory::fromResponse(402, self::problem((string) json_encode([
            'title' => 'Payment Required',
            'upgrade_url' => 'https://cronheart.com/billing/upgrade',
        ]), 402));

        self::assertInstanceOf(PlanRestrictionException::class, $exception);
        self::assertSame('https://cronheart.com/billing/upgrade', $exception->upgradeUrl);
    }

    public function test_validation_carries_field_errors(): void
    {
        $exception = ExceptionFactory::fromResponse(422, self::problem((string) json_encode([
            'detail' => 'Invalid.',
            'errors' => ['schedule_expr' => 'invalid cron'],
        ]), 422));

        self::assertInstanceOf(ValidationException::class, $exception);
        self::assertSame(['schedule_expr' => 'invalid cron'], $exception->errors);
    }

    public function test_rate_limit_prefers_header_over_body(): void
    {
        $exception = ExceptionFactory::fromResponse(
            429,
            self::problem((string) json_encode(['retry_after' => 10]), 429),
            retryAfterHeader: 30,
        );

        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertSame(30, $exception->retryAfter);
    }

    public function test_rate_limit_falls_back_to_body_retry_after(): void
    {
        $exception = ExceptionFactory::fromResponse(
            429,
            self::problem((string) json_encode(['retry_after' => 10]), 429),
        );

        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertSame(10, $exception->retryAfter);
    }
}
