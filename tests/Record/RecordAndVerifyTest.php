<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\ClockAdvanceFailed;
use Impruthvi\CashierDunning\Record\Exceptions\RecordingRefused;
use Impruthvi\CashierDunning\Record\RecordAndVerify;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Impruthvi\CashierDunning\Support\AtomicFile;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Exception\RateLimitException;
use Stripe\Util\CaseInsensitiveArray;

/*
 * Recording, verifying and writing are one decision: a fixture reaches disk only
 * once it has replayed clean. Every stage that can refuse a recording hands it
 * to the quarantine with what was captured up to that point, so each refusal
 * test below checks the artifact's request ids and captured events. What the
 * quarantine itself writes and says is QuarantineTest's business. These tests
 * cross the one interface, with Stripe scripted and the real replay runner
 * behind it.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cd-record-'.uniqid();
});

afterEach(function () {
    foreach (['stripe', 'quarantine'] as $directory) {
        array_map(unlink(...), glob($this->root.DIRECTORY_SEPARATOR.$directory.DIRECTORY_SEPARATOR.'*.json') ?: []);
        @rmdir($this->root.DIRECTORY_SEPARATOR.$directory);
    }

    @rmdir($this->root);
});

/**
 * Records against a scripted Stripe and returns the refusal it ended in.
 *
 * @param  array<string, mixed>  $script
 * @param  list<string>  $required
 */
