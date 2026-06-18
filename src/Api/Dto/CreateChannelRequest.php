<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Input for `POST /api/v1/channels`.
 *
 * Each {@see ChannelKind} requires a different transport field, so the
 * constructor enforces that the right one is present. A webhook additionally
 * requires a `secret` (the backend signs deliveries with it and rejects a
 * secret-less webhook); `secret` is invalid for every other kind. Prefer the
 * named constructors — `email()`, `telegram()`, `slack()`, `discord()`,
 * `webhook()` — which make the per-kind contract self-documenting; the primary
 * constructor is public for cases that build the kind dynamically.
 */
final class CreateChannelRequest
{
    public function __construct(
        public readonly ChannelKind $kind,
        public readonly string $label,
        public readonly ?string $address = null,
        public readonly ?string $chatId = null,
        public readonly ?string $webhookUrl = null,
        public readonly ?string $secret = null,
    ) {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Channel label must be a non-empty string.');
        }

        [$field, $value] = match ($kind) {
            ChannelKind::Email => ['address', $address],
            ChannelKind::Telegram => ['chat_id', $chatId],
            ChannelKind::Slack, ChannelKind::Discord, ChannelKind::Webhook => ['webhook_url', $webhookUrl],
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
    }

    public static function email(string $label, string $address): self
    {
        return new self(ChannelKind::Email, $label, address: $address);
    }

    public static function telegram(string $label, string $chatId): self
    {
        return new self(ChannelKind::Telegram, $label, chatId: $chatId);
    }

    public static function slack(string $label, string $webhookUrl): self
    {
        return new self(ChannelKind::Slack, $label, webhookUrl: $webhookUrl);
    }

    public static function discord(string $label, string $webhookUrl): self
    {
        return new self(ChannelKind::Discord, $label, webhookUrl: $webhookUrl);
    }

    public static function webhook(string $label, string $webhookUrl, string $secret): self
    {
        return new self(ChannelKind::Webhook, $label, webhookUrl: $webhookUrl, secret: $secret);
    }

    /**
     * The kind, label, and exactly the transport field(s) for that kind,
     * snake_cased for the wire.
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

        return $body;
    }
}
