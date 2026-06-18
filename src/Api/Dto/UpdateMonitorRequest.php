<?php

declare(strict_types=1);

namespace CronMonitor\Api\Dto;

/**
 * Input for `PATCH /api/v1/monitors/{uuid}` — a partial update.
 *
 * Every field is optional: only the non-null fields are sent, so the backend
 * leaves the rest untouched. Client-side guards mirror those of
 * {@see CreateMonitorRequest} but fire only when a field is actually provided.
 *
 * `channelIds` follows the backend's "presence replaces routing" rule: pass
 * null to leave channel routing as-is, or an array (including the empty array)
 * to replace it — `[]` clears all channels.
 */
final class UpdateMonitorRequest
{
    /**
     * @param list<int>|null $channelIds
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?ScheduleKind $scheduleKind = null,
        public readonly ?string $scheduleExpr = null,
        public readonly ?string $tz = null,
        public readonly ?int $graceSeconds = null,
        public readonly ?array $channelIds = null,
    ) {
        if (null !== $name && '' === trim($name)) {
            throw new \InvalidArgumentException('Monitor name, when provided, must be a non-empty string.');
        }
        if (null !== $scheduleExpr && '' === trim($scheduleExpr)) {
            throw new \InvalidArgumentException('Monitor schedule expression, when provided, must be a non-empty string.');
        }
        if (null !== $graceSeconds && $graceSeconds < 0) {
            throw new \InvalidArgumentException('graceSeconds, when provided, must be >= 0.');
        }
        if (null !== $channelIds) {
            foreach ($channelIds as $id) {
                if (!\is_int($id) || $id <= 0) {
                    throw new \InvalidArgumentException('channelIds, when provided, must be a list of positive integers.');
                }
            }
        }
    }

    /**
     * Whether this patch would change nothing (every field null). The client
     * rejects an empty patch locally instead of sending a no-op request.
     */
    public function isEmpty(): bool
    {
        return [] === $this->toArray();
    }

    /**
     * Only the provided (non-null) fields, snake_cased for the wire.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [];
        if (null !== $this->name) {
            $body['name'] = $this->name;
        }
        if (null !== $this->scheduleKind) {
            $body['schedule_kind'] = $this->scheduleKind->value;
        }
        if (null !== $this->scheduleExpr) {
            $body['schedule_expr'] = $this->scheduleExpr;
        }
        if (null !== $this->tz) {
            $body['tz'] = $this->tz;
        }
        if (null !== $this->graceSeconds) {
            $body['grace_seconds'] = $this->graceSeconds;
        }
        if (null !== $this->channelIds) {
            $body['channel_ids'] = array_values($this->channelIds);
        }

        return $body;
    }
}
