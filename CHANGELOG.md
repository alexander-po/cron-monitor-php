# Changelog

All notable changes to the `cron-monitor/php-sdk` package land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security

- **A failed channel create no longer hands the webhook URL or the signing
  secret to stack-frame arguments.** With `zend.exception_ignore_args=0`
  (PHP's development default) an error tracker records every frame's
  arguments, and a `createChannel()` that failed — a `422`, a transport error,
  a body that could not be encoded — carried the channel's webhook URL and
  secret there. A Slack or Discord webhook URL is itself a credential.
  `createChannel()`'s request, the client's internal request body, and the
  `webhookUrl` / `secret` parameters of `CreateChannelRequest`'s constructor
  and of its `slack()`, `discord()` and `webhook()` factories are now
  `#[\SensitiveParameter]`, so a request the DTO rejects is covered too. The
  PSR-7 request still reaches the PSR-18 client (the next entry says what a
  client's exception keeps); the body sits in its stream, not in a string
  argument.
- **A failed call no longer hands the API token or a monitor UUID to
  stack-frame arguments.** A monitor's UUID is the credential on its ping
  endpoint, and the token authenticates the whole account. Every
  `MonitorApiClient` method that takes a UUID, the client's internal request
  path, `Monitor::fromArray()` (which also reads the new UUID
  `rotateMonitorUuid()` returns), `MonitorPage::fromArray()`,
  `Configuration`'s `apiKey` (constructor and `withDefaultEndpoint()`) and
  `pingUrl()`'s UUID are now `#[\SensitiveParameter]`. So is the PSR-7 request
  in the client's retry loop and in `CurlPsr18Client::sendRequest()`, which
  hides the `Authorization` header and the request URL with it. `print_r()`
  and `var_dump()` show the token of a `Configuration`, on its own or inside a
  client, and the request a `CurlException` keeps, as a
  `SensitiveParameterValue`; `var_export()`, `json_encode()` and Symfony's
  VarDumper still read `Configuration`'s public `apiKey`, and
  `CurlException::getRequest()` still returns the request. Still exposed: a
  PSR-18 client other than the bundled `CurlPsr18Client`, the Symfony
  bundle's default `Psr18Client` among them, keeps the request, header
  included, in its own exception's frames and properties, and
  `ApiTransportException::getPrevious()` chains that exception unchanged. With
  `zend.exception_ignore_args=1`, which `php.ini-production` sets (PHP's
  built-in default and the official Docker images leave it at `0`), exception
  traces carry no arguments; `debug_backtrace()` still does.
- **A monitor UUID with a trailing newline is rejected before any request.**
  A regex `$` also matches before a final newline, so `Configuration::pingUrl()`
  and every `MonitorApiClient` method that takes a UUID accepted `"<uuid>\n"`,
  the shape a value read from a file often has. The newline stayed in the
  request path, percent-encoded by guzzlehttp/psr7 and turned into `_` by
  nyholm/psr7, and there neither the route placeholder nor the ping client's
  scrub recognised the UUID: on a transport failure the whole UUID reached the
  `ApiTransportException` message and the `route` log context, and, with a
  PSR-18 client that quotes the request URI in its errors as Guzzle does, the
  ping client's `last_error` log context and `PingResult::$errorMessage`. The
  service never routed such a path, so the call could only fail. The
  management client now throws its usual `\InvalidArgumentException` in place
  of a `NotFoundException`; the ping client, where the `404` failed silently,
  now logs its URL-build error at `error` level and returns a failed result
  without sending anything.
- **A failed ping no longer hands the monitor UUID to the SDK's own frame
  arguments, and `print_r()` of a bridge no longer shows it.**
  `CronMonitorClient` never throws, but it logs a failed ping, and a PSR-3
  handler that attaches a backtrace to the record, as error trackers do,
  reads `debug_backtrace()`, which keeps frame arguments whatever
  `zend.exception_ignore_args` says. The `$monitorUuid` of `heartbeat()`,
  `start()`, `success()`, `fail()`, `ping()` and the client's internal
  dispatch is now `#[\SensitiveParameter]`, and so is the callback the
  Messenger middleware, the console subscriber and the queue middleware wrap
  their pings in. `print_r()` and `var_dump()` show the Messenger
  middleware's monitor map, the console subscriber's command map and cached
  attribute UUIDs, and the queue middleware's UUID as a
  `SensitiveParameterValue`, and the Laravel scheduler hooks capture the UUID
  as one. The bus's middleware stack, a console event (through its command,
  application and dispatcher), Laravel's pipeline and a scheduled `Event`
  hold these objects, so every frame that received one used to print the
  UUID. `var_export()` and Symfony's VarDumper still read the bridges'
  properties. Still exposed: what the host keeps itself, such as the
  `%env()%` values Symfony's container has resolved (a monitor UUID, the API
  token) or Laravel's config repository (the token and the `monitors` map),
  reaches every frame whose arguments lead to the container. That includes
  the bridges' `handle()`, `onCommand()` and `onTerminate()`, and in Laravel,
  whose logger holds the application, whatever holds the ping client:
  `EventMonitor::safe()`, a scheduled `Event` and the frames that call its
  hooks.

