---
name: add-cronheart
description: Add cronheart.com heartbeat monitoring to a PHP application with the cron-monitor/php-sdk package. Use when asked to monitor cron jobs, scheduled console commands, Symfony Scheduler messages, Laravel scheduler tasks or queued jobs with cronheart, or to wire this SDK into a Symfony, Laravel or plain PHP app.
---

# Add cronheart monitoring to a PHP application

Work through the steps in order. Each step says what to run and what its output must show before the next step starts. Every UUID and token in this file is a placeholder; a real one is never written into a file, a command line or a message.

## Rules for every step

1. A monitor UUID is the write credential of one monitor; a Personal Access Token (`cmk_…`) is the credential of the whole account. Both live only in environment variables or the application's secret store. In committed files write `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` and `cmk_xxxxxxxxxxxxxxxx`. Never pass a real value as a command argument or flag (it lands in shell history, CI logs and process listings); export it in the environment of the shell or the cron entry and reference the variable.
2. The ping client never throws and never breaks the job. Keep it that way: no retries, exits or assertions around a ping.
3. Monitors are reconciled by name. A renamed job gets a second monitor, so rename the monitor in the dashboard in the same change.

## Step 1: check the runtime and detect the framework

```bash
php -r 'echo PHP_VERSION, " curl=", extension_loaded("curl") ? "yes" : "no", PHP_EOL;'
composer show --direct --name-only
```

- PHP below 8.2: stop and report; the SDK requires 8.2.
- `curl=no`: the bundled transport needs `ext-curl`. Enable it, or bind a PSR-18 client in the application and continue.
- `symfony/framework-bundle` in the list: Symfony. `symfony/scheduler` as well: the app has Scheduler messages (step 4a-2).
- `laravel/framework` in the list: Laravel.
- Neither: plain PHP (step 4c).

Then list what runs on a schedule; each entry becomes one monitor:

- crontab and systemd timer lines that call `bin/console <name>` or `php artisan <name>`;
- Symfony: `php bin/console debug:scheduler` lists every `RecurringMessage` with its trigger;
- Laravel: `php artisan schedule:list`.

## Step 2: install

```bash
composer require cron-monitor/php-sdk
```

- Symfony: Symfony Flex adds the bundle to `config/bundles.php`. If the file lacks `CronMonitor\Bridge\Symfony\CronMonitorBundle::class => ['all' => true],`, add it. Check: `php bin/console debug:config cron_monitor` prints `endpoint: 'https://cronheart.com'`.
- Laravel: package discovery registers `CronMonitor\Bridge\Laravel\CronMonitorServiceProvider`. Check: `php artisan list cron-monitor` shows `cron-monitor:sync`. If the application's `composer.json` lists the package under `extra.laravel.dont-discover`, register the provider in `bootstrap/providers.php` (Laravel 11) or in the `providers` array of `config/app.php` (Laravel 10).
- Plain PHP: nothing else; `CronMonitor\Client\CronMonitorClient` and the CLI (`vendor/bin/cron-monitor`) are ready.

## Step 3: configuration keys

Five keys, the same on both frameworks:

| Key | Default | Set it when |
| --- | --- | --- |
| `endpoint` | `https://cronheart.com` | the target is another install (staging, self-hosted) |
| `timeout_seconds` | `5.0` | leave it; a ping must not lengthen the job |
| `retries` | `1` | leave it |
| `api_key` | `null` | steps 5 and 6 need it (`cron-monitor:sync --dry-run` / `--apply`, the management client); pings never do |
| `allow_insecure_endpoint` | `false` | `http://` endpoints only; refused together with `api_key` |

### Symfony

Create `config/packages/cron_monitor.yaml`:

```yaml
cron_monitor:
    api_key: '%env(default::CRON_MONITOR_API_KEY)%'
    commands:
        'app:reports:nightly': '%env(CRON_MONITOR_REPORTS_NIGHTLY_UUID)%'
    messages:
        App\Scheduler\Message\NightlyReportRun: '%env(CRON_MONITOR_NIGHTLY_REPORT_RUN_UUID)%'
```

