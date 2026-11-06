<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Record\Exceptions\RecordingRefused;
use Impruthvi\CashierDunning\Reporting\JsonReport;
use Impruthvi\CashierDunning\Support\AtomicFile;
use Impruthvi\CashierDunning\Support\WebhookEvent;
use Throwable;

/**
 * Keeps what a refused recording leaves behind, and says so.
 *
 * The one owner of a refused recording: given the attempt and why it was
 * refused, it builds the artifact, chooses where it goes, writes it, and
 * composes the refusal a person reads. The refusal says either where the
 * artifact is, or, when it could not be written, both failures.
 *
 * It exists because the alternative is a black box. Recording is rate limited,
 * slow, and only reproducible against a live Stripe account; telling someone
 * "that didn't work, try again" costs them twenty invoices of quota and tells
 * them nothing about why. The artifact holds what did arrive, what didn't, and
 * when each event landed, along with the response request ids Stripe support
 * will ask for. A recording that did not replay clean, or could not be saved,
 * also keeps the fixture it would have written and the replay report when one
 * is available.
 *
 * The artifact is written under `quarantine/` in the fixture directory, which
 * the fixture loader never searches, and without a top-level `format_version`,
 * which the loader requires. The fixture inside it is nested, so carrying one
 * does not make the artifact loadable either.
 *
 * @internal
 */
final readonly class Quarantine
{
    /** @param  string  $fixturePath  the application's fixture directory */
    public function __construct(private string $fixturePath) {}

    public function keep(RecordingAttempt $attempt, Refusal $refusal): RecordingRefused
    {
        $failedAt = CarbonImmutable::now('UTC');
        $types = array_map(
            static fn (array $event): string => WebhookEvent::type($event) ?? 'unknown',
            $attempt->captured
        );
        $missing = $attempt->scenario->manifest->missingFrom($types);

        $artifactPath = $failure = null;

        try {
            $artifactPath = $this->write($attempt, $failedAt, $this->artifact($attempt, $refusal, $failedAt, $types, $missing));
        } catch (Throwable $e) {
            $failure = $e;
        }

        return new RecordingRefused(
            $this->message($attempt, $refusal, $types, $missing, $artifactPath, $failure),
            previous: $refusal->getPrevious(),
            report: $refusal->report,
            artifactPath: $artifactPath,
            quarantineFailure: $failure,
        );
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private function artifact(RecordingAttempt $attempt, Refusal $refusal, CarbonImmutable $failedAt, array $types, array $missing): array
    {
        $manifest = $attempt->scenario->manifest;

        $artifact = [
            'quarantined' => true,
            'scenario' => $attempt->scenario->name,
            'reason' => $refusal->reason,
            'started_at' => $attempt->startedAt->toIso8601String(),
            'failed_at' => $failedAt->toIso8601String(),
            // Cast: Carbon returns a float here, and a fractional second in a
            // diagnostic artifact is noise that makes two runs look different.
            'elapsed_seconds' => (int) $failedAt->diffInSeconds($attempt->startedAt, absolute: true),
            'required_events' => $manifest->required,
            'missing_events' => $missing,
            'undeclared_events' => $manifest->undeclaredIn($types),
            'captured_events' => array_map(
                static fn (array $event): array => [
                    'id' => $event['id'] ?? null,
                    'type' => $event['type'] ?? null,
                    'created' => $event['created'] ?? null,
                ],
                $attempt->captured
            ),
            'request_ids' => $attempt->requestIds,
        ];

        if ($refusal->report !== null) {
            $artifact['replay'] = JsonReport::toArray($refusal->report);
        }

        if ($refusal->fixture !== null) {
            $artifact['fixture'] = $refusal->fixture->toArray();
        }

        return $artifact;
    }

    /**
     * Writes the artifact and returns where it went.
     *
     * The filename carries the scenario and a timestamp, because the second
     * attempt at a recording is the one you most want to compare against the
     * first. A random suffix keeps attempts in the same second distinct.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function write(RecordingAttempt $attempt, CarbonImmutable $failedAt, array $artifact): string
    {
        $path = $this->fixturePath
            .DIRECTORY_SEPARATOR.'quarantine'
            .DIRECTORY_SEPARATOR.$attempt->scenario->name.'-'.$failedAt->format('Ymd-His').'-'.bin2hex(random_bytes(16)).'.quarantine.json';

        AtomicFile::write($path, json_encode(
            $artifact,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )."\n");

        return $path;
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $missing
     */
    private function message(
        RecordingAttempt $attempt,
        Refusal $refusal,
        array $types,
        array $missing,
        ?string $artifactPath,
        ?Throwable $failure,
    ): string {
        $lines = [
            "Recording [{$attempt->scenario->name}] {$refusal->outcome}: ".rtrim($refusal->reason, '.').'.',
            '',
            trim('No fixture was written. '.$refusal->consequence),
        ];

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = 'Never arrived: '.implode(', ', $missing);
        }

        $lines[] = '';
        $lines[] = $types === []
            ? 'Nothing was captured at all.'
            : 'Captured '.count($types).' event(s): '.implode(', ', array_unique($types));

        $lines[] = '';

        if ($artifactPath !== null) {
            $lines[] = "Diagnostics written to {$artifactPath}";
        } else {
            $lines[] = 'Quarantine could not be saved: '.$failure?->getMessage();
            $lines[] = 'No diagnostic artifact was written.';
        }

        return implode("\n", $lines);
    }
}
