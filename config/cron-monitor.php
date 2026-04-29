<?php

declare(strict_types=1);

use CronMonitor\Client\Configuration;

return [
    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    | Base URL of the cron-monitor instance. Defaults to the SaaS install.
    | Self-hosted users point this at their own deployment. Always HTTPS in
    | production: the per-monitor UUID acts as a write credential and must
    | not be transmitted in clear text.
    */
    'endpoint' => env('CRON_MONITOR_ENDPOINT', Configuration::DEFAULT_ENDPOINT),

    /*
    |--------------------------------------------------------------------------
    | Timeout (seconds)
    |--------------------------------------------------------------------------
    | Per-request timeout for ping deliveries. Keep low so a slow network
    | does not extend job duration measurably. The SDK also honours the
    | global Guzzle/PSR-18 client timeout; this value is the upper bound
    | enforced inside the client.
    */
    'timeout_seconds' => env('CRON_MONITOR_TIMEOUT', Configuration::DEFAULT_TIMEOUT_SECONDS),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    | Per-ping retry budget. Pings are idempotent server-side so retries are
    | always safe; the conservative default keeps the worst-case end-of-job
    | tail latency bounded.
    */
    'retries' => env('CRON_MONITOR_RETRIES', Configuration::DEFAULT_RETRIES),

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    | Optional account-level key for future authenticated routes. Leave
    | empty for the public ping flow (which authenticates via the per-
    | monitor UUID embedded in the URL).
    */
    'api_key' => env('CRON_MONITOR_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Allow insecure endpoint
    |--------------------------------------------------------------------------
    | Required when pointing at a plain-HTTP self-hosted instance. Defaults
    | false so a misconfigured `CRON_MONITOR_ENDPOINT=http://...` fails fast
    | rather than leaking the UUID over the wire.
    */
    'allow_insecure_endpoint' => env('CRON_MONITOR_ALLOW_INSECURE', false),

    /*
    |--------------------------------------------------------------------------
    | Monitor mapping (`->monitor('uuid')` macro)
    |--------------------------------------------------------------------------
    | Optional command-name → UUID map. Not consumed by the runtime today —
    | the macro takes the UUID inline. Reserved for a future "discover and
    | bind by name" flow surfaced via `php artisan cron-monitor:sync`.
    */
    'monitors' => [
        // 'reports:run' => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx',
    ],
];
