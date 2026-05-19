# Changelog

All notable changes to the `cron-monitor/php-sdk` package land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet — open a PR and add your entry under the appropriate subsection._

## [0.2.1] — 2026-05-19

Patch release that closes a real DX gap surfaced right after 0.2.0
shipped: the `#[Monitor]` attribute could only carry literal UUIDs,
but the per-monitor UUID is a write capability secret that belongs in
env vars, not in source. PHP attribute arguments are limited to
compile-time constant expressions — `#[Monitor(uuid: getenv('FOO'))]`
is a parse error, and `'%env(FOO)%'` reaches the resolver as a
literal string because Symfony's env-placeholder expansion does not
look inside attribute payloads. Workaround in 0.2.0 was the YAML /
config-map path; this release closes the gap on the attribute path
itself.

### Added

- **`#[Monitor(env: 'VAR_NAME')]` attribute form.** Carries the env
  var *name* instead of its value:
  ```php
  #[Monitor(env: 'CRON_MONITOR_REPORTS_NIGHTLY_UUID')]
  ```
  Both bridges (Symfony Console subscriber and Laravel scheduler
  resolver) honour the form. Lookup walks `$_ENV` → `$_SERVER` →
  `getenv()` in that order to cover CLI, FPM, and container-injected
  env setups where any one of those can be unpopulated. A missing or
  empty env var is treated as deliberate suppression — same policy
  as an empty literal `#[Monitor(uuid: '')]` or an empty YAML map
  entry.
- **`Monitor::resolveUuid()` method.** Both bridges now call this
  single accessor instead of reaching for `$attribute->uuid`
  directly. The resolution policy lives in the attribute class
  itself; the bridges only orchestrate.

### Changed

- **`Monitor` attribute constructor enforces an invariant**: exactly
  one of `uuid:` / `env:` must be provided. Constructing with both
  or neither throws `\InvalidArgumentException`. The bridges catch
  that into "no monitoring for this command" so a misuse never
  breaks the host job; the loud throw at attribute instantiation
  also gives test suites a chance to surface the mistake.

### Documentation

- README sections for Symfony and Laravel attribute usage now show
  both forms side-by-side, with the env-sourced form flagged as the
  prod-recommended pattern and the literal form retained for local
  dev / one-off scripts.

## [0.2.0] — 2026-05-19

Headline release: `composer require cron-monitor/php-sdk` is now the only
step needed to wire up cron-monitor in plain PHP, Symfony, and Laravel
projects. The SDK ships its own minimal HTTP transport, and the new
`#[Monitor]` attribute lets the UUID live next to the command class
instead of being duplicated in YAML / config.

### Added

- **Built-in cURL PSR-18 transport** — `CronMonitor\Client\CurlPsr18Client`
  (~95 LOC over `ext-curl`) plus a static factory
  `CronMonitorClient::create(?Configuration, ?LoggerInterface)`. Plain
  PHP / Slim users can now go from `composer require` to a working
  ping in three lines:
  ```php
  use CronMonitor\Client\CronMonitorClient;
  CronMonitorClient::create()->success('xxxxxxxx-…');
  ```
  Failures from libcurl are wrapped in a new
  `CronMonitor\Client\CurlException` (implements PSR-18
  `NetworkExceptionInterface`), which the existing client catches into
  `PingResult::failed(...)` — the no-throw contract is preserved
  end-to-end.
- **`#[Monitor(uuid: '...')]` attribute** at the package-root namespace
  `CronMonitor\Attribute\Monitor`. Honoured by:
  - The Symfony console subscriber — declare the UUID on the `Command`
    class instead of mapping the command name in
    `cron_monitor.commands:` YAML.
  - The Laravel scheduler `->monitor()` macro — calling `->monitor()`
    with no argument resolves the UUID from the attribute on the
    Artisan command class behind the event.
  Precedence: an explicit YAML map (Symfony) or string argument
  (Laravel) always wins over the attribute. An empty string is
  treated as deliberate suppression so per-environment overrides
  (`%env(MY_UUID)%` blank in dev) continue to work.

### Changed

- **Laravel service provider's PSR-18 / PSR-17 fallback** now uses the
  bundled cURL transport + `nyholm/psr7` instead of Guzzle. Users with
  a `ClientInterface` / `RequestFactoryInterface` / `StreamFactoryInterface`
  already bound in the container still win — only the unbound path
  changes. **BC note:** Laravel installs that relied on the provider
  silently constructing a `GuzzleHttp\Client` will now get the cURL
  transport instead; the wire behaviour is identical for the ping
  shapes the SDK sends. Guzzle is no longer required and can be
  removed from `require` if it was only ever pulled in for this SDK.
