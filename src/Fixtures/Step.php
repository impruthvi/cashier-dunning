<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * One point in a recorded billing timeline: advance the clock to here, deliver
 * these events, and this is what the app was entitled to afterwards.
 *
 * `entitlements` is a snapshot, not an assertion. Whether it is treated as an
 * expectation is the runner's decision, not the format's.
 */
final readonly class Step
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<ApiExchange>  $apiExchanges
     * @param  array<string, bool|int|null>  $entitlements
     */
    public function __construct(
        public Duration $advanceTo,
        public string $label,
        public array $events,
        public array $apiExchanges = [],
        public array $entitlements = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, int $index): self
    {
        foreach (['advance_to', 'label', 'events'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw InvalidFixture::malformedStep($index, "missing key [{$key}]");
            }
        }

        if (! is_array($data['events'])) {
            throw InvalidFixture::malformedStep($index, '[events] must be a list');
        }

        return new self(
            advanceTo: Duration::parse((string) $data['advance_to']),
            label: (string) $data['label'],
            events: array_values($data['events']),
            apiExchanges: array_map(
                ApiExchange::fromArray(...),
                array_values((array) ($data['api_responses'] ?? []))
            ),
            entitlements: (array) ($data['entitlements'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'advance_to' => $this->advanceTo->toString(),
            'label' => $this->label,
            'events' => $this->events,
            'api_responses' => array_map(
                static fn (ApiExchange $exchange): array => $exchange->toArray(),
                $this->apiExchanges
            ),
            'entitlements' => Json::object($this->entitlements),
        ];
    }

    /**
     * The same step with its entitlement snapshot filled in.
     *
     * Recording captures what the provider did; the entitlement column is what
     * the application made of it, and that only exists once the events have
     * been through the application. So it is attached afterwards rather than
     * guessed at record time.
     *
     * @param  array<string, scalar|null>  $entitlements
     */
    public function withEntitlements(array $entitlements): self
    {
        return new self(
            advanceTo: $this->advanceTo,
            label: $this->label,
            events: $this->events,
            apiExchanges: $this->apiExchanges,
            entitlements: $entitlements,
        );
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_map(
            static fn (array $event): string => (string) ($event['type'] ?? 'unknown'),
            $this->events
        );
    }

    /**
     * Shuffling a step with fewer than two events is a no-op. The runner reports
     * that as "n/a" rather than "passed", so it never reads as coverage it isn't.
     */
    public function isShuffleable(): bool
    {
        return count($this->events) > 1;
    }
}
