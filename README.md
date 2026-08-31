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

  ✓ +0d      trial starts
      customer.subscription.created  200
        teams: false -> true (at customer.subscription.created)
        projects: 0 -> 10 (at customer.subscription.created)
  ✓ +11d     three days before the trial ends
      customer.subscription.trial_will_end  200
  ✓ +14d1h   trial ends, first payment attempt fails
      customer.subscription.updated  200
      invoice.payment_failed  200
  ✓ +17d1h   grace period expires on the second failed attempt
      invoice.payment_failed  200
  ✓ +28d     retries continue, access holds
      invoice.payment_failed  200          (×6)
  ✓ +31d     retries exhausted, subscription cancelled
      invoice.payment_failed  200
        teams: true -> false (at invoice.payment_failed)
        projects: 10 -> 0 (at invoice.payment_failed)
      customer.subscription.deleted  200
  ✓ +34d     customer fixes their card and resubscribes
      customer.subscription.created  200
        teams: false -> true (at customer.subscription.created)

  PASS  All 46 assertions passed.
```

*(Abridged: the real run also delivers the `invoice.created`, `invoice.finalized`,
`invoice.paid` and `invoice.payment_succeeded` events Stripe emits alongside
these — 25 events across 7 steps.)*

Thirty-four days of billing. No Stripe account. Sub-second.

## The part live Stripe cannot test

Stripe guarantees **at-least-once delivery** and states plainly that it does
**not guarantee order** — a subscription may be deleted before the event that
created it arrives.

Almost nobody tests against that, because you cannot ask Stripe to redeliver an
event on demand, or to send today's webhooks backwards. A fixture is a list, and
lists can be rearranged:

```
$ php artisan billing:simulate trial-dunning-cancel-reactivate --duplicate --seed=7

  Chaos  same events, orders Stripe is entitled to use

  ok    pass 0: in order
  FAIL  pass 1: duplicated
        [mail:jenny@example.com] happened 9 time(s) in order and 18 time(s) duplicated

  FAIL  1 ordering(s) changed the outcome. Reproduce with --seed=7.
```

That application ends the run with a **perfectly correct subscription row**. Its
only defect is that nine customers received two emails — which is why comparing
final state, the thing most billing test suites do, would call it idempotent.

## Installation

```bash
composer require impruthvi/cashier-dunning --dev
```

Requires PHP 8.3+, Laravel 12 or 13, and `laravel/cashier` 16.

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
]));
```

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
        feature   recording     application
        teams     true          false
        api       true          —
        projects  10            3
```

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
