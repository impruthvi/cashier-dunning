<?php

namespace Impruthvi\CashierDunning\Guards;

use Impruthvi\CashierDunning\Guards\Exceptions\UnsafeKey;

/**
 * Stands between `--record` and Stripe.
 *
 * Recording is not a read-only operation. It creates a customer, a
 * subscription and invoices, then advances a test clock through months of
 * billing in seconds. Every one of those operations is legal against a live
 * key, and against a live key they charge real cards belonging to real
 * customers. There is no undo.
 *
 * So the guard is an allowlist, not a blocklist: recording proceeds only when
 * the key can be positively identified as a test key. A key in an unrecognised
 * format is refused rather than assumed harmless, because a prefix Stripe
 * introduces after this code was written must not silently become permitted.
 *
 * Replay is not guarded, because replay never reads the key at all.
 */
final class KeyModeGuard
{
    private const TEST_PREFIXES = ['sk_test_', 'rk_test_'];

    private const LIVE_PREFIXES = ['sk_live_', 'rk_live_'];

    public static function detect(?string $key): KeyMode
    {
        $key = trim((string) $key);

        if ($key === '') {
            return KeyMode::Missing;
        }

        foreach (self::TEST_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return KeyMode::Test;
            }
        }

        foreach (self::LIVE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return KeyMode::Live;
            }
        }

        return KeyMode::Unrecognised;
    }

    /**
     * @throws UnsafeKey when the key is anything other than a Stripe test key
     */
    public static function assertSafeToRecord(?string $key): KeyMode
    {
        $mode = self::detect($key);

        if (! $mode->canRecord()) {
            throw UnsafeKey::forRecording($mode);
        }

        return $mode;
    }

    /**
     * A form of the key safe to print in a terminal, a CI log or a bug report.
     *
     * Only the prefix survives. Even the last four characters of a live key are
     * more than a diagnostic needs, and CI logs outlive the runs that made them.
     */
    public static function redact(?string $key): string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return '(not set)';
        }

        $segments = explode('_', $key);

        if (count($segments) < 3) {
            return '(unrecognised format)';
        }

        return $segments[0].'_'.$segments[1].'_…';
    }
}