- **`composer.json` `suggest` reframed.** Guzzle and `symfony/http-client`
  are now described as optional accelerators (for connection pooling
  or an existing HTTP stack), not as recommended dependencies — the
  bundled cURL transport handles the default case.
- **`MonitorConsoleSubscriber::resolveUuid()` signature** now accepts the
  `Command` instance instead of just its name, so it can read
  `#[Monitor]` off the class as a fallback to the YAML map.
  **BC note:** this is a `private` method — direct callers do not
  exist outside the bundle, but anyone extending the subscriber via
  subclass should update their override.
- **`Event::monitor()` macro signature** widened to
  `?string $monitorUuid = null`. Existing `->monitor('uuid')` calls
  continue to work unchanged; the new no-argument form falls back to
  the attribute on the command class. Calling `->monitor(null)` is no
  longer a `TypeError` — it becomes the attribute-fallback path.
- **User-Agent header** bumped from `cron-monitor-php-sdk/0.1` to
  `cron-monitor-php-sdk/0.2` so backend telemetry can distinguish
  SDK versions.
- **PHP version floor** stays at 8.2 (unchanged from 0.1.x). The new
  cURL transport requires `ext-curl` to be loaded — almost every PHP
  install has it on, but the README now flags it explicitly.

### Documentation

- README rewritten around the zero-dep flow. New "What's in the box"
  section, Packagist version / monthly downloads / PHP requirement
  badges, and both attribute call shapes (Symfony + Laravel) shown
  side-by-side with the precedence rule called out.
- `composer.json` keywords gained `artisan`, `console`, and `psr-18`
  for Packagist search discoverability.

## [0.1.3] — 2026-05-14

### Changed

- **Symfony bundle is now drop-in.** `nyholm/psr7` (~30 KB, zero
  transitive deps, the de-facto PSR-17 standard in the Symfony
  ecosystem) is bundled as a hard dependency, and the bundle's
  `services.php` registers it under `cron_monitor.psr17_factory`
  aliased to `RequestFactoryInterface` and `StreamFactoryInterface`.
  When `symfony/http-client` is present (the common case in Symfony 7
  projects), `Psr18Client` is also registered and aliased to
  `ClientInterface`. Net effect: `composer require
  cron-monitor/php-sdk` is now actually drop-in for Symfony 7 — no
  second `composer require nyholm/psr7` + `symfony/psr-http-message-
  bridge` step, no `EnvNotFoundException` on first cache:clear from
  unmet PSR-17 service requirements.
- Consumers who already wire their own PSR-17 / PSR-18 (via the
  nyholm/psr7 Flex recipe, a custom service definition, guzzlehttp/psr7,
  slim/psr7, etc.) keep winning the alias resolution — Symfony applies
  consumer aliases AFTER bundle extensions load, so the bundle defaults
  exist as fallback only.
- **README install section rewritten** to honestly describe the two
  integration paths. The old "the SDK falls back to Guzzle when one is
  not bound" line was true only for the framework-agnostic
  `CronMonitorClient` constructor path; in the Symfony bundle path,
  `services.php` declared PSR-17 services as hard dependencies and the
  container compile failed with a non-existent-service error. Now both
  paths are documented accurately.

## [0.1.2] — 2026-05-14

### Fixed

- **Command and message map keys containing hyphens are now preserved
  byte-for-byte.** The bundle's `Configuration` tree used
  `useAttributeAsKey()` without `normalizeKeys(false)`, so Symfony's
  default dash→underscore normalization silently rewrote
  `app:short-links:purge-disabled` to `app:short_links:purge_disabled`
  at compile time. The kernel subscriber's `$commandMap[$commandName]`
  lookup never matched the actual command name, and start/success/fail
  pings stopped firing without any error or warning log line.
  Surfaced when a host project (url-shortener) first wired in a real
  Symfony command name (most third-party commands contain hyphens —
  the README's `app:reports:nightly` example used colons only and
  masked the bug). Both `commands:` and `messages:` array nodes now
  disable key normalization, and a `ConfigurationTest` pins the
  behaviour so the regression cannot recur silently.

## [0.1.1] — 2026-05-14

### Fixed

