<?php

declare(strict_types=1);

namespace CronMonitor\Tests\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * The scheduler `Event` constructor requires a mutex, but no test runs the
 * event through the scheduler, so nothing consults it.
 */
final class UnusedEventMutex implements EventMutex
{
    public function create(Event $event): never
    {
        throw new \LogicException('the mutex should not be touched in these tests');
    }

    public function exists(Event $event): never
    {
        throw new \LogicException('the mutex should not be touched in these tests');
    }

    public function forget(Event $event): never
    {
        throw new \LogicException('the mutex should not be touched in these tests');
    }
}
