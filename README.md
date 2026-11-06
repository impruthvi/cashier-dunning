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

Add one step to CI and every pull request replays a real Stripe billing
lifecycle (a trial, a failing card, retries, cancellation, a comeback) through
your Cashier application's real webhook route, and fails the build if your app
grants or revokes access differently than the **fixture** says it should. A
**replay** needs no Stripe account, no API key and no network, and finishes in
under a second.

Dunning is the billing code least likely to be tested, because testing it
properly means waiting a month for a card to fail three times. A fixture is
that month, recorded once from a real Stripe test account.

## Installation

```bash
composer require impruthvi/cashier-dunning --dev
```

Requires PHP 8.3+, Laravel 12 or 13, and `laravel/cashier` 16. Applications
still on Laravel 11 can stay on v0.2.x, the last release line that supported it.

## Point it at your billable

A **billable** is the model a fixture's webhooks are addressed to, usually your
`User`. Tell the package how to make one, in a service provider:

```php
use Impruthvi\CashierDunning\CashierDunning;

CashierDunning::createBillableUsing(fn () => User::factory()->create([
    'stripe_id' => 'cus_replay1',
    'email' => 'replay@example.test',
]));
```

A fixture stores no real Stripe ids. Each one is replaced with a stand-in when
the fixture is replayed, and the customer in every shipped fixture becomes
`cus_replay1`. Without this registration, replay falls back to your Cashier
model's own factory. That record won't carry `cus_replay1`, so the replay stops
before sending a single webhook. Pin the email too; [Replaying out of
order](#replaying-out-of-order) explains why a random address breaks the comparison.

## Run your first replay

A **scenario** is a named billing story with a fixture behind it. To see the
ones available, run the command without one:

```bash
php artisan billing:simulate
```

Then replay the longest, thirty-four days from trial to comeback:

```bash
php artisan billing:simulate trial-dunning-cancel-reactivate
```

```
  trial-dunning-cancel-reactivate  replayed with no Stripe account

  ok   +0d  trial starts
      invoice.finalized  200
      ...
  ok   +34d  customer fixes their card and resubscribes
      ...

  Side effects  what the application did
      mail:replay@example.test  ×9
      9 outbound delivery(s) blocked. A replay never mails a real customer.

  PASS  All 46 assertions passed.
```

Each line under a step is a webhook posted through your real route, with the
status your app returned. The counts depend on your app: the output above
came from one whose dunning listener sends mail.

**Side effects** are what your app tried to do to the outside world. Replay
records each one and refuses to deliver it, so your listeners really run and
nothing reaches a customer. The replay also rolls back every database write
it caused, including the billable it created.

In a terminal `ok` is a green `✓`; in a CI log it prints as the word.

## Add it to CI

Add one step to the job that already runs your tests, after dependencies are
installed and the database is migrated:

```yaml
      - name: Replay billing scenarios
        env:
          QUEUE_CONNECTION: sync
        run: php artisan billing:simulate trial-dunning-cancel-reactivate --json=build/billing.json
```

A replay that disagrees with its fixture exits non-zero, so the step fails the
build on its own. `--json` also writes the result as a machine-readable report;
the directory is created if it doesn't exist. The step needs no Stripe secret,
because replay never reads a key, so it runs the same on pull requests from forks.

`QUEUE_CONNECTION=sync` matters if your dunning work is queued. A job pushed to
Redis, SQS or another out-of-process queue survives the replay's rollback, and a
worker would run it later against data that no longer exists. With `sync` it
runs inline, and its mail is caught like any other side effect.

Each scenario is its own command. Add a line per scenario, each with its own
`--json` path.

## Entitlements

A passing replay so far proves your webhooks returned 200. The more useful
question is what your app let the customer do afterwards. An **entitlement**
is one answer to that, such as access to teams or a project limit. What a plan
grants is your product's decision, so the package ships no implementation:
register a resolver that reports your app's entitlements for a billable.

```php
CashierDunning::resolveEntitlementsUsing(fn (User $user) => [
    'teams'    => $user->subscribed('default'),
    'projects' => $user->subscribed('default') ? 10 : 0,
]);
```

Each fixture step records the entitlements Stripe's state should produce, and
the replay compares your app's against them. The resolver is called once after
every webhook, not once per step, so `--explain` can name the event that moved
each one:

```bash
php artisan billing:simulate trial-dunning-cancel-reactivate --explain
```

```
  ok   +31d  retries exhausted, subscription cancelled
      invoice.payment_failed  200
        api: true -> false (at invoice.payment_failed)
        projects: 10 -> 0 (at invoice.payment_failed)
        teams: true -> false (at invoice.payment_failed)
      customer.subscription.deleted  200
```

That is the difference between "your users lost access" and "your users lost
access before Stripe cancelled anything".

<details>
<summary>The full thirty-four-day run</summary>

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

</details>

Registering nothing is fine: replays still run, and subscription status and
webhook handling are worth watching before any entitlements exist. To configure
a resolver without a closure, publish the config with
`php artisan vendor:publish --tag=cashier-dunning-config` and set
`entitlements.resolver` to a class implementing
`Impruthvi\CashierDunning\Contracts\EntitlementResolver`. A closure can't go
in the config file, because `config:cache` can't serialise one.

## When a replay disagrees with the fixture

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

Every webhook returned 200 and the subscription row is correct. The app grants
10 projects on a plan the fixture says is worth 25: a limit that drifted out of
step with billing, which no check of HTTP status codes would catch. The command
exits non-zero, so in CI this is a red build.

Every entitlement is shown, not only the ones that differ. A single red line
reads as a glitch; the same line among green ones reads as "everything else
held, this one moved."

## Replaying out of order

Stripe delivers webhooks at least once and promises no order. Live Stripe can't
test either on demand: redelivery is a manual resend from the Dashboard or
`stripe events resend <id>`, and there is no way to ask for a different order at
all. A fixture is a list you own, so a replay can do both, offline and in CI.

A fixture step is one position on the clock: the events Stripe emitted at
effectively the same moment. Two flags reorder them:

- `--shuffle` permutes the events within each step.
- `--duplicate` delivers every event a second time, after the originals, the way
  a Stripe redelivery arrives.

```bash
php artisan billing:simulate trial-dunning-cancel-reactivate --shuffle --duplicate
```

The in-order replay runs first. Each chaos pass then replays the same fixture in
a new order and compares what happened with the in-order replay, side effects
included. `--iterations` sets how many passes (default 4). Without `--seed`
each run picks a new seed; a failure prints the one to reproduce it with.

```
$ php artisan billing:simulate trial-dunning-cancel-reactivate --duplicate --seed=7 --iterations=1

  (the ordered pass runs first and prints its own timeline, then:)

  Chaos  same events, orders Stripe is entitled to use

  ok    pass 0: in order
  FAIL  pass 1: duplicated
        [mail:replay@example.test] happened 9 time(s) in order and 18 time(s) duplicated

  FAIL  1 ordering(s) changed the outcome. Reproduce with --seed=7.
```

That app ends the run with a correct subscription row. Its only defect is that
it would have emailed the customer twice for each of nine failed payments, which
is why comparing final state, the check most billing test suites make, calls it
idempotent. This is also why the billable's email has to be pinned: side effects
are compared by recipient, and a random address would differ on every pass.

> [!NOTE]
> Reordering stays within a step; it never moves an event across a clock
> advance. Stripe's own extreme case, a subscription deleted before its creation
> event arrives, is out of reach: in the shipped fixture those two events are
> five steps apart.

## Isolation and its limits

**Isolation** is the guarantee that a replay leaves no trace: its database writes
are rolled back, no outbound delivery reaches anyone, and your real Stripe key
can't be read. Each part has an edge worth knowing before you trust a green run.

**Database.** A replay wraps two connections in a transaction and rolls both
back, on success or failure: the one Cashier's customer model uses
(`Cashier::useCustomerModel(...)`) and your default connection. When they are
the same connection it is wrapped once. Writes to any other connection are not
isolated and persist. Transactions already open before the replay remain open.

**Mail and notifications.** This is deliberately not `Mail::fake()`. A fake
swaps the mailer out, so a listener that builds a message wrongly never builds
it at all, and the bug you came to find is unreachable. Instead every configured
mailer points at the array transport for the length of the run: your listener
and your `toMail()` run, the message is built and its recipients resolved, and
it has nowhere to go. `MessageSending` still fires, so the side effect is
recorded. Notification channels with no mail transport behind them, such as
Slack or SMS, are refused outright.

**Queued jobs are observed, not blocked.** `JobQueued` fires after Laravel has
already pushed the job, so there is nothing left to refuse. A `database` queue
on the billable's connection or the default connection rolls back with the
replay. Any other queue (a `database` queue on another connection, Redis, SQS,
Beanstalk) keeps the job, and a worker runs it later against data that no longer
exists. Run replays with `QUEUE_CONNECTION=sync` if your dunning work is queued.

**Your code can't commit its way out.** Code that calls `DB::commit()` or
`DB::rollBack()` on either replay connection takes the replay's transaction
away, and the rollback afterwards would leave real rows behind. The replay
detects this and fails with a named error instead of reporting PASS. Work
scheduled with `->afterCommit()` can't run inside a replay that never commits,
so the replay detects those callbacks too and fails rather than report on side
effects it never saw.

**Your Stripe key.** While a replay runs, your real key is not in configuration
to be read, so nothing can reach live Stripe by accident.

**The billable is checked before any webhook.** The replay reads the customer id
from the fixture and checks it resolves to exactly the record your factory
returned. No `stripe_id`, the wrong one, no matching row, or several rows sharing
it each get their own error, so you're told what is actually broken instead of
shown a red entitlement table.

## Commands

```bash
# list the scenarios this installation can replay
php artisan billing:simulate

# replay a scenario
php artisan billing:simulate <scenario>

# name the event behind every entitlement change
php artisan billing:simulate <scenario> --explain

# replay in orders Stripe is entitled to use
php artisan billing:simulate <scenario> --shuffle --duplicate --seed=7 --iterations=4

# write a machine-readable report for CI
php artisan billing:simulate <scenario> --json=build/billing.json

# record against your own Stripe test account
php artisan billing:simulate <scenario> --record

# check what this installation can do, offline
php artisan billing:doctor
```

## Check an installation

`billing:doctor` answers "will this work here?" without running anything:

```
$ php artisan billing:doctor

  cashier-dunning environment check

  ✓ Stripe key: not set. Fine for replay; recording needs a test key.
  ✓ Cashier billable model: App\Models\User
  ! Webhook secret: not set. Cashier skips signature verification, so replay cannot prove your signature handling works.
  ✓ Entitlement resolver: closure registered in code
  ✓ 3 fixture(s) valid in /app/vendor/impruthvi/cashier-dunning/fixtures
  ✓ Scenario [trial-dunning-cancel-reactivate]: recorded.
  ✓ Scenario [downgrade-over-usage-limit]: recorded.

  Replay  works with no Stripe account, key or network.
  Record  blocked: no key configured.
```

The fixture path is wherever Composer installed the package. Only scenarios
that can be recorded are listed, so the hand-authored
`cancel-at-period-end-then-canceled-early` has a fixture but no line of its own.

## Recording against your own account

The shipped fixtures were recorded against a dedicated test account. Dunning
depends on account settings, such as how often Stripe retries a failed card and
what it does when retries run out, so your account can produce a different
lifecycle. Record the scenarios against it:

```bash
STRIPE_SECRET=sk_test_... php artisan billing:simulate trial-dunning-cancel-reactivate --record
```

Recording uses a Stripe **test clock**: a clock in test mode that a customer and
subscription can be attached to and moved forward on demand, so trial ends,
renewals and retries happen when the clock is advanced rather than when the
calendar gets there. The recorder creates the customer and subscription on a
new clock, advances it through each step of the scenario, and writes down the
webhooks Stripe sends and the API responses it gives.

The fixture is saved to `tests/fixtures/billing`, or to `fixtures.path` in the
config. Fixtures there take precedence over the shipped ones of the same name.
Commit them: they are what lets CI replay without a network.

**Only a test key will do.** The guard is an allowlist, not a blocklist:
recording proceeds only when the key can be positively identified as a test
key, so a prefix Stripe introduces next year can't quietly become permitted.
Against a live key, recording would mean real charges to real cards.

**A recording has to replay before it is saved.** Each scenario has a
**manifest**: the events its recording must contain. A recording that is
missing one, can't be turned into a fixture, or doesn't replay clean through
your app is not saved. It becomes a **quarantine** under `quarantine/` in your
fixture directory instead, holding what arrived and what didn't, plus the
fixture that would have been written and the replay report when there is one.
A fixture that can't be replayed is worse than none, because it looks like
coverage.

**Saving never leaves half a file.** A failed save leaves any previous fixture
untouched. If the quarantine itself can't be saved, the command fails and
reports both failures rather than claiming one exists.

**Your test account keeps what recording created.** The local verification
replay rolls back like any other, but the customers, subscriptions and invoices
recording created stay in your Stripe test account.

## Scenarios

| Scenario | Story | Fixture |
|---|---|---|
| `trial-dunning-cancel-reactivate` | A trial ends, the card fails, retries run out, the subscription is cancelled, the customer comes back | Recorded |
| `downgrade-over-usage-limit` | A customer moves to a smaller plan mid-period and their limit drops below what they are already using | Recorded |
| `cancel-at-period-end-then-canceled-early` | A customer schedules a cancellation, Stripe cancels early, and Cashier keeps reporting the subscription as active | Hand-authored |

The recorded fixtures come from a dedicated test account with invented products,
so there is nothing sensitive in them by construction.
`cancel-at-period-end-then-canceled-early` is hand-authored from Stripe's
documented subscription object instead. It reproduces
[laravel/cashier-stripe#1791][1791], and the point is to show the defect without
needing an account to see it. Its provenance says so, and because it has no
recording behind it, `--record` can't produce it.

## What a fixture may contain

Fixtures are committed, often to a public repository, so their contents are
restricted by an **allowlist** of permitted fields. Recording drops anything
the allowlist doesn't permit; saving or loading refuses it.

A fixture holds no real Stripe ids. Each is a **placeholder**, such as
`{{cus_1}}`, and points on the timeline are placeholders too, such as
`{{t+14d}}`. A replay resolves them to fresh values, which is where
`cus_replay1` comes from.

Each Stripe call your app made during recording is kept as an **API exchange**:
the request and the response Stripe gave. A replay answers a call only from an
exchange in the current step, matched on its **match path**, such as
`/v1/subscriptions/{{sub_1}}`, and on any request parameters in its
**match body**.

Saving or loading refuses a fixture that:

- carries a field in an event or API response that the allowlist doesn't permit
- has an API exchange whose match body uses a parameter the allowlist doesn't permit
- has a match path with a literal provider id or a query string, rather than
  lowercase words and whole placeholders
- is missing an event its manifest requires
- has a field of the wrong type, such as a status of `"200"` or an event with no
  string `type`, rather than casting it
- holds a recognised literal provider id as a whole string value in an event,
  API response body or match body, nested lists included

The refusal names the step, the location in the payload and the placeholder kind
to use, plus the method and match path for an API exchange. The literal-id rule
covers the same id kinds recording does: unsupported prefixes such as `acct_`
and `seti_`, ids embedded in longer strings, and fixture metadata are outside it.

## How it works

Cashier's Stripe calls don't go through Laravel's HTTP client, so `Http::fake()`
can't see them. A replay installs a transport in the Stripe SDK's own seam
(`ApiRequestor::setHttpClient`) and answers each call from the fixture's API
exchanges. Webhooks are signed with a real HMAC against a throwaway secret and
posted through your HTTP kernel, so routing, middleware and Cashier's signature
verification all run as they would in production with a webhook secret
configured.

Two rules keep a replay honest:

- **Nothing is invented.** A Stripe call with no recorded response is an error
  naming the call and the step, never an empty body.
- **Nothing is served out of time.** Responses belong to the step that recorded
  them, so a subscription that was `trialing` at step 1 can't answer for step 3,
  where it is `past_due`.

And a replay that asserted nothing can't pass. A green billing test that proves
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
timestamps are stored as placeholders, key order is fixed, and encoding is
pinned. A re-recording of the same scenario should produce identical bytes.

## Credits

- [Pruthvi Rajput](https://github.com/impruthvi)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[1791]: https://github.com/laravel/cashier-stripe/issues/1791
