<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Input for `POST /api/v1/channels`.
 *
 * Each {@see ChannelKind} requires a different transport field, so the
 * constructor enforces that the right one is present. A webhook additionally
 * requires a `secret` (the backend signs deliveries with it and rejects a
 * secret-less webhook); `secret` is invalid for every other kind. A PagerDuty
 * channel requires a `routingKey` (the Integration Key of an Events API v2
 * integration), which is invalid for every other kind. Only presence is
 * checked here; the host, length and pattern are the server's to judge.
 * Prefer the named constructors — `email()`, `telegram()`, `slack()`,
 * `discord()`, `webhook()`, `teams()`, `googleChat()`, `pagerDuty()` — which
 * make the per-kind contract self-documenting; the primary constructor is
 * public for cases that build the kind dynamically.
 */
final class CreateChannelRequest
{
    public function __construct(
        public readonly ChannelKind $kind,
        public readonly string $label,
        public readonly ?string $address = null,
        public readonly ?string $chatId = null,
        #[\SensitiveParameter]
        public readonly ?string $webhookUrl = null,
        #[\SensitiveParameter]
        public readonly ?string $secret = null,
        #[\SensitiveParameter]
        public readonly ?string $routingKey = null,
    ) {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Channel label must be a non-empty string.');
        }

        [$field, $value] = match ($kind) {
            ChannelKind::Email => ['address', $address],
            ChannelKind::Telegram => ['chat_id', $chatId],
            ChannelKind::Slack, ChannelKind::Discord, ChannelKind::Webhook, ChannelKind::Teams, ChannelKind::GoogleChat => ['webhook_url', $webhookUrl],
            ChannelKind::PagerDuty => ['routing_key', $routingKey],
        };
        if (null === $value || '' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('A %s channel requires a non-empty "%s".', $kind->value, $field));
        }

        if (ChannelKind::Webhook === $kind) {
            if (null === $secret || '' === trim($secret)) {
                throw new \InvalidArgumentException('A webhook channel requires a non-empty "secret".');
            }
        } elseif (null !== $secret) {
            throw new \InvalidArgumentException('A "secret" is only valid for a webhook channel.');
        }

        if (null !== $routingKey && ChannelKind::PagerDuty !== $kind) {
            throw new \InvalidArgumentException('A "routing_key" is only valid for a pagerduty channel.');
        }
    }

    public static function email(string $label, string $address): self
    {
        return new self(ChannelKind::Email, $label, address: $address);
    }

    public static function telegram(string $label, string $chatId): self
    {
        return new self(ChannelKind::Telegram, $label, chatId: $chatId);
    }

    public static function slack(string $label, #[\SensitiveParameter] string $webhookUrl): self
    {
        return new self(ChannelKind::Slack, $label, webhookUrl: $webhookUrl);
    }

    public static function discord(string $label, #[\SensitiveParameter] string $webhookUrl): self
    {
        return new self(ChannelKind::Discord, $label, webhookUrl: $webhookUrl);
    }

    public static function webhook(string $label, #[\SensitiveParameter] string $webhookUrl, #[\SensitiveParameter] string $secret): self
    {
        return new self(ChannelKind::Webhook, $label, webhookUrl: $webhookUrl, secret: $secret);
    }

    public static function teams(string $label, #[\SensitiveParameter] string $webhookUrl): self
    {
        return new self(ChannelKind::Teams, $label, webhookUrl: $webhookUrl);
    }

    public static function googleChat(string $label, #[\SensitiveParameter] string $webhookUrl): self
    {
        return new self(ChannelKind::GoogleChat, $label, webhookUrl: $webhookUrl);
    }

    public static function pagerDuty(string $label, #[\SensitiveParameter] string $routingKey): self
    {
        return new self(ChannelKind::PagerDuty, $label, routingKey: $routingKey);
    }

    /**
     * The kind, label and every transport field that was set, snake_cased
     * for the wire. The named constructors set exactly the fields of their kind.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $body = [
            'kind' => $this->kind->value,
            'label' => $this->label,
        ];
        if (null !== $this->address) {
            $body['address'] = $this->address;
        }
        if (null !== $this->chatId) {
            $body['chat_id'] = $this->chatId;
        }
        if (null !== $this->webhookUrl) {
            $body['webhook_url'] = $this->webhookUrl;
        }
        if (null !== $this->secret) {
            $body['secret'] = $this->secret;
        }
        if (null !== $this->routingKey) {
            $body['routing_key'] = $this->routingKey;
        }

        return $body;
    }

    /**
     * `print_r()` and `var_dump()` show the webhook URL, the secret and the
     * routing key the way PHP shows a `#[\SensitiveParameter]` argument:
     * whoever holds the URL or the routing key can post to the channel, and
     * whoever holds the secret can sign a delivery.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        $properties['webhookUrl'] = new \SensitiveParameterValue($this->webhookUrl);
        $properties['secret'] = new \SensitiveParameterValue($this->secret);
        $properties['routingKey'] = new \SensitiveParameterValue($this->routingKey);

        return $properties;
    }
}
