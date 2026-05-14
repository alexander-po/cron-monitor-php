<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for the cron-monitor SDK.
 *
 * We need a slightly richer bootstrap than the default `vendor/autoload.php`
 * because the Laravel bridge's `MonitorQueueJob::withUuid()` helper depends
 * on Laravel's global `app()` helper, which lives in `illuminate/foundation`
 * — a package we deliberately do NOT pull in as a dev dependency (its
 * transitive surface is huge and unrelated to anything we ship). Production
 * users have `app()` because Laravel itself defines it; in tests we shim a
 * minimal compatible implementation backed by the lightweight
 * `illuminate/container` we already ship.
 */

require __DIR__.'/../vendor/autoload.php';

if (!\function_exists('app')) {
    /**
     * Minimal `app()` shim sufficient for `MonitorQueueJob::withUuid()`. The
     * production Laravel helper has a richer surface (no-arg returns the
     * container, second-arg parameters, deferred resolution), but
     * `withUuid()` only ever calls it with a single FQCN.
     */
    function app(?string $abstract = null): mixed
    {
        $container = Illuminate\Container\Container::getInstance();
        if (null === $abstract) {
            return $container;
        }

        return $container->make($abstract);
    }
}
