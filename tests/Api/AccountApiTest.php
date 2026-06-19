<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api;

use CronMonitor\Api\Dto\PlanKey;
use CronMonitor\Api\Exception\ApiTransportException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use CronMonitor\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AccountApiTest extends TestCase
{
    private function client(RecordingHttpClient $http, int $retries = 0): MonitorApiClient
    {
        $factory = new HttpFactory();

        return new MonitorApiClient(
            new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: $retries),
            $http,
            $factory,
            $factory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function accountBody(string $planKey = 'starter'): array
    {
        return [
            'plan' => ['key' => $planKey, 'label' => 'Starter', 'monitor_limit' => 50],
            'monitor_budget' => ['used' => 12, 'limit' => 50, 'remaining' => 38],
            'api_rate_limit' => ['limit' => 120, 'remaining' => 119],
        ];
    }

    private static function jsonResponse(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public function test_get_account_parses_nested_snapshot(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(200, self::accountBody())]);
        $client = $this->client($http);

        $account = $client->getAccount();

        self::assertSame(PlanKey::Starter, $account->plan->key);
        self::assertSame('Starter', $account->plan->label);
        self::assertSame(50, $account->plan->monitorLimit);
        self::assertSame(12, $account->monitorBudget->used);
        self::assertSame(38, $account->monitorBudget->remaining);
        self::assertSame(120, $account->apiRateLimit->limit);
        self::assertSame(119, $account->apiRateLimit->remaining);

        $sent = $http->requests[0];
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('https://cronheart.com/api/v1/account', (string) $sent->getUri());
        self::assertSame('Bearer cmk_test_token', $sent->getHeaderLine('Authorization'));
    }

    public function test_get_account_is_retried_on_server_error(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse(503, []),
            self::jsonResponse(200, self::accountBody()),
        ]);
        $client = $this->client($http, retries: 1);

        $client->getAccount();

        self::assertCount(2, $http->requests);
    }

    public function test_get_account_rejects_unknown_plan_key(): void
    {
        // A plan key the SDK enum does not know must surface as a wrapped
        // contract violation, never a silently-accepted unknown value.
        $http = new RecordingHttpClient([self::jsonResponse(200, self::accountBody('enterprise'))]);
        $client = $this->client($http);

        $this->expectException(ApiTransportException::class);
        $client->getAccount();
    }
}