`default::` turns an unset or empty variable into `null`; a plain `%env(CRON_MONITOR_API_KEY)%` resolving to an empty string would be sent as an empty bearer token on every request. Add every per-monitor variable to the committed `.env` with an empty value (`CRON_MONITOR_REPORTS_NIGHTLY_UUID=`): the container cannot resolve a missing variable, and an empty value means "not monitored in this environment". Real values go into `.env.local` or the host environment; `composer dump-env prod` carries them along.

### Laravel

The package reads `CRON_MONITOR_ENDPOINT`, `CRON_MONITOR_API_KEY`, `CRON_MONITOR_TIMEOUT`, `CRON_MONITOR_RETRIES` and `CRON_MONITOR_ALLOW_INSECURE` from the environment without any published file. Do not add an empty `CRON_MONITOR_API_KEY=` line to `.env`: the empty string is sent as an empty bearer token. Publish the config file to hold the per-monitor UUIDs:

```bash
php artisan vendor:publish --tag=cron-monitor-config
```

In `config/cron-monitor.php` fill the `monitors` map from the environment:

```php
'monitors' => [
    'reports:nightly' => env('CRON_MONITOR_REPORTS_NIGHTLY_UUID', ''),
],
```

The runtime does not read this map by itself; step 4b reads it at the call site. Going through `config()` keeps the UUIDs available after `php artisan config:cache`, which stops Laravel from loading `.env`.

## Step 4: attribute each scheduled task to a monitor

### 4a: Symfony

4a-1. A console command started by cron or a systemd timer. Choose one form per command; the YAML map wins when both are present.

The attribute on the command class keeps the UUID with the code and the value in the environment:

```php
use CronMonitor\Attribute\Monitor;

#[AsCommand(name: 'app:reports:nightly')]
#[Monitor(env: 'CRON_MONITOR_REPORTS_NIGHTLY_UUID')]
final class GenerateNightlyReportCommand extends Command
```

The `commands:` map of step 3 is the other form. Either way the bundle's console event subscriber sends `start` when the command begins, `success` on exit code 0 and `fail` otherwise; an uncaught throwable's class, message and file:line go into the fail body.

4a-2. A Symfony Scheduler `RecurringMessage` handled through Messenger. Map the message class in `messages:` (step 3) and put the SDK middleware on the bus that handles it. The bundle registers the middleware as a service, but Messenger runs only the middleware listed in the bus configuration:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - CronMonitor\Bridge\Symfony\Messenger\MonitorPingMiddleware
```

Pings fire on the consumer side (`messenger:consume scheduler_<name>`), once per handled message: `start` before the handler, `success` after it, `fail` when it throws.

### 4b: Laravel

In `routes/console.php` (Laravel 10: `app/Console/Kernel.php`) chain `->monitor(...)` on each scheduled command, reading the UUID from the map of step 3:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:nightly')
    ->dailyAt('02:00')
    ->monitor(config('cron-monitor.monitors')['reports:nightly'] ?? '');
```

An empty string means "not monitored here". The macro hooks the event's `before`, `onSuccess` and `onFailure` callbacks, so the pings fire from `schedule:run`, not from running the command by hand.

The attribute form (`#[Monitor(env: 'CRON_MONITOR_REPORTS_NIGHTLY_UUID')]` on the command class plus a bare `->monitor()`) also works, but it reads the process environment directly, and a production app with cached configuration does not load `.env`. Use it only where the host exports the variable.

A queued job that runs outside the scheduler:

```php
use CronMonitor\Bridge\Laravel\Queue\MonitorQueueJob;

public function middleware(): array
{
    $uuid = config('cron-monitor.monitors')['reports:nightly'] ?? '';

    return '' === $uuid ? [] : [MonitorQueueJob::withUuid($uuid)];
}
```

### 4c: plain PHP

```php
use CronMonitor\Client\CronMonitorClient;

$client = CronMonitorClient::create();
$uuid = getenv('CRON_MONITOR_REPORTS_NIGHTLY_UUID') ?: null;

if (null !== $uuid) {
    $client->start($uuid);
}
try {
    runTheJob();
    if (null !== $uuid) {
        $client->success($uuid);
    }
} catch (\Throwable $e) {
    if (null !== $uuid) {
        $client->fail($uuid, $e->getMessage());
    }
    throw $e;
}
```

