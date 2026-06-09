<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorPage;
use PHPUnit\Framework\TestCase;

final class MonitorPageTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function monitorRow(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'name' => 'Monitor '.$uuid,
            'schedule_kind' => 'interval',
            'schedule_expr' => '300',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'new',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.$uuid,
            'badge_url' => 'https://cronheart.com/badge/'.$uuid.'.svg',
        ];
    }

    public function test_from_array_maps_rows_and_envelope(): void
    {
        $page = MonitorPage::fromArray([
            'data' => [
                self::monitorRow('11111111-1111-4111-8111-111111111111'),
                self::monitorRow('22222222-2222-4222-8222-222222222222'),
            ],
            'total' => 5,
            'limit' => 2,
            'offset' => 0,
        ]);

        self::assertCount(2, $page->data);
        self::assertContainsOnlyInstancesOf(Monitor::class, $page->data);
        self::assertSame(5, $page->total);
        self::assertSame(2, $page->limit);
        self::assertSame(0, $page->offset);
    }

    public function test_has_more_is_true_when_offset_plus_count_below_total(): void
    {
        $page = MonitorPage::fromArray([
            'data' => [self::monitorRow('11111111-1111-4111-8111-111111111111')],
            'total' => 3,
            'limit' => 1,
            'offset' => 0,
        ]);

        self::assertTrue($page->hasMore());
    }

    public function test_has_more_is_false_on_last_page(): void
    {
        $page = MonitorPage::fromArray([
            'data' => [self::monitorRow('33333333-3333-4333-8333-333333333333')],
            'total' => 3,
            'limit' => 1,
            'offset' => 2,
        ]);

        self::assertFalse($page->hasMore());
    }

    public function test_missing_data_array_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MonitorPage::fromArray(['total' => 0, 'limit' => 50, 'offset' => 0]);
    }
}