### Changed

- **A request body that cannot be encoded as JSON no longer chains a
  `\JsonException`.** The `ApiTransportException` names the encoder's error in
  its message instead, and its `getPrevious()` is now `null`: the
  `\JsonException`'s own trace held the whole body, secrets included.
- **A malformed monitor UUID is no longer quoted in the
  `\InvalidArgumentException` the management client throws.** The message now
  reads "The monitor identifier is not a valid cron-monitor UUID (check for
  whitespace, e.g. a trailing newline).", the same text
  `Configuration::pingUrl()` now uses, so the ping client's
  `PingResult::$errorMessage` and `error` log context carry it too: a value
  that fails the check, such as a UUID with a stray space, can still hold the
  real one.

## [1.5.0] — 2026-09-26

A minor release: a cronheart.com account and its first API token can now come
from the terminal. Additive apart from the entries under Changed; the
existing wire mapping is untouched.

### Added

- **`vendor/bin/cron-monitor signup <email> --accept-terms`.** Creates an
  account and its first API token without the web UI. The backend mails the
  address one confirmation link; the command shows the code to type on that
  page, polls at the server's interval (waiting out a `429`'s `Retry-After`,
  never past the code's lifetime), and once the person confirms prints
  `CRON_MONITOR_API_KEY=cmk_…` on standard output, once. Nothing else the
  command writes goes there, so the documented capture, which also keeps
  only that line, appends the token to a git-ignored env file without it
  reaching the screen. Without `--accept-terms` it refuses and names the
  terms and privacy URLs; an expired or already used request stops with the
  server's recovery advice; a confirmation whose answer cannot be read stops
  with the way back to a token instead of polling on; a plain-HTTP endpoint
  is refused.
- **`MonitorApiClient::startSignup()` and `pollSignupToken()`**, the two calls
  behind the command, with the `SignupStarted` and `SignupToken` DTOs. They
  send no token even when one is configured, refuse a plain-HTTP endpoint
  whatever `allowInsecureEndpoint` says, and are never retried automatically.
  `pollSignupToken()` returns `null` until the person confirms; its `410` is
  the new `SignupExpiredException`, a subclass of
  `UnexpectedResponseException`, so existing catches keep working. Only
  `token` is required to read the confirmation; an answer without it is an
  `ApiTransportException` carrying that answer's status. The device code and
  every payload that carries it, the token or a rotated webhook secret are
  `#[\SensitiveParameter]` arguments on the way through the client, the DTOs
  and their hydration helpers, so an error tracker that records stack-frame
  arguments does not receive them.

### Changed

- **The CLI's usage text after an argument error, and PHP's own error output,
  go to standard error.** Both went to standard output; `--help` and a
  delivered ping's `ok` line still print there. PHP's error output moves only
  where displaying it was already on. An unknown flag or command is echoed
  back with any API token or monitor UUID in it redacted.
