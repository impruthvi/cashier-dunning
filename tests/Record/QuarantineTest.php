<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\Exceptions\IncompleteRecording;
use Impruthvi\CashierDunning\Record\QuarantineArtifact;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;

function shortRecording(): QuarantineArtifact
{
    return new QuarantineArtifact(
        scenario: (new ScenarioRepository)->find('trial-dunning-cancel-reactivate'),
        captured: [
            ['id' => 'evt_1', 'type' => 'customer.subscription.created', 'created' => 1767225600],
            ['id' => 'evt_2', 'type' => 'invoice.created', 'created' => 1767225700],
            ['id' => 'evt_3', 'type' => 'radar.early_fraud_warning.created', 'created' => 1767225800],
        ],
        requestIds: ['req_abc123'],
        reason: 'polling stopped after 90 seconds with required events outstanding',
        startedAt: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
        failedAt: CarbonImmutable::parse('2026-01-01T00:01:30Z'),
    );
}

it('records what arrived and what did not', function () {
    // Recording is rate limited, slow and only reproducible against a live
    // account. "That didn't work, try again" costs a day's quota and explains
    // nothing.
    $artifact = shortRecording()->toArray();

    expect($artifact['missing_events'])->toContain('invoice.payment_failed')
        ->and($artifact['missing_events'])->toContain('customer.subscription.deleted')
        ->and($artifact['captured_events'])->toHaveCount(3)
        ->and($artifact['elapsed_seconds'])->toBe(90);
});

it('keeps the request ids Stripe support will ask for', function () {
    expect(shortRecording()->toArray()['request_ids'])->toBe(['req_abc123']);
});

it('flags events the scenario never declared', function () {
    // Not a failure, but the manifest may need to learn about them — which is
    // how a manifest stays honest as Stripe changes.
    expect(shortRecording()->toArray()['undeclared_events'])
        ->toBe(['radar.early_fraud_warning.created']);
});

it('cannot be mistaken for a fixture by the loader', function () {
    // Quarantine has to survive someone widening a glob later. The artifact has
    // no format_version, so the loader refuses it by construction rather than
    // by living in a different directory.
    FixtureFile::decode(shortRecording()->encode());
})->throws(InvalidFixture::class, 'format_version');

it('writes itself where a failed recording can be found again', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cashier-dunning-quarantine-'.uniqid();

    $path = shortRecording()->write($directory);

    expect(file_exists($path))->toBeTrue()
        ->and(basename($path))->toStartWith('trial-dunning-cancel-reactivate-')
        ->and(basename($path))->toEndWith('.quarantine.json')
        ->and(json_decode((string) file_get_contents($path), true)['quarantined'])->toBeTrue();

    unlink($path);
    rmdir($directory);
});

it('names the missing events in the exception a person actually reads', function () {
    $exception = IncompleteRecording::missingEvents(
        'trial-dunning-cancel-reactivate',
        ['invoice.payment_failed'],
        ['customer.subscription.created'],
        '/tmp/quarantine/x.json',
    );

    expect($exception->getMessage())->toContain('No fixture was written')
        ->and($exception->getMessage())->toContain('Never arrived: invoice.payment_failed')
        ->and($exception->getMessage())->toContain('/tmp/quarantine/x.json');
});

it('says plainly when nothing was captured at all', function () {
    expect(IncompleteRecording::clockFailed('demo', [], 'clock stuck in advancing')->getMessage())
        ->toContain('Nothing was captured at all.')
        ->and(IncompleteRecording::clockFailed('demo', [], 'clock stuck in advancing')->getMessage())
        ->toContain('the test clock did not finish advancing');
});

it('reports a rate limit as its own reason rather than a generic failure', function () {
    // Stripe allows twenty invoices per subscription per day. Knowing that is
    // what tells you to wait rather than to debug.
    expect(IncompleteRecording::rateLimited('demo', ['a'], '20 invoices per day reached')->getMessage())
        ->toContain('Stripe rate limited the recording: 20 invoices per day reached');
});