- **Symfony bundle no longer crashes the consumer's container compile.**
  `Resources/config/services.php` called `service()` and
  `tagged_iterator()` without `use function …\Configurator\service`
  imports, so PHP resolved them to the global namespace and every
  consumer's first `cache:clear --env=prod` died with `Call to
  undefined function service()`. The SDK's own test suite never
  surfaced this because it builds the container via PHPUnit fixtures
  that short-circuit services.php loading; an actual `composer
  require cron-monitor/php-sdk` + Symfony kernel boot hit it
  immediately. Imports added. **Follow-up:** add a `MicroKernel`-based
  integration smoke test so this exact regression cannot recur.
- **Empty-string UUID mappings are now treated as "unmapped" in both
  `MonitorConsoleSubscriber` and `MonitorPingMiddleware`.** The
  recommended wiring pattern is to map a command / message FQCN to
  `'%env(MY_UUID)%'` and leave that env var blank outside prod —
  before this fix, dev/test runs of those commands would call
  `CronMonitorClient::start('')`, the SDK's UUID-v4 validator would
  throw, `safePing` would catch it, and the bundle would emit one
  warning log line per invocation. Both call-sites now short-circuit
  on the empty string, producing zero noise.

## [0.1.0] — 2026-05-14

First public release on Packagist. Composer SDK for the cron-monitor
service (cronheart.com), with framework-agnostic core plus Symfony bundle
and Laravel service-provider bridges.

### Added

- **Symfony Console subscriber.** Any `bin/console <name>` invocation whose
  command name is mapped under `cron_monitor.commands:` in the bundle YAML
  now fires `start` / `success` / `fail` pings automatically — no code
  changes required inside the command. Covers the common case of a console
  command wired directly into crontab or a systemd timer, without going
  through Scheduler + Messenger.
- **Laravel queue job middleware** `MonitorQueueJob`. Implements the standard
  job-middleware contract (`handle($job, $next)`); use the
  `MonitorQueueJob::withUuid('uuid')` shortcut from your job's
  `middleware()` method. If the container cannot resolve the SDK client
  (unbound singleton, partially booted application), the middleware
  falls back to a no-op rather than crashing the worker.

### Changed

- **Minimum PHP version raised from 8.1 to 8.2.** PHP 8.1 reached end of
  security support in November 2024 and ships no further upstream
  patches; adopters still on 8.1 are running an unpatched runtime
  regardless of which package they require. CI matrix now covers
  `8.2 / 8.3 / 8.4` (latest stable). Bumping the floor at the v0.1.x
  line — before any stable major — is the cheapest moment to set the
  right baseline. **BC note:** consumers pinned to PHP 8.1 must upgrade
  the runtime before pulling this release.
- **Default endpoint is now `https://cronheart.com`** (was
  `https://cron-monitor.io`) to match the production host of the
  cron-monitor service. Self-hosted installs override via
  `Configuration::__construct` or the `endpoint` YAML knob; nothing
  else moves.
- **`Configuration::pingUrl()` action regex tightened to `{1,16}`.** The
  cron-monitor server route accepts `[a-zA-Z0-9_-]{1,16}` only; the SDK
  previously allowed up to 32 chars and any longer action would have
  produced a 404 on dispatch. BC note: third-party callers passing
  custom actions of 17–32 characters through `CronMonitorClient::ping`
  now receive an `InvalidArgumentException` client-side. The built-in
  `start`/`success`/`fail`/`heartbeat` actions are unaffected.
- **Body cap reduced from 10 240 to 10 000 bytes** to match the server's
  exact `Ping::BODY_EXCERPT_MAX_BYTES`. Avoids paying egress on the 240
  bytes the server was silently truncating. Truncated payloads still
  carry the `\n[truncated by SDK]` suffix marker.

### Fixed

- **`ScheduleInventory` returned `messageClass: 'unknown'` for every
  recurring message on Symfony 6.4+.** The 6.4 release reshaped
  `RecurringMessage` to hold a `MessageProviderInterface` instead of a
  bare message object; the old reflection-by-name lookup of a `message`
  property silently produced "unknown" rows in `cron-monitor:sync`
  output. The inventory now reaches through `StaticMessageProvider`
  (the provider used by `RecurringMessage::every()` and `::cron()`)
  and surfaces the concrete message FQCN; custom providers fall back
  to the provider class name.

### Documentation

- Security section of `README.md` now explicitly notes that `fail`
  pings include exception class, message, and `file:line` in the body
  — both of which can leak attacker-controlled input or host
  deployment layout. Includes redaction guidance.