- **A management-API answer that is not valid JSON no longer chains a
  `\JsonException`.** The `ApiTransportException` names the decoder's error in
  its message instead, so the raw body, which can hold a one-time secret,
  stays out of the exception chain.

### Documentation

- **The agent recipe starts the token step with the signup command.**
  `skills/add-cronheart/SKILL.md` and `AGENTS.md` send an agent to
  `cron-monitor signup` when the person has no account: run in the background
  with the person's own consent to the terms, both streams in private
  temporary files, and only the token line appended to the git-ignored env
  file, only on success. Creating a token in the dashboard stays the path for
  an existing account. The README and the `Configuration::$apiKey` docblock
  now name that dashboard page Account → API tokens, as cronheart.com does,
  instead of "Settings → API Tokens".

## [1.4.2] — 2026-09-25

A documentation-only patch. The repository gains an agent-facing setup recipe; the
installed package changes only by the README section that points to it. No code
path changes and the wire mapping is untouched.

### Added

- **`skills/add-cronheart/SKILL.md`, an agent-facing recipe for wiring this SDK
  into an application.** A numbered, deterministic set of steps a coding agent
  (or a person) follows to detect Symfony or Laravel, install the package, set
  the configuration keys, attribute each scheduled task to a monitor, create
  the monitors, verify the first ping and read the management-client
  exceptions. `AGENTS.md` at the repository root points to it, and the README
  gains a "For coding agents" section. Both are repository content, excluded
  from the package a `composer require` install downloads via `.gitattributes`
  `export-ignore`; they never reach an installed copy of the SDK.

## [1.4.1] — 2026-09-25

A documentation-only patch. cronheart.com opened the REST API to the Free
plan, so the SDK's copy no longer ties a management-API token to a paid tier.
No code path changes and the wire mapping is untouched.

### Documentation

- **The management API is on every plan.** The README, the `api_key` knob and
  the `PlanRestrictionException` docblock said API access required Starter or
  higher. Every plan now includes it, rate-limited per account — 30 requests a
  minute on Free, 120 on Starter, 300 on Growth, 600 on Scale — and the token
  is issued from Account → API tokens. `PlanRestrictionException` stays as the
  402 mapping for a self-hosted or older backend, or for a plan-gated feature
  added later; it is no longer an "upgrade to use the API" signal.
- **Rate-limit docblocks name the account, not the token.** The limit is
  shared by every token on the account, and `RateLimitStanding::$limit` now
  lists the Free allowance alongside the paid tiers.

## [1.4.0] — 2026-08-20

Hardening pass over the SDK's own core. **Two changes are breaking** — both are
marked below, and each removes something that could not be kept without
defeating the fix it belongs to. Everything else is additive or internal.

### Security

- **The ping client no longer writes the monitor UUID to the host's logs.** On
  the ping endpoint that UUID is the only credential — whoever holds it can post
  any state for that monitor — and it was logged verbatim on both the failure
  warning and the bad-identifier error, where it outlives the job and travels
  wherever logs are shipped. Both records now carry `monitor_uuid_hash`: the
  first 16 hex characters of the value's SHA-256, the same digest the server
  logs, so an SDK line and a server line still join on one monitor during an
  incident. The digest is taken over the lower-cased value, because the ping URL
  accepts a UUID case-insensitively while the server stores one canonical
  spelling — without that, two call sites naming the same monitor would land
  under two unrelated keys. The format is now recorded in the wire-anchor table,
  since it joins two log streams and cannot move on one side alone.

- **Credentials are stripped from text the SDK quotes back.** PSR-18 clients
  routinely append the failing request URI to their own exception message, and
  for a ping that URI *is* the credential; a transport that echoes request
  headers would carry the `cmk_…` token the same way. Both are now removed
  before that text reaches a log record or `PingResult::$errorMessage`. The UUID
  pass runs only for a well-formed identifier, so a short or malformed one
  cannot rewrite the diagnostic an operator needs to read.

