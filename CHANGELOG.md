# Changelog

All notable changes to `cashier-dunning` will be documented in this file.

## Unreleased

**Breaking PHP API Changes And Migration**

- **`RecordingRefused` keeps only its message, previous exception, `report`, `artifactPath` and `context()`.** The public `scenario`, `reason`, `captured`, `requestIds`, `missing`, `startedAt`, `fixture` and `quarantineFailure` fields are removed, along with the static factories and `withRequestIds()`, `quarantinedAt()` and `quarantineFailed()`. The message already names what never arrived and what was captured. The quarantine artifact at `artifactPath` holds the captured events, request ids and fixture. A failed quarantine is under `context()['quarantine_failure']`.
- **`QuarantineArtifact` is removed.** Quarantine is written only by `RecordAndVerify`. The artifact's contents and filename are unchanged.
- **`Recorder::record()` takes a `RecordingAttempt` and returns the `Fixture`.** The captured events and request ids are filled into the attempt as recording goes on. `Recorder` remains internal.
- **`FixtureRepository` searches only provider folders** (`<dir>/stripe/*.json`). Other subfolders of a fixture directory were never fixtures, and are no longer scanned.

## v0.4.0 - 2026-11-05

This release hardens fixture loading and recording failures, improves diagnostics, and introduces breaking changes for consumers using the PHP APIs directly.

**User-Facing Fixes**

- Fixture validation now runs consistently when loading through replay, `billing:doctor`, or other file readers. Invalid fixtures fail early with contextual diagnostics instead of reaching replay unchecked.
- Fresh recordings that cannot become valid fixtures, fail replay, or cannot be saved are reported as recording refusals. Failed replays display their timeline when a report is available.
- Recording refusals retain captured-event metadata and Stripe response request IDs in quarantine artifacts. Replay/save failures additionally retain the candidate fixture and any available replay report. A refusal before replay starts has no report.
- Fixture and quarantine saves use complete temporary files before replacement. Failed saves leave previous fixtures untouched; quarantine filenames include random suffixes to avoid collisions. These guarantees protect against partial writes, not power-loss durability.
- If saving quarantine also fails, diagnostics retain both failures without claiming an artifact exists.
- Timeline highlighting now agrees with replay’s strict entitlement comparison: integer `10` and string `"10"` are different even when their displayed values match.
- Recording and replay share one placeholder grammar. `Normalizer::placeholderFor()` consistently names IDs used in both paths and payloads.

**Breaking PHP API Changes And Migration**

- **`RecordAndVerify` requires `$fixturePath` as its third constructor argument.** Its `record()` method now saves the verified fixture and returns `{fixture, path}`, not `{fixture, report}`. Remove caller-owned saving. If you need a `ReplayReport`, replay the saved fixture separately.
- **`Record\Exceptions\IncompleteRecording` is removed.** Catch `RecordingRefused` instead. This is not a simple rename: factories have different arguments, and `captured` now contains event payload arrays rather than event-type strings. Use `array_column($exception->captured, 'type')` when type names are needed.
- **`Recorder` is now internal and readonly.** Direct users must account for removal of its quarantine-directory constructor argument and `capturedEvents()`. `record()` requires an explicit start time and returns `{fixture, captured, requestIds}` instead of a `Fixture`. Prefer the CLI or `RecordAndVerify` workflow over standalone recorder use.
- **`ApiExchange::fromArray()` and `Manifest::fromArray()` are removed.** Their decoding replacements accept an internal `DecodedObject`. External consumers should use constructors for programmatic objects or `FixtureFile::read()`/`decode()` for complete fixtures.
- **`ApiExchange::matches()` is removed.** Request matching belongs to `FixtureHttpClient`, where placeholders and step scoping are handled.
- **`Scenario`, `ScenarioStep`, and `Action` lose `fromArray()` and `toArray()`.** Construct scenarios in PHP using their constructors and factories; consumers needing another serialization format must own that conversion.
- **`Allowlist::assert()` is removed.** Use the loading boundary to refuse invalid fixtures, or inspect `disallowedPaths()` when collecting field diagnostics.
- **`Allowlist::applyToObject()` no longer accepts an event type.** Rules are selected from the provider object’s own `object` field. Subscription-item rules are included; unknown object kinds remain fail-closed.
- **`DisallowedField::inEvent()` is replaced by step-aware factories**, including `inStepEvent($stepIndex, $eventType, $paths)`. `DisallowedField` now extends `InvalidFixture`, so catching `InvalidFixture` includes allowlist refusals.
- **`Provider::fromFixture()` requires a string**, rather than accepting `mixed`. Validate direct-call inputs before invoking it.

