<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Manifest;
use Impruthvi\CashierDunning\Record\Exceptions\IncompleteRecording;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Scenarios\Action;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Scenarios\ScenarioStep;

function twoStepScenario(array $required): Scenario
{
    return new Scenario(
        name: 'test-scenario',
        description: 'two steps',
        steps: [
            ScenarioStep::at('0d', 'subscribe', Action::subscribe('price_monthly', 'pm_card_chargeCustomerFail', 14)),
            ScenarioStep::at('14d1h', 'payment fails'),
        ],
        manifest: new Manifest(required: $required),
    );
}

it('records a scenario into a fixture', function () {
    [$stripe, $fake, $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
        subscriptionEvent('customer.subscription.updated', 'past_due'),
    ]));

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect($fixture->scenario)->toBe('test-scenario')
        ->and($fixture->steps)->toHaveCount(2)
        ->and($fixture->eventTypes())->toContain('customer.subscription.created')
        ->and($fake->calls)->toContain('POST /v1/test_helpers/test_clocks');

    $restore();
});

it('normalises ids so two recordings of the same journey match', function () {
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect($fixture->steps[0]->events[0]['data']['object']['id'])->toBe('{{sub_1}}')
        ->and($fixture->steps[0]->events[0]['data']['object']['customer'])->toBe('{{cus_1}}');

    $restore();
});

it('redacts on the way out rather than as a review step somebody forgets', function () {
    // Fixtures are published. Redaction happens at the point of writing.
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    $object = $fixture->steps[0]->events[0]['data']['object'];

    expect($object)->not->toHaveKey('customer_email')
        ->and($object['items']['data'][0]['price'])->not->toHaveKey('nickname')
        ->and($object['items']['data'][0]['price']['product'])->toBe('{{prod_1}}');

    $restore();
});

it('records the subscription state at each step so replay cannot answer out of time', function () {
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect($fixture->steps[0]->apiExchanges)->toHaveCount(1)
        ->and($fixture->steps[0]->apiExchanges[0]->path)->toBe('/v1/subscriptions/{{sub_1}}')
        ->and($fixture->steps[0]->apiExchanges[0]->responseBody)->not->toHaveKey('customer_email');

    $restore();
});

it('refuses to write a fixture when a required event never arrived', function () {
    // The whole point. A short recording replays green forever and the drift
    // job, comparing it against another short recording, agrees.
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    try {
        (new Recorder($stripe, eventTimeoutSeconds: 0))->record(
            twoStepScenario(['customer.subscription.created', 'invoice.payment_failed']),
            CarbonImmutable::parse('2026-01-01T00:00:00Z')
        );
        $this->fail('Expected the recording to be refused.');
    } catch (IncompleteRecording $e) {
        expect($e->getMessage())->toContain('No fixture was written')
            ->and($e->missing)->toBe(['invoice.payment_failed']);
    }

    $restore();
});

it('leaves a quarantine artifact a person can read', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cd-quarantine-'.uniqid();

    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    try {
        (new Recorder($stripe, quarantineDirectory: $directory, eventTimeoutSeconds: 0))->record(
            twoStepScenario(['invoice.payment_failed']),
            CarbonImmutable::parse('2026-01-01T00:00:00Z')
        );
    } catch (IncompleteRecording $e) {
        expect($e->artifactPath)->not->toBeNull()
            ->and(json_decode((string) file_get_contents((string) $e->artifactPath), true)['missing_events'])
            ->toBe(['invoice.payment_failed']);

        unlink((string) $e->artifactPath);
        rmdir($directory);
    }

    $restore();
});

it('deletes the test clock even when the recording fails', function () {
    // Deleting a clock takes its customers and subscriptions with it. A recorder
    // that leaked clocks would fill someone's account with half-built customers.
    [$stripe, $fake, $restore] = scriptedStripe(recordingScript([]));

    try {
        (new Recorder($stripe, eventTimeoutSeconds: 0))->record(
            twoStepScenario(['invoice.payment_failed']),
            CarbonImmutable::parse('2026-01-01T00:00:00Z')
        );
    } catch (IncompleteRecording) {
        // expected
    }

    expect($fake->calls)->toContain('DELETE /v1/test_helpers/test_clocks/clock_1');

    $restore();
});

it('puts the customer on the clock so its billing can be fast-forwarded', function () {
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    $restore();
})->throwsNoExceptions();

it('gives two different subscriptions two different placeholders', function () {
    // A normalizer that restarted at each step handed {{sub_1}} to both a
    // customer's first subscription and the one they took out after cancelling.
    // Replay then treated the second as the first, Cashier's duplicate check
    // matched, and the resubscription was silently skipped — while every
    // assertion still passed.
    $script = recordingScript([]);
    $script['GET /v1/events'] = ['object' => 'list', 'data' => [
        ['id' => 'evt_2', 'object' => 'event', 'type' => 'customer.subscription.created', 'created' => 1767225600,
            'data' => ['object' => ['id' => 'sub_second', 'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_recorded']]],
        ['id' => 'evt_1', 'object' => 'event', 'type' => 'customer.subscription.created', 'created' => 1767225500,
            'data' => ['object' => ['id' => 'sub_first', 'object' => 'subscription', 'status' => 'trialing', 'customer' => 'cus_recorded']]],
    ]];

    [$stripe, , $restore] = scriptedStripe($script);

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    $ids = [];

    foreach ($fixture->steps as $step) {
        foreach ($step->events as $event) {
            $ids[] = $event['data']['object']['id'];
        }
    }

    expect(array_unique($ids))->toHaveCount(2)
        ->and($ids)->toContain('{{sub_1}}')
        ->and($ids)->toContain('{{sub_2}}');

    $restore();
});

it('quarantines a recording whose allowlisted text cannot be read back as a fixture', function () {
    // Stripe can put a literal {{...}} into a string field the allowlist keeps.
    // The fixture refuses it, and the clock is already deleted by then, so the
    // captured events are the only record of what happened.
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cd-quarantine-'.uniqid();

    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', '{{bad}}'),
    ]));

    try {
        (new Recorder($stripe, quarantineDirectory: $directory, eventTimeoutSeconds: 0))->record(
            twoStepScenario(['customer.subscription.created']),
            CarbonImmutable::parse('2026-01-01T00:00:00Z')
        );
        $this->fail('Expected the recording to be refused.');
    } catch (InvalidFixture $e) {
        $artifacts = glob($directory.DIRECTORY_SEPARATOR.'*.quarantine.json') ?: [];

        expect($e->getMessage())->toContain('{{bad}}')
            ->and($e->getMessage())->toContain('No fixture was written')
            ->and($artifacts)->toHaveCount(1)
            ->and($e->getMessage())->toContain($artifacts[0])
            ->and(json_decode((string) file_get_contents($artifacts[0]), true)['captured_events'])->toHaveCount(1);

        array_map(unlink(...), $artifacts);
        rmdir($directory);
    } finally {
        $restore();
    }
});
