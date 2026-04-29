<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Scheduler;

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Read-only view of every Symfony Scheduler-defined recurring message in the
 * application, flattened into rows that can be serialised, printed, or
 * pushed to the cron-monitor dashboard.
 *
 * The Symfony Scheduler service tag is `scheduler.schedule_provider`. The
 * extension wires every tagged provider into this inventory so end users
 * never have to think about provider plumbing.
 */
final class ScheduleInventory
{
    /**
     * @param iterable<ScheduleProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return list<ScheduledJob>
     */
    public function jobs(): array
    {
        $jobs = [];
        foreach ($this->providers as $provider) {
            $schedule = $provider->getSchedule();
            if (!$schedule instanceof Schedule) {
                continue;
            }

            foreach ($schedule->getRecurringMessages() as $recurring) {
                $jobs[] = $this->describe($provider, $recurring);
            }
        }

        return $jobs;
    }

    private function describe(ScheduleProviderInterface $provider, RecurringMessage $recurring): ScheduledJob
    {
        // RecurringMessage is intentionally opaque — it holds a Trigger and a
        // message together, both behind protected accessors. We use reflection
        // because the alternative (rebuilding the message via reflection
        // serialisation) is much more brittle. The shape of RecurringMessage
        // has been stable since Symfony 6.3, so this reads more like a
        // structured accessor than a hack.
        $trigger = $this->reflectProperty($recurring, 'trigger');
        $message = $this->reflectProperty($recurring, 'message');

        $messageClass = \is_object($message) ? $message::class : 'unknown';
        $triggerDescription = $this->describeTrigger($trigger);
        $providerClass = $provider::class;

        return new ScheduledJob(
            providerClass: $providerClass,
            messageClass: $messageClass,
            trigger: $triggerDescription,
        );
    }

    private function describeTrigger(mixed $trigger): string
    {
        if (\is_object($trigger)) {
            // Most Trigger implementations override __toString to render
            // the cron expression / interval shape. Fall back to FQCN
            // otherwise so the row is at least identifiable.
            if (method_exists($trigger, '__toString')) {
                return (string) $trigger;
            }

            return $trigger::class;
        }

        return (string) $trigger;
    }

    private function reflectProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionObject($object);
        if (!$reflection->hasProperty($property)) {
            return null;
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }
}
