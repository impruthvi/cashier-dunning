<?php

namespace Impruthvi\CashierDunning\Entitlements;

/**
 * One feature's value changing, optionally attributed to the event that caused
 * it.
 *
 * Attribution is what makes `--explain` useful. "Your users lost API access"
 * is a symptom; "your users lost API access at invoice.payment_failed, three
 * days before Stripe cancelled anything" is the bug.
 */
final readonly class EntitlementChange
{
    public function __construct(
        public string $feature,
        public string|int|float|bool|null $from,
        public string|int|float|bool|null $to,
        public ?string $eventType = null,
        public ?string $eventId = null,
    ) {}

    /**
     * Attribute this change to the event that was being applied when it happened.
     *
     * @param  array<string, mixed>  $event
     */
    public function causedBy(array $event): self
    {
        return new self(
            $this->feature,
            $this->from,
            $this->to,
            isset($event['type']) ? (string) $event['type'] : null,
            isset($event['id']) ? (string) $event['id'] : null,
        );
    }

    public function isGain(): bool
    {
        return $this->weight($this->to) > $this->weight($this->from);
    }

    public function isLoss(): bool
    {
        return $this->weight($this->to) < $this->weight($this->from);
    }

    public function describe(): string
    {
        $line = "{$this->feature}: ".$this->render($this->from).' -> '.$this->render($this->to);

        return $this->eventType === null ? $line : "{$line} (at {$this->eventType})";
    }

    private function render(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Orders values so gain and loss mean something across mixed types: null is
     * nothing, false is nothing, and a numeric limit is worth its own size.
     * Strings are unordered, so a change between two strings is neither.
     */
    private function weight(string|int|float|bool|null $value): float
    {
        return match (true) {
            $value === null => 0.0,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_int($value), is_float($value) => (float) $value,
            default => 0.0,
        };
    }
}
