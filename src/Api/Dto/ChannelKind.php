<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Notification-channel transport kind.
 *
 * Intended for request-side use when creating a channel: the create-channel
 * request DTO branches on the kind to require the right transport field. The
 * {@see Channel} response DTO deliberately keeps its `kind` as a plain string
 * — the set may grow and nothing in the SDK branches on a *returned* channel's
 * kind, so an enum there would add parse-fragility for no benefit.
 */
enum ChannelKind: string
{
    case Email = 'email';
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Discord = 'discord';
    case Webhook = 'webhook';
}