- **The management client reports the route it called, not the resolved path.**
  `ApiTransportException`'s message and the transport-failure log line carried
  `/api/v1/monitors/<uuid>` in full, and an exception message is the most
  copy-pasted string in software — it lands in issue trackers and chat threads
  long after the incident. Both now read `/api/v1/monitors/{uuid}` (and
  `/channels/{id}`). Rendering any `ApiException` — `(string) $e`, string
  interpolation, most framework error pages — redacts UUIDs and API tokens
  across the whole `previous` chain, so a transport exception one level down can
  no longer re-expose what the top-level message hides. `getPrevious()` itself is
  unchanged and still returns the original throwable, message intact, for
  callers that branch on its type. The log context key changed from `uri` to
  `route`.

- **BREAKING — an API key may no longer be combined with a plain-HTTP
  endpoint.** `allowInsecureEndpoint: true` exists for anonymous ping-only
  self-hosted installs, where the per-monitor UUID is the only thing on the wire;
  it silently extended to `cmk_…` tokens, which authenticate the whole account.
  Any non-`https` endpoint with an API key set is now an
  `InvalidArgumentException` at construction. Insecure **ping-only** installs are
  unaffected.

  *Upgrading:* if you run `endpoint: http://…` together with `api_key`, that
  configuration stops booting. Terminate TLS in front of the service and point
  the SDK at `https://`, or drop the token and use the SDK for pings only. Under
  Laravel the failure surfaces late — the configuration is built lazily inside a
  singleton the `monitor()` macro resolves — so the throw lands during
  `schedule:run` and aborts the whole scheduled run, not at boot. Check this
  before deploying, not after.

### Fixed

- **The no-throw contract now holds against a non-conforming stack.** The ping
  client caught only `ClientExceptionInterface`, so a PSR-18 implementation that
  let a `\RuntimeException` or a `\TypeError` escape broke the host job — the
  exact class of failure this SDK exists to flag. Message construction (the
  PSR-17 request and stream factories) sat outside the guarded region
  altogether, and so did the final `warning` call, so a factory that threw, or a
  host logger with a full disk, did the same. All of those paths now fold into a
  logged warning plus a `PingResult::failed(...)`, and a transport that throws
  outside PSR-18 consumes its retry budget rather than aborting on attempt one.
  Because fail-open means an SDK bug of ours never surfaces a stack trace, the
  catch-all logs `error_class` so one can still be told from a hostile transport.

- **`vendor/bin/cron-monitor` works on a plain `composer require` install.** The
  CLI hard-required `guzzlehttp/guzzle`, which is only a dev dependency, so it
  exited 64 for every consumer who installed the package normally — while the
  README promised `composer require` was the only step. It now sends over the
  bundled cURL transport the SDK has shipped since 0.2.0. Its usage text also
  still advertised the pre-rebrand domain; the default endpoint is now read from
  `Configuration::DEFAULT_ENDPOINT`, so it cannot drift again. `ext-curl` is
  declared in `suggest`, and `bin/cron-monitor` is now analysed by PHPStan
  alongside the rest of the package — it had been outside the gate, which is
  much of why this survived.

- **Body truncation no longer emits invalid UTF-8.** The 10 000-byte cap was
  applied with `substr`, which counts bytes: a multi-byte character straddling
  the cut went out as a lone lead byte, leaving the excerpt undecodable for
  everything downstream that expects text. The slice now backs off to a
  code-point boundary before the marker is appended, and stays at or under the
  cap. A body that was never valid UTF-8 — captured stderr need not be text — is
  still capped rather than rejected, and is returned byte-for-byte.

### Added

- **`CronMonitor\Api\Dto\Vocabulary::value()`** — the wire string of an open
  vocabulary field, whichever half it arrived as. `Vocabulary::value($monitor->status)`
  returns `'up'` for a value this SDK knows and `'quarantined'` for one it does
  not, which is what display and logging want; narrow with `instanceof` when the
  *behaviour* differs.

### Changed

