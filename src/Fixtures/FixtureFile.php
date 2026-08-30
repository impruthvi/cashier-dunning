<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use JsonException;

/**
 * Reads and writes fixture files.
 *
 * Serialisation is pinned: pretty printed, two-space indent, unescaped slashes
 * and unicode, trailing newline. Fixtures are reviewed as diffs, so formatting
 * is part of the format.
 */
final class FixtureFile
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public static function read(string $path): Fixture
    {
        if (! is_file($path)) {
            throw new InvalidFixture("Fixture file not found at [{$path}].");
        }

        return self::decode((string) file_get_contents($path));
    }

    public static function decode(string $json): Fixture
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidFixture('Fixture is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($data)) {
            throw new InvalidFixture('Fixture must decode to an object.');
        }

        return Fixture::fromArray($data);
    }

    public static function encode(Fixture $fixture): string
    {
        // PHP indents with four spaces; two keeps deeply nested provider payloads
        // readable in a side-by-side diff.
        $json = json_encode($fixture->toArray(), self::JSON_FLAGS);

        $reindented = preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $m): string => str_repeat(' ', strlen($m[0]) / 2),
            (string) $json
        );

        return $reindented."\n";
    }

    public static function write(Fixture $fixture, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, self::encode($fixture));
    }
}
