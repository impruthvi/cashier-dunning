<?php

use Impruthvi\CashierDunning\Record\Recorder;

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
        ->assertSuccessful();
});

it('reports a recording the fixture format refuses instead of crashing', function () {
    // The recording reaches Stripe and back, then fails when the fixture is
    // built. That has to read as a failed recording, not a stack trace.
    config()->set('cashier.secret', 'sk_test_scripted');
    $root = sys_get_temp_dir().'/cashier-dunning-fixtures-'.uniqid();
    config()->set('cashier-dunning.fixtures.path', $root);

    app()->bind(Recorder::class, fn ($app, array $parameters) => new Recorder(
        $parameters['stripe'],
        $parameters['quarantineDirectory'],
        eventTimeoutSeconds: 0,
    ));

    [, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', '{{bad}}'),
        subscriptionEvent('customer.subscription.updated', 'active'),
    ]));

    try {
        $this->artisan('billing:simulate downgrade-over-usage-limit --record')
            ->expectsOutputToContain('[{{bad}}] is not a placeholder')
            ->doesntExpectOutputToContain('Stack trace')
            ->assertFailed();

        expect(glob($root.'/quarantine/*.quarantine.json') ?: [])->toHaveCount(1)
            ->and(file_exists($root.'/stripe/downgrade-over-usage-limit.json'))->toBeFalse();
    } finally {
        $restore();
        array_map(unlink(...), glob($root.'/quarantine/*.json') ?: []);
        @rmdir($root.'/quarantine');
        @rmdir($root);
    }
});