- **BREAKING — vocabulary is open on read, closed on write.** `Hydrator` returned
  an enum case or threw, so a single status added server-side would have broken
  every `getMonitor()` call in every installed version — including for callers
  that never look at the field. `Monitor::$status`, `Monitor::$scheduleKind`,
  `Ping::$kind`, `Alert::$kind` and `Plan::$key` are now typed `Enum|string` and
  carry the server's verbatim string when the SDK does not recognise it. That is
  the tolerance `Channel::$kind` already had, promoted to a rule. Writing is
  unchanged and still strict: a request DTO takes nothing but a real enum case,
  so a value the SDK merely passed through cannot travel back out as if it were
  understood. A field of the wrong *type* still fails the read loudly.

  *Upgrading:* a value this SDK knows is still the same enum case, so `===`
  checks and existing `match` arms keep working. What changes is the unknown
  case, and it is a **runtime** difference, not only a static-analysis one:
  `$monitor->status->value` warns and yields `null` (an `ErrorException` under
  Laravel's or Symfony's error handler) — use `Vocabulary::value()`; passing the
  property to a parameter typed as the enum raises a `TypeError`; and a `match`
  without a `default` raises `\UnhandledMatchError`. Each of those replaces a
  read that previously threw `ApiTransportException`, so the failure moves rather
  than appears. The quieter case worth checking deliberately: `=== MonitorStatus::Up`
  is simply `false` for an unknown status, so an `else` branch that means "down"
  will now misclassify instead of failing — the deliberate trade for not breaking
  every read at once.

- Both clients now report `User-Agent: cron-monitor-php-sdk/1.4`. A test pins
  the two constants to each other and to the package's declared branch alias —
  nothing previously kept them in step, and a missed bump would have had one
  client under-reporting its version indefinitely.

## [1.3.0] — 2026-07-29

An additive read-surface release: a monitor now reports the notification
channels it alerts, so routing can be confirmed, diffed and copied instead of
only written. **No breaking changes** — code written against 1.2.0 keeps
working unchanged.

### Added


- **`Monitor::$channels`** — a monitor read now exposes its alert routing:
  which notification channels it delivers to, as a list of the new
  **`CronMonitor\Api\Dto\MonitorChannel`** (`id`, `kind`, `label`), in the id
  order the backend sends. Available wherever a `Monitor` comes back —
  `getMonitor()`, `listMonitors()` / `allMonitors()`, and every write response
  (`createMonitor()`, `updateMonitor()`, the lifecycle verbs). Previously the
  SDK could *set* `channelIds` but never read back what a monitor actually
  routes to, so a caller could not confirm a write, diff a monitor against a
  desired spec, or copy one monitor's routing onto another. `MonitorChannel::$id`
  is a string, exactly what `CreateMonitorRequest` / `UpdateMonitorRequest`
  accept as a `channelIds` entry, so that copy needs no conversion.

  It reports what is **attached, not what will be delivered.** Attachment is
  necessary but not sufficient — verification, an active snooze, a paused
  monitor and an operator's per-kind switch each suppress delivery on their
  own, so a non-empty list is not a promise that an alert reaches anyone.
  `Channel::$verified` plus `Monitor::$snoozedUntil` / `$status` narrow it
  down; `Alert::$dispatchedTo` is the only per-channel evidence of an actual
  send. Correspondingly, **never feed an empty `channels` straight into
  `UpdateMonitorRequest::$channelIds`** — an empty `channel_ids` replaces the
  target's routing with none, so copying from a monitor that reports none
  would leave the target alerting nobody (the README snippet guards this).
  `kind` is a plain string (like `Channel::$kind`) so a channel kind added
  server-side can never make a monitor read unhydratable; that tolerance
  covers the vocabulary, not the types — a wrongly-typed field still fails
  the read loudly.

  **No breaking changes.** The constructor parameter is appended and defaults
  to `[]`, so positional callers are unaffected, and a response that omits the
  key entirely hydrates to an empty list instead of failing — which keeps this
  release working against a backend that predates the field, and against an
  idempotent create replaying a response body stored before it. Live on
  cronheart.com since 2026-07-29.

### Changed

