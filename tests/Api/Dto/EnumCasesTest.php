<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Api\Dto;

use CronMonitor\Api\Dto\AlertKind;
use CronMonitor\Api\Dto\ChannelKind;
use CronMonitor\Api\Dto\PingKind;
use CronMonitor\Api\Dto\PlanKey;
use CronMonitor\Api\Dto\SnoozeDuration;
use PHPUnit\Framework\TestCase;

final class EnumCasesTest extends TestCase
{
    public function test_backed_values_match_the_wire_contract(): void
    {
        self::assertSame(['1h', '4h', '1d', '1w'], array_map(static fn (SnoozeDuration $c): string => $c->value, SnoozeDuration::cases()));
        self::assertSame(['heartbeat', 'start', 'success', 'fail'], array_map(static fn (PingKind $c): string => $c->value, PingKind::cases()));
        self::assertSame(['late', 'fail', 'recovered'], array_map(static fn (AlertKind $c): string => $c->value, AlertKind::cases()));
        self::assertSame(['email', 'telegram', 'slack', 'discord', 'webhook'], array_map(static fn (ChannelKind $c): string => $c->value, ChannelKind::cases()));
        self::assertSame(['free', 'starter', 'growth', 'scale'], array_map(static fn (PlanKey $c): string => $c->value, PlanKey::cases()));
    }

    public function test_try_from_rejects_unknown_wire_values(): void
    {
        self::assertNull(SnoozeDuration::tryFrom('2h'));
        self::assertNull(PingKind::tryFrom('pong'));
        self::assertNull(AlertKind::tryFrom('warned'));
        self::assertNull(ChannelKind::tryFrom('sms'));
        self::assertNull(PlanKey::tryFrom('enterprise'));
    }
}
