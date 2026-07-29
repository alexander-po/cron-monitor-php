<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\MonitorChannel;
use PHPUnit\Framework\TestCase;

final class MonitorChannelTest extends TestCase
{
    public function test_from_array_hydrates_every_field(): void
    {
        $channel = MonitorChannel::fromArray(['id' => '42', 'kind' => 'telegram', 'label' => 'ops-bot']);

        self::assertSame('42', $channel->id);
        self::assertSame('telegram', $channel->kind);
        self::assertSame('ops-bot', $channel->label);
    }

    public function test_id_stays_a_string_beyond_the_php_int_range(): void
    {
        // The backend serializes its BIGINT ids as JSON strings precisely so
        // they survive a platform where they exceed the native int range; the
        // DTO must not narrow them back.
        $channel = MonitorChannel::fromArray([
            'id' => '18446744073709551615',
            'kind' => 'slack',
            'label' => 'deploys',
        ]);

        self::assertSame('18446744073709551615', $channel->id);
    }

    public function test_an_unknown_kind_still_hydrates(): void
    {
        // `kind` is a plain string, not an enum: a channel kind the backend
        // adds later must not make a whole monitor read unhydratable for a
        // consumer that never branches on it.
        $channel = MonitorChannel::fromArray(['id' => '7', 'kind' => 'carrier-pigeon', 'label' => 'legacy']);

        self::assertSame('carrier-pigeon', $channel->kind);
    }

    public function test_channel_id_must_be_a_string(): void
    {
        // Same rule as Channel::$id — an int must be rejected rather than
        // silently accepted, so a backend regression surfaces loudly.
        $this->expectException(\UnexpectedValueException::class);

        MonitorChannel::fromArray(['id' => 42, 'kind' => 'telegram', 'label' => 'ops-bot']);
    }

    public function test_a_missing_kind_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        MonitorChannel::fromArray(['id' => '42', 'label' => 'ops-bot']);
    }

    public function test_a_missing_id_is_a_contract_violation(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        MonitorChannel::fromArray(['kind' => 'telegram', 'label' => 'ops-bot']);
    }

    public function test_a_missing_label_is_a_contract_violation(): void
    {
        // Pinned separately: a tolerant read would hydrate an empty label and
        // print a nameless destination, the "silent nulls" the Hydrator exists
        // to prevent.
        $this->expectException(\UnexpectedValueException::class);

        MonitorChannel::fromArray(['id' => '42', 'kind' => 'telegram']);
    }
}
