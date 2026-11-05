<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierDunning\Record\Recorder;
use Laravel\Cashier\Events\WebhookReceived;

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

/**
 * Points `--record` at a scripted Stripe and a fresh fixture root.
 *
 * @param  list<array<string, mixed>>  $events
 * @return array{0: string, 1: Closure(): void} the fixture root, and the cleanup to run in `finally`
 */
function scriptedRecording(array $events): array
{
    config()->set('cashier.secret', 'sk_test_scripted');
    $root = sys_get_temp_dir().'/cashier-dunning-fixtures-'.uniqid();
    config()->set('cashier-dunning.fixtures.path', $root);

    // The command resolves the recorder, so its timeouts can be shortened here.
    app()->bind(Recorder::class, fn ($app, array $parameters) => new Recorder(
        $parameters['stripe'],
        eventTimeoutSeconds: 0,
    ));

    [, , $restore] = scriptedStripe(recordingScript($events));

    return [$root, function () use ($root, $restore): void {
        $restore();

        foreach (['stripe', 'quarantine'] as $directory) {
            array_map(unlink(...), glob($root.'/'.$directory.'/*.json') ?: []);
            @rmdir($root.'/'.$directory);
        }

        @rmdir($root);
    }];
}

it('writes a recording that replays clean, and says where', function () {
    billableUser();

    [$root, $cleanup] = scriptedRecording([
        subscriptionEvent('customer.subscription.created', 'trialing'),
        subscriptionEvent('customer.subscription.updated', 'active'),
    ]);

    try {
        $this->artisan('billing:simulate downgrade-over-usage-limit --record')
            ->expectsOutputToContain('Wrote '.nativePath($root, 'stripe', 'downgrade-over-usage-limit.json'))
            ->expectsOutputToContain('replayed clean.')
            ->assertSuccessful();

        expect(file_exists($root.'/stripe/downgrade-over-usage-limit.json'))->toBeTrue()
            ->and(glob($root.'/quarantine/*.json') ?: [])->toBe([]);
    } finally {
        $cleanup();
    }
});

it('reports a recording the fixture format refuses instead of crashing', function () {
    // The recording reaches Stripe and back, then fails when the fixture is
    // built. That has to read as a failed recording, not a stack trace.
    [$root, $cleanup] = scriptedRecording([
        subscriptionEvent('customer.subscription.created', '{{bad}}'),
        subscriptionEvent('customer.subscription.updated', 'active'),
    ]);

    try {
        $this->artisan('billing:simulate downgrade-over-usage-limit --record')
            ->expectsOutputToContain('[{{bad}}] is not a placeholder')
            ->doesntExpectOutputToContain('Stack trace')
            ->assertFailed();

        expect(glob($root.'/quarantine/*.quarantine.json') ?: [])->toHaveCount(1)
            ->and(file_exists($root.'/stripe/downgrade-over-usage-limit.json'))->toBeFalse();
    } finally {
        $cleanup();
    }
});

it('shows the replay a fresh recording failed, and writes no fixture', function () {
    // The refusal names the verdict. The timeline under it is what says which
    // event the application fell over on.
    billableUser();
    Event::listen(WebhookReceived::class, function (): void {
        throw new RuntimeException('the application fell over');
    });

    [$root, $cleanup] = scriptedRecording([
        subscriptionEvent('customer.subscription.created', 'trialing'),
        subscriptionEvent('customer.subscription.updated', 'active'),
    ]);

    try {
        $this->artisan('billing:simulate downgrade-over-usage-limit --record')
            ->expectsOutputToContain('did not replay clean')
            ->expectsOutputToContain('The application answered 500')
            ->doesntExpectOutputToContain('Stack trace')
            ->assertFailed();

        expect(glob($root.'/quarantine/*.quarantine.json') ?: [])->toHaveCount(1)
            ->and(file_exists($root.'/stripe/downgrade-over-usage-limit.json'))->toBeFalse();
    } finally {
        $cleanup();
    }
});

it('reports save and quarantine failures and exits unsuccessfully', function () {
    billableUser();
    [$root, $cleanup] = scriptedRecording([
        subscriptionEvent('customer.subscription.created', 'trialing'),
        subscriptionEvent('customer.subscription.updated', 'active'),
    ]);
    $unblock = blockDirectories($root.'/stripe', $root.'/quarantine');

    try {
        $exit = Artisan::call('billing:simulate downgrade-over-usage-limit --record');

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('could not be saved', 'Quarantine could not be saved', 'No diagnostic artifact was written')
            ->and(Artisan::output())->not->toContain('Diagnostics written to', 'Wrote '.$root);
    } finally {
        $unblock();
        $cleanup();
    }
});
