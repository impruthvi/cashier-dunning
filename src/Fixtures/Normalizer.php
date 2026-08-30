<?php

namespace Impruthvi\CashierDunning\Fixtures;

/**
 * Replaces provider ids and absolute timestamps with stable placeholders.
 *
 *   sub_1PxyzABC   ->  {{sub_1}}
 *   1767225600     ->  {{t+21d}}
 *
 * Placeholders are assigned per id-prefix in first-seen order, so recording the
 * same journey twice produces byte-identical output. Without that, every
 * re-record diffs on ids that carry no meaning, drift detection drowns in noise,
 * and nobody can review a contributed fixture.
 *
 * Normalisation is one-way by design. Replay never contacts the provider, so the
 * real ids are never needed again.
 */
final class Normalizer
{
    /**
     * Keys whose integer values are provider timestamps. Detecting by key rather
     * than by "looks like an epoch" avoids rewriting amounts and quantities that
     * happen to fall in the same numeric range.
     */
    private const TIMESTAMP_KEYS = [
        'created', 'start_date', 'ended_at', 'canceled_at', 'cancel_at',
        'current_period_start', 'current_period_end',
        'trial_start', 'trial_end',
        'period_start', 'period_end',
        'next_payment_attempt', 'due_date', 'finalized_at', 'paid_at',
        'billing_cycle_anchor', 'frozen_time',
    ];

    private const ID_PATTERN = '/^(sub|cus|in|price|prod|evt|pi|pm|txn|il|si|ch|card|sub_sched|clock)_[A-Za-z0-9]+$/';

    /** @var array<string, string> real id => placeholder */
    private array $assigned = [];

    /** @var array<string, int> prefix => next number */
    private array $counters = [];

    public function __construct(private readonly int $scenarioStartEpoch) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        return $this->walk($payload);
    }

    /** @return array<string, string> */
    public function assignments(): array
    {
        return $this->assigned;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node, ?string $parentKey = null): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walked = $this->walk($value, is_string($key) ? $key : $parentKey);

                // An empty container is dropped rather than kept. PHP cannot tell
                // `{}` from `[]`, so keeping one would make the fixture re-encode
                // differently from how it was recorded, and every re-record would
                // diff on it. An empty map carries no replayable information.
                if ($walked !== []) {
                    $out[$key] = $walked;
                }

                continue;
            }

            $out[$key] = match (true) {
                is_int($value) && $this->isTimestampKey($key) => $this->placeholderForTimestamp($value),
                is_string($value) => $this->placeholderForId($value),
                default => $value,
            };
        }

        return $out;
    }

    private function isTimestampKey(int|string $key): bool
    {
        return is_string($key) && in_array($key, self::TIMESTAMP_KEYS, true);
    }

    private function placeholderForTimestamp(int $epoch): string
    {
        $offset = Duration::fromMinutes(intdiv(max(0, $epoch - $this->scenarioStartEpoch), 60));

        return '{{t+'.$offset->toString().'}}';
    }

    private function placeholderForId(string $value): string
    {
        if (preg_match(self::ID_PATTERN, $value, $matches) !== 1) {
            return $value;
        }

        if (isset($this->assigned[$value])) {
            return $this->assigned[$value];
        }

        $prefix = $matches[1];
        $number = $this->counters[$prefix] = ($this->counters[$prefix] ?? 0) + 1;

        return $this->assigned[$value] = '{{'.$prefix.'_'.$number.'}}';
    }
}
