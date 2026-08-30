<?php

namespace Impruthvi\CashierDunning\Replay;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Duration;

/**
 * Turns the placeholders a fixture stores back into concrete values for one run.
 *
 *   {{sub_1}}    ->  sub_replay1
 *   {{t+14d1h}}  ->  1767312000
 *
 * Recording normalises ids and timestamps so that two recordings of the same
 * journey are byte-identical. Replay has to undo that, because the application
 * under test stores whatever id it is handed and then asks Stripe about it
 * later — and the request it makes has to match what the fixture recorded.
 *
 * The concrete values are invented, stable within a run, and keep the provider's
 * prefix, so anything that inspects an id to decide what kind of object it is
 * keeps working. They are not meant to look real: an id in a failing test that
 * reads `sub_replay1` tells you immediately that nothing here came from Stripe.
 */
final readonly class Placeholders
{
    private const IDENTIFIER = '/\{\{([a-z_]+?)_(\d+)\}\}/';

    private const TIMESTAMP = '/\{\{t\+([0-9dhm]+)\}\}/';

    public function __construct(private CarbonImmutable $startedAt) {}

    /**
     * Resolve every placeholder in a structure, leaving everything else alone.
     */
    public function resolve(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->resolve(...), $value);
        }

        return is_string($value) ? $this->resolveString($value) : $value;
    }

    /**
     * A whole-string placeholder resolves to its natural type — a timestamp
     * becomes an integer, because that is what Stripe sends and what Cashier
     * will try to do date arithmetic on. Placeholders embedded in a longer
     * string, such as a URL path, resolve in place and stay strings.
     */
    public function resolveString(string $value): string|int
    {
        if (preg_match('/^'.trim(self::TIMESTAMP, '/').'$/', $value, $matches) === 1) {
            return $this->timestamp($matches[1]);
        }

        $value = preg_replace_callback(
            self::TIMESTAMP,
            fn (array $m): string => (string) $this->timestamp($m[1]),
            $value
        );

        return (string) preg_replace_callback(
            self::IDENTIFIER,
            static fn (array $m): string => $m[1].'_replay'.$m[2],
            (string) $value
        );
    }

    public function timestamp(string $offset): int
    {
        return $this->startedAt->addMinutes(Duration::parse($offset)->minutes)->getTimestamp();
    }
}
