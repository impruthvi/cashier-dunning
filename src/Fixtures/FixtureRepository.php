<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * Finds fixtures on disk.
 *
 * Two sources, in order: the application's own recordings, then the reference
 * corpus that ships with the package. The application wins on a name clash, so
 * re-recording a shipped scenario against your own Stripe settings replaces it
 * rather than requiring a new name — dunning behaviour depends on account
 * settings, and yours are not the corpus author's.
 */
final readonly class FixtureRepository
{
    public function __construct(
        private ?string $applicationPath = null,
        private ?string $packagePath = null,
    ) {}

    /** @return list<string> directories searched, in precedence order */
    public function paths(): array
    {
        return array_values(array_filter([
            $this->applicationPath,
            $this->packagePath ?? dirname(__DIR__, 2).'/fixtures',
        ], static fn (?string $path): bool => $path !== null && is_dir($path)));
    }

    /** @return list<string> every fixture file, application recordings first */
    public function files(): array
    {
        $files = [];

        foreach ($this->paths() as $path) {
            foreach (glob($path.'/*/*.json') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return array<string, string> scenario name => path, first source wins */
    public function scenarios(): array
    {
        $scenarios = [];

        foreach ($this->files() as $file) {
            $name = basename($file, '.json');
            $scenarios[$name] ??= $file;
        }

        ksort($scenarios);

        return $scenarios;
    }

    public function find(string $scenario): Fixture
    {
        $scenarios = $this->scenarios();

        if (! isset($scenarios[$scenario])) {
            throw new InvalidFixture(
                "No fixture named [{$scenario}]. Available: ".
                (($names = array_keys($scenarios)) === [] ? '(none)' : implode(', ', $names)).'.'
            );
        }

        return FixtureFile::read($scenarios[$scenario]);
    }
}
