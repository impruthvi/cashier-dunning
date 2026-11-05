<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\DisallowedField;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Support\AtomicFile;
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

    /**
     * Loading is where a fixture is refused, so every reader meets the same
     * rules: replay, doctor, and anything else that reads a file. A fixture is
     * refused when an event or a recorded API response carries a field the
     * allowlist does not permit, when an API exchange matches on a request
     * parameter it does not permit, or when a required event in its manifest
     * never arrived.
     *
     * @throws InvalidFixture
     */
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

        return self::refuseInvalid(Fixture::fromArray($data));
    }

    private static function refuseInvalid(Fixture $fixture): Fixture
    {
        $allowlist = new Allowlist;

        foreach ($fixture->steps as $index => $step) {
            foreach ($step->events as $event) {
                $offending = $allowlist->disallowedPaths($event);

                if ($offending !== []) {
                    throw DisallowedField::inStepEvent($index, (string) ($event['type'] ?? ''), $offending);
                }
            }

            foreach ($step->apiExchanges as $exchange) {
                $offending = $allowlist->disallowedRequestParameters($exchange->matchBody);

                if ($offending !== []) {
                    throw DisallowedField::inApiMatch($index, $exchange, $offending);
                }

                $offending = $allowlist->disallowedPathsInObject($exchange->responseBody);

                if ($offending !== []) {
                    throw DisallowedField::inApiResponse($index, $exchange, $offending);
                }
            }
        }

        if (! $fixture->satisfiesManifest()) {
            throw InvalidFixture::incomplete($fixture->scenario, $fixture->missingRequiredEvents());
        }

        return $fixture;
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

    public static function write(Fixture $fixture, string $path): void
    {
        AtomicFile::write($path, self::encode($fixture));
    }
}
