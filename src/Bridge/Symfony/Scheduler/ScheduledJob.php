<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Scheduler;

/**
 * Flattened row in {@see ScheduleInventory}.
 *
 * Kept as a separate value object (rather than an associative array) so it
 * survives static analysis cleanly when callers fan it out to a CSV / JSON
 * serialiser or to the cron-monitor sync HTTP endpoint.
 */
final class ScheduledJob
{
    public function __construct(
        public readonly string $providerClass,
        public readonly string $messageClass,
        public readonly string $trigger,
    ) {
    }

    /**
     * @return array{provider: string, message: string, trigger: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerClass,
            'message' => $this->messageClass,
            'trigger' => $this->trigger,
        ];
    }
}
