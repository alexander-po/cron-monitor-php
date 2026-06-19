<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\ChannelKind;
use CronMonitor\Api\Dto\CreateChannelRequest;
use PHPUnit\Framework\TestCase;

final class CreateChannelRequestTest extends TestCase
{
    public function test_email_named_constructor(): void
    {
        $req = CreateChannelRequest::email('My inbox', 'me@example.test');

        self::assertSame(['kind' => 'email', 'label' => 'My inbox', 'address' => 'me@example.test'], $req->toArray());
    }

    public function test_telegram_named_constructor(): void
    {
        $req = CreateChannelRequest::telegram('TG', '123456');

        self::assertSame(['kind' => 'telegram', 'label' => 'TG', 'chat_id' => '123456'], $req->toArray());
    }

    public function test_slack_and_discord_use_webhook_url(): void
    {
        self::assertSame(
            ['kind' => 'slack', 'label' => 'S', 'webhook_url' => 'https://hooks.slack.test/x'],
            CreateChannelRequest::slack('S', 'https://hooks.slack.test/x')->toArray(),
        );
        self::assertSame(
            ['kind' => 'discord', 'label' => 'D', 'webhook_url' => 'https://discord.test/x'],
            CreateChannelRequest::discord('D', 'https://discord.test/x')->toArray(),
        );
    }

    public function test_webhook_carries_its_required_secret(): void
    {
        self::assertSame(
            ['kind' => 'webhook', 'label' => 'W', 'webhook_url' => 'https://hooks.example.test/x', 'secret' => 'a-shared-signing-secret'],
            CreateChannelRequest::webhook('W', 'https://hooks.example.test/x', 'a-shared-signing-secret')->toArray(),
        );
    }

    public function test_webhook_requires_a_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateChannelRequest(ChannelKind::Webhook, 'W', webhookUrl: 'https://hooks.example.test/x');
    }

    public function test_blank_label_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CreateChannelRequest::email('  ', 'me@example.test');
    }

    public function test_email_requires_an_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateChannelRequest(ChannelKind::Email, 'X');
    }

    public function test_telegram_requires_a_chat_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateChannelRequest(ChannelKind::Telegram, 'X');
    }

    public function test_webhook_requires_a_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateChannelRequest(ChannelKind::Webhook, 'X');
    }

    public function test_secret_is_only_valid_for_a_webhook(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateChannelRequest(ChannelKind::Email, 'X', address: 'me@example.test', secret: 'nope');
    }
}
