<?php

namespace Impruthvi\CashierDunning\Fixtures;

/**
 * What a scenario promises a recording will contain.
 *
 * This is the answer to the one critical gap the design review left open. A
 * recording that ends early — a timeout, a rate limit, a test clock that never
 * finished advancing — produces a fixture that is wrong but entirely plausible.
 * It replays green forever, and the drift job compares it against the same
 * short recording and agrees. Nothing downstream can catch it, because
 * everything downstream is reading the same broken file.
 *
 * So completeness is declared up front, by the scenario, and checked before a
 * fixture is written rather than after.
 *
 * Required events are the ones without which the scenario means nothing: a
 * dunning recording that never captured `invoice.payment_failed` is not a
 * dunning recording. Optional events exist because Stripe's exact emissions
 * vary with account settings and API version — `invoice.finalized` shows up
 * for some configurations and not others, and failing on that would make the
 * recorder unusable for anyone whose Stripe account is not the author's.
 */
final readonly class Manifest
{
    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    public function __construct(
        public array $required = [],
        public array $optional = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            required: array_values(array_map(strval(...), (array) ($data['required_events'] ?? []))),
            optional: array_values(array_map(strval(...), (array) ($data['optional_events'] ?? []))),
        );
    }

    /** @return array{required_events: list<string>, optional_events: list<string>} */
    public function toArray(): array
    {
        return [
            'required_events' => $this->required,
            'optional_events' => $this->optional,
        ];
    }

    /**
     * Required events that never arrived.
     *
     * @param  list<string>  $eventTypes
     * @return list<string>
     */
    public function missingFrom(array $eventTypes): array
    {
        return array_values(array_diff($this->required, $eventTypes));
    }

    /**
     * Events that arrived without being declared either way.
     *
     * Not a failure. Stripe adds event types, and an account with automatic tax
     * or a customer portal emits things this scenario never asked about.
     * Surfaced so a maintainer can decide whether the manifest should learn
     * about them, which is how a manifest stays honest as Stripe changes.
     *
     * @param  list<string>  $eventTypes
     * @return list<string>
     */
    public function undeclaredIn(array $eventTypes): array
    {
        return array_values(array_unique(array_diff(
            $eventTypes,
            $this->required,
            $this->optional
        )));
    }

    /**
     * Whether the scenario asked for this event type at all.
     *
     * Recording keeps only declared events. A real Stripe account emits three
     * times more than a billing timeline needs — setup intents, charge attempts,
     * every intermediate invoice update — and the allowlist has no field rules
     * for most of them, so they survive as husks carrying nothing but an id.
     * Shipping those would triple the size of a fixture with lines no reviewer
     * can check and no replay can use.
     */
    public function declares(string $eventType): bool
    {
        return in_array($eventType, $this->required, true)
            || in_array($eventType, $this->optional, true);
    }

    /** @param list<string> $eventTypes */
    public function isSatisfiedBy(array $eventTypes): bool
    {
        return $this->missingFrom($eventTypes) === [];
    }

    public function isEmpty(): bool
    {
        return $this->required === [] && $this->optional === [];
    }
}
