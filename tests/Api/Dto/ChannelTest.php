<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\Channel;
use CronMonitor\Api\Dto\ChannelPage;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    public function test_channel_from_array_keeps_masked_config_verbatim(): void
    {
        $channel = Channel::fromArray([
            'id' => 7,
            'kind' => 'webhook',
            'label' => 'Ops webhook',
            'verified' => true,
            'config' => ['url' => '***', 'secret' => '***'],
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame(7, $channel->id);
        self::assertSame('webhook', $channel->kind);
        self::assertSame('Ops webhook', $channel->label);
        self::assertTrue($channel->verified);
        self::assertSame(['url' => '***', 'secret' => '***'], $channel->config);
        self::assertSame('2026-01-01T00:00:00+00:00', $channel->createdAt->format(\DateTimeInterface::RFC3339));
    }

    public function test_channel_page_maps_rows_and_total(): void
    {
        $page = ChannelPage::fromArray([
            'data' => [
                ['id' => 1, 'kind' => 'email', 'label' => 'Me', 'verified' => false, 'config' => ['address' => 'a@b.test'], 'created_at' => '2026-01-01T00:00:00+00:00'],
                ['id' => 2, 'kind' => 'telegram', 'label' => 'TG', 'verified' => true, 'config' => ['chat_id' => '12345'], 'created_at' => '2026-01-02T00:00:00+00:00'],
            ],
            'total' => 2,
        ]);

        self::assertCount(2, $page->data);
        self::assertContainsOnlyInstancesOf(Channel::class, $page->data);
        self::assertSame(2, $page->total);
    }

    public function test_non_object_config_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Channel::fromArray([
            'id' => 1,
            'kind' => 'email',
            'label' => 'Me',
            'verified' => false,
            'config' => 'not-an-object',
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);
    }
}
