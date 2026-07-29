<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

use CronMonitor\Api\Internal\Hydrator;

/**
 * One notification channel a monitor routes its alerts to, as embedded in a
 * monitor payload ({@see Monitor::$channels}).
 *
 * A deliberately slim view of a {@see Channel}: the backend embeds only the
 * identity needed to recognise the destination. `verified` and `config` are
 * not part of it — call `MonitorApiClient::getChannel()` when you need those.
 *
 * It reports what is **attached, not what will be delivered**. Attachment is
 * necessary but not sufficient: the backend also skips an unverified channel,
 * any delivery while the monitor is snoozed or paused, and a channel kind an
 * operator has switched off. So a non-empty {@see Monitor::$channels} is not a
 * promise that an alert reaches anyone — {@see Channel::$verified} and
 * {@see Monitor::$snoozedUntil} narrow it down, and {@see Alert::$dispatchedTo}
 * on a past alert is the only per-channel evidence of an actual send.
 *
 * `kind` stays a plain string for the same reason {@see Channel::$kind} does:
 * the set of channel kinds is the most likely part of the API to grow, and a
 * kind added server-side must not make an entire monitor read unhydratable
 * for a consumer that never branches on it. That tolerance is about the
 * *vocabulary*, not the *types*: a field of the wrong JSON type still fails
 * the read loudly, deliberately, exactly as every other hydrator here does.
 *
 * `id` is the backend's BIGINT identifier carried as a string, matching the
 * API's serialized type, and it is what {@see CreateMonitorRequest} /
 * {@see UpdateMonitorRequest} accept as a `channelIds` entry — so one
 * monitor's routing copies onto another with no conversion.
 */
final class MonitorChannel
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'id'),
            Hydrator::string($data, 'kind'),
            Hydrator::string($data, 'label'),
        );
    }
}
