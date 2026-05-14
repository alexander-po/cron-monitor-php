# Changelog

All notable changes to the `cron-monitor/php-sdk` package land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet — open a PR and add your entry under the appropriate subsection._

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
