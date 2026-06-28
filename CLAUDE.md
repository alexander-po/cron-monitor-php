# CLAUDE.md

Project-specific notes for agents (Claude Code, Cursor, etc.) working in
this repository. The backend service this SDK pings is the cron-monitor
server behind cronheart.com (a separate, private repository).

## What this repo is

`cron-monitor/php-sdk` — a Composer library that PHP applications use to
send heartbeat / start / success / fail pings to
[cronheart.com](https://cronheart.com) (the production host of the
cron-monitor service). PHP ≥ 8.2, PSR-18 / PSR-17 / PSR-3 abstract HTTP
dependencies, first-class Symfony Scheduler + Messenger and Laravel
Scheduler bridges.

Source of truth for the wire contract — ping URL shape, body cap, action
regex, accepted HTTP responses — is the cron-monitor backend (the private
service repo: its ping controller and `Ping` entity). When you change either
side, update both.

## Hard contract: never break the host job

Every code path in this SDK runs **inside the user's scheduled job**. A
broken cron-monitor backend, an unreachable network, a misbehaving PSR-18
client — none of them may cause the wrapping job to fail. The whole point
of the service is to detect when a scheduled job stops running; if our SDK
becomes the cause, we invert the value we're meant to provide.

Concrete rules this turns into:

- `CronMonitorClient` swallows network and HTTP errors into a logged
  warning + a `PingResult::failed(...)` return value. No `throw`.
- Every bridge (Symfony Messenger middleware, Symfony Console subscriber,
  Laravel scheduler `EventMonitor`, Laravel queue `MonitorQueueJob`)
  wraps SDK calls in a defensive `safePing` / try-catch. The SDK
  contract says it does not throw, but if a custom PSR-18 stack
  violates that, we still must not break the host job.
- `MonitorQueueJob::withUuid()` catches `\Throwable` (not just
  `\Exception`) — container failures can surface as `Error`, and the
  job must still run.

If you add a new bridge, mirror this discipline. Any new file that calls
the client must have at least one test that simulates a thrown SDK error
and asserts the host-job equivalent still completes.

## Branch & commit conventions

Mirrors the sibling project — same rules, same reasoning:

- **Never commit directly to `main`.** Every change lives on its own
  feature branch and lands on `main` via a merged pull request. No
  exceptions for "small" or "docs-only".
- **Branch naming**: `feature/<short-kebab-topic>`. The topic must
  describe **what was done**, not just the area touched
  (`feature/console-subscriber` ✓, `feature/symfony-stuff` ✗).
- **One commit per branch.** Before opening the PR, squash review /
  fixup / "wip" commits into a single self-contained commit. The
  canonical recipe:
  ```bash
  git reset --soft origin/main
  git commit  # one commit, with a real message
  git push --force-with-lease
  ```
  Rationale: `main` history is linear, every commit on `main` is
  revertable, `git bisect` is usable, and the deploy audit trail maps
  1:1 to reviewed PRs.
- Local `main` must always equal `origin/main`. If you find yourself
  with a direct-to-main commit, move it to a branch
  (`git branch <topic> <sha> && git reset --hard origin/main`) before
  pushing.
- **Don't add `Co-Authored-By: Claude` trailers** to commit messages.

## Running the toolchain locally

No PHP install required on the host — everything runs in a Docker
container against the vendored dependencies:

```bash
# Tests
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit

# PHPStan (level 8 — needs more memory than the 128M default)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
    php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress

# php-cs-fixer (dry-run)
docker run --rm -v "$PWD":/app -w /app -e PHP_CS_FIXER_IGNORE_ENV=1 \
    php:8.2-cli vendor/bin/php-cs-fixer fix --dry-run --diff

# Coverage (pcov)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli sh -c \
    "pecl install pcov >/dev/null 2>&1; docker-php-ext-enable pcov >/dev/null 2>&1; \
     php -d pcov.enabled=1 -d pcov.directory=/app/src \
     vendor/bin/phpunit --coverage-text --no-progress"
```

CI runs the same three checks plus `composer audit` over GitHub's PHP
8.2 / 8.3 / 8.4 matrix on the test job; lint + audit run on 8.2 only.

## Test bootstrap

