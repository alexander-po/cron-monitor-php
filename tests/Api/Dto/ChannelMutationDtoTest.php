<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\ChannelSecret;
use CronMonitor\Api\Dto\TestChannelResult;
use CronMonitor\Tests\Support\SecretDumpAssertions;
use PHPUnit\Framework\TestCase;

final class ChannelMutationDtoTest extends TestCase
{
    use SecretDumpAssertions;

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
            'config' => ['url' => '***'],
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

    public function test_a_dumped_rotation_masks_the_secret_and_the_property_still_carries_it(): void
    {
        $result = ChannelSecret::fromArray(self::channelRow() + ['secret' => 'whsec_fake_dumped_secret']);

        self::assertDumpsMask($result, ['whsec_fake_dumped_secret'], ['secret']);
        self::assertSame('whsec_fake_dumped_secret', $result->secret);
    }
}
