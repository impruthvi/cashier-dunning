<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * An offset from the start of a scenario, written as "21d", "21d1h", "2h30m".
 *
 * Sub-day precision is not decoration: Stripe leaves subscription invoices in
 * `draft` for roughly an hour before finalising them, so a scenario that only
 * moves in whole days can never observe an invoice being paid.
 *
 * Stored as a readable string rather than an integer because fixtures are
 * reviewed as pull-request diffs, and "21d1h" survives that better than 30300.
 */
final readonly class Duration
{
    private function __construct(public int $minutes) {}

    public static function fromMinutes(int $minutes): self
    {
        return new self(max(0, $minutes));
    }

    public static function parse(string $value): self
    {
        $matched = preg_match(
            '/^(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?$/',
            trim($value),
            $parts
        );

        if ($matched !== 1 || trim($value) === '') {
            throw InvalidFixture::malformedDuration($value);
        }

        $days = (int) ($parts[1] ?? 0);
        $hours = (int) ($parts[2] ?? 0);
        $minutes = (int) ($parts[3] ?? 0);

        return new self(($days * 24 * 60) + ($hours * 60) + $minutes);
    }

    public function toString(): string
    {
        if ($this->minutes === 0) {
            return '0d';
        }

        $days = intdiv($this->minutes, 24 * 60);
        $hours = intdiv($this->minutes % (24 * 60), 60);
        $minutes = $this->minutes % 60;

        $out = '';
        $days > 0 && $out .= "{$days}d";
        $hours > 0 && $out .= "{$hours}h";
        $minutes > 0 && $out .= "{$minutes}m";

        return $out;
    }

    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }
}
