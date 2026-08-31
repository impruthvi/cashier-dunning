<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\User;

afterEach(fn () => CashierDunning::flush());

function registerReplayApp(): void
{
    $user = User::create(['name' => 'Jenny', 'email' => 'jenny@example.com', 'stripe_id' => 'cus_replay1']);

    CashierDunning::createBillableUsing(fn () => $user);
    CashierDunning::resolveEntitlementsUsing(function (User $billable): array {
        $billable->refresh();
        $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

        $entitled = $subscription !== null
            && ! $billable->dunning_exhausted
            && $subscription->ends_at === null
            && in_array($subscription->stripe_status, ['trialing', 'active', 'past_due'], true);

        return ['teams' => $entitled, 'api' => $entitled, 'projects' => $entitled ? 10 : 0];
    });
}

it('replays the shipped scenario and exits zero', function () {
    registerReplayApp();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('replayed with no Stripe account')
        ->expectsOutputToContain('trial ends, first payment attempt fails')
        ->expectsOutputToContain('All 46 assertions passed.')
        ->assertSuccessful();
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
    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('createBillableUsing')
        ->assertFailed();
});

it('runs chaos passes when asked and reports the seed', function () {
    registerReplayApp();

    $this->artisan('billing:simulate', [
        'scenario' => 'trial-dunning-cancel-reactivate',
        '--shuffle' => true,
        '--iterations' => 2,
        '--seed' => 99,
    ])
        ->expectsOutputToContain('same events, orders Stripe is entitled to use')
        ->expectsOutputToContain('pass 1: shuffled')
        ->assertSuccessful();
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

it('does not run chaos passes unless asked', function () {
    registerReplayApp();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->doesntExpectOutputToContain('orders Stripe is entitled to use')
        ->assertSuccessful();
});
