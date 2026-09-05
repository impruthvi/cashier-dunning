<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\User;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(fn () => DunningMailer::reset());
afterEach(function () {
    CashierDunning::flush();
    DunningMailer::reset();
});

function registerReplayApp(): void
{
    billableUser();
    registerDunningPolicy();
}

it('replays the shipped scenario and exits zero', function () {
    registerReplayApp();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('replayed with no Stripe account')
        ->expectsOutputToContain('trial ends, first payment attempt fails')
        ->expectsOutputToContain('All 46 assertions passed.')
        ->assertSuccessful();
});

it('replays the same scenario twice on one database with a fresh billable per run', function () {
    registerReplayApp();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);

    // No database refresh or factory re-registration between commands: this is
    // the second invocation a developer makes after the first green replay.
    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('All 46 assertions passed.')
        ->assertSuccessful();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('All 46 assertions passed.')
        ->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);
});

it('exits non-zero when the application does not match the recording', function () {
    User::create(['name' => 'Jenny', 'email' => 'jenny@example.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => User::first());
    CashierDunning::resolveEntitlementsUsing(fn (): array => ['teams' => false]);

    // The terminal shows the two sides rather than a sentence about them: the
    // useful question is always "what did it say instead", and a table answers
    // it without being read.
    // One expectation per written line: expectsOutputToContain consumes a line
    // per expectation, so several substrings from the same row starve each other.
    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('feature')
        ->expectsOutputToContain('teams')
        ->expectsOutputToContain('projects')
        ->assertFailed();
});

it('explains which event moved an entitlement', function () {
    registerReplayApp();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate --explain')
        ->expectsOutputToContain('teams: true -> false (at invoice.payment_failed)')
        ->assertSuccessful();
});

it('lists the available scenarios when given none', function () {
    $this->artisan('billing:simulate')
        ->expectsOutputToContain('billing:simulate trial-dunning-cancel-reactivate')
        ->assertSuccessful();
});

it('names the scenarios it does have when asked for one it does not', function () {
    $this->artisan('billing:simulate no-such-scenario')
        ->expectsOutputToContain('trial-dunning-cancel-reactivate')
        ->assertFailed();
});

it('explains how to register a billable before running anything', function () {
    // The normal test billable now has a factory; this case needs a model
    // without one to exercise the missing-factory diagnostic.
    Cashier::useCustomerModel(Authenticatable::class);

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('createBillableUsing')
        ->assertFailed();
});

it('rejects the fallback factory billable before delivering a webhook when it has no stripe id', function () {
    CashierDunning::flush();
    $webhooks = 0;
    Event::listen(WebhookReceived::class, function () use (&$webhooks): void {
        $webhooks++;
    });

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('no stripe_id')
        ->assertFailed();

    expect($webhooks)->toBe(0);
    $this->assertDatabaseCount('users', 0);
});

it('rejects a billable with a different replay customer id before delivering a webhook', function () {
    $webhooks = 0;
    Event::listen(WebhookReceived::class, function () use (&$webhooks): void {
        $webhooks++;
    });
    CashierDunning::createBillableUsing(fn () => User::factory()->create([
        'stripe_id' => 'cus_wrong',
    ]));

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('fixture webhooks target stripe_id [cus_replay1]')
        ->assertFailed();

    expect($webhooks)->toBe(0);
    $this->assertDatabaseCount('users', 0);
});

it('rejects duplicate replay residue when Cashier resolves a different billable', function () {
    $existing = User::factory()->create(['stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => User::factory()->create([
        'stripe_id' => 'cus_replay1',
    ]));

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('Cashier::findBillable() returned a different record')
        ->assertFailed();

    expect(User::pluck('id')->all())->toBe([$existing->id]);
});

it('runs CLI shuffle from a fresh database with a fresh billable per pass', function () {
    registerReplayApp();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);

    $this->artisan('billing:simulate', [
        'scenario' => 'trial-dunning-cancel-reactivate',
        '--shuffle' => true,
        '--iterations' => 2,
        '--seed' => 7,
    ])
        ->expectsOutputToContain('All 46 assertions passed.')
        ->expectsOutputToContain('same events, orders Stripe is entitled to use')
        ->expectsOutputToContain('ok    pass 0: in order')
        ->expectsOutputToContain('ok    pass 1: shuffled')
        ->expectsOutputToContain('ok    pass 2: shuffled')
        ->expectsOutputToContain('The application behaved identically across 3 ordering(s).')
        ->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);
});

it('catches an application that acts twice on a redelivered event', function () {
    // Stripe delivers at least once. Live Stripe cannot be asked to redeliver
    // on demand, so almost nobody tests this — and the application still ends
    // the run with a perfectly correct subscription row.
    registerReplayApp();
    DunningMailer::$deduplicate = false;

    $this->artisan('billing:simulate', [
        'scenario' => 'trial-dunning-cancel-reactivate',
        '--duplicate' => true,
        '--iterations' => 1,
        '--seed' => 7,
    ])
        ->expectsOutputToContain('mail:jenny@example.com')
        ->expectsOutputToContain('--seed=7')
        ->assertFailed();
});

it('shows the failing step and event when a chaos replay itself fails', function () {
    $factoryCalls = 0;
    CashierDunning::createBillableUsing(function () use (&$factoryCalls): User {
        $factoryCalls++;

        return User::factory()->create([
            'name' => $factoryCalls === 1 ? 'Jenny' : 'Broken chaos billable',
            'stripe_id' => 'cus_replay1',
        ]);
    });

    registerDunningPolicy();
    $policy = CashierDunning::entitlementResolver();
    CashierDunning::resolveEntitlementsUsing(
        fn (User $billable): array => $billable->name === 'Broken chaos billable'
            ? ['teams' => false, 'api' => false, 'projects' => 0]
            : $policy->resolve($billable)
    );

    // The ordered command pass succeeds. The next factory call belongs to the
    // chaos baseline and deliberately returns an application state that fails
    // at step zero, reproducing the formerly context-free "FAIL pass 0" output.
    $this->artisan('billing:simulate', [
        'scenario' => 'trial-dunning-cancel-reactivate',
        '--shuffle' => true,
        '--iterations' => 1,
        '--seed' => 7,
    ])
        ->expectsOutputToContain('FAIL  pass 0: in order')
        ->expectsOutputToContain('FAIL +0d  trial starts')
        ->expectsOutputToContain('customer.subscription.created')
        ->expectsOutputToContain('feature')
        ->assertFailed();
});

it('does not run chaos passes unless asked', function () {
    registerReplayApp();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->doesntExpectOutputToContain('orders Stripe is entitled to use')
        ->assertSuccessful();
});
