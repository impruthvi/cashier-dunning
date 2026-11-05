<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\ClockAdvanceFailed;
use Impruthvi\CashierDunning\Record\Exceptions\RecordingRefused;
use Impruthvi\CashierDunning\Record\RecordAndVerify;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Exception\RateLimitException;

/*
 * Recording, verifying and writing are one decision: a fixture reaches disk only
 * once it has replayed clean, and everything else that ends a recording leaves a
 * quarantine behind. These tests cross that one interface, with Stripe scripted
 * and the real replay runner behind it.
 */

beforeEach(function () {
    CashierDunning::flush();
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cd-record-'.uniqid();
});

afterEach(function () {
    CashierDunning::flush();

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

    expect($path)->toBe($this->root.DIRECTORY_SEPARATOR.'stripe'.DIRECTORY_SEPARATOR.'test-scenario.json')
        ->and($fixture->steps[0]->entitlements)->toEqual(['teams' => true, 'api' => true, 'projects' => 10])
        ->and(file_get_contents($path))->toBe(FixtureFile::encode($fixture))
        ->and(FixtureFile::read($path)->scenario)->toBe('test-scenario')
        ->and(quarantinesIn($this->root))->toBe([]);
});

it('quarantines a recording that ended before every required event arrived', function () {
    // A short recording replays green forever, and the drift job, comparing it
    // against another short recording, agrees.
    $e = refusedRecording($this->root, recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]), ['customer.subscription.created', 'invoice.payment_failed']);

    $artifact = onlyQuarantineIn($this->root);

    expect($e->missing)->toBe(['invoice.payment_failed'])
        ->and($e->getMessage())->toContain('Never arrived: invoice.payment_failed')
        ->and($e->getMessage())->toContain('No fixture was written')
        ->and($e->getMessage())->toContain((string) $e->artifactPath)
        ->and($artifact['missing_events'])->toBe(['invoice.payment_failed'])
        ->and($artifact['captured_events'])->toHaveCount(1);

    noFixtureIn($this->root);
});

it('quarantines a rate-limited recording under its own reason', function () {
    // Stripe allows twenty invoices per subscription per day. Knowing that is
    // what tells you to wait rather than to debug.
    $script = recordingScript([]);
    $script['POST /v1/subscriptions'] = ['__status' => 429, '__body' => ['error' => [
        'type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => '20 invoices per day reached',
    ]]];

    $e = refusedRecording($this->root, $script);

    expect($e->getMessage())->toContain('Stripe rate limited the recording: 20 invoices per day reached')
        ->and($e->getPrevious())->toBeInstanceOf(RateLimitException::class)
        ->and(onlyQuarantineIn($this->root)['reason'])->toContain('Stripe rate limited the recording');

    noFixtureIn($this->root);
});

it('quarantines a recording whose test clock never finished advancing', function () {
    $script = recordingScript([subscriptionEvent('customer.subscription.created', 'trialing')]);
    $script['GET /v1/test_helpers/test_clocks/*'] = ['id' => 'clock_1', 'object' => 'test_clock', 'status' => 'internal_failure'];

    $e = refusedRecording($this->root, $script);

    expect($e->getMessage())->toContain('the test clock did not finish advancing: Stripe reported an internal failure')
        ->and($e->getPrevious())->toBeInstanceOf(ClockAdvanceFailed::class)
        ->and(onlyQuarantineIn($this->root)['captured_events'])->toHaveCount(1);

    noFixtureIn($this->root);
});

it('quarantines a recording whose allowlisted text cannot be read back as a fixture', function () {
    // Stripe can put a literal {{...}} into a string field the allowlist keeps.
    // The fixture refuses it, and the clock is already deleted by then, so the
    // captured events are the only record of what happened.
    $e = refusedRecording($this->root, recordingScript([
        subscriptionEvent('customer.subscription.created', '{{bad}}'),
    ]));

    $artifacts = quarantinesIn($this->root);

    expect($e->getMessage())->toContain('[{{bad}}] is not a placeholder')
        ->and($e->getMessage())->toContain('No fixture was written')
        ->and($e->getPrevious())->toBeInstanceOf(InvalidFixture::class)
        ->and($artifacts)->toHaveCount(1)
        ->and($e->getMessage())->toContain($artifacts[0])
        ->and(onlyQuarantineIn($this->root)['captured_events'])->toHaveCount(1);

    noFixtureIn($this->root);
});

it('quarantines a recording the application could not replay, with the replay and the fixture', function () {
    // The capture cost real quota and the clock is gone. A replay that fails
    // here is the case where the diagnosis lives in the replay, not the capture.
    billableUser();
    Event::listen(WebhookReceived::class, function (): void {
        throw new RuntimeException('the application fell over');
    });

    $e = refusedRecording($this->root, recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    $artifacts = quarantinesIn($this->root);
    $artifact = onlyQuarantineIn($this->root);

    expect($e->getMessage())->toContain('did not replay clean')
        ->and($e->getMessage())->toContain('No fixture was written')
        ->and($e->report?->passed())->toBeFalse()
        ->and($artifact['replay']['passed'])->toBeFalse()
        ->and($artifact['fixture']['scenario'])->toBe('test-scenario')
        ->and($artifact['captured_events'])->toHaveCount(1);

    // Carrying a whole fixture must not make the quarantine loadable as one.
    expect(fn () => FixtureFile::read($artifacts[0]))->toThrow(InvalidFixture::class, 'format_version');

    noFixtureIn($this->root);
});

it('quarantines a recording the simulation environment refused to replay', function () {
    // No billable is registered, so the replay cannot start at all.
    $e = refusedRecording($this->root, recordingScript([
        subscriptionEvent('customer.subscription.created', 'trialing'),
    ]));

    expect($e->getMessage())->toContain('did not replay clean')
        ->and($e->getPrevious())->toBeInstanceOf(SimulationFailed::class)
        ->and($e->report)->toBeNull()
        ->and(onlyQuarantineIn($this->root)['fixture']['scenario'])->toBe('test-scenario');

    noFixtureIn($this->root);
});
