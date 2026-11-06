<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\ClockAdvanceFailed;
use Impruthvi\CashierDunning\Record\Quarantine;
use Impruthvi\CashierDunning\Record\RecordingAttempt;
use Impruthvi\CashierDunning\Record\Refusal;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;
use Impruthvi\CashierDunning\Support\AtomicFile;
use Stripe\Exception\RateLimitException;

/*
 * Quarantine is the one owner of a refused recording: it builds the artifact,
 * decides where it goes, writes it, and composes the refusal a person reads.
 * These tests hand it an attempt and a cause, with no Stripe involved.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cd-quarantine-'.uniqid();
    CarbonImmutable::setTestNow('2026-01-01T00:01:30Z');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    array_map(unlink(...), glob($this->root.'/quarantine/*') ?: []);
    @rmdir($this->root.'/quarantine');
    @rmdir($this->root);
});

/** Three events in, and the dunning events the scenario requires never came. */
function shortAttempt(): RecordingAttempt
{
    $attempt = new RecordingAttempt(
        (new ScenarioRepository)->find('trial-dunning-cancel-reactivate'),
        CarbonImmutable::parse('2026-01-01T00:00:00Z'),
    );

    $attempt->captured = [
        ['id' => 'evt_1', 'type' => 'customer.subscription.created', 'created' => 1767225600],
        ['id' => 'evt_2', 'type' => 'invoice.created', 'created' => 1767225700],
        ['id' => 'evt_3', 'type' => 'radar.early_fraud_warning.created', 'created' => 1767225800],
    ];
    $attempt->requestIds = ['req_abc123'];

    return $attempt;
}

/** @return array<string, mixed> */
function artifactAt(?string $path): array
{
    expect($path)->not->toBeNull()
        ->and(file_exists((string) $path))->toBeTrue();

    return json_decode((string) file_get_contents((string) $path), true);
}

it('records what arrived, what did not, and the request ids Stripe support will ask for', function () {
    // Recording is rate limited, slow and only reproducible against a live
    // account. "That didn't work, try again" costs a day's quota and explains
    // nothing.
    $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::missingEvents());

    $artifact = artifactAt($e->artifactPath);

    expect($artifact['quarantined'])->toBeTrue()
        ->and($artifact['scenario'])->toBe('trial-dunning-cancel-reactivate')
        ->and($artifact['reason'])->toBe('the recording ended before every required event arrived')
        ->and($artifact['started_at'])->toBe('2026-01-01T00:00:00+00:00')
        ->and($artifact['failed_at'])->toBe('2026-01-01T00:01:30+00:00')
        ->and($artifact['elapsed_seconds'])->toBe(90)
        ->and($artifact['missing_events'])->toContain('invoice.payment_failed', 'customer.subscription.deleted')
        ->and($artifact['missing_events'])->not->toContain('customer.subscription.created')
        ->and(array_column($artifact['captured_events'], 'id'))->toBe(['evt_1', 'evt_2', 'evt_3'])
        ->and($artifact['request_ids'])->toBe(['req_abc123'])
        ->and($artifact)->not->toHaveKeys(['replay', 'fixture']);
});

it('flags events the scenario never declared', function () {
    // Not a failure, but the manifest may need to learn about them — which is
    // how a manifest stays honest as Stripe changes.
    $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::missingEvents());

    expect(artifactAt($e->artifactPath)['undeclared_events'])->toBe(['radar.early_fraud_warning.created']);
});

it('names the same missing events in the message as in the artifact', function () {
    $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::missingEvents());

    $missing = artifactAt($e->artifactPath)['missing_events'];

    expect($e->getMessage())->toContain(
        'Recording [trial-dunning-cancel-reactivate] is incomplete: the recording ended before every required event arrived.',
        'No fixture was written.',
        'Never arrived: '.implode(', ', $missing),
        'Captured 3 event(s): customer.subscription.created, invoice.created, radar.early_fraud_warning.created',
        'Diagnostics written to '.$e->artifactPath,
    );
});

