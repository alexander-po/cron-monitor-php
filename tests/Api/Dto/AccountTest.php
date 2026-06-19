<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Account;
use CronMonitor\Api\Dto\PlanKey;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function test_account_hydrates_nested_objects(): void
    {
        $account = Account::fromArray([
            'plan' => ['key' => 'starter', 'label' => 'Starter', 'monitor_limit' => 50],
            'monitor_budget' => ['used' => 7, 'limit' => 50, 'remaining' => 43],
            'api_rate_limit' => ['limit' => 120, 'remaining' => 119],
        ]);

        self::assertSame(PlanKey::Starter, $account->plan->key);
        self::assertSame('Starter', $account->plan->label);
        self::assertSame(50, $account->plan->monitorLimit);
        self::assertSame(7, $account->monitorBudget->used);
        self::assertSame(43, $account->monitorBudget->remaining);
        self::assertSame(120, $account->apiRateLimit->limit);
        self::assertSame(119, $account->apiRateLimit->remaining);
    }

    public function test_account_unknown_plan_key_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Account::fromArray([
            'plan' => ['key' => 'enterprise', 'label' => 'Enterprise', 'monitor_limit' => 9999],
            'monitor_budget' => ['used' => 0, 'limit' => 0, 'remaining' => 0],
            'api_rate_limit' => ['limit' => 0, 'remaining' => 0],
        ]);
    }

    public function test_account_missing_nested_object_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Account::fromArray(['plan' => ['key' => 'free', 'label' => 'Free', 'monitor_limit' => 20]]);
    }
}