**Stricter Fixture Validation**

Fixture format version remains **1**, but previously accepted malformed fixtures may now fail to load.

Loading refuses:

- Fields with wrong types, including numeric-string response statuses and non-scalar entitlement values.
- JSON objects where lists are required, and lists where objects are required—including empty containers and numeric-keyed objects.
- Events without string `type` and `id` fields.
- Missing manifest-required events.
- Disallowed fields in events or recorded responses, and disallowed request parameters in match bodies.
- Unknown placeholder tokens in events and API exchanges.
- Missing or malformed match paths, including query strings, fragments, literal provider IDs, embedded/time placeholders, invalid segments, and trailing newlines.
- Recognized literal provider IDs appearing as whole string values anywhere in events, match bodies, or response bodies.

**Fixture migration guidance**

- Use `{}` for empty object fields such as `manifest`, `provenance`, `entitlements`, and API bodies; use `[]` for lists such as `events` and `api_responses`.
- Write response status as `200`, not `"200"`.
- Use consistent placeholders such as `{{cus_1}}` instead of recognized literal IDs.
- Keep paths to lowercase literal segments and whole ID placeholders; put request parameters in the match body.
- Correct or re-record incomplete fixtures rather than relying on previously unchecked content.

Absent optional fields retain their defaults. Unsupported ID prefixes such as `acct_` and `seti_`, embedded IDs in body strings, and fixture metadata remain outside literal-ID value validation.

All three shipped fixtures remain byte-identical and round-trip unchanged.

**Development And Release Tooling**

- Added `composer check`, running formatting, analysis, then tests.
- PHPStan now runs at level 9 with an empty baseline, refreshed autoloading, and an explicit memory limit.
- Required CI includes static analysis. PR API-compatibility checks report breaking changes as warnings for release-note preparation.
- Release changelog updates now arrive through CI-checked pull requests instead of direct pushes to main.
- Strengthened refusal diagnostics, Windows-safe test setup, quarantine coverage, and independent saved-file replay assertions in live recording tests.

**Requirements And Verification**

Requirements remain PHP 8.3+, Laravel 12 or 13, Cashier 16, and Stripe PHP 17.4–21 within the declared Composer constraints.

Local checks and merged-main CI pass. **Two live Stripe recording tests remain skipped without a test key; this release has not been freshly verified against a live Stripe test account.**

## v0.3.0 - 2026-11-04

### Changed

