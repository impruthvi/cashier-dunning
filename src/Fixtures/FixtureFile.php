<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\DisallowedField;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Support\AtomicFile;
use Impruthvi\CashierDunning\Support\WebhookEvent;
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

    /**
     * What a value at one of {@see Allowlist::vocabularyPathsIn()} must look
     * like to be exempt from the literal-id rule: lowercase words joined by
     * underscores, as `card_declined` is. A real id such as `card_1AbC23` in
     * the same place is still refused.
     *
     * The rule is narrowed here rather than in kindOf() because kindOf() also
     * decides what the recorder replaces, and real Stripe ids can be all
     * lowercase words, such as a custom price id `price_monthly`.
     */
    private const VOCABULARY_WORD = '/^[a-z]+(?:_[a-z]+)*$/';

    public static function read(string $path): Fixture
    {
        if (! is_file($path)) {
            throw new InvalidFixture("Fixture file not found at [{$path}].");
        }

        return self::decode((string) file_get_contents($path));
    }

    /**
     * Decoding is where a fixture is refused, so every reader meets the same
     * rules: replay, doctor, and anything else that reads a file, and so does
     * {@see write()}, which decodes what it is about to save. A fixture is
     * refused when an event or a recorded API response carries a field the
     * allowlist does not permit, when an API exchange matches on a request
     * parameter it does not permit, when a match path is missing or breaks its
     * shape rule ({@see MatchPath}), when a whole string value in an event,
     * match body or response body is a literal provider id recognised by
     * {@see Placeholder::kindOf()}, when a required event in its manifest
     * never arrived, or when a field has the wrong type: a status of `"200"`, a
     * match body that is a list, an event without a string `type` and `id`.
     * Those are refused rather than cast, because a cast replays something the
     * author never wrote.
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

        return self::refuseInvalid(Fixture::fromArray($data));
    }

    private static function refuseInvalid(Fixture $fixture): Fixture
    {
        $allowlist = new Allowlist;

        foreach ($fixture->steps as $index => $step) {
            foreach ($step->events as $eventIndex => $event) {
                $offending = $allowlist->disallowedPaths($event);

                if ($offending !== []) {
                    throw DisallowedField::inStepEvent($index, WebhookEvent::type($event) ?? '', $offending);
                }

                self::refuseLiteralIds($event, $index, "event {$eventIndex} [".(WebhookEvent::type($event) ?? '').']');
            }

            foreach ($step->apiExchanges as $exchange) {
                if ($exchange->path === '') {
                    throw InvalidFixture::missingMatchPath($index, $exchange);
                }

                $problem = MatchPath::problemWith($exchange->path);

                if ($problem !== null) {
                    throw InvalidFixture::malformedMatchPath($index, $exchange, $problem);
                }

                $offending = $allowlist->disallowedRequestParameters($exchange->matchBody);

                if ($offending !== []) {
                    throw DisallowedField::inApiMatch($index, $exchange, $offending);
                }

                self::refuseLiteralIds($exchange->matchBody, $index, "the match body of [{$exchange->method} {$exchange->path}]");

                $offending = $allowlist->disallowedPathsInObject($exchange->responseBody);

                if ($offending !== []) {
                    throw DisallowedField::inApiResponse($index, $exchange, $offending);
                }

                self::refuseLiteralIds(
                    $exchange->responseBody,
                    $index,
                    "the response body of [{$exchange->method} {$exchange->path}]",
                    vocabularyPaths: $allowlist->vocabularyPathsIn($exchange->responseBody),
                );
            }
        }

        if (! $fixture->satisfiesManifest()) {
            throw InvalidFixture::incomplete($fixture->scenario, $fixture->missingRequiredEvents());
        }

        return $fixture;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $vocabularyPaths  paths where Stripe's own vocabulary is not read as an id
     */
    private static function refuseLiteralIds(array $payload, int $step, string $where, string $prefix = '', array $vocabularyPaths = []): void
    {
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::refuseLiteralIds($value, $step, $where, $path, $vocabularyPaths);
            } elseif (is_string($value) && in_array($path, $vocabularyPaths, true) && preg_match(self::VOCABULARY_WORD, $value) === 1) {
                continue;
            } elseif (is_string($value) && ($kind = Placeholder::kindOf($value)) !== null) {
                throw InvalidFixture::literalProviderId($step, $where, $path, $kind);
            }
        }
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
     * Writes only what {@see read()} would load back. The encoded bytes go
     * through the same decode a reader uses, so a fixture the rules refuse is
     * refused here, before it replaces anything already at the path.
     *
     * @throws InvalidFixture
     */
    public static function write(Fixture $fixture, string $path): void
    {
        $json = self::encode($fixture);

        self::decode($json);

        AtomicFile::write($path, $json);
    }
}
