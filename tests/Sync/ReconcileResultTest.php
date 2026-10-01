<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Sync;

use CronMonitor\Sync\ReconcilableJob;
use CronMonitor\Sync\ReconcileResult;
use CronMonitor\Tests\Support\SecretTraceAssertions;
use PHPUnit\Framework\TestCase;

final class ReconcileResultTest extends TestCase
{
    use SecretTraceAssertions;

    private const UUID = '00000000-0000-0000-0000-000000000000';

    public function test_a_mis_wired_existing_result_keeps_the_uuid_out_of_trace_arguments(): void
    {
        $notAJob = self::misWiredDependency();

        $this->assertSecretStaysOutOfTraces(self::UUID, static fn () => ReconcileResult::existing($notAJob, self::UUID), \TypeError::class);
    }

    public function test_a_mis_wired_created_result_keeps_the_uuid_out_of_trace_arguments(): void
    {
        $notAJob = self::misWiredDependency();

        $this->assertSecretStaysOutOfTraces(self::UUID, static fn () => ReconcileResult::created($notAJob, self::UUID), \TypeError::class);
    }

    public function test_a_dumped_result_masks_the_uuid_and_the_property_still_carries_it(): void
    {
        $job = new ReconcilableJob('App\\Cron\\Daily', '0 2 * * *');

        foreach ([ReconcileResult::existing($job, self::UUID), ReconcileResult::created($job, self::UUID)] as $result) {
            self::assertStringNotContainsString(self::UUID, print_r($result, true));
            ob_start();
            var_dump($result);
            self::assertStringNotContainsString(self::UUID, (string) ob_get_clean());
            self::assertStringContainsString('App\\Cron\\Daily', print_r($result, true));
            self::assertSame(self::UUID, $result->uuid);
        }
    }
}
