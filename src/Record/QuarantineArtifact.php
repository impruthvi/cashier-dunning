<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Scenarios\Scenario;

/**
 * What a failed recording leaves behind.
 *
 * Written outside the fixture directory, deliberately, and without the
 * `format_version` key the loader requires — a quarantined recording must be
 * impossible to load by accident, including by a glob that someone widens later.
 *
 * It exists because the alternative is a black box. Recording is rate limited,
 * slow, and only reproducible against a live Stripe account; telling someone
 * "that didn't work, try again" costs them twenty invoices of quota and tells
 * them nothing about why. The artifact holds what did arrive, what didn't, when
 * each event landed, and the request ids Stripe support will ask for.
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
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $types = array_map(
            static fn (array $event): string => (string) ($event['type'] ?? 'unknown'),
            $this->captured
        );

        return [
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
     * first.
     */
    public function write(string $directory): string
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$this->scenario->name.'-'.$this->failedAt->format('Ymd-His').'.quarantine.json';

        file_put_contents($path, $this->encode());

        return $path;
    }
}
