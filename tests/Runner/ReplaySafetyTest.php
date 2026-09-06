<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Fixtures\Step;
use Impruthvi\CashierDunning\Replay\FixtureHttpClient;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\User;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\ApiRequestor;

/**
 * The two ways a replay could reach past its own boundary.
 *
 * Both were found by an independent review of the shipped v0.2.0 and both are
 * silent by nature: one touches a real Stripe account, the other leaves real
 * rows behind while still printing PASS.
 */
beforeEach(fn () => DunningMailer::reset());
afterEach(function () {
    CashierDunning::flush();
    DunningMailer::reset();
});

function safetyReplay(): mixed
{
    registerDunningPolicy();

    return (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))
        ->run((new FixtureRepository)->find('trial-dunning-cancel-reactivate'));
}

it('builds the billable with the ephemeral key, never the real one', function () {
    // A billable factory is ordinary application code, and
    // createAsStripeCustomer() is Cashier's documented way to make a customer.
    // Built before the credentials moved, that call reached the developer's real
    // account with their real key.
    config()->set('cashier.secret', 'sk_test_the_developers_real_key');

    $seen = null;

    CashierDunning::createBillableUsing(function () use (&$seen): User {
        $seen = config('cashier.secret');

        return User::factory()->create([
            'stripe_id' => 'cus_replay1',
            'email' => 'replay@example.test',
        ]);
    });

    safetyReplay();

    expect($seen)->not->toBeNull()
        ->and($seen)->not->toBe('sk_test_the_developers_real_key');
});

it('cannot reach the real Stripe transport from inside the billable factory', function () {
    // The transport is the other half: swapping the key but leaving the real
    // HTTP client installed would still put a request on the wire.
    $transport = null;

    CashierDunning::createBillableUsing(function () use (&$transport): User {
        $transport = ApiRequestor::httpClient();

        return User::factory()->create([
            'stripe_id' => 'cus_replay1',
            'email' => 'replay@example.test',
        ]);
    });

    safetyReplay();

    expect($transport)->toBeInstanceOf(FixtureHttpClient::class);
});

it('fails loudly when application code commits the transaction the replay opened', function () {
    // Connection::rollBack($toLevel) returns without doing anything when the
    // level has already dropped, so a webhook handler calling DB::commit() used
    // to make the replay's writes permanent while the run still reported PASS.
    billableUser();

    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) === 'invoice.payment_failed' && DB::transactionLevel() > 0) {
            DB::commit();
        }
    });

    expect(fn () => safetyReplay())
        ->toThrow(SimulationFailed::class, 'could not undo its own writes');
});

it('names the real problem when Cashier finds no record at all', function () {
    // make() instead of create() is the easy version of this mistake: the model
    // carries the right stripe_id, so the id check passes, and then Cashier
    // queries for a row that was never written.
    //
    // The old code called that "returned a different record" and told you to go
    // delete duplicate rows — sending you to clean a table that is empty.
    CashierDunning::createBillableUsing(fn () => User::factory()->make([
        'stripe_id' => 'cus_replay1',
        'email' => 'replay@example.test',
    ]));

    expect(fn () => safetyReplay())
        ->toThrow(SimulationFailed::class, 'found no record with that id');
});

it('reads the expected customer out of the recording rather than assuming it', function () {
    $fixture = (new FixtureRepository)->find('trial-dunning-cancel-reactivate');

    expect($fixture->customerPlaceholder())->toBe('{{cus_1}}');
});

it('stands the preflight down when the recording names no single customer', function () {
    // A fixture naming no customer, or more than one, cannot be checked against
    // a single billable. The check is skipped rather than failing a run for not
    // matching an id the recording never used — which is what a hardcoded
    // '{{cus_1}}' did to anyone who recorded their own scenario.
    $fixture = (new FixtureRepository)->find('trial-dunning-cancel-reactivate');

    $noCustomer = new Fixture(
        provider: $fixture->provider,
        scenario: 'no-customer',
        provenance: $fixture->provenance,
        manifest: $fixture->manifest,
        steps: [
            new Step(
                advanceTo: $fixture->steps[0]->advanceTo,
                label: 'nothing that names a customer',
                events: [['id' => 'evt_1', 'type' => 'ping']],
            ),
        ],
    );

    expect($noCustomer->customerPlaceholder())->toBeNull();
});
