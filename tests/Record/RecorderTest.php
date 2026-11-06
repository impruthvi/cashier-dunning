<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Record\RecordingAttempt;
use Impruthvi\CashierDunning\Record\Refusal;

/** @param list<string> $required */
function attemptFor(array $required): RecordingAttempt
{
    return new RecordingAttempt(twoStepScenario($required), CarbonImmutable::parse('2026-01-01T00:00:00Z'));
}

it('records a scenario into a fixture', function () {
    [$stripe, $fake, $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
        subscriptionEvent('customer.subscription.updated', 'past_due'),
    ]));

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record($attempt = attemptFor(['customer.subscription.created']));
    $captured = $attempt->captured;

    expect($fixture->scenario)->toBe('test-scenario')
        ->and($fixture->steps)->toHaveCount(2)
        ->and($fixture->eventTypes())->toContain('customer.subscription.created')
        ->and($fake->calls)->toContain('POST /v1/test_helpers/test_clocks');

    // The fixture keeps only what the scenario declared, normalised. The raw
    // capture keeps everything Stripe sent, as it sent it.
    expect(array_column($captured, 'type'))->toContain('customer.subscription.updated')
        ->and($fixture->eventTypes())->not->toContain('customer.subscription.updated')
        ->and($captured[0]['id'])->toStartWith('evt_');

    $restore();
});

it('normalises ids so two recordings of the same journey match', function () {
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    $fixture = (new Recorder($stripe, eventTimeoutSeconds: 0))
        ->record(attemptFor(['customer.subscription.created']));

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
        ->record(attemptFor(['customer.subscription.created']));

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
        ->record(attemptFor(['customer.subscription.created']));

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
        (new Recorder($stripe, eventTimeoutSeconds: 0))->record($attempt = attemptFor(['customer.subscription.created', 'invoice.payment_failed']));
        $this->fail('Expected the recording to be refused.');
    } catch (Refusal $e) {
        expect($e->outcome)->toBe('is incomplete')
            ->and($e->reason)->toBe('the recording ended before every required event arrived')
            ->and($attempt->captured)->toHaveCount(1);
    }

    $restore();
});

it('deletes the test clock even when the recording fails', function () {
    // Deleting a clock takes its customers and subscriptions with it. A recorder
    // that leaked clocks would fill someone's account with half-built customers.
    [$stripe, $fake, $restore] = scriptedStripe(recordingScript([]));

    try {
        (new Recorder($stripe, eventTimeoutSeconds: 0))->record(attemptFor(['invoice.payment_failed']));
    } catch (Refusal) {
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
        ->record(attemptFor(['customer.subscription.created']));

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
        ->record(attemptFor(['customer.subscription.created']));

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

it('refuses a recording whose allowlisted text cannot be read back as a fixture', function () {
    // Stripe can put a literal {{...}} into a string field the allowlist keeps.
    // The fixture refuses it, and the clock is already deleted by then, so the
    // attempt has to keep the captured events: they are the only record of
    // what happened. Quarantining them is RecordAndVerify's job.
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', '{{bad}}'),
    ]));

    try {
        (new Recorder($stripe, eventTimeoutSeconds: 0))->record($attempt = attemptFor(['customer.subscription.created']));
        $this->fail('Expected the recording to be refused.');
    } catch (Refusal $e) {
        expect($e->getMessage())->toContain('{{bad}}')
            ->and($e->outcome)->toBe('could not be turned into a fixture')
            ->and($e->getPrevious())->toBeInstanceOf(InvalidFixture::class)
            ->and($attempt->captured)->toHaveCount(1);
    } finally {
        $restore();
    }
});

it('starts every recording with nothing captured', function () {
    // A recorder that kept its capture between calls would let the first
    // recording's events satisfy the second one's manifest, and write a short
    // recording as though it were complete.
    $script = recordingScript([subscriptionEvent('customer.subscription.created', 'trialing')]);
    $script['POST /v1/test_helpers/test_clocks']['__headers'] = ['Request-Id' => 'req_first'];
    [$stripe, $fake, $restore] = scriptedStripe($script);

    $recorder = new Recorder($stripe, eventTimeoutSeconds: 0);
    $recorder->record(attemptFor(['customer.subscription.created']));

    $fake->script['POST /v1/test_helpers/test_clocks']['__headers'] = ['Request-Id' => 'req_second'];
    $fake->script['GET /v1/events'] = ['object' => 'list', 'data' => []];

    try {
        $recorder->record($attempt = attemptFor(['customer.subscription.created']));
        $this->fail('Expected the second recording to be refused.');
    } catch (Refusal $e) {
        expect($e->outcome)->toBe('is incomplete')
            ->and($attempt->captured)->toBe([])
            ->and($attempt->requestIds)->toBe(['req_second']);
    } finally {
        $restore();
    }
});
