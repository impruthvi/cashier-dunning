<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * A recorded billing lifecycle: an ordered timeline of clock advances, the
 * events the provider emitted, the API responses it gave, and what the app was
 * entitled to at each point.
 *
 *   {
 *     "format_version": 1,
 *     "provider": "stripe",
 *     "scenario": "trial-dunning-cancel-reactivate",
 *     "provenance": { ...who recorded it, against what... },
 *     "manifest":   { "required_events": [...], "optional_events": [...] },
 *     "steps":      [ { "advance_to": "21d", "events": [...], ... } ]
 *   }
 *
 * Ids and timestamps are placeholders ({{sub_1}}, {{t+21d}}), assigned in
 * first-seen order, so two recordings of the same journey produce identical
 * bytes. That is what makes drift detection possible and pull-request diffs
 * readable.
 */
final readonly class Fixture
{
    public const FORMAT_VERSION = 1;

    /**
     * @param  list<Step>  $steps
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public Provider $provider,
        public string $scenario,
        public array $provenance,
        public Manifest $manifest,
        public array $steps,
        public int $formatVersion = self::FORMAT_VERSION,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['format_version', 'provider', 'scenario', 'steps'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw InvalidFixture::missingKey($key);
            }
        }

        $version = (int) $data['format_version'];

        if ($version !== self::FORMAT_VERSION) {
            throw InvalidFixture::unsupportedFormatVersion($version, self::FORMAT_VERSION);
        }

        $scenario = (string) $data['scenario'];
        $rawSteps = (array) $data['steps'];

        if ($rawSteps === []) {
            throw InvalidFixture::empty($scenario);
        }

        $steps = [];

        foreach (array_values($rawSteps) as $index => $step) {
            $steps[] = Step::fromArray((array) $step, $index);
        }

        return new self(
            provider: Provider::fromFixture($data['provider']),
            scenario: $scenario,
            provenance: (array) ($data['provenance'] ?? []),
            manifest: Manifest::fromArray((array) ($data['manifest'] ?? [])),
            steps: $steps,
            formatVersion: $version,
        );
    }

    /**
     * Key order is fixed rather than incidental. The corpus grows by pull
     * request, so a stable serialisation is what keeps a re-record diff to the
     * lines that actually changed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'provider' => $this->provider->value,
            'scenario' => $this->scenario,
            'provenance' => Json::objectDeep($this->provenance),
            'manifest' => $this->manifest->toArray(),
            'steps' => array_map(
                static fn (Step $step): array => $step->toArray(),
                $this->steps
            ),
        ];
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_merge(...array_map(
            static fn (Step $step): array => $step->eventTypes(),
            $this->steps
        ));
    }

    /**
     * Every required event the manifest declared must actually be present. A
     * recording that came up short is refused rather than quietly replayed.
     *
     * @return list<string>
     */
    public function missingRequiredEvents(): array
    {
        return $this->manifest->missingFrom($this->eventTypes());
    }

    public function satisfiesManifest(): bool
    {
        return $this->manifest->isSatisfiedBy($this->eventTypes());
    }
}