- Both clients now report `User-Agent: cron-monitor-php-sdk/1.3`.

## [1.2.0] — 2026-06-28

A small, additive consumer-DX release. **No breaking changes** — code
written against 1.1.0 keeps working unchanged.

### Added

- **`CronMonitor\Api\Exception\ChannelDeliveryException`** — a dedicated
  exception for the channel-test `502`. `MonitorApiClient::testChannel()`
  now raises it when the test send reaches the backend cleanly but the
  downstream destination rejects or fails the delivery, so a consumer can
  tell "the destination rejected the test" apart from any other bad gateway
  without inspecting the status code. It **extends
  `UnexpectedResponseException`** (the type that 502 was already mapped to),
  so existing `catch (UnexpectedResponseException)` / `catch (ApiException)`
  handling keeps working — a strictly additive narrowing, not a breaking
  change. Only `testChannel()` raises it; a 502 from any other endpoint
  stays a plain `UnexpectedResponseException`, and an unverified or
  transport-less channel still answers `422` (`ValidationException`).

### Changed

- Both clients now report `User-Agent: cron-monitor-php-sdk/1.2`.

## [1.1.0] — 2026-06-19

A feature release rounding the management-API client out to the backend's
full `/api/v1` surface, plus an opt-in `cron-monitor:sync --apply` that
creates missing monitors. **No breaking changes** — code written against
1.0.0 keeps working unchanged; everything here is additive.

### Added

- **`CronMonitor\Api\MonitorApiClient` — monitor lifecycle:**
  `updateMonitor()`, `deleteMonitor()`, `pauseMonitor()`, `resumeMonitor()`,
  `snoozeMonitor()`, `unsnoozeMonitor()`, `rotateMonitorUuid()`. Idempotent
  transitions retry within the configured budget; rotation (which kills the
  old ping URL with no grace) never retries.
- **Monitor history reads:** `listPings()` / `allPings()` (opaque cursor
  pagination with a cycle guard) and `listAlerts()` / `allAlerts()` (offset
  pagination), the latter mirroring `allMonitors()`.
- **Channel lifecycle:** `createChannel()`, `getChannel()`,
  `updateChannel()`, `deleteChannel()`, `rotateChannelSecret()` (returns the
  once-only plaintext), `testChannel()`. Side-effecting verbs
  (create / rotate-secret / test) never retry.
- **`getAccount()`** — plan, monitor budget, and live API rate-limit
  standing in one read.
- **Optional `Idempotency-Key`** on `createMonitor()` / `createChannel()`
  (trailing `?string $idempotencyKey`). Supplying a key makes the create
  retryable — the backend dedups a replay carrying the same key and body.
- **New DTOs / enums:** `UpdateMonitorRequest`, `CreateChannelRequest`,
  `Ping`/`PingPage`, `Alert`/`AlertPage`, `ChannelSecret`,
  `TestChannelResult`, `Account`/`Plan`/`MonitorBudget`/`RateLimitStanding`,
  and the `SnoozeDuration` / `PingKind` / `AlertKind` / `ChannelKind` /
  `PlanKey` enums. `Monitor` gained a nullable `snoozedUntil`.
- **`cron-monitor:sync --apply`** on both bridges (via the shared
  `CronMonitor\Sync\MonitorReconciler`): reconciles scheduler jobs against
  the account by name and creates the missing ones. `--dry-run` previews
  without writing; `--channel=<id>` routes created monitors. The default
  no-flag mode keeps the credential-free list + config-snippet behaviour.
  The Laravel bridge threads each event's timezone into the created monitor
  (a non-UTC schedule is not silently created as UTC); the Symfony Scheduler
  exposes no per-trigger timezone, so Symfony-synced monitors are created in
  UTC (set their timezone on the dashboard if the schedule is not UTC). Two
  jobs sharing a name are reported as a `conflict` and neither is created,
  rather than silently collapsing onto one monitor.

### Fixed

