# cron-monitor PHP SDK

Composer SDK for [cronheart.com](https://cronheart.com) — heartbeat
monitoring for scheduled jobs. Get pinged when a cron / systemd timer
/ scheduler entry stops checking in on time. First-class support for
**Symfony Scheduler** and the **Laravel scheduler**.

[![CI](https://github.com/alexander-po/cron-monitor-php/actions/workflows/ci.yml/badge.svg)](https://github.com/alexander-po/cron-monitor-php/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/cron-monitor/php-sdk.svg)](https://packagist.org/packages/cron-monitor/php-sdk)
[![Monthly Downloads](https://img.shields.io/packagist/dm/cron-monitor/php-sdk.svg)](https://packagist.org/packages/cron-monitor/php-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/cron-monitor/php-sdk.svg)](https://packagist.org/packages/cron-monitor/php-sdk)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Why

Uptime monitors don't catch the silent failure mode: a backup that stopped
running a month ago, an invoice job that didn't fire on the 1st, an ETL
pipeline whose systemd timer was renamed. cronheart's per-job dead-man
switch does. This SDK takes the boilerplate out of wiring it up.

## What's in the box

- **Drop-in Symfony bundle** — auto-registered; `bin/console cron-monitor:sync`
  inventories Scheduler `RecurringMessage` providers and console commands.
- **Drop-in Laravel package** — auto-discovered service provider;
  `Schedule::command(...)->monitor()` macro and `MonitorQueueJob`
  middleware for `ShouldQueue` jobs.
- **`#[Monitor(uuid: ...)]` attribute** — the UUID lives on the command
  class instead of being duplicated in YAML / config. Works on both
  Symfony Console and Laravel Artisan commands.
- **Zero extra dependencies** — bundled cURL PSR-18 transport and
  `nyholm/psr7` factories. Bring Guzzle or `symfony/http-client` if you
  want connection pooling; otherwise `composer require` is enough.
- **Never breaks the host job** — every network / HTTP error becomes a
  `PingResult::failed(...)` return value. A broken cron-monitor backend
  cannot punish your scheduler.

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
either map the command name in YAML **or** declare the monitor UUID right
on the command class, and the bundle wraps every invocation in
start/success/fail pings via a kernel event subscriber.

YAML map (good for "configuration as deploy artefact" workflows):

```yaml
cron_monitor:
    commands:
        'app:reports:nightly': 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'
```

Class attribute (good for "the UUID lives next to the code"):

```php
use CronMonitor\Attribute\Monitor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'app:reports:nightly')]
#[Monitor(env: 'CRON_MONITOR_REPORTS_NIGHTLY_UUID')]
final class GenerateNightlyReportCommand extends Command
{
    // ...
}
```

Two attribute forms are supported:

- `#[Monitor(env: 'VAR_NAME')]` — **recommended for production.** The
  attribute carries the env-var *name*; the SDK resolves the value at
  runtime via `$_ENV` → `$_SERVER` → `getenv()`. The UUID — a write
  capability secret — stays out of git history and rotates without a
  redeploy.
- `#[Monitor(uuid: '...literal...')]` — convenient for local dev or
  one-off scripts where the UUID is not sensitive.

(`#[Monitor(uuid: getenv('VAR'))]` is a PHP parse error — attribute
arguments must be compile-time constants — and `'%env(VAR)%'` is not
expanded inside attribute payloads. The two-parameter shape is the
intended way to thread env-sourced UUIDs into the attribute path.)

Both attribute sources are honoured, and **YAML wins on conflict** —
so you can override either form per environment in YAML (e.g.
`'app:reports:nightly': '%env(MY_UUID)%'` with `MY_UUID` blank in dev
to suppress monitoring without touching the attribute). A missing or
empty env var is itself treated as deliberate suppression — same
policy as an empty YAML map entry.

No code changes inside the command body. A non-zero exit fires `fail`; an
uncaught throwable fires `fail` with the exception class, message, and
file:line in the body so the cron-monitor dashboard shows the immediate
cause without you tailing logs.

## Laravel scheduler integration

The service provider is auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag=cron-monitor-config
```

Then in `routes/console.php`, either pass the UUID at the call site
**or** declare it on the command class and call `->monitor()` without
arguments:

```php
use Illuminate\Support\Facades\Schedule;

// Explicit UUID
Schedule::command('reports:nightly')
    ->dailyAt('02:00')
    ->monitor('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx');

// UUID lives on the command class
use App\Console\Commands\GenerateNightlyReportCommand;

Schedule::command(GenerateNightlyReportCommand::class)
    ->dailyAt('02:00')
    ->monitor();
```

For the no-arg form, the macro reads the `#[Monitor]` attribute on the
Artisan command class behind the scheduled event. The attribute has
two construction forms — env-sourced (recommended for production) and
literal:

```php
use CronMonitor\Attribute\Monitor;
use Illuminate\Console\Command;

// Recommended for production — UUID stays out of git history.
#[Monitor(env: 'CRON_MONITOR_REPORTS_NIGHTLY_UUID')]
final class GenerateNightlyReportCommand extends Command
{
    protected $signature = 'reports:nightly';

    // ...
}

// Or with a literal UUID — fine for local dev / one-off scripts.
#[Monitor(uuid: 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx')]
```

The env-sourced form resolves through `$_ENV` → `$_SERVER` →
`getenv()` so it works across CLI, FPM, and container-injected env
setups. A missing or empty env var is treated as deliberate
suppression for the current environment.

Precedence rule: an explicit string argument to `->monitor(...)` always
wins over the attribute, and an empty string (`->monitor(env('MY_UUID', ''))`)
is treated as explicit suppression for the current environment. The
`->monitor(...)` macro hooks `before` / `onSuccess` / `onFailure` so you
get start/success/fail pings on the same boundary as the job execution.

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
