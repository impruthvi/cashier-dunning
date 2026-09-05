# Changelog

All notable changes to `cashier-dunning` will be documented in this file.

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