Or from the shell, around any command:

```bash
vendor/bin/cron-monitor start "$CRON_MONITOR_REPORTS_NIGHTLY_UUID"
if /opt/app/job.sh 2> /tmp/job.err; then
    vendor/bin/cron-monitor success "$CRON_MONITOR_REPORTS_NIGHTLY_UUID"
else
    vendor/bin/cron-monitor fail "$CRON_MONITOR_REPORTS_NIGHTLY_UUID" < /tmp/job.err
fi
```

`fail` reads its body from standard input when `--body=` is absent, so always redirect something into it. Exit codes: 0 delivered, 2 not delivered (the job continues), 64 usage or configuration error.

## Step 5: get an API token

1. Sign in at cronheart.com and open Account, then API tokens (`https://cronheart.com/account/api-tokens`). Create a token. Tokens are issued on every plan, including Free, once the account's email address is verified. Before the Free plan gained API access this page was a Starter-and-up feature; if `https://cronheart.com/pricing` does not list REST API access on the Free plan, that install predates the change and step 6 falls back to 6c.
2. Export the token where the sync will run: `CRON_MONITOR_API_KEY=cmk_xxxxxxxxxxxxxxxx` in the shell environment or `.env.local`. Symfony resolves `%env()%` at runtime, so no cache clear is needed. Never write it into YAML, PHP or a command line.

## Step 6: create the monitors

6a. Inventory, no network and no token:

```bash
php bin/console cron-monitor:sync      # Symfony
php artisan cron-monitor:sync          # Laravel
```

Symfony prints one row per Scheduler message; console commands driven by crontab are not in that list (handle them in 6c). Laravel prints one row per scheduler event. Both print a config snippet with placeholder UUIDs.

6b. Reconcile against the account:

```bash
php bin/console cron-monitor:sync --dry-run
php bin/console cron-monitor:sync --apply
```

`--dry-run` marks each job `exists`, `would-create`, `conflict` (two jobs share a name; give them distinct names) or `skipped` (not a 5-field cron expression; create it by hand in 6c). `--apply` creates the `would-create` rows and prints each new UUID with a config snippet; `--channel=<id>` routes the created monitors to one notification channel (ids come from the dashboard or `listChannels()`). What the name is: the message FQCN on Symfony; on Laravel the event's full command string, `'/usr/bin/php' 'artisan' reports:nightly`, which includes the PHP binary path and so differs between machines. Run `--apply` once, from the machine that runs the scheduler, and keep the names as created; for clean names create the monitors in 6c instead. Symfony-created monitors are in UTC (set the timezone in the dashboard when the schedule runs elsewhere); Laravel carries the event's timezone. A `failed` row prints the API error (step 8).

6c. Jobs the sync cannot see or create (crontab console commands, interval jobs, plain PHP): create the monitor in the dashboard ("New monitor": name, cron expression, timezone, grace) or with the management client, once per job:

```php
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;

$api = MonitorApiClient::create(
    Configuration::withDefaultEndpoint(apiKey: getenv('CRON_MONITOR_API_KEY') ?: null),
);
$monitor = $api->createMonitor(
    new CreateMonitorRequest(
        name: 'app:reports:nightly',
        scheduleKind: ScheduleKind::Cron,
        scheduleExpr: '0 2 * * *',
        tz: 'UTC',
        graceSeconds: 300,
    ),
    idempotencyKey: 'app:reports:nightly',
);
echo $monitor->uuid, PHP_EOL;
```

The idempotency key makes a rerun return the same monitor instead of a duplicate.

6d. Wire: put each UUID into its variable (`CRON_MONITOR_<JOB>_UUID`) in the environment or `.env.local`, matching the keys of step 3. Never replace the `%env()%` or `env()` reference with the literal.

## Step 7: verify the first ping

7a. Transport and UUID, on any framework:

