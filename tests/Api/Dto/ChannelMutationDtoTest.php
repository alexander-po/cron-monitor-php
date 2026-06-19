<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\ChannelSecret;
use CronMonitor\Api\Dto\TestChannelResult;
use PHPUnit\Framework\TestCase;

final class ChannelMutationDtoTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function channelRow(): array
    {
        return [
            'id' => '3',
            'kind' => 'webhook',
            'label' => 'Ops webhook',
            'verified' => true,
            'config' => ['url' => 'https://hooks.example.test/x'],
            'created_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    public function test_channel_secret_wraps_the_channel_and_plaintext(): void
    {
        $result = ChannelSecret::fromArray(self::channelRow() + ['secret' => 'whsec_plaintext']);

        self::assertSame('3', $result->channel->id);
        self::assertSame('webhook', $result->channel->kind);
        self::assertSame('whsec_plaintext', $result->secret);
    }

    public function test_channel_secret_requires_the_secret(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ChannelSecret::fromArray(self::channelRow());
    }

    public function test_test_channel_result_hydrates(): void
    {
        $result = TestChannelResult::fromArray([
            'delivered' => true,
            'newly_verified' => false,
            'channel' => self::channelRow(),
        ]);

        self::assertTrue($result->delivered);
        self::assertFalse($result->newlyVerified);
        self::assertSame('3', $result->channel->id);
    }

    public function test_test_channel_result_requires_a_channel_object(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TestChannelResult::fromArray(['delivered' => true, 'newly_verified' => false, 'channel' => 'nope']);
    }
}