function refusedRecording(string $root, array $script, array $required = ['customer.subscription.created']): RecordingRefused
{
    [$stripe, , $restore] = scriptedStripe($script);

    try {
        (new RecordAndVerify(new Recorder($stripe, eventTimeoutSeconds: 0), app(ReplayRunner::class), $root))
            ->record(twoStepScenario($required), CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    } catch (RecordingRefused $e) {
        return $e;
    } finally {
        $restore();
    }

    throw new RuntimeException('Expected the recording to be refused.');
}

/**
 * A recording script whose responses each carry a request id named for the call.
 *
 * @param  list<array<string, mixed>>  $events
 * @return array<string, mixed>
 */
function taggedRecordingScript(array $events): array
{
    $script = recordingScript($events);

    foreach ([
        'POST /v1/test_helpers/test_clocks' => 'req_clock_create',
        'POST /v1/customers' => 'req_customer',
        'GET /v1/prices' => 'req_catalog',
        'POST /v1/subscriptions' => 'req_subscribe',
        'GET /v1/events' => 'req_poll',
        'GET /v1/subscriptions/*' => 'req_subscription_state',
        'POST /v1/test_helpers/test_clocks/*/advance' => 'req_clock_advance',
        'GET /v1/test_helpers/test_clocks/*' => 'req_clock_status',
        'DELETE /v1/test_helpers/test_clocks/*' => 'req_clock_delete',
    ] as $endpoint => $requestId) {
        $script[$endpoint]['__headers'] = ['Request-Id' => $requestId];
    }

    // Stripe's header casing is not guaranteed, and the SDK may hand headers
    // over as a plain array or as its own case-insensitive one.
    $script['POST /v1/customers']['__headers'] = ['request-id' => 'req_customer'];
    $script['GET /v1/test_helpers/test_clocks/*']['__headers'] = new CaseInsensitiveArray(['Request-Id' => 'req_clock_status']);

    return $script;
}

/** Every request id a two-step recording collects when it runs to the end. */
const FULL_RECORDING_REQUEST_IDS = [
    'req_clock_create', 'req_customer', 'req_catalog', 'req_subscribe',
    'req_poll', 'req_subscription_state', 'req_clock_advance',
    'req_clock_status', 'req_poll', 'req_subscription_state', 'req_clock_delete',
];

/** @return list<string> */
function quarantinesIn(string $root): array
{
    return glob($root.DIRECTORY_SEPARATOR.'quarantine'.DIRECTORY_SEPARATOR.'*.quarantine.json') ?: [];
}

/** @return array<string, mixed> */
function onlyQuarantineIn(string $root): array
{
    $artifacts = quarantinesIn($root);

    expect($artifacts)->toHaveCount(1);

    return json_decode((string) file_get_contents($artifacts[0]), true);
}

function noFixtureIn(string $root): void
{
    expect(glob($root.DIRECTORY_SEPARATOR.'stripe'.DIRECTORY_SEPARATOR.'*.json') ?: [])->toBe([]);
}

/**
 * The file at $path is exactly $fixture, and loads back as the scenario
 * twoStepScenario() records. The literal is the point: comparing against
 * $fixture->scenario would only check the encoding against itself.
 */
function expectSavedFixture(string $path, Fixture $fixture): void
{
    expect(file_get_contents($path))->toBe(FixtureFile::encode($fixture))
        ->and(FixtureFile::read($path)->scenario)->toBe('test-scenario');
}

it('writes a recording that replays clean, with the entitlements the application granted', function () {
    billableUser();
    registerDunningPolicy();

    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    try {
        ['fixture' => $fixture, 'path' => $path] = (new RecordAndVerify(
            new Recorder($stripe, eventTimeoutSeconds: 0),
            app(ReplayRunner::class),
            $this->root,
        ))->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    } finally {
        $restore();
    }

    expect($path)->toBe(nativePath($this->root, 'stripe', 'test-scenario.json'))
        ->and($fixture->steps[0]->entitlements)->toEqual(['teams' => true, 'api' => true, 'projects' => 10])
        ->and(quarantinesIn($this->root))->toBe([]);

    expectSavedFixture($path, $fixture);
});

it('quarantines a rate-limited recording under its own reason', function (string $endpoint, array $requestIds) {
    // Stripe allows twenty invoices per subscription per day. Knowing that is
    // what tells you to wait rather than to debug.
    $script = taggedRecordingScript([]);
    $script[$endpoint] = ['__status' => 429, '__headers' => ['Request-Id' => 'req_rate_limited'], '__body' => ['error' => [
        'type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => '20 invoices per day reached',
    ]]];

    $e = refusedRecording($this->root, $script);
    $artifact = onlyQuarantineIn($this->root);

    expect($e->getMessage())->toContain('Stripe rate limited the recording: 20 invoices per day reached')
        ->and($e->getPrevious())->toBeInstanceOf(RateLimitException::class)
        ->and($e->artifactPath)->toBe(quarantinesIn($this->root)[0])
        ->and($artifact['request_ids'])->toBe($requestIds)
        ->and($artifact['captured_events'])->toBe([]);

    noFixtureIn($this->root);
})->with([
    'creating the clock' => ['POST /v1/test_helpers/test_clocks', ['req_rate_limited']],
    'subscribing' => ['POST /v1/subscriptions', ['req_clock_create', 'req_customer', 'req_catalog', 'req_rate_limited', 'req_clock_delete']],
]);

it('quarantines a recording whose test clock never finished advancing', function () {
    $created = subscriptionEvent('customer.subscription.created', 'trialing');
    $script = taggedRecordingScript([$created]);
    $script['GET /v1/test_helpers/test_clocks/*'] = [
        'id' => 'clock_1', 'object' => 'test_clock', 'status' => 'internal_failure',
        '__headers' => ['Request-Id' => 'req_clock_failed'],
    ];

    $e = refusedRecording($this->root, $script);
    $artifact = onlyQuarantineIn($this->root);

    expect($e->getMessage())->toContain('the test clock did not finish advancing: Stripe reported an internal failure')
        ->and($e->getPrevious())->toBeInstanceOf(ClockAdvanceFailed::class)
        ->and($artifact['request_ids'])->toBe([
            'req_clock_create', 'req_customer', 'req_catalog', 'req_subscribe',
            'req_poll', 'req_subscription_state', 'req_clock_advance', 'req_clock_failed', 'req_clock_delete',
        ])
        ->and(array_column($artifact['captured_events'], 'id'))->toBe([$created['id']]);

    noFixtureIn($this->root);
});

it('quarantines a recording that ended before every required event arrived', function () {
    // A short recording replays green forever, and the drift job, comparing it
    // against another short recording, agrees.
    $created = subscriptionEvent('customer.subscription.created', 'trialing');

    $e = refusedRecording($this->root, taggedRecordingScript([$created]), ['customer.subscription.created', 'invoice.payment_failed']);
    $artifact = onlyQuarantineIn($this->root);

    expect($e->getMessage())->toContain('is incomplete', 'Never arrived: invoice.payment_failed')
        ->and($e->report)->toBeNull()
        ->and($artifact['missing_events'])->toBe(['invoice.payment_failed'])
        ->and($artifact['request_ids'])->toBe(FULL_RECORDING_REQUEST_IDS)
        ->and(array_column($artifact['captured_events'], 'id'))->toBe([$created['id']]);

    noFixtureIn($this->root);
});

it('quarantines a recording that cannot be read back as a fixture, before replaying it', function (Closure $alter, string $complaint) {
    // The clock is already deleted by now, so the captured events are the only
    // record of what happened. Saved, the fixture would be refused on its
    // first load, after the Stripe quota was spent.
    $script = taggedRecordingScript([subscriptionEvent('customer.subscription.created', 'trialing')]);
    $script = $alter($script);

    $e = refusedRecording($this->root, $script);
    $artifact = onlyQuarantineIn($this->root);

    expect($e->getMessage())->toContain('could not be turned into a fixture', $complaint)
        ->and($e->getPrevious())->toBeInstanceOf(InvalidFixture::class)
        ->and($e->report)->toBeNull()
        ->and($artifact)->not->toHaveKeys(['replay', 'fixture'])
        ->and($artifact['request_ids'])->toBe(FULL_RECORDING_REQUEST_IDS)
        ->and(array_column($artifact['captured_events'], 'type'))->toBe(['customer.subscription.created']);

    noFixtureIn($this->root);
})->with([
    // Stripe can put a literal {{...}} into a string field the allowlist keeps.
    'a placeholder in recorded text' => [function (array $script): array {
        $script['GET /v1/events']['data'] = [subscriptionEvent('customer.subscription.created', '{{bad}}')];

        return $script;
    }, '[{{bad}}] is not a placeholder'],
    // The normalizer leaves an id of a kind it does not know unchanged, so the
    // subscription-state exchange is recorded with a literal path segment.
    'a literal id in a match path' => [function (array $script): array {
        $script['POST /v1/subscriptions']['id'] = 'zzz_1AbC';

        return $script;
    }, '[zzz_1AbC]'],
]);

it('quarantines a recording that does not replay clean, with the fixture it would have written', function (bool $replayStarts) {
    // The capture cost real quota and the clock is gone. A replay that fails
    // here is the case where the diagnosis lives in the replay, not the capture.
    if ($replayStarts) {
        billableUser();
        Event::listen(WebhookReceived::class, function (): void {
            throw new RuntimeException('the application fell over');
        });
    }

    // The scenario never asked for the update, so the fixture drops it. The
    // quarantine keeps what Stripe actually sent, real ids included.
    $created = subscriptionEvent('customer.subscription.created', 'trialing');
    $updated = subscriptionEvent('customer.subscription.updated', 'active');

    $e = refusedRecording($this->root, taggedRecordingScript([$created, $updated]));
    $artifact = onlyQuarantineIn($this->root);

    expect($e->getMessage())->toContain('did not replay clean')
        ->and($artifact['fixture']['scenario'])->toBe('test-scenario')
        ->and($artifact['undeclared_events'])->toBe(['customer.subscription.updated'])
        ->and($artifact['request_ids'])->toBe(FULL_RECORDING_REQUEST_IDS)
        ->and(array_column($artifact['captured_events'], 'id'))->toBe([$created['id'], $updated['id']]);

    if ($replayStarts) {
        expect($e->report?->passed())->toBeFalse()
            ->and($artifact['replay']['passed'])->toBeFalse();
    } else {
        // No billable is registered, so the replay cannot start at all.
        expect($e->getPrevious())->toBeInstanceOf(SimulationFailed::class)
            ->and($e->report)->toBeNull()
            ->and($artifact)->not->toHaveKey('replay');
    }

    noFixtureIn($this->root);
})->with([
    'the application failed it' => [true],
    'the simulation refused to start it' => [false],
]);

it('quarantines a verified fixture when its fixture directory is blocked', function () {
    billableUser();
    registerDunningPolicy();
    $unblock = blockDirectories($this->root.'/stripe');
    $created = subscriptionEvent('customer.subscription.created', 'trialing');

    try {
        $e = refusedRecording($this->root, taggedRecordingScript([$created]));
        $artifact = onlyQuarantineIn($this->root);

        expect($e->getMessage())->toContain('could not be saved', 'Cannot create directory', 'Any previous fixture was left untouched')
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($e->report?->passed())->toBeTrue()
            ->and($artifact['reason'])->toBe($e->getPrevious()?->getMessage())
            ->and($artifact['replay']['passed'])->toBeTrue()
            ->and($artifact['fixture']['steps'][0]['entitlements'])->toEqual(['teams' => true, 'api' => true, 'projects' => 10])
            ->and($artifact['request_ids'])->toBe(FULL_RECORDING_REQUEST_IDS)
            ->and(array_column($artifact['captured_events'], 'id'))->toBe([$created['id']])
            ->and(file_get_contents($this->root.'/stripe'))->toBe('blocked');
    } finally {
        $unblock();
    }
});

it('leaves an existing fixture untouched when replacement cannot be written', function () {
    billableUser();
    mkdir($this->root.'/stripe', 0755, true);
    $path = nativePath($this->root, 'stripe', 'test-scenario.json');
    $original = shippedFixture();
    file_put_contents($path, $original);
    chmod($this->root.'/stripe', 0555);

    try {
        // chmod and is_writable do not reliably describe directory creation
        // permissions on Windows. Probe the operation this test must prevent;
        // privileged users can also bypass permission bits on Unix.
        $probePath = $this->root.'/stripe/.permission-probe';
        $probe = @fopen($probePath, 'xb');

        if ($probe !== false) {
            fclose($probe);
            unlink($probePath);
            test()->markTestSkipped('Requires a directory that denies file creation after chmod.');
        }

        $e = refusedRecording($this->root, recordingScript([
            subscriptionEvent('customer.subscription.created', 'trialing'),
        ]));

        expect($e->getMessage())->toContain('Cannot create temporary file')
            ->and(file_get_contents($path))->toBe($original)
            ->and(onlyQuarantineIn($this->root)['replay']['passed'])->toBeTrue()
            ->and(glob($this->root.'/stripe/'.AtomicFile::TEMPORARY_PREFIX.'*'))->toBe([]);
    } finally {
        chmod($this->root.'/stripe', 0755);
    }
});

it('cleans up the temporary file when the final rename fails', function () {
    billableUser();
    $destination = nativePath($this->root, 'stripe', 'test-scenario.json');
    mkdir($destination, 0755, true);

    try {
        $e = refusedRecording($this->root, recordingScript([
            subscriptionEvent('customer.subscription.created', 'trialing'),
        ]));

        expect($e->getMessage())->toContain('Cannot replace file ['.$destination.']')
            ->and(is_dir($destination))->toBeTrue()
            ->and(glob($this->root.'/stripe/'.AtomicFile::TEMPORARY_PREFIX.'*'))->toBe([])
            ->and(onlyQuarantineIn($this->root)['replay']['passed'])->toBeTrue();
    } finally {
        rmdir($destination);
    }
});

it('replaces a fixture without modifying the previous file in place', function () {
    billableUser();
    mkdir($this->root.'/stripe', 0755, true);
    $path = nativePath($this->root, 'stripe', 'test-scenario.json');
    $previous = $this->root.'/previous.json';
    $original = shippedFixture();
    file_put_contents($path, $original);
    // A reader holding the previous file must still see its complete contents.
    link($path, $previous);
    [$stripe, , $restore] = scriptedStripe(recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    try {
        $result = (new RecordAndVerify(new Recorder($stripe, eventTimeoutSeconds: 0), app(ReplayRunner::class), $this->root))
            ->record(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

        expectSavedFixture($path, $result['fixture']);

        expect($result['path'])->toBe($path)
            ->and(file_get_contents($previous))->toBe($original)
            ->and(glob($this->root.'/stripe/'.AtomicFile::TEMPORARY_PREFIX.'*'))->toBe([]);
    } finally {
        $restore();
        unlink($previous);
    }
});
