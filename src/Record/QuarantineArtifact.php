<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Reporting\JsonReport;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Support\AtomicFile;

/**
 * What a failed recording leaves behind, when the quarantine itself can be written.
 *
 * Written outside the fixture directory, deliberately, and without the
 * `format_version` key the loader requires — a quarantined recording must be
 * impossible to load by accident, including by a glob that someone widens later.
 *
 * It exists because the alternative is a black box. Recording is rate limited,
 * slow, and only reproducible against a live Stripe account; telling someone
 * "that didn't work, try again" costs them twenty invoices of quota and tells
 * them nothing about why. The artifact holds what did arrive, what didn't, and
 * when each event landed, along with the response request ids Stripe support
 * will ask for.
 *
 * A recording that did not replay clean, or could not be saved, keeps the replay
 * and the fixture it would have written, because there the diagnosis lives in
 * the replay. The fixture is nested, so the artifact still has no top-level
 * `format_version` and still cannot be loaded.
 */
final readonly class QuarantineArtifact
{
    /**
     * @param  list<array<string, mixed>>  $captured
     * @param  list<string>  $requestIds
     */
    public function __construct(
        public Scenario $scenario,
        public array $captured,
        public array $requestIds,
        public string $reason,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $failedAt,
        public ?ReplayReport $replay = null,
        public ?Fixture $fixture = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $types = array_map(
            static fn (array $event): string => (string) ($event['type'] ?? 'unknown'),
            $this->captured
        );

        $artifact = [
            'quarantined' => true,
            'scenario' => $this->scenario->name,
            'reason' => $this->reason,
            'started_at' => $this->startedAt->toIso8601String(),
            'failed_at' => $this->failedAt->toIso8601String(),
            // Cast: Carbon returns a float here, and a fractional second in a
            // diagnostic artifact is noise that makes two runs look different.
            'elapsed_seconds' => (int) $this->failedAt->diffInSeconds($this->startedAt, absolute: true),
            'required_events' => $this->scenario->manifest->required,
            'missing_events' => $this->scenario->manifest->missingFrom($types),
            'undeclared_events' => $this->scenario->manifest->undeclaredIn($types),
            'captured_events' => array_map(
                static fn (array $event): array => [
                    'id' => $event['id'] ?? null,
                    'type' => $event['type'] ?? null,
                    'created' => $event['created'] ?? null,
                ],
                $this->captured
            ),
            'request_ids' => $this->requestIds,
        ];

        if ($this->replay !== null) {
            $artifact['replay'] = JsonReport::toArray($this->replay);
        }

        if ($this->fixture !== null) {
            $artifact['fixture'] = $this->fixture->toArray();
        }

        return $artifact;
    }

    public function encode(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )."\n";
    }

    /**
     * Writes the artifact and returns where it went.
     *
     * The filename carries the scenario and a timestamp, because the second
     * attempt at a recording is the one you most want to compare against the
     * first. A random suffix keeps attempts in the same second distinct.
     */
    public function write(string $directory): string
    {
        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$this->scenario->name.'-'.$this->failedAt->format('Ymd-His').'-'.bin2hex(random_bytes(16)).'.quarantine.json';

        AtomicFile::write($path, $this->encode());

        return $path;
    }
}
