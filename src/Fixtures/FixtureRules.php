<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\DisallowedField;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Support\WebhookEvent;

/**
 * The rules for what a fixture may hold, run by {@see Fixture}'s constructor so
 * that every Fixture that exists can be saved and loaded (ADR 0002).
 *
 * A fixture is refused when it has no steps, when a placeholder is not one
 * this version understands, when an event or a recorded API response carries
 * a field the allowlist does not permit, when an API exchange matches on a
 * request parameter it does not permit, when a match path is missing or breaks
 * its shape rule ({@see MatchPath}), when a whole string value in an event,
 * match body or response body is a literal provider id recognised by
 * {@see Placeholder::kindOf()}, or when a required event in its manifest never
 * arrived. The rules about the document itself (valid JSON, required keys,
 * format version, field types) stay with decoding, in {@see FixtureFile}.
 *
 * Only Fixture calls this, and it is tested only through Fixture.
 *
 * @internal
 */
final class FixtureRules
{
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

    /** @throws InvalidFixture */
    public static function enforce(Fixture $fixture): void
    {
        if ($fixture->steps === []) {
            throw InvalidFixture::empty($fixture->scenario);
        }

        self::refuseUnrecognisedPlaceholders($fixture);

        $allowlist = new Allowlist;

        foreach ($fixture->steps as $index => $step) {
            self::refuseInvalidStep($allowlist, $step, $index);
        }

        $missing = $fixture->manifest->missingFrom($fixture->eventTypes());

        if ($missing !== []) {
            throw InvalidFixture::incomplete($fixture->scenario, $missing);
        }
    }

    private static function refuseInvalidStep(Allowlist $allowlist, Step $step, int $index): void
    {
        foreach ($step->events as $eventIndex => $event) {
            $type = WebhookEvent::type($event) ?? '';
            $offending = $allowlist->disallowedPaths($event);

            if ($offending !== []) {
                throw DisallowedField::inStepEvent($index, $type, $offending);
            }

            self::refuseLiteralIds($event, $index, "event {$eventIndex} [{$type}]");
        }

        foreach ($step->apiExchanges as $exchange) {
            if ($exchange->path === '') {
                throw InvalidFixture::missingMatchPath($index, $exchange->method);
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

    /**
     * Checked here rather than when a placeholder is resolved: by then the
     * replay is underway inside the application's webhook handler, and a typo
     * such as {{sub-1}} would surface as a request that matched nothing.
     * Refused when the fixture is built, it names the step and the token.
     */
    private static function refuseUnrecognisedPlaceholders(Fixture $fixture): void
    {
        foreach ($fixture->steps as $index => $step) {
            foreach ($step->placeholderTokens() as $token) {
                if (! $token->isRecognised()) {
                    throw InvalidFixture::unrecognisedPlaceholder($index, $token->text);
                }
            }
        }
    }
}
