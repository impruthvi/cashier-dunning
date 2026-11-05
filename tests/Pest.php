<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Manifest;
use Impruthvi\CashierDunning\Scenarios\Action;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Scenarios\ScenarioStep;
use Impruthvi\CashierDunning\Tests\Support\User;
use Impruthvi\CashierDunning\Tests\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

uses(TestCase::class)->in(__DIR__);

/**
 * Register the README's factory-per-call setup. Creating a billable here and
 * capturing it would hide collisions between consecutive replay invocations.
 */
function billableUser(): void
{
    CashierDunning::createBillableUsing(fn () => User::factory()->create([
        'stripe_id' => 'cus_replay1',
    ]));
}

/**
 * The dunning policy the shipped fixtures were recorded against: access
 * survives the retry window and ends when Stripe gives up.
 *
 * Cashier's subscription row cannot express this on its own — it reads
 * `past_due` both before and after the final retry — which is the reason the
 * resolver contract exists.
 */
function registerDunningPolicy(): void
{
    CashierDunning::resolveEntitlementsUsing(
        function (User $billable): array {
            $billable->refresh();
            $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

            $entitled = $subscription !== null
                && ! $billable->dunning_exhausted
                && $subscription->ends_at === null
                && in_array($subscription->stripe_status, ['trialing', 'active', 'past_due'], true);

            return ['teams' => $entitled, 'api' => $entitled, 'projects' => $entitled ? 10 : 0];
        }
    );
}

/**
 * A plan-aware policy, for the downgrade scenario.
 *
 * Keyed on the placeholder ids a replay resolves to rather than on Stripe
 * lookup keys: a fixture stores `{{price_1}}`, and by the time the application
 * sees it, it is `price_replay1`. Placeholders are assigned in first-seen
 * order, so the plan subscribed to first is `price_replay1` and the one
 * downgraded to is `price_replay2` — which is exactly the mapping a real
 * application keeps between its own Stripe price ids and its plans.
 */
function registerPlanLimits(): void
{
    CashierDunning::resolveEntitlementsUsing(
        function (User $billable): array {
            $billable->refresh();
            $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

            $entitled = $subscription !== null
                && $subscription->ends_at === null
                && in_array($subscription->stripe_status, ['trialing', 'active', 'past_due'], true);

            // Placeholders are assigned in first-seen order, so the plan
            // subscribed to first is price_replay1 and the one downgraded to is
            // price_replay2 — the same mapping a real application keeps between
            // its Stripe price ids and its plans.
            $limits = ['price_replay1' => 25, 'price_replay2' => 3];

            return [
                'projects' => $entitled ? ($limits[$subscription?->stripe_price ?? ''] ?? 0) : 0,
                'api' => $entitled,
            ];
        }
    );
}

/**
 * The shipped dunning fixture, altered, as the application's only recording.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $alter
 * @return string the fixture directory to remove with removeFixtureDirectory()
 */
function applicationFixtureFrom(string $scenario, callable $alter): string
{
    $root = sys_get_temp_dir().'/cashier-dunning-fixtures-'.uniqid();
    mkdir($root.'/stripe', 0755, true);

    $data = json_decode((string) file_get_contents(__DIR__.'/../fixtures/stripe/trial-dunning-cancel-reactivate.json'), true);
    file_put_contents($root.'/stripe/'.$scenario.'.json', json_encode($alter($data)));

    config()->set('cashier-dunning.fixtures.path', $root);

    return $root;
}

function removeFixtureDirectory(string $root): void
{
    array_map(unlink(...), glob($root.'/stripe/*.json') ?: []);
    rmdir($root.'/stripe');
    rmdir($root);
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function withLeakedEventField(array $fixture): array
{
    $fixture['steps'][0]['events'][0]['data']['object']['customer_email'] = 'someone@example.com';

    return $fixture;
}

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

                // A scripted failure: ['__status' => 429, '__body' => [...]].
                if (isset($responses['__status'])) {
                    return [json_encode($responses['__body'] ?? []), $responses['__status'], []];
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

/** A subscribe-then-fail scenario, small enough to script by hand. */
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
