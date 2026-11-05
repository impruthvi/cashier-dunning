<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Record\RecordAndVerify;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
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

function recordAndWrite(string $scenarioName): Fixture
{
    $scenario = (new ScenarioRepository)->find($scenarioName);

    ['fixture' => $fixture, 'path' => $path] = (new RecordAndVerify(
        new Recorder(
            stripe: Cashier::stripe(),
            eventTimeoutSeconds: (int) (env('CASHIER_DUNNING_EVENT_TIMEOUT') ?? 25),
        ),
        app(ReplayRunner::class),
        dirname(__DIR__, 2).'/fixtures',
    ))->record($scenario);

    expect($fixture->satisfiesManifest())->toBeTrue();

    fwrite(STDERR, "\n---RECORDED--- {$path}\n");
    foreach ($fixture->steps as $step) {
        fwrite(STDERR, sprintf(
            "  +%-7s %-46s %s\n",
            $step->advanceTo->toString(),
            $step->label,
            json_encode($step->entitlements),
        ));
    }

    return $fixture;
}

it('records the dunning scenario against Stripe', function () {
    billableUser();
    registerDunningPolicy();

    expect(recordAndWrite('trial-dunning-cancel-reactivate')->steps)->toHaveCount(7);

    CashierDunning::flush();
})->group('live');

it('records the downgrade scenario against Stripe', function () {
    billableUser();
    registerPlanLimits();

    expect(recordAndWrite('downgrade-over-usage-limit')->steps)->toHaveCount(5);

    CashierDunning::flush();
})->group('live');
