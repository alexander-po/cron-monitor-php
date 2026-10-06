<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\ChannelKind;
use CronMonitor\Api\Dto\CreateChannelRequest;
use CronMonitor\Tests\Support\SecretDumpAssertions;
use CronMonitor\Tests\Support\SecretTraceAssertions;
use PHPUnit\Framework\TestCase;

final class CreateChannelRequestTest extends TestCase
{
    use SecretDumpAssertions;
    use SecretTraceAssertions;

    private const WEBHOOK_URL = 'https://hooks.example.test/deliver/fake-webhook-path-request';
    private const SECRET = 'signing-secret-fake-request';
    private const ROUTING_KEY = 'fakeroutingkey0000000000fake0001';

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

    public function test_teams_and_google_chat_use_webhook_url(): void
    {
        self::assertSame(
            ['kind' => 'teams', 'label' => 'T', 'webhook_url' => 'https://example.environment.api.powerplatform.test/x'],
            CreateChannelRequest::teams('T', 'https://example.environment.api.powerplatform.test/x')->toArray(),
        );
        self::assertSame(
            ['kind' => 'google_chat', 'label' => 'G', 'webhook_url' => 'https://chat.googleapis.test/v1/spaces/x/messages'],
            CreateChannelRequest::googleChat('G', 'https://chat.googleapis.test/v1/spaces/x/messages')->toArray(),
        );
    }

    public function test_pagerduty_carries_its_routing_key(): void
    {
        self::assertSame(
            ['kind' => 'pagerduty', 'label' => 'P', 'routing_key' => self::ROUTING_KEY],
            CreateChannelRequest::pagerDuty('P', self::ROUTING_KEY)->toArray(),
        );
    }

    public function test_the_new_kinds_require_their_transport_field(): void
    {
        foreach ([ChannelKind::Teams, ChannelKind::GoogleChat, ChannelKind::PagerDuty] as $kind) {
            try {
                new CreateChannelRequest($kind, 'X');
                self::fail('Expected an exception for '.$kind->value);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($kind->value, $e->getMessage());
            }
        }
        try {
            new CreateChannelRequest(ChannelKind::PagerDuty, 'X', routingKey: '  ');
            self::fail('Expected an exception for a blank routing key.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('routing_key', $e->getMessage());
        }
    }

    public function test_only_presence_of_the_routing_key_is_checked_client_side(): void
    {
        self::assertSame('short', CreateChannelRequest::pagerDuty('P', 'short')->toArray()['routing_key']);
    }

    public function test_a_routing_key_is_only_valid_for_pagerduty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateChannelRequest(ChannelKind::Slack, 'X', webhookUrl: 'https://hooks.example.test/x', routingKey: self::ROUTING_KEY);
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

    public function test_a_rejected_request_keeps_the_webhook_url_and_secret_out_of_trace_arguments(): void
    {
        $rejected = [
            [static fn () => CreateChannelRequest::slack(' ', self::WEBHOOK_URL), [self::WEBHOOK_URL]],
            [static fn () => CreateChannelRequest::discord(' ', self::WEBHOOK_URL), [self::WEBHOOK_URL]],
            [static fn () => CreateChannelRequest::webhook(' ', self::WEBHOOK_URL, self::SECRET), [self::WEBHOOK_URL, self::SECRET]],
            [static fn () => new CreateChannelRequest(ChannelKind::Slack, 'S', webhookUrl: self::WEBHOOK_URL, secret: self::SECRET), [self::WEBHOOK_URL, self::SECRET]],
            [static fn () => CreateChannelRequest::teams(' ', self::WEBHOOK_URL), [self::WEBHOOK_URL]],
            [static fn () => CreateChannelRequest::googleChat(' ', self::WEBHOOK_URL), [self::WEBHOOK_URL]],
            [static fn () => CreateChannelRequest::pagerDuty(' ', self::ROUTING_KEY), [self::ROUTING_KEY]],
            [static fn () => new CreateChannelRequest(ChannelKind::Slack, 'S', webhookUrl: self::WEBHOOK_URL, routingKey: self::ROUTING_KEY), [self::WEBHOOK_URL, self::ROUTING_KEY]],
        ];

        foreach ($rejected as [$build, $secrets]) {
            foreach ($secrets as $secret) {
                $this->assertSecretStaysOutOfTraces($secret, $build, \InvalidArgumentException::class);
            }
        }
    }

    public function test_a_dumped_webhook_request_masks_the_url_and_the_secret_and_the_properties_still_carry_them(): void
    {
        $request = CreateChannelRequest::webhook('Ops webhook', self::WEBHOOK_URL, self::SECRET);

        self::assertDumpsMask($request, [self::WEBHOOK_URL, self::SECRET], ['webhookUrl', 'secret']);
        self::assertSame(self::WEBHOOK_URL, $request->webhookUrl);
        self::assertSame(self::SECRET, $request->secret);
    }

    public function test_a_dumped_pagerduty_request_masks_the_routing_key_and_the_property_still_carries_it(): void
    {
        $request = CreateChannelRequest::pagerDuty('On call', self::ROUTING_KEY);

        self::assertDumpsMask($request, [self::ROUTING_KEY], ['routingKey']);
        self::assertSame(self::ROUTING_KEY, $request->routingKey);
    }

    public function test_a_dumped_teams_request_masks_the_webhook_url(): void
    {
        self::assertDumpsMask(CreateChannelRequest::teams('Ops', self::WEBHOOK_URL), [self::WEBHOOK_URL], ['webhookUrl']);
        self::assertDumpsMask(CreateChannelRequest::googleChat('Ops', self::WEBHOOK_URL), [self::WEBHOOK_URL], ['webhookUrl']);
    }
}
