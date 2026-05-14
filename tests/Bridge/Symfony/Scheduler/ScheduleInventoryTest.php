<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Bridge\Symfony\Scheduler;

use CronMonitor\Bridge\Symfony\Scheduler\ScheduledJob;
use CronMonitor\Bridge\Symfony\Scheduler\ScheduleInventory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Drives `ScheduleInventory` end-to-end with a real Symfony Scheduler
 * provider, a real `RecurringMessage::every(...)`, and a real message
 * object. The class reads opaque private state via reflection at runtime,
 * so the only way to validate it stays in sync with Symfony is to feed it
 * the same objects the bundle does in production.
 */
final class ScheduleInventoryTest extends TestCase
{
    public function test_flattens_messages_from_every_tagged_provider(): void
    {
        $inventory = new ScheduleInventory([
            $this->providerWith(new SampleNightlyMessage(), 'PT1H'),
            $this->providerWith(new SampleWeeklyMessage(), 'P1D'),
        ]);

        $jobs = $inventory->jobs();

        self::assertCount(2, $jobs);
        self::assertContainsOnlyInstancesOf(ScheduledJob::class, $jobs);

        $messageClasses = array_map(static fn (ScheduledJob $j) => $j->messageClass, $jobs);
        self::assertContains(SampleNightlyMessage::class, $messageClasses);
        self::assertContains(SampleWeeklyMessage::class, $messageClasses);
    }

    public function test_describe_trigger_uses_string_representation_when_available(): void
    {
        $inventory = new ScheduleInventory([
            $this->providerWith(new SampleNightlyMessage(), 'PT1H'),
        ]);

        $jobs = $inventory->jobs();

        self::assertCount(1, $jobs);
        // Symfony's PeriodicalTrigger implements __toString; the inventory
        // must surface that rather than the trigger's FQCN — the whole point
        // of the table the SyncCommand prints is to be readable.
        self::assertNotSame('', $jobs[0]->trigger);
        self::assertStringNotContainsString('PeriodicalTrigger', $jobs[0]->trigger);
    }

    public function test_provider_returning_non_schedule_is_skipped(): void
    {
        // Some bundles register provider services that lazily build their
        // schedule and may return null/empty in certain environments
        // (preview deployments, CI without all secrets). The inventory must
        // not crash — it must just skip those providers.
        $broken = new class implements ScheduleProviderInterface {
            public function getSchedule(): Schedule
            {
                return new Schedule();
            }
        };

        $inventory = new ScheduleInventory([$broken]);
        self::assertSame([], $inventory->jobs());
    }

    public function test_scheduled_job_to_array_shape_is_stable(): void
    {
        $job = new ScheduledJob(
            providerClass: 'App\\Schedule\\Provider',
            messageClass: SampleNightlyMessage::class,
            trigger: 'every 1 hour',
        );

        self::assertSame(
            [
                'provider' => 'App\\Schedule\\Provider',
                'message' => SampleNightlyMessage::class,
                'trigger' => 'every 1 hour',
            ],
            $job->toArray(),
        );
    }

    private function providerWith(object $message, string $frequency): ScheduleProviderInterface
    {
        return new class($message, $frequency) implements ScheduleProviderInterface {
            public function __construct(
                private readonly object $message,
                private readonly string $frequency,
            ) {
            }

            public function getSchedule(): Schedule
            {
                return (new Schedule())->add(
                    RecurringMessage::every($this->frequency, $this->message),
                );
            }
        };
    }
}

final class SampleNightlyMessage
{
}

final class SampleWeeklyMessage
{
}
