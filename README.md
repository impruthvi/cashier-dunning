<p align="center">
  <img src="art/logo.svg" alt="Cashier Dunning" width="128">
</p>

<h1 align="center">cashier-dunning</h1>

<p align="center">
  <strong>Replay real Stripe billing lifecycles with no Stripe account.</strong>
</p>


[![Latest Version on Packagist](https://img.shields.io/packagist/v/impruthvi/cashier-dunning.svg?style=flat-square)](https://packagist.org/packages/impruthvi/cashier-dunning)
[![Tests](https://github.com/impruthvi/cashier-dunning/actions/workflows/run-tests.yml/badge.svg)](https://github.com/impruthvi/cashier-dunning/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/impruthvi/cashier-dunning.svg?style=flat-square)](https://packagist.org/packages/impruthvi/cashier-dunning)

Failed payments are where SaaS revenue quietly leaks, and dunning is the code
least likely to be tested — because testing it properly means waiting a month
for a card to fail three times.

This package records a real Stripe billing lifecycle once, then replays it in
under a second, forever, with **no Stripe account, no API key and no network**.

```
$ php artisan billing:simulate trial-dunning-cancel-reactivate --explain

  trial-dunning-cancel-reactivate  replayed with no Stripe account

  ok   +0d  trial starts
      invoice.finalized  200
      invoice.created  200
      invoice.paid  200
      customer.subscription.created  200
        api: false -> true (at customer.subscription.created)
        projects: 0 -> 10 (at customer.subscription.created)
        teams: false -> true (at customer.subscription.created)
      invoice.payment_succeeded  200
  ok   +11d  three days before the trial ends
      customer.subscription.trial_will_end  200
  ok   +14d1h  trial ends, first payment attempt fails
      invoice.created  200
      customer.subscription.updated  200
      invoice.finalized  200
      customer.subscription.updated  200
      invoice.payment_failed  200
  ok   +17d1h  grace period expires on the second failed attempt
      invoice.payment_failed  200
  ok   +28d  retries continue, access holds
      invoice.payment_failed  200
      invoice.payment_failed  200
      invoice.payment_failed  200
      invoice.payment_failed  200
      invoice.payment_failed  200
      invoice.payment_failed  200
  ok   +31d  retries exhausted, subscription cancelled
      invoice.payment_failed  200
        api: true -> false (at invoice.payment_failed)
        projects: 10 -> 0 (at invoice.payment_failed)
        teams: true -> false (at invoice.payment_failed)
      customer.subscription.deleted  200
  ok   +34d  customer fixes their card and resubscribes
      invoice.finalized  200
      invoice.paid  200
      customer.subscription.created  200
        api: false -> true (at customer.subscription.created)
        projects: 0 -> 10 (at customer.subscription.created)
        teams: false -> true (at customer.subscription.created)
      invoice.created  200
      invoice.payment_succeeded  200

  Side effects  what the application did
      mail:replay@example.test  ×9
      9 outbound delivery(s) blocked. A replay never mails a real customer.

  PASS  All 46 assertions passed.
```

*(Captured from a real run. In a terminal the `ok` column is a green `✓`;
piped to a file or a CI log it degrades to words, which is what you see here.)*

Thirty-four days of billing. No Stripe account. Sub-second.

## The part live Stripe cannot test

Stripe guarantees **at-least-once delivery** and does not guarantee order.
Almost nobody tests against either, because you cannot ask Stripe to redeliver
an event on demand, or to deliver a step's events in a different sequence. A
fixture is a list you own, so replay can.

**What this actually does, precisely:** a fixture step is one position on the
clock — the events Stripe emitted at effectively the same moment. `--shuffle`
permutes the events *within* a step. `--duplicate` delivers every event a second
time, appended after the originals, the way a Stripe redelivery arrives. Both
are seeded, so a failing pass reproduces exactly.

**What it does not do:** move an event across a step boundary. Stripe's own
documentation offers "a subscription might be deleted before the corresponding
creation event arrives" as the extreme case, and this package cannot produce
that ordering — in the shipped fixture `customer.subscription.created` is at
step 0 and `customer.subscription.deleted` is at step 5, six clock positions
apart, and the orderer never crosses that gap. Reordering across a clock advance
is a real design question about which orderings are physically possible, and it
is not answered here.

Within a step is where the ordering bugs actually live. Step 2 of the shipped
fixture delivers `invoice.created`, two `customer.subscription.updated` and
`invoice.payment_failed` at one timestamp; nothing about Stripe's contract says
which lands first, and an application that only works in the recorded sequence
is broken:

```
$ php artisan billing:simulate trial-dunning-cancel-reactivate --duplicate --seed=7 --iterations=1

  (the ordered pass runs first and prints its own timeline, then:)

  Chaos  same events, orders Stripe is entitled to use

  ok    pass 0: in order
  FAIL  pass 1: duplicated
        [mail:replay@example.test] happened 9 time(s) in order and 18 time(s) duplicated

  FAIL  1 ordering(s) changed the outcome. Reproduce with --seed=7.
```

That application ends the run with a **perfectly correct subscription row**. Its
only defect is that it would have emailed the customer twice for each of nine
failed payments — which is why comparing final state, the thing most billing test
suites do, would call it idempotent. No mail left the building: replay records
the attempt and refuses the delivery.

## Installation

```bash
composer require impruthvi/cashier-dunning --dev
```

Requires PHP 8.3+, Laravel 11, 12 or 13, and `laravel/cashier` 16.

Laravel 11 is supported on PHP 8.3 and 8.4 only. Composer would let you install
it on 8.5, but 11.x shipped before 8.5 existed and is not tested against it
upstream, so it is not a combination this package advertises.

## Getting started

Replay a shipped scenario immediately — nothing to configure, nothing to record:

```bash
php artisan billing:simulate trial-dunning-cancel-reactivate
```

The package needs to know which model the billing belongs to. In a service
provider:

```php
use Impruthvi\CashierDunning\CashierDunning;

CashierDunning::createBillableUsing(fn () => User::factory()->create([
    'stripe_id' => 'cus_replay1',
    'email' => 'replay@example.test',
]));
```

Pin the email too, not only the `stripe_id`. Side effects are keyed by recipient,
so a factory leaving the email to Faker gives every chaos pass a different
address and turns every comparison into a divergence that is really just a
different random string.

Before delivering any webhook, the command reads the customer id out of the
recording and checks that it resolves to exactly the record your factory
returned. Each way that can go wrong gets its own error — no `stripe_id`, the
wrong one, no matching row at all, or several rows sharing it — so you are told
what is actually broken instead of shown a red entitlement table.

Each replay rolls back its database writes, including the factory-created user,
on success or failure. Repeated commands start from the same database state;
transactions already open before replay remain open.

Isolation uses the connection of Cashier's configured customer model
(`Cashier::useCustomerModel(...)`). Your factory and billing writes must use that
same connection. Writes to other connections are outside this rollback guarantee.
Application code that commits the simulation's transaction is detected and fails
the run rather than silently persisting. With `--record`,
local verification writes roll back, but resources created in the Stripe test
account remain there.

### A replay does not mail your customers

Replay posts a real `invoice.payment_failed` through your real webhook route, so
your real dunning listener runs — and a dunning listener's whole job is to email
the customer. On a machine with working SMTP credentials that email would be
sent, to whatever address your billable carries.

So the run is real right up to the last hop. Your listener runs, the mailable is
built, the recipients are resolved, the attempt is recorded — and the delivery is
refused. Every replay prints what your application tried to do:

```
$ php artisan billing:simulate trial-dunning-cancel-reactivate

  (timeline omitted — see above)

  Side effects  what the application did
      mail:replay@example.test  ×9
      9 outbound delivery(s) blocked. A replay never mails a real customer.

  PASS  All 46 assertions passed.
```

This is deliberately not `Mail::fake()`. A fake swaps the mailer out, so a
listener that formats a message wrongly never formats it at all — and the bug you
came to find stops being reachable. Instead, every configured mailer is pointed
at the array transport for the length of the run. Your listener runs, your
`toMail()` runs, the message is built and the recipients resolved; it simply has
nowhere to go. `MessageSending` still fires, so the ledger sees the attempt.

Notifications go the same way. Only channels with no mail transport behind them —
Slack, SMS, and anything else that talks straight to a network — are refused
outright, because there is no lower seam to stop them at.

**The limit — queued work is observed, not blocked.** `JobQueued` fires *after*
Laravel has already pushed the job, so there is nothing left to refuse. On a
`database` queue the row is inside the replay's transaction and disappears with
it; on Redis, SQS or Beanstalk the job survives the rollback and a worker will
run it afterwards, against data that no longer exists. **If your dunning work is
queued, run replays with `QUEUE_CONNECTION=sync`** — then the handler runs inline
and its mail is caught like any other.

**If your application commits.** A replay owns the transaction it opened. Code
that calls `DB::commit()` or `DB::rollBack()` on that connection takes it away,
and the rollback afterwards becomes a no-op that leaves real rows behind. The run
now checks and fails with a named error rather than reporting PASS over
persisted data.

Laravel work scheduled with `->afterCommit()` cannot execute inside a replay
that never commits. The command detects those callbacks and exits with a named
failure instead of reporting that unseen side effects behaved correctly.

### Entitlements

The interesting question is not what Stripe did — it is what your application
let the customer do afterwards. That is a product decision no billing library
can answer, so this package ships a contract and no implementation:

```php
CashierDunning::resolveEntitlementsUsing(function (User $user) {
    $subscription = $user->subscription('default');

    return [
        'teams'    => $user->subscribed('default'),
        'projects' => $user->subscribed('default') ? 10 : 0,
    ];
});
```

The resolver is called **once after every webhook**, not once per step, so
`--explain` can name the event that moved an entitlement:

```
teams: true -> false (at invoice.payment_failed)
```

That is the difference between "your users lost access" and "your users lost
access three days before Stripe cancelled anything."

Registering nothing is fine. Subscription status and webhook handling are worth
watching before any entitlements exist.

### When a replay disagrees with the recording

```
$ php artisan billing:simulate downgrade-over-usage-limit

  downgrade-over-usage-limit  replayed with no Stripe account

  FAIL +0d  subscribed to the larger plan
      invoice.created  200
      invoice.finalized  200
      invoice.paid  200
      invoice.payment_succeeded  200
      customer.subscription.created  200

        feature   recording     application
        api       true          true
        projects  25            10


  FAIL  1 step(s) did not match the recording, starting at step 0 (subscribed to the larger plan).
```

Every webhook returned 200. The subscription row is correct. The application
simply grants 10 projects on a plan the recording says is worth 25 — a limit
that drifted out of step with billing, which no amount of checking HTTP status
codes would surface. The command exits non-zero.

Every declared feature is shown, not only the differing ones — a single red line
reads as a glitch, while the same line among green ones reads as "everything
else held, this one moved."

## Commands

```bash
# replay a recorded lifecycle
php artisan billing:simulate <scenario>

# name the event behind every entitlement change
php artisan billing:simulate <scenario> --explain

# replay in orders Stripe is entitled to use
php artisan billing:simulate <scenario> --shuffle --duplicate --seed=7 --iterations=4

# machine-readable report for CI
php artisan billing:simulate <scenario> --json=build/billing.json

# record against your own Stripe test account
php artisan billing:simulate <scenario> --record

# what can this installation do, offline
php artisan billing:doctor
```

`billing:doctor` answers "will this work here" without running anything:

```
$ php artisan billing:doctor

  cashier-dunning environment check

  ✓ Stripe key: not set. Fine for replay; recording needs a test key.
  ✓ Cashier billable model: App\Models\User
  ! Webhook secret: not set. Cashier skips signature verification, so replay cannot prove your signature handling works.
  ✓ Entitlement resolver: closure registered in code
  ✓ 2 fixture(s) valid in /app/vendor/impruthvi/cashier-dunning/fixtures
  ✓ Scenario [trial-dunning-cancel-reactivate]: recorded.
  ✓ Scenario [downgrade-over-usage-limit]: recorded.

  Replay  works with no Stripe account, key or network.
  Record  blocked: no key configured.
```

*(The fixtures path is wherever Composer installed the package; the absolute
path above is shortened.)*

## Recording your own

Recording drives a real Stripe **test** account through a test clock, then
replays the result against your application before writing anything — a fixture
that cannot be replayed is worse than no fixture, because it looks like coverage.

```bash
STRIPE_SECRET=sk_test_... php artisan billing:simulate trial-dunning-cancel-reactivate --record
```

**Recording refuses to run against anything but a test key.** The guard is an
allowlist, not a blocklist: recording proceeds only when the key can be
positively identified as a test key, so a prefix Stripe introduces next year
cannot silently become permitted. Recording creates customers, subscriptions and
invoices and advances a clock through months of billing — against a live key
that means real charges to real cards, and there is no undo.

A recording that comes up short never becomes a fixture. It becomes a
quarantined artifact holding what arrived, what did not, and the request ids
Stripe support will ask for.

## Scenarios

| Scenario | Story |
|---|---|
| `trial-dunning-cancel-reactivate` | A trial ends, the card fails, retries run out, the subscription is cancelled, the customer comes back |
| `downgrade-over-usage-limit` | A customer moves to a smaller plan mid-period and their limit drops below what they are already using |

Both fixtures in this repository were recorded against a dedicated synthetic
Stripe test account with invented products, so there is nothing sensitive in
them by construction. Field-level redaction runs on write against an allowlist
that fails closed.

## How it works

Cashier's Stripe calls do not go through Laravel's HTTP client, so `Http::fake()`
cannot see them. Replay installs a transport into the Stripe SDK's own seam
(`ApiRequestor::setHttpClient`) and answers from the fixture. Webhooks are signed
with a real HMAC against an ephemeral secret and posted through your HTTP kernel,
so routing, middleware and Cashier's signature verification all run exactly as
they do in production.

While a replay is running, your real Stripe key is not in configuration to be
read. Nothing can reach live Stripe by accident.

Two rules keep a replay honest:

- **Nothing is invented.** A Stripe call with no recorded response is an error
  naming the call and the step, never an empty body.
- **Nothing is served out of time.** Responses are scoped to the step that
  recorded them, so a subscription that was `trialing` at step 1 cannot answer
  for step 3 where it is `past_due`.

And a run that asserted nothing cannot pass. A green billing test that proves
nothing is worse than no test, because it stops anyone looking.

## Testing

```bash
composer test
```

The suite runs entirely offline. The live recording tests skip themselves unless
`STRIPE_SECRET` is set, because GitHub withholds secrets from fork pull requests
and a suite that needed one would be red for exactly the contributors this
project wants.

## Contributing

Fixtures are reviewed as diffs, so the format is deliberately stable: ids and
timestamps are normalised to placeholders, key order is fixed, and encoding is
pinned. A re-recording of the same journey should produce identical bytes.

## Credits

- [Pruthvi Rajput](https://github.com/impruthvi)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
