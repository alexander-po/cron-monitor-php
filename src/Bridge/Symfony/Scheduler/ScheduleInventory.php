<?php

declare(strict_types=1);

namespace CronMonitor\Bridge\Symfony\Scheduler;

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\MessageProviderInterface;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;

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
        // RecurringMessage was reshaped in Symfony 6.4: it now holds a
        // `TriggerInterface` and a `MessageProviderInterface` rather than a
        // bare message object. The trigger is reachable via the public
        // `getTrigger()` accessor; the message has to come out of the
        // provider, and the only Symfony-shipped provider that surfaces a
        // concrete message class without spinning up a MessageContext is
        // `StaticMessageProvider`, whose `messages` array we read via
        // reflection. For any other provider shape we fall back to the
        // provider FQCN so the row still identifies something useful.
        $trigger = $recurring->getTrigger();
        $messageClass = $this->resolveMessageClass($recurring->getProvider());

        return new ScheduledJob(
            providerClass: $provider::class,
            messageClass: $messageClass,
            trigger: $this->describeTrigger($trigger),
        );
    }

    private function resolveMessageClass(MessageProviderInterface $messageProvider): string
    {
        if ($messageProvider instanceof StaticMessageProvider) {
            $messages = $this->reflectProperty($messageProvider, 'messages');
            if (\is_array($messages) && [] !== $messages) {
                $first = reset($messages);
                if (\is_object($first)) {
                    return $first::class;
                }
            }
        }

        // Callback or custom providers — we cannot enumerate without a
        // MessageContext (and constructing one here would couple us to
        // internals that may change). Surface the provider FQCN so the
        // dashboard row at least identifies what dispatched the message.
        return $messageProvider::class;
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