`tests/bootstrap.php` shims Laravel's global `app()` helper using
`Illuminate\Container\Container` so the `MonitorQueueJob::withUuid()`
test path can exercise both happy and container-unbound branches.
Production users have `app()` from `illuminate/foundation` (which we
deliberately do not pull in as a dev-dep — its transitive surface is
huge and unrelated).

When testing Laravel scheduler integration (`EventMonitor`), we extract
the inner callbacks out of Laravel's `function (Container $container) use
($callback)` wrappers via `ReflectionFunction::getStaticVariables()`,
bypassing `BoundMethod::call()` so the assertion reaches the real ping
callback without booting a container. See
`tests/Bridge/Laravel/Scheduler/EventMonitorTest.php`.

## Wire contract anchors

These constants and regex are anchored to the backend. If you change one
side, change the other in the same PR (cross-repo, separately reviewed).
The backend lives in its own private repo, so its column names the concept,
not a file path:

| Item                  | SDK location                                | Backend anchor                                            |
|-----------------------|---------------------------------------------|-----------------------------------------------------------|
| `BODY_CAP_BYTES`      | `src/Client/CronMonitorClient.php` (10 000) | backend `Ping` entity body-excerpt cap (10 000)           |
| Action segment regex  | `src/Client/Configuration.php` (`{1,16}`)   | backend ping route (`{1,16}`)                             |
| UUID validation       | `src/Client/Configuration.php` (canonical) | backend ping route (`{36}` hex)                           |
| Default endpoint      | `src/Client/Configuration.php`              | production host (`cronheart.com`)                         |
| Snooze durations      | `src/Api/Dto/SnoozeDuration.php` (`1h/4h/1d/1w`) | backend `SnoozeDuration` enum                        |
| Channel kinds         | `src/Api/Dto/ChannelKind.php` (request-side) | backend `ChannelKind` enum                              |
| Plan keys             | `src/Api/Dto/PlanKey.php` (`free/starter/growth/scale`) | backend `Plan` enum                          |
| BIGINT ids as strings | `Ping`/`Alert`/`Channel` `$id` (`string`)   | backend serialises Doctrine BIGINT `getId()` as JSON string |
| 204 / 502 contract    | `requestNoContent()` (204); generic 502 → `UnexpectedResponseException`, `testChannel()` 502 → `ChannelDeliveryException` (subclass) | backend DELETE routes; channel-test bad-gateway (502) |
| `Idempotency-Key`     | `MonitorApiClient::idempotency()` header    | backend idempotency guard (full-body fingerprint)         |

## What this SDK does NOT do

Don't add these without explicit design discussion:

- Async / fire-and-forget pings (curl_multi, fork, queue). Today every
  ping is synchronous against a 5-second timeout. Adding async means
  rethinking the retry budget, ordering guarantees, and resource
  cleanup on a host job that's about to exit.
- Server-side polling. The public `/ping/<uuid>` flow is
  anonymous-via-UUID-as-credential by design; there is no long-poll or
  webhook-subscription surface in the SDK.
- The management client (`CronMonitor\Api\MonitorApiClient`) THROWS
  (admin / CLI context); keep it strictly separate from the no-throw ping
  client. 1.0.0 shipped list / get / create monitors + list channels;
  1.1.0 rounded it out to the backend's full `/api/v1` surface — monitor
  lifecycle (update / delete / pause / resume / snooze / rotate-uuid),
  history reads (pings cursor-paginated, alerts offset-paginated), channel
  lifecycle (create / get / rename / delete / rotate-secret / test),
  `getAccount`, and optional `Idempotency-Key` on creates. The bridge
  `cron-monitor:sync` also gained `--apply` (create missing monitors via
  the shared `src/Sync/MonitorReconciler`). 1.2.0 added
  `ChannelDeliveryException`: `testChannel()`'s 502 (downstream delivery
  failed) now rethrows this `UnexpectedResponseException` subclass, while
  `ExceptionFactory` stays generic — a 502 from any other endpoint is still
  a plain `UnexpectedResponseException`. See README "Managing monitors via
  the API". Still deferred to a later minor: surfacing
  `Idempotency-Replayed`.
- Bundled framework version pins. Composer constraints stay loose
  (`^6.4 || ^7.0` for Symfony, `^10.0 || ^11.0` for Laravel); the
  user's app pins.
