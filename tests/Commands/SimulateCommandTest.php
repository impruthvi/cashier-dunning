<?php

it('refuses to record against a live key before touching Stripe', function () {
    // The guard sits in the command, not in the recorder, so no later code path
    // can reach Stripe without passing it.
    config()->set('cashier.secret', 'sk_live_abc123');

    $this->artisan('billing:simulate --record')
        ->expectsOutputToContain('LIVE key')
        ->assertFailed();
});

it('refuses to record with no key configured', function () {
    config()->set('cashier.secret', null);

    $this->artisan('billing:simulate --record')
        ->expectsOutputToContain('no Stripe key is configured')
        ->assertFailed();
});

it('does not check the key when replaying', function () {
    // Replay never reads the key. Guarding it would break the one workflow this
    // package exists to make possible: testing billing with no Stripe account.
    config()->set('cashier.secret', 'sk_live_abc123');

    $this->artisan('billing:simulate')
        ->doesntExpectOutputToContain('LIVE')
        ->assertFailed();
});
