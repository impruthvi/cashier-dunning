<?php

namespace Impruthvi\CashierDunning\Entitlements;

/**
 * Compares only the features the fixture declared, using their original types.
 * Features added by the application do not invalidate an older fixture.
 * A declared feature going missing is compared against null, just like a
 * snapshot lookup, so a missing feature and an explicit null remain equivalent.
 *
 * @internal
 */
final readonly class EntitlementComparison
{
    /**
     * @param  array<string, array{expected: scalar|null, actual: scalar|null, differs: bool}>  $rows
     * @param  list<string>  $mismatches
     */
    private function __construct(
        public array $rows,
        public int $assertions,
        public array $mismatches,
    ) {}

    /**
     * @param  array<string, scalar|null>  $expected
     * @param  array<string, scalar|null>  $actual
     */
    public static function between(array $expected, array $actual): self
    {
        $rows = [];
        $mismatches = [];

        foreach ($expected as $feature => $value) {
            $seen = $actual[$feature] ?? null;
            $differs = $value !== $seen;
            $rows[$feature] = ['expected' => $value, 'actual' => $seen, 'differs' => $differs];

            if ($differs) {
                $mismatches[] = sprintf(
                    '%s: recording says %s, application says %s',
                    $feature,
                    self::render($value),
                    self::render($seen),
                );
            }
        }

        return new self($rows, count($rows), $mismatches);
    }

    private static function render(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
