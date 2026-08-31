<?php

use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;
use Laravel\Cashier\Cashier;

/**
 * Records the reference corpus against a real Stripe test account.
 *
 * Skipped unless a key is present, which is what keeps the contract this
 * package rests on intact: GitHub withholds secrets from fork pull requests, so
 * a suite that needed one would be red for exactly the contributors the project
 * wants. Everything else in the suite runs offline; this is the only test that
 * does not, and it opts in rather than out.
 */
beforeEach(function () {
    $key = env('STRIPE_SECRET');

    if (! is_string($key) || ! str_starts_with($key, 'sk_test_')) {
        $this->markTestSkipped('Set STRIPE_SECRET to a Stripe test key to re-record the corpus.');
    }

    config()->set('cashier.secret', $key);
});

it('records the dunning scenario against Stripe', function () {
    $scenario = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate');

    $fixture = (new Recorder(
        stripe: Cashier::stripe(),
        quarantineDirectory: dirname(__DIR__, 2).'/fixtures/quarantine',
        eventTimeoutSeconds: (int) (env('CASHIER_DUNNING_EVENT_TIMEOUT') ?? 25),
    ))->record($scenario);

    expect($fixture->satisfiesManifest())->toBeTrue();

    $path = dirname(__DIR__, 2).'/fixtures/stripe/'.$fixture->scenario.'.recorded.json';
    FixtureFile::write($fixture, $path);

    fwrite(STDERR, "\n---RECORDED--- {$path}\n");
    foreach ($fixture->steps as $step) {
        fwrite(STDERR, sprintf(
            "  +%s %s -> %s\n",
            $step->advanceTo->toString(),
            $step->label,
            implode(', ', $step->eventTypes()) ?: '(no events)'
        ));
    }
})->group('live');
