<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Ping;
use CronMonitor\Api\Dto\PingKind;
use CronMonitor\Api\Dto\PingPage;
use PHPUnit\Framework\TestCase;

final class PingTest extends TestCase
{
    public function test_ping_hydrates_all_fields(): void
    {
        $ping = Ping::fromArray([
            'id' => '254',
            'kind' => 'heartbeat',
            'received_at' => '2026-06-09T12:06:55+00:00',
            'runtime_ms' => 1200,
        ]);

        self::assertSame('254', $ping->id);
        self::assertSame(PingKind::Heartbeat, $ping->kind);
        self::assertSame('2026-06-09T12:06:55+00:00', $ping->receivedAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(1200, $ping->runtimeMs);
    }

    public function test_ping_runtime_ms_is_nullable(): void
    {
        $ping = Ping::fromArray(['id' => '1', 'kind' => 'start', 'received_at' => '2026-06-09T12:00:00+00:00', 'runtime_ms' => null]);

        self::assertNull($ping->runtimeMs);
    }

    public function test_ping_id_must_be_a_string(): void
    {
        // The backend serializes the BIGINT id as a JSON string; an int would
        // silently break against the real API, so the DTO rejects it.
        $this->expectException(\UnexpectedValueException::class);
        Ping::fromArray(['id' => 254, 'kind' => 'heartbeat', 'received_at' => '2026-06-09T12:00:00+00:00', 'runtime_ms' => null]);
    }

    public function test_ping_unknown_kind_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Ping::fromArray(['id' => '1', 'kind' => 'pong', 'received_at' => '2026-06-09T12:00:00+00:00', 'runtime_ms' => null]);
    }

    public function test_ping_page_exposes_cursor_and_has_more(): void
    {
        $page = PingPage::fromArray([
            'data' => [
                ['id' => '1', 'kind' => 'heartbeat', 'received_at' => '2026-06-09T12:00:00+00:00', 'runtime_ms' => null],
            ],
            'next_cursor' => 'opaque-cursor-123',
        ]);

        self::assertCount(1, $page->data);
        self::assertInstanceOf(Ping::class, $page->data[0]);
        self::assertSame('opaque-cursor-123', $page->nextCursor);
        self::assertTrue($page->hasMore());
    }

    public function test_ping_page_last_page_has_no_more(): void
    {
        $page = PingPage::fromArray(['data' => [], 'next_cursor' => null]);

        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursor);
    }
}
