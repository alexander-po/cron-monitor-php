# cron-monitor PHP SDK

Composer SDK for [cron-monitor.io](https://cron-monitor.io) — a Healthchecks-style
service that pings you when a scheduled job stops running. First-class
support for **Symfony Scheduler** and the **Laravel scheduler**.

[![CI](https://github.com/alexander-po/cron-monitor-php/actions/workflows/ci.yml/badge.svg)](https://github.com/alexander-po/cron-monitor-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Why

Uptime monitors don't catch the silent failure mode: a backup that stopped
running a month ago, an invoice job that didn't fire on the 1st, an ETL
pipeline whose systemd timer was renamed. cron-monitor's per-job dead-man
switch does. This SDK takes the boilerplate out of wiring it up.

## Install

```bash
composer require cron-monitor/php-sdk
```

PHP ≥ 8.1. PSR-18 / PSR-17 / PSR-3 dependencies are abstract — bring any
HTTP client, the SDK falls back to Guzzle when one is not bound.

## Quick start (framework-agnostic)

```php
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;

$config  = Configuration::withDefaultEndpoint();
$factory = new HttpFactory();
$client  = new CronMonitorClient(
    $config,
    new Guzzle(['timeout' => $config->timeoutSeconds]),
    $factory,
    $factory,
);

$client->start('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx');
try {
    runMyImportantJob();
    $client->success('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx');
} catch (\Throwable $e) {
    $client->fail('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx', $e->getMessage());
    throw $e;
}
```

The client **never throws** on network or HTTP errors — a broken
cron-monitor backend will not break your job.

## Symfony Scheduler integration

Register the bundle (Flex usually does this for you):

```php
// config/bundles.php
return [
    // ...
    CronMonitor\Bridge\Symfony\CronMonitorBundle::class => ['all' => true],
];
```

Configure:

```yaml
# config/packages/cron_monitor.yaml
cron_monitor:
    endpoint:        '%env(CRON_MONITOR_ENDPOINT)%'  # optional, defaults to SaaS
    timeout_seconds: 5.0
    retries:         1
    api_key:         '%env(CRON_MONITOR_API_KEY)%'   # optional
    messages:
        # FQCN of any message your Scheduler dispatches via Messenger.
        # The middleware ships start/success/fail pings for each one.
        App\Scheduler\Message\NightlyReportRun: 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'
```

Discover the FQCNs the bundle can see:

```bash
php bin/console cron-monitor:sync
```

It prints every `RecurringMessage` from every tagged
`scheduler.schedule_provider`, plus a YAML snippet you can paste into the
config above.

## Laravel scheduler integration

The service provider is auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag=cron-monitor-config
```

Then in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:nightly')
    ->dailyAt('02:00')
    ->monitor('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx');
```

The `->monitor(...)` macro hooks `before` / `onSuccess` / `onFailure` so
you get start/success/fail pings on the same boundary as the job execution.

`php artisan cron-monitor:sync` lists every scheduled command and emits a
`config/cron-monitor.php` snippet.

## Standalone CLI

For plain cron / systemd timer users, the `vendor/bin/cron-monitor`
binary doesn't require any framework:

```bash
# Heartbeat from a one-liner cron entry
* * * * * /opt/scripts/run.sh && cron-monitor heartbeat $UUID

# Wrap a job with start/success/fail
cron-monitor start $UUID
if /opt/scripts/run.sh 2> /tmp/err; then
    cron-monitor success $UUID
else
    cron-monitor fail $UUID --body="$(cat /tmp/err)"
fi
```

Set `CRON_MONITOR_ENDPOINT` and `CRON_MONITOR_API_KEY` in the environment
to avoid repeating the flags.

## Configuration knobs

| Setting                    | Default                  | Notes |
|----------------------------|--------------------------|-------|
| `endpoint`                 | `https://cron-monitor.io`| Self-hosted: point at your install. |
| `timeout_seconds`          | `5.0`                    | Per-request, low by design. |
| `retries`                  | `1`                      | Pings are idempotent server-side. |
| `api_key`                  | `null`                   | Reserved for future authenticated routes. |
| `allow_insecure_endpoint`  | `false`                  | Required for `http://` endpoints. |

## Security

- HTTPS is required by default. The SDK refuses to send pings to plain
  HTTP unless `allow_insecure_endpoint: true` is explicitly set.
- The per-monitor UUID is treated as a write credential and is validated
  against the canonical UUID v4 shape before being concatenated into a
  URL — no path traversal via the action segment.
- The `Authorization: Bearer <api_key>` header is attached only when an
  API key is configured; nothing is sent for anonymous installs.

## Development

```bash
composer install
composer test          # PHPUnit
composer stan          # PHPStan level 8
composer cs-check      # php-cs-fixer dry-run
```

## License

MIT — see [`LICENSE`](LICENSE).
