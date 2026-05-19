# cron-monitor PHP SDK

Composer SDK for [cronheart.com](https://cronheart.com) — heartbeat
monitoring for scheduled jobs. Get pinged when a cron / systemd timer
/ scheduler entry stops checking in on time. First-class support for
**Symfony Scheduler** and the **Laravel scheduler**.

[![CI](https://github.com/alexander-po/cron-monitor-php/actions/workflows/ci.yml/badge.svg)](https://github.com/alexander-po/cron-monitor-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Why

Uptime monitors don't catch the silent failure mode: a backup that stopped
running a month ago, an invoice job that didn't fire on the 1st, an ETL
pipeline whose systemd timer was renamed. cronheart's per-job dead-man
switch does. This SDK takes the boilerplate out of wiring it up.

## Install

```bash
composer require cron-monitor/php-sdk
```

PHP ≥ 8.2 with `ext-curl` (the bundled cURL transport uses it; almost every
PHP install has it on).

`composer require` is the only step. The SDK ships with its own minimal cURL
PSR-18 transport plus the PSR-17 factories from the bundled `nyholm/psr7` —
no Guzzle / symfony/http-client required.

- **Symfony bundle path** — drop-in. The bundle prefers
  `symfony/http-client`'s `Psr18Client` if present and falls back to the
  bundled cURL transport otherwise. Your own PSR-17 / PSR-18 bindings
  always win.
- **Laravel path** — auto-discovered. The service provider uses bindings
  from the container when present and falls back to the bundled cURL
  transport + `nyholm/psr7` factories.
- **Framework-agnostic path** (plain PHP / Slim / etc.) — use
  `CronMonitorClient::create()` (see Quick start). Want to plug in
  Guzzle or symfony/http-client? Construct `CronMonitorClient` directly
  and pass your PSR-18 client + PSR-17 factory.

## Quick start (framework-agnostic)

```php
use CronMonitor\Client\CronMonitorClient;

CronMonitorClient::create()->success('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx');
```

Wrap a long-running job:

```php
use CronMonitor\Client\CronMonitorClient;

$client = CronMonitorClient::create();
$uuid   = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';

$client->start($uuid);
try {
    runMyImportantJob();
    $client->success($uuid);
} catch (\Throwable $e) {
    $client->fail($uuid, $e->getMessage());
    throw $e;
}
```

The client **never throws** on network or HTTP errors — a broken
cron-monitor backend will not break your job.

For a custom endpoint (staging / preview), tighter timeout, or API key:

```php
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;

$client = CronMonitorClient::create(
    new Configuration(
        endpoint:       'https://staging.cronheart.com',
        timeoutSeconds: 3.0,
        retries:        2,
        apiKey:         getenv('CRON_MONITOR_API_KEY') ?: null,
    ),
);
```

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

### Plain `bin/console` commands

If your cron entry is a console command rather than a Scheduler run — e.g.
`* * * * * php bin/console app:reports:nightly` straight out of crontab —
map the command name and the bundle wraps every invocation in
start/success/fail pings via a kernel event subscriber:

```yaml
cron_monitor:
    commands:
        'app:reports:nightly': 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'
```

No code changes inside the command. A non-zero exit fires `fail`; an
uncaught throwable fires `fail` with the exception class, message, and
file:line in the body so the cron-monitor dashboard shows the immediate
cause without you tailing logs.

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

### Queued jobs

For `ShouldQueue` jobs dispatched outside the scheduler, attach the bundled
job middleware:

```php
use CronMonitor\Bridge\Laravel\Queue\MonitorQueueJob;

class GenerateNightlyReport implements ShouldQueue
{
    public function middleware(): array
    {
        return [MonitorQueueJob::withUuid('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx')];
    }
}
```

Each invocation pings `start`, then `success` on completion or `fail` on a
thrown exception (with the class, message, and file:line in the body). The
underlying SDK swallows its own failures, so a flaky cron-monitor backend
never breaks the queued job.

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
| `endpoint`                 | `https://cronheart.com`  | Self-hosted: point at your install. |
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
- **`fail` pings include exception text and a host file path.** When a
  monitored handler throws, the SDK sends the exception class name,
  `getMessage()`, and `file:line` of the throw site to the cron-monitor
  endpoint (capped at 10 KB). Exception messages routinely embed
  attacker-controlled input (e.g. `PDOException` SQL fragments,
  validation errors echoing user data); the file path discloses the host
  deployment layout. If your threat model treats either as sensitive,
  wrap the host job in a `try`/`catch` that throws a sanitised
  exception, or call `CronMonitorClient::fail($uuid, $body)` directly
  with a curated body.

## Development

```bash
composer install
composer test          # PHPUnit
composer stan          # PHPStan level 8
composer cs-check      # php-cs-fixer dry-run
```

## License

MIT — see [`LICENSE`](LICENSE).
