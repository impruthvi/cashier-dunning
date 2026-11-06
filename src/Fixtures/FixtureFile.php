<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Support\AtomicFile;
use JsonException;
use stdClass;

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

    /**
     * Decoding refuses what is wrong with the document: invalid JSON, a missing
     * key, an unsupported format version, an absent match path, or a field of
     * the wrong type, such as a status of `"200"`, a match body that is a list,
     * or an event without a string `type` and `id`. Those are refused rather
     * than cast, because a cast replays something the author never wrote.
     * What the fixture may hold is refused by {@see Fixture}'s constructor
     * ({@see FixtureRules}), so every reader and writer meets the same rules.
     *
     * @throws InvalidFixture
     */
    public static function decode(string $json): Fixture
    {
        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidFixture('Fixture is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! $data instanceof stdClass) {
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
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)),
            (string) $json
        );

        return $reindented."\n";
    }

    /**
     * Encoding cannot fail on a Fixture: its constructor already refused
     * anything {@see read()} would refuse, so what is saved always loads.
     */
    public static function write(Fixture $fixture, string $path): void
    {
        AtomicFile::write($path, self::encode($fixture));
    }
}
