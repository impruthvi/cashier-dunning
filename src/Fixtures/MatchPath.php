<?php

namespace Impruthvi\CashierDunning\Fixtures;

/**
 * The shape a published API exchange's match path must have, such as
 * `/v1/subscriptions/{{sub_1}}`.
 *
 * Separate from the allowlist, which judges fields: a path is not a field.
 * Checked when a fixture is saved or loaded: on save it catches a recorded id
 * the normalizer did not recognise, and on load a hand author's path.
 *
 *   - It starts with `/` and has no query string or fragment. The provider SDK
 *     hands a request's parameters to the HTTP client separately, and replay
 *     drops a query string before matching, so a query string could never
 *     match; the match body says the same thing.
 *   - Each segment is a lowercase literal word, such as `subscription_items`,
 *     or one provider-id placeholder filling the whole segment, such as
 *     `{{si_1}}`. A literal provider id, a time placeholder, or a placeholder
 *     embedded in a longer segment is refused. A literal id could not match
 *     anyway, because placeholders resolve to fresh ids on each replay.
 *
 * There is no list of permitted endpoints.
 *
 * @internal
 */
final class MatchPath
{
    private const WORD = '/\A[a-z0-9_]+\z/';

    /**
     * What is wrong with a match path, phrased to follow "the match path of
     * [GET /v1/…]", or null when it has the right shape. A missing path is the
     * caller's to refuse: there is no path to name in the message.
     */
    public static function problemWith(string $path): ?string
    {
        if (str_contains($path, '?') || str_contains($path, '#')) {
            return 'has a query string or fragment. Match on request parameters in the match body instead.';
        }

        if (! str_starts_with($path, '/')) {
            return 'does not start with /.';
        }

        foreach (explode('/', substr($path, 1)) as $segment) {
            $problem = self::problemWithSegment($segment);

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    private static function problemWithSegment(string $segment): ?string
    {
        if ($segment === '') {
            return 'has an empty segment.';
        }

        if (Placeholder::isWholeId($segment)) {
            return null;
        }

        $kind = Placeholder::kindOf($segment);

        if ($kind !== null) {
            return "has the literal provider id [{$segment}]. Use a placeholder such as ".Placeholder::forId($kind, 1).'.';
        }

        if (preg_match(self::WORD, $segment) === 1) {
            return null;
        }

        return "has the segment [{$segment}], which is neither a lowercase word nor a whole provider-id placeholder.";
    }
}
