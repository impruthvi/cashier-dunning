<?php

namespace Impruthvi\CashierDunning\Chaos;

/**
 * The result of replaying the same timeline several ways.
 */
final readonly class ChaosReport
{
    /** @param list<PassResult> $passes */
    public function __construct(
        public string $scenario,
        public int $seed,
        public array $passes,
    ) {}

    public function passed(): bool
    {
        foreach ($this->passes as $pass) {
            if (! $pass->passed()) {
                return false;
            }
        }

        // A run where every pass was inapplicable proved nothing. Reporting it
        // as success would be the same coverage theatre the runner refuses
        // elsewhere.
        return $this->applicablePasses() > 0;
    }

    public function applicablePasses(): int
    {
        return count(array_filter(
            $this->passes,
            static fn (PassResult $pass): bool => ! $pass->notApplicable
        ));
    }

    /** @return list<PassResult> */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->passes,
            static fn (PassResult $pass): bool => ! $pass->passed()
        ));
    }

    public function verdict(): string
    {
        if ($this->applicablePasses() === 0) {
            return 'No pass changed anything. Every step has fewer than two events, '.
                'so there was no order to get wrong.';
        }

        $failures = $this->failures();

        if ($failures === []) {
            return 'The application behaved identically across '.
                $this->applicablePasses().' ordering(s).';
        }

        return count($failures).' ordering(s) changed the outcome. '.
            'Reproduce with --seed='.$this->seed.'.';
    }
}
