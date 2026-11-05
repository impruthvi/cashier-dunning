<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Closure;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use LogicException;

/**
 * The syntax of a fixture's placeholders, and the only place it is written down.
 *
 *   {{sub_1}}    a provider id: its kind, then its first-seen number
 *   {{t+14d1h}}  a point on the timeline, as an offset from the scenario start
 *
 * Recording mints these (Normalizer) and replay resolves them (Placeholders).
 * Both go through here, so what one writes is always what the other reads. A
 * fixture is refused at load if it holds a token this grammar cannot parse.
 *
 * @internal
 */
final class Placeholder
{
    /** The kinds of provider id a fixture can hold a placeholder for. */
    public const KINDS = [
        'sub', 'cus', 'in', 'price', 'prod', 'evt', 'pi', 'pm',
        'txn', 'il', 'si', 'ch', 'card', 'sub_sched', 'clock',
    ];

    /** Anything that claims to be a token, recognised or not. */
    private const ANY = '/\{\{([^{}]*)\}\}/';

    /** A string that is wholly one token. */
    private const WHOLE = '/^\{\{([^{}]*)\}\}$/';

    private const ID = '/^([a-z_]+)_(\d+)$/';

    private const OFFSET = '/^t\+([0-9dhm]+)$/';

    public static function forId(string $kind, int $number): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new LogicException("[{$kind}] is not a placeholder kind.");
        }

        return '{{'.$kind.'_'.$number.'}}';
    }

    public static function forOffset(Duration $offset): string
    {
        return '{{t+'.$offset->toString().'}}';
    }

    /**
     * The kind of a real provider id, such as `sub` for `sub_1PxyzABC`, or null
     * when the string is not a provider id at all.
     */
    public static function kindOf(string $providerId): ?string
    {
        // Longest kind first, so `sub_sched_…` is never read as a `sub_`.
        $kinds = self::KINDS;
        usort($kinds, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $pattern = '/^('.implode('|', array_map(static fn (string $kind): string => preg_quote($kind, '/'), $kinds)).')_[A-Za-z0-9]+$/';

        return preg_match($pattern, $providerId, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Whether a string is wholly one provider-id placeholder, such as
     * `{{sub_1}}`: not a point on the timeline, and not a token embedded in a
     * longer string.
     */
    public static function isWholeId(string $value): bool
    {
        if (preg_match(self::WHOLE, $value) !== 1) {
            return false;
        }

        return self::parse($value)->kind !== null;
    }

    /**
     * Every distinct token in a payload, in the order first seen: in string
     * values at any depth, including tokens embedded in a longer string such as
     * a URL path. Keys are not read; a placeholder is never a key.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<PlaceholderToken>
     */
    public static function tokensIn(array $payload): array
    {
        $found = [];

        array_walk_recursive($payload, static function (mixed $value) use (&$found): void {
            if (! is_string($value) || preg_match_all(self::ANY, $value, $matches) === 0) {
                return;
            }

            foreach ($matches[0] as $text) {
                $found[$text] ??= self::parse($text);
            }
        });

        return array_values($found);
    }

    /**
     * Replace every token in a string with what `$resolve` gives for it.
     *
     * A string that is wholly one token becomes the resolved value itself, so a
     * timestamp can come back as the integer Stripe would have sent. A token
     * embedded in a longer string is replaced in place, and the result stays a
     * string.
     *
     * @param  Closure(PlaceholderToken): (string|int)  $resolve
     */
    public static function replace(string $value, Closure $resolve): string|int
    {
        if (preg_match(self::WHOLE, $value) === 1) {
            return $resolve(self::parse($value));
        }

        return (string) preg_replace_callback(
            self::ANY,
            static fn (array $m): string => (string) $resolve(self::parse($m[0])),
            $value
        );
    }

    private static function parse(string $text): PlaceholderToken
    {
        $inner = substr($text, 2, -2);

        if (preg_match(self::ID, $inner, $matches) === 1 && in_array($matches[1], self::KINDS, true)) {
            return PlaceholderToken::id($text, $matches[1], (int) $matches[2]);
        }

        if (preg_match(self::OFFSET, $inner, $matches) === 1) {
            try {
                return PlaceholderToken::offset($text, Duration::parse($matches[1]));
            } catch (InvalidFixture) {
                // Shaped like an offset but not one, such as `t+1h1d`.
            }
        }

        return PlaceholderToken::unrecognised($text);
    }
}