it('writes where a failed recording can be found again, and never as a fixture', function () {
    $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::missingEvents());

    expect(dirname((string) $e->artifactPath))->toBe($this->root.DIRECTORY_SEPARATOR.'quarantine')
        ->and(basename((string) $e->artifactPath))->toStartWith('trial-dunning-cancel-reactivate-20260101-000130-')
        ->and(basename((string) $e->artifactPath))->toEndWith('.quarantine.json')
        ->and($e->context())->toBe([]);

    // No format_version, so the loader refuses it by construction rather than
    // by living in a different directory.
    expect(fn () => FixtureFile::read((string) $e->artifactPath))->toThrow(InvalidFixture::class, 'format_version');
});

it('keeps the fixture and replay of a recording that did not replay clean, still unloadable', function () {
    $fixture = FixtureFile::decode(shippedFixture());
    $report = divergingReport();

    $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::didNotReplay($fixture, $report));

    $artifact = artifactAt($e->artifactPath);

    expect($e->getMessage())->toContain('did not replay clean', 'it looks like coverage')
        ->and($e->report)->toBe($report)
        ->and($artifact['replay']['passed'])->toBeFalse()
        ->and($artifact['fixture']['scenario'])->toBe('trial-dunning-cancel-reactivate');

    // Carrying a whole fixture must not make the quarantine loadable as one.
    expect(fn () => FixtureFile::read((string) $e->artifactPath))->toThrow(InvalidFixture::class, 'format_version');
});

it('reports a rate limit as its own reason, and keeps it as the cause', function () {
    // Stripe allows twenty invoices per subscription per day. Knowing that is
    // what tells you to wait rather than to debug.
    $cause = RateLimitException::factory('20 invoices per day reached');

    $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::rateLimited($cause));

    expect($e->getMessage())->toContain('Stripe rate limited the recording: 20 invoices per day reached')
        ->and($e->getPrevious())->toBe($cause)
        ->and(artifactAt($e->artifactPath)['reason'])->toBe('Stripe rate limited the recording: 20 invoices per day reached');
});

it('says plainly when nothing was captured at all', function () {
    $attempt = new RecordingAttempt(twoStepScenario(['customer.subscription.created']), CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    $e = (new Quarantine($this->root))->keep($attempt, Refusal::clockFailed(new ClockAdvanceFailed('clock stuck in advancing')));

    expect($e->getMessage())->toContain(
        'the test clock did not finish advancing: clock stuck in advancing',
        'Never arrived: customer.subscription.created',
        'Nothing was captured at all.',
    )
        ->and(artifactAt($e->artifactPath)['captured_events'])->toBe([]);
});

it('retains both failures when the quarantine cannot be saved', function () {
    $unblock = blockDirectories($this->root.'/quarantine');
    $cause = new ClockAdvanceFailed('clock stuck in advancing');

    try {
        $e = (new Quarantine($this->root))->keep(shortAttempt(), Refusal::clockFailed($cause));

        expect($e->getMessage())->toContain('clock stuck in advancing', 'Quarantine could not be saved', 'No diagnostic artifact was written')
            ->and($e->getMessage())->not->toContain('Diagnostics written to')
            ->and($e->artifactPath)->toBeNull()
            ->and($e->getPrevious())->toBe($cause)
            // previous holds the first failure, so report() reaches the
            // second one, and its trace, only through the log context.
            ->and($e->context()['quarantine_failure']->getMessage())->toContain(nativePath($this->root, 'quarantine'));
    } finally {
        $unblock();
    }
});

it('preserves distinct quarantines from the same second', function () {
    $quarantine = new Quarantine($this->root);

    $first = $quarantine->keep(shortAttempt(), Refusal::missingEvents())->artifactPath;
    $contents = file_get_contents((string) $first);
    $second = $quarantine->keep(shortAttempt(), Refusal::missingEvents())->artifactPath;

    expect($second)->not->toBe($first)
        ->and(file_get_contents((string) $first))->toBe($contents)
        ->and(file_get_contents((string) $second))->toBe($contents)
        ->and(glob($this->root.'/quarantine/'.AtomicFile::TEMPORARY_PREFIX.'*'))->toBe([]);
});
