<?php

use Impruthvi\CashierDunning\CashierDunning;
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
        ->expectsOutputToContain('All 25 assertions passed.')
        ->assertSuccessful();
});

it('exits non-zero when the application does not match the recording', function () {
    User::create(['name' => 'Jenny', 'email' => 'jenny@example.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => User::first());
    CashierDunning::resolveEntitlementsUsing(fn (): array => ['teams' => false]);

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('teams: recording says true, application says false')
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
