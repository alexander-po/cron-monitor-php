<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Alert;
use CronMonitor\Api\Dto\AlertKind;
use CronMonitor\Api\Dto\AlertPage;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    public function test_alert_hydrates_with_dispatched_to(): void
    {
        $alert = Alert::fromArray([
            'id' => '7',
            'kind' => 'late',
            'created_at' => '2026-06-10T04:05:00+00:00',
            'dispatched_to' => ['channel_ids' => [3, 4]],
        ]);

        self::assertSame('7', $alert->id);
        self::assertSame(AlertKind::Late, $alert->kind);
        self::assertSame('2026-06-10T04:05:00+00:00', $alert->createdAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(['channel_ids' => [3, 4]], $alert->dispatchedTo);
    }

    public function test_alert_dispatched_to_is_nullable(): void
    {
        $alert = Alert::fromArray(['id' => '8', 'kind' => 'recovered', 'created_at' => '2026-06-10T05:00:00+00:00', 'dispatched_to' => null]);

        self::assertNull($alert->dispatchedTo);
    }

    public function test_alert_dispatched_to_wrong_type_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Alert::fromArray(['id' => '8', 'kind' => 'fail', 'created_at' => '2026-06-10T05:00:00+00:00', 'dispatched_to' => 'nope']);
    }

    public function test_alert_id_must_be_a_string(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Alert::fromArray(['id' => 8, 'kind' => 'fail', 'created_at' => '2026-06-10T05:00:00+00:00', 'dispatched_to' => null]);
    }

    public function test_alert_unknown_kind_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Alert::fromArray(['id' => '8', 'kind' => 'warned', 'created_at' => '2026-06-10T05:00:00+00:00', 'dispatched_to' => null]);
    }

    public function test_alert_page_offset_pagination_reports_has_more(): void
    {
        $page = AlertPage::fromArray([
            'data' => [['id' => '1', 'kind' => 'late', 'created_at' => '2026-06-10T04:00:00+00:00', 'dispatched_to' => null]],
            'total' => 5,
            'limit' => 1,
            'offset' => 0,
        ]);

        self::assertCount(1, $page->data);
        self::assertInstanceOf(Alert::class, $page->data[0]);
        self::assertTrue($page->hasMore());
    }
}
