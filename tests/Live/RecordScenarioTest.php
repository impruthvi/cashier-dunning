<?php

use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\RecordAndVerify;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;
use Impruthvi\CashierDunning\Tests\Support\User;
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
    // The application whose entitlement policy the recording will capture:
    // access survives the retry window and ends when Stripe gives up.
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

    $scenario = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate');

    ['fixture' => $fixture, 'report' => $report] = (new RecordAndVerify(
        new Recorder(
            stripe: Cashier::stripe(),
            quarantineDirectory: dirname(__DIR__, 2).'/fixtures/quarantine',
            eventTimeoutSeconds: (int) (env('CASHIER_DUNNING_EVENT_TIMEOUT') ?? 25),
        ),
        new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)),
    ))->record($scenario);

    expect($fixture->satisfiesManifest())->toBeTrue()
        ->and($report->passed())->toBeTrue();

    $path = dirname(__DIR__, 2).'/fixtures/stripe/'.$fixture->scenario.'-recorded.json';
    FixtureFile::write($fixture, $path);

    fwrite(STDERR, "\n---RECORDED--- {$path}\n");
    foreach ($fixture->steps as $step) {
        fwrite(STDERR, sprintf(
            "  +%-7s %-52s %s\n",
            $step->advanceTo->toString(),
            implode(', ', $step->eventTypes()) ?: '(no events)',
            json_encode($step->entitlements),
        ));
    }

    CashierDunning::flush();
})->group('live');
