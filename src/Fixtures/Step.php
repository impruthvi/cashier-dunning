<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Support\WebhookEvent;
use stdClass;

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
     * @param  array<string, scalar|null>  $entitlements
     */
    public function __construct(
        public Duration $advanceTo,
        public string $label,
        public array $events,
        public array $apiExchanges = [],
        public array $entitlements = [],
    ) {}

    /** @param array<string, mixed>|stdClass $data */
    public static function fromArray(array|stdClass $data, int $index): self
    {
        $step = DecodedObject::of($data, static fn (string $reason): InvalidFixture => InvalidFixture::malformedStep($index, $reason));

        foreach (['advance_to', 'label', 'events'] as $key) {
            if (! $step->has($key)) {
                throw InvalidFixture::malformedStep($index, "missing key [{$key}]");
            }
        }

        return new self(
            advanceTo: Duration::parse($step->string('advance_to')),
            label: $step->string('label'),
            events: array_map(self::event(...), $step->objects('events')),
            apiExchanges: array_map(ApiExchange::fromDecoded(...), $step->objects('api_responses')),
            entitlements: $step->scalars('entitlements'),
        );
    }

    /**
     * Everything downstream reads an event's type and id, so an event without
     * both as strings is refused here rather than replayed as `unknown`.
     *
     * @return array<string, mixed>
     */
    private static function event(DecodedObject $event): array
    {
        $event->string('type');
        $event->string('id');

        return $event->toArray();
    }

    /**
     * Every distinct placeholder this step's events and API exchanges hold.
     *
     * @return list<PlaceholderToken>
     */
    public function placeholderTokens(): array
    {
        return Placeholder::tokensIn([
            $this->events,
            array_map(
                static fn (ApiExchange $exchange): array => [$exchange->path, $exchange->matchBody, $exchange->responseBody],
                $this->apiExchanges
            ),
        ]);
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
            static fn (array $event): string => WebhookEvent::type($event) ?? 'unknown',
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