```bash
vendor/bin/cron-monitor heartbeat "$CRON_MONITOR_REPORTS_NIGHTLY_UUID"
```

Expect `ok heartbeat status=200 attempts=1` and exit code 0. Exit 2 with `ping failed:` means the endpoint did not accept the ping (unreachable host, TLS, a UUID the account does not own or one that was rotated). Exit 64 is a usage or configuration error, such as an `http://` endpoint without `--allow-insecure`.

7b. Wiring, the way production runs the job:

- Symfony console command: `php bin/console app:reports:nightly`; the subscriber pings `start`, then `success` or `fail`.
- Symfony Scheduler: `php bin/console messenger:consume scheduler_<name> --limit=1 -vv` waits for the next trigger (`debug:scheduler` shows when), handles one message and exits.
- Laravel scheduler: `php artisan schedule:test --name="reports:nightly"` runs that event with its callbacks, so the pings fire without waiting for the schedule.
- Laravel queued job: dispatch it, then `php artisan queue:work --once`.

7c. Confirm on the server. The dashboard shows the monitor with a last ping and status `up`, or from PHP:

```php
use CronMonitor\Api\Dto\Vocabulary;

$monitor = $api->getMonitor((string) getenv('CRON_MONITOR_REPORTS_NIGHTLY_UUID'));
printf("%s %s\n", Vocabulary::value($monitor->status), $monitor->lastPingAt?->format('c') ?? 'never');
```

`up` with a timestamp: done. `new` and `never`: no ping arrived; compare the variable name in the map with the environment, check the middleware is on the bus (4a-2) and that the Laravel run went through the scheduler (4b). `late` or `down`: the monitor's schedule or grace does not match how often the job runs. `paused`: paused in the dashboard.

## Step 8: what the exceptions mean

Only the management client throws (`MonitorApiClient`, and `cron-monitor:sync --dry-run` / `--apply` through it); the ping client and the bridges never do. Every exception extends `CronMonitor\Api\Exception\ApiException` and carries `statusCode`, `detail` and `title`.

| Exception | HTTP | Meaning | Do |
| --- | --- | --- | --- |
| `ApiTransportException` | none | No usable response: DNS, connection, TLS, timeout, or a body that could not be decoded. | Check `endpoint`, network egress and the CA bundle. `http://` is refused unless `allow_insecure_endpoint`, and never with an `api_key`. |
| `AuthenticationException` | 401 | The token is missing, malformed, revoked or expired. | Check `CRON_MONITOR_API_KEY` is exported where the command runs and starts with `cmk_`; an empty value is sent as an empty token. |
| `PlanRestrictionException` | 402 | The account's plan does not include API access; `upgradeUrl` links to the plan page. | An install where the Free plan has API access never sends it. On one that predates that change, create the monitors in the dashboard (6c) and skip `--apply`. |
| `ForbiddenException` | 403 | The token is valid but the action is refused: the plan's monitor limit is reached, or the account's email is not verified. | Verify the email, delete unused monitors or upgrade; existing monitors keep working. |
| `NotFoundException` | 404 | No such monitor or channel, including one that belongs to another account. | Check the UUID or id and the account the token belongs to. |
| `ConflictException` | 409 | The request conflicts with the current state, such as a disabled notification transport. | Read `detail`. |
| `ValidationException` | 422 | A field was rejected; `errors` maps field to message. | Fix the schedule expression or timezone. |
| `RateLimitException` | 429 | The account's request limit for its plan; `retryAfter` is the wait in seconds. | Wait and rerun; creates are never retried automatically. |
| `UnexpectedResponseException` | 400, 5xx, other | A malformed request or a server error. | Rerun later. `ChannelDeliveryException` is its 502 subclass, raised only by `testChannel()` when the destination refused the test alert. |

The sync command's own message `cron-monitor:sync --apply/--dry-run needs an API token` means `api_key` resolved to `null`: step 5.

## Step 9: report

Report the jobs with their monitor names and variable names (never the values), the outputs of 7a, 7b and 7c, and what stays manual: interval jobs, the timezone of Symfony-created monitors, crontab commands created by hand.
