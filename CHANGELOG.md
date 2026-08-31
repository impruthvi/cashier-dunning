# Changelog

All notable changes to `cashier-dunning` will be documented in this file.

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