- **A replay now isolates the application's default connection as well as the billable's.** Writes a webhook listener made to the default connection used to persist whenever Cashier's customer model lived on another connection — the most common multi-connection setup — and only a chaos replay happened to roll them back. A plain replay and a chaos replay now give the same guarantee: writes on the billable's connection and the default connection are rolled back (one transaction when they are the same connection), and code that commits either replay transaction fails the run. Writes to any other connection remain outside the guarantee. See [ADR-0001](https://github.com/impruthvi/cashier-dunning/blob/main/docs/adr/0001-isolate-billable-and-default-connections.md).
- `ReplayRunner`'s constructor now requires a `SimulationEnvironment`, so constructing it directly with `new ReplayRunner(...)` breaks. Resolve it from the container instead: `app(ReplayRunner::class)`.
- `ChaosRunner` no longer takes a database connection or opens a transaction around each pass; construct it with just the `ReplayRunner`. Every pass is isolated by the replay itself.
- The simulation environment and its collaborators (`SimulationEnvironment`, `SimulationContext`, `EphemeralCredentials`, `OutboundGuard`, `AfterCommitGuard`) are now `@internal` and not part of the public API.

**Full Changelog**: https://github.com/impruthvi/cashier-dunning/compare/v0.2.3...v0.3.0

## v0.2.3 - 2026-09-24

### Fixed

- Replayed events now carry `livemode`, which every real Stripe event has and no hand-written fixture declared. An application that checks the flag — refusing to apply a live event to a test-mode install — rejected the entire replay, and the failure read as the application's bug rather than the harness's. A fixture that declares its own value keeps it.

Defaulting at the delivery boundary rather than in the fixtures fixes every recorded scenario at once, and cannot regress when a new one is recorded.

**Full Changelog**: https://github.com/impruthvi/cashier-dunning/compare/v0.2.2...v0.2.3

## v0.2.2 - 2026-09-20

### Fixed

- Resolver-free replays now skip recorded entitlement comparisons while continuing to assert every delivered webhook.

### Added

- Support for `stripe/stripe-php` 21.
- CI coverage for Stripe PHP 17.4 through 21.

## v0.2.1 — the safety net had holes in it - 2026-09-06

v0.2.0 shipped a guard that stops a replay mailing your customers. An
independent review of that release found two ways a replay could still reach
past its own boundary, and one way the guard could be skipped entirely.

**Upgrade from v0.2.0.** Nothing to do beyond the bump; no configuration or
setup changes.

### Fixed

- **A billable factory could reach your real Stripe account.** The factory ran
  before the ephemeral credentials and replay transport were installed, so a
  factory calling `createAsStripeCustomer()` — Cashier's documented way to make
  a customer — used your real key against the real API. The billable is now
  built inside the protected window.
- **A replay could leave real rows behind and still report PASS.** Application
  code calling `DB::commit()` took ownership of the transaction the replay
  opened; the rollback afterwards became a silent no-op and the writes became
  permanent. The runner now checks it still owns the transaction and fails with
  a named error.
- **The mail guard could be skipped.** Delivery was refused by returning `false`
  from `MessageSending`, but `Dispatcher::until()` stops at the first listener
  that answers — so an application listener returning any non-null value meant
  the guard was never consulted. Every configured mailer is now pointed at the
  array transport for the run, which no listener ordering can defeat.
- **Notifications never ran the application's `toMail()`.** Blocking at
  `NotificationSending` returned before the channel built the message, so the
  bug class the guard exists to expose was unreachable for notification-based
  dunning. Mail-channel notifications now run in full and die at the transport;
  only channels with no mail transport behind them are refused outright.
- **The billable preflight assumed `cus_1`.** The expected customer id was a
  hardcoded literal rather than something read from the fixture, so a recording
  whose billable was any other customer failed with an error blaming the
  factory. It is now read from the recording, and stands down when the
  recording names no single customer.
- **"Cashier returned a different record" covered three different problems.**
  No matching row, several rows sharing an id, and a genuine mismatch now each
  get their own message. The duplicate case is the v0.1.0 residue one, and it
  now says so.
- **Side effects listed only `To:` recipients.** Cc and Bcc are people too, and
  two messages differing only in Cc collided into one ledger entry. All three
  are recorded, sorted and de-duplicated.
- `beginTransaction()` moved inside the try/finally, so a transaction-begin
  listener that throws cannot leak an open transaction.

### Changed

- The `OutputStyle` workaround shared by both commands moved into a
  `WritesToConsole` trait. It was duplicated, and only one copy carried the
  explanation.

### Documentation

- The mail-safety section no longer claims more than it delivers. Queued work is
  observed but not blocked: on Redis or SQS the job outlives the rollback, so
  replays of queued dunning want `QUEUE_CONNECTION=sync`.

## v0.2.0 — a replay you can run twice, that never mails a customer - 2026-09-06

A correctness release. Every headline feature in v0.1.0 worked once, on a clean
database, and this fixes what happened on the second run and on a machine with
working SMTP credentials.

**If you installed v0.1.0, upgrade.** Two of these are the kind of bug that
makes the tool worse than not having it: a replay that left rows behind reported
a failure on its second run that had nothing to do with your application, and a
replay on a machine with real mail credentials emailed whatever address your
billable carried.

### Upgrading from v0.1.0

v0.1.0 committed its replay data. That residue is still in your database and the
new billable preflight will refuse to run until it is gone:

```
The billable factory created [App\Models\User] with stripe_id [cus_replay1],
but Cashier::findBillable() returned a different record.




```
Delete the rows an old replay left behind — users with the replay `stripe_id`
your factory uses, and their subscriptions. There is no cleanup command; the
population is small enough that guessing at which rows are yours would be worse
than asking.

Also pin the billable's `email` alongside its `stripe_id`. Side effects are
keyed by recipient, so a factory that leaves the email to Faker gives every
chaos pass a different address and turns every comparison into a divergence
that is really just a different random string.

### Fixed

- **Repeat runs.** `ReplayRunner::run()` now wraps the whole replay in a
  transaction on the billable model's own connection and rolls back in
  `finally`, including when the replay throws. Running the same scenario twice
  against one database starts from the same state both times, and a transaction
  the caller already had open stays open. Previously every run committed, so the
  second one replayed against subscriptions the first had created and diverged
  for reasons that had nothing to do with the fixture.
- **Real email.** A replay drives your real webhook route, so your real dunning
  listener runs — and a dunning listener's job is to email the customer. Mail
  and notifications are now intercepted at Laravel's `MessageSending` and
  `NotificationSending` seams: your listener still runs, the mailable is still
  built, the recipients are still resolved and recorded, and the delivery is
  refused. Every replay prints what your application tried to do and how much of
  it was stopped. This is deliberately not `Mail::fake()`, which would swap the
  mailer out and make formatting bugs unreachable.
- **Silent success on unobservable work.** Jobs dispatched with `->afterCommit()`
  never fire `JobQueued` inside a replay that always rolls back, so the ledger
  saw nothing and chaos reported that the application behaved identically. It
  now detects those callbacks and fails with a named error instead.
- **A misleading red table.** When the billable factory produced a record Cashier
  could not resolve — no `stripe_id`, the wrong one, or a duplicate left by an
  earlier run — every webhook 200'd as a no-op and the run failed on
  entitlements. The command now verifies the billable resolves before delivering
  anything, and says which of the three went wrong.
- **`FAIL pass 0` with no reason.** A failing chaos pass now shows the step, the
  event, the status and the entitlement table, including for the baseline pass,
  which had no divergences to iterate and so printed nothing at all.
- **Flattened output.** The command passed Laravel's `OutputStyle` to the
  renderer, and it collapses every run of spaces to one — so the padded verdict
  column, the divergence table and the indents all arrived single-spaced, and
  the examples in the README were only reachable from the test suite. Rendering
  goes to the raw stream, and the chaos verdict column is padded so `ok`, `n/a`
  and `FAIL` keep a straight left edge.

### Added

- **Laravel 11 support**, on PHP 8.3 and 8.4. Composer would install it on 8.5,
  but 11.x shipped before 8.5 existed and is not tested against it upstream, so
  it is not advertised.
- **Side effects in every report.** What the application did to the outside
  world, counted by kind, on the plain replay path as well as chaos — in the
  terminal output and in the `--json` artifact as `side_effects` and
  `blocked_deliveries`.
- **Declared dependencies.** The nine `illuminate/*` components this package
  imports directly, plus `nesbot/carbon` and `symfony/console`,
  `http-foundation` and `mime`. They previously resolved only through Cashier
  and would have broken the moment its own constraints narrowed.

### Changed

- **`ChaosRunner` no longer takes a `Dispatcher`.** One ledger is created per
  replay inside `ReplayRunner` rather than a second overlapping one per chaos
  pass. If you construct `ChaosRunner` directly, drop the second argument.
- **`ReplayReport` gained `sideEffects` and `blockedDeliveries`,** both
  defaulted, so existing constructions are unaffected.
- **The ordering claim is narrower, and now accurate.** `--shuffle` permutes
  events *within* a step — one position on the clock, the events Stripe emitted
  at effectively the same moment. It does not move an event across a step
  boundary. Stripe's "a subscription might be deleted before the corresponding
  creation event arrives" was quoted as this feature's justification and is the
  one ordering it cannot produce: in the shipped fixture those two events are six
  clock positions apart. Reordering across a clock advance is a real design
  question and is not answered here.

### Requirements

PHP 8.3+, Laravel 11, 12 or 13, Cashier 16. Laravel 11 on PHP 8.3 and 8.4 only.

### Status

Still early, and the honest summary has not changed: feedback on whether the
failure output actually helps you fix a billing bug is the most useful thing you
could send.

## v0.1.0 — replay Stripe billing lifecycles with no Stripe account - 2026-08-31

First release.

Failed payments are where SaaS revenue quietly leaks, and dunning is the code
least likely to be tested — because testing it properly means waiting a month
for a card to fail three times.

This package records a real Stripe billing lifecycle once, then replays it in
under a second, forever, with no Stripe account, no API key and no network.

### What's in it

- **Replay** — drives your application through Cashier's real webhook route,
  with real HMAC signature verification, from a fixture on disk. Nothing is
  invented: a Stripe call with no recorded response is an error naming the call
  and the step. Nothing is served out of time: a subscription that was
  `trialing` at step 1 cannot answer for step 3 where it is `past_due`.
- **Record** — drives a Stripe test account through a test clock, then replays
  the result against your app before writing anything. A fixture that cannot be
  replayed is worse than no fixture, because it looks like coverage.
- **Entitlements** — a contract, resolved once per webhook, so a change can be
  attributed to the event that caused it: `teams: true -> false (at invoice.payment_failed)`.
- **Chaos replay** — the same timeline in orders Stripe is entitled to use.
  Stripe guarantees at-least-once delivery and no ordering; you cannot ask it to
  redeliver on demand, but a fixture is a list and lists can be rearranged.
  Compared on side effects rather than end state, because an application that
  emails a customer twice still ends with a correct subscription row.
- **Safety** — recording refuses to run against anything but a Stripe test key.
  An allowlist, not a blocklist, so a prefix Stripe introduces next year cannot
  silently become permitted.

### Scenarios

Two, both recorded against a dedicated synthetic test account:

- `trial-dunning-cancel-reactivate` — a trial ends, the card fails, retries run
  out, the subscription is cancelled, the customer comes back
- `downgrade-over-usage-limit` — a customer moves to a smaller plan mid-period

### Requirements

PHP 8.3+, Laravel 12 or 13, Cashier 16.

### Status

Early. The format is stable enough to review as diffs but not yet promised
across versions. Feedback on whether the failure output actually helps you fix a
billing bug is the most useful thing you could send.

## 0.1.0 - 2026-08-31

First release.

- Replay a recorded Stripe billing lifecycle with no Stripe account, key or network
- Record scenarios against a Stripe test account using test clocks, with the
  recording replayed and verified before any fixture is written
- `EntitlementResolver` contract, resolved once per webhook so entitlement
  changes can be attributed to the event that caused them
- Chaos replay: `--shuffle` and `--duplicate` run the same timeline in orders
  Stripe is entitled to use, comparing side effects rather than end state
- Refuses to record against anything but a Stripe test key
- `billing:doctor` reports what an installation can do, entirely offline
- Two recorded reference scenarios: trial dunning through cancellation and
  recovery, and a mid-period downgrade
