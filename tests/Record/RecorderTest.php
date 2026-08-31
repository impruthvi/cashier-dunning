<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Manifest;
use Impruthvi\CashierDunning\Record\Exceptions\IncompleteRecording;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Scenarios\Action;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Scenarios\ScenarioStep;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Scripts Stripe. The recorder is exercised through the same transport seam
 * replay uses, so the whole record path is testable with no account, no key and
 * no network — which is also what keeps the test suite runnable on a fork PR.
 */
function scriptedStripe(array $script): array
{
    $client = new class($script) implements ClientInterface
    {
        /** @var list<string> */
        public array $calls = [];

        public function __construct(public array $script) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
            $this->calls[] = strtoupper((string) $method).' '.$path;

            foreach ($this->script as $pattern => $responses) {
                [$wanted, $wantedPath] = explode(' ', $pattern, 2);

                if (strtoupper((string) $method) !== $wanted || ! fnmatch($wantedPath, $path)) {
                    continue;
                }

                return [json_encode($responses), 200, []];
            }

            return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "unscripted {$method} {$path}"]]), 400, []];
        }
    };

    $original = ApiRequestor::httpClient();
    ApiRequestor::setHttpClient($client);

    // The fake itself is returned rather than its call list: a reference does
    // not survive list-destructuring, so handing back the array would give the
    // test a copy that stops updating the moment it is assigned.
    return [new StripeClient('sk_test_scripted'), $client, fn () => ApiRequestor::setHttpClient($original)];
}

function subscriptionEvent(string $type, string $status): array
{
    return [
        'id' => 'evt_'.bin2hex(random_bytes(4)),
        'object' => 'event',
        'type' => $type,
        'created' => 1767225600,
        'data' => ['object' => [
            'id' => 'sub_recorded',
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_recorded',
            'customer_email' => 'jenny.rosen@example.com',
            'items' => ['data' => [[
                'id' => 'si_1',
                'quantity' => 1,
                'price' => ['id' => 'price_1', 'product' => 'prod_1', 'nickname' => 'Negotiated rate'],
            ]]],
        ]],
    ];
}

function recordingScript(array $events): array
{
    return [
        'POST /v1/test_helpers/test_clocks' => ['id' => 'clock_1', 'object' => 'test_clock', 'status' => 'ready'],
        'POST /v1/test_helpers/test_clocks/*/advance' => ['id' => 'clock_1', 'status' => 'advancing'],
        'GET /v1/test_helpers/test_clocks/*' => ['id' => 'clock_1', 'object' => 'test_clock', 'status' => 'ready'],
        'DELETE /v1/test_helpers/test_clocks/*' => ['id' => 'clock_1', 'deleted' => true],
        'GET /v1/prices' => ['object' => 'list', 'data' => [['id' => 'price_1', 'object' => 'price']]],
        'POST /v1/customers' => ['id' => 'cus_recorded', 'object' => 'customer'],
        'POST /v1/customers/*' => ['id' => 'cus_recorded', 'object' => 'customer'],
        'POST /v1/payment_methods/*/attach' => ['id' => 'pm_1', 'object' => 'payment_method'],
        'POST /v1/subscriptions' => ['id' => 'sub_recorded', 'object' => 'subscription', 'status' => 'trialing'],
        'GET /v1/subscriptions/*' => [
            'id' => 'sub_recorded', 'object' => 'subscription', 'status' => 'past_due',
            'customer' => 'cus_recorded', 'customer_email' => 'jenny.rosen@example.com',
        ],
        'GET /v1/events' => ['object' => 'list', 'data' => array_reverse($events)],
    ];
}

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
