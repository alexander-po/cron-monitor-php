<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Input for `POST /api/v1/monitors`.
 *
 * The constructor enforces client-side guards that mirror the backend's
 * validation so callers get a fast, local {@see \InvalidArgumentException}
 * for obvious programmer errors instead of a round-trip `422`. This is
 * distinct from the server-side `ValidationException` the API client raises
 * when the backend rejects an otherwise well-formed request (e.g. an invalid
 * cron expression the SDK cannot evaluate locally).
 */
final class CreateMonitorRequest
{
    /**
     * @param list<int> $channelIds notification channel ids to attach (see {@see Channel})
     */
    public function __construct(
        public readonly string $name,
        public readonly ScheduleKind $scheduleKind,
        public readonly string $scheduleExpr,
        public readonly string $tz = 'UTC',
        public readonly int $graceSeconds = 60,
        public readonly array $channelIds = [],
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Monitor name must be a non-empty string.');
        }
        if ('' === trim($scheduleExpr)) {
            throw new \InvalidArgumentException('Monitor schedule expression must be a non-empty string.');
        }
        if ($graceSeconds < 0) {
            throw new \InvalidArgumentException('graceSeconds must be >= 0.');
        }
        foreach ($channelIds as $id) {
            if (!\is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('channelIds must be a list of positive integers.');
            }
        }
    }

    /**
     * @return array{name: string, schedule_kind: string, schedule_expr: string, tz: string, grace_seconds: int, channel_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'schedule_kind' => $this->scheduleKind->value,
            'schedule_expr' => $this->scheduleExpr,
            'tz' => $this->tz,
            'grace_seconds' => $this->graceSeconds,
            'channel_ids' => array_values($this->channelIds),
        ];
    }
}