- `Channel::$id` is now a string (the backend serialises the channel's
  BIGINT id as a JSON string); the 1.0.0 `int` typing would have thrown on
  every real `listChannels()` response. The channel-by-id methods
  (`getChannel`, `updateChannel`, `deleteChannel`, `rotateChannelSecret`,
  `testChannel`) take the id as a `string` to match, and
  `CreateMonitorRequest`/`UpdateMonitorRequest::$channelIds` now accept
  `int|string` (normalised to a string on the wire) — so a returned
  `$channel->id` feeds straight back in (to fetch, mutate, or route a
  monitor) without a lossy `(int)` cast that would corrupt a BIGINT beyond
  PHP's int range. Passing channel ids as ints stays valid, so existing
  1.0.0 `createMonitor` callers keep working.

## [1.0.0] — 2026-06-09

First stable release. The ping client and its bridges have been steady
across the 0.1 / 0.2 line; 1.0.0 commits to Semantic Versioning for the
public surface and adds the authenticated **management-API client** that
the `Configuration::apiKey` field was reserved for since 0.1.0.

This is a feature release with **no breaking changes** — code written
against 0.2.x keeps working unchanged. The major bump signals the BC
commitment, not a migration.

### Added

- **`CronMonitor\Api\MonitorApiClient`** — authenticated client for the
  cronheart.com management API (`/api/v1/...`):
  - `listMonitors(offset, limit)`, `getMonitor(uuid)`,
    `createMonitor(CreateMonitorRequest)`,
    `allMonitors()` (a generator that walks every page lazily), and
    `listChannels()`.
  - Zero-config `MonitorApiClient::create()` mirroring the ping
    client's factory (bundled cURL transport + nyholm PSR-17).
  - Authentication via the Personal Access Token (`cmk_…`) carried in
    `Configuration::apiKey`, sent as `Authorization: Bearer`. Create a
    token in the cronheart.com dashboard (Settings → API Tokens). API
    access requires a Starter plan or higher.
- **Immutable DTOs** under `CronMonitor\Api\Dto`: `Monitor`,
  `MonitorPage`, `Channel`, `ChannelPage`, `CreateMonitorRequest`, plus
  the `ScheduleKind` and `MonitorStatus` backed enums. Timestamps are
  parsed into `\DateTimeImmutable`.
- **Typed exception hierarchy** under `CronMonitor\Api\Exception`:
  `ApiException` (abstract base) with `AuthenticationException` (401),
  `PlanRestrictionException` (402, carries `upgradeUrl`),
  `ForbiddenException` (403), `NotFoundException` (404),
  `ConflictException` (409), `ValidationException` (422, carries field
  `errors`), `RateLimitException` (429, carries `retryAfter`),
  `UnexpectedResponseException` (other 4xx/5xx) and
  `ApiTransportException` (network / decode). **Unlike the ping client,
  the API client throws** — it runs in admin / CLI contexts where the
  caller wants to know about failures.
- **Bridge wiring**: `MonitorApiClient` is registered as a public,
  injectable service in the Symfony bundle and bound as a singleton in
  the Laravel service provider, reusing the same transport /
  `Configuration` (incl. `api_key`) as the ping client.

### Changed

- **`Configuration::apiKey` is now active.** Previously documented as
  "reserved for future authenticated routes", it now carries the PAT
  the management API authenticates with. No signature change — the
  field, the Symfony `api_key:` key, the Laravel `api_key` config and
  the `CRON_MONITOR_API_KEY` env var all keep their names. The ping
  flow still does not require it.
- Both clients report `User-Agent: cron-monitor-php-sdk/1.0`.

### Fixed

- Corrected the copyright holder name in `LICENSE`.

### Notes

- The ping client (`CronMonitor\Client\CronMonitorClient`) and its
  bundled cURL transport are untouched: their no-throw,
  never-break-the-host-job contract is unchanged.
- `CronMonitor\Api\Internal\*` is marked `@internal` and is explicitly
  **excluded** from the SemVer surface.
- Still not shipped (by design): `createChannel`, monitor
  update / delete / pause, and the API-backed `cron-monitor:sync`
  reconciliation command — candidates for 1.1.

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
