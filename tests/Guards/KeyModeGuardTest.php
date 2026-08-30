<?php

use Impruthvi\CashierDunning\Guards\Exceptions\UnsafeKey;
use Impruthvi\CashierDunning\Guards\KeyMode;
use Impruthvi\CashierDunning\Guards\KeyModeGuard;

it('identifies test keys', function (string $key) {
    expect(KeyModeGuard::detect($key))->toBe(KeyMode::Test);
})->with(['sk_test_abc123', 'rk_test_abc123']);

it('identifies live keys', function (string $key) {
    expect(KeyModeGuard::detect($key))->toBe(KeyMode::Live);
})->with(['sk_live_abc123', 'rk_live_abc123']);

it('treats a missing key as missing rather than unsafe', function (?string $key) {
    // Replay needs no key at all, so absence is a normal state, not an error.
    expect(KeyModeGuard::detect($key))->toBe(KeyMode::Missing);
})->with([null, '', '   ']);

it('refuses to classify anything it does not recognise', function (string $key) {
    // An allowlist, not a blocklist. A prefix Stripe introduces after this code
    // was written must not become permitted by default.
    expect(KeyModeGuard::detect($key))->toBe(KeyMode::Unrecognised);
})->with([
    'pk_test_abc123',      // publishable, not secret
    'whsec_abc123',        // webhook signing secret
    'sk_abc123',           // no mode segment
    'sk_prod_abc123',      // plausible but not a thing
    'SK_TEST_ABC123',      // prefixes are case sensitive
]);

it('permits recording only with a test key', function () {
    expect(KeyModeGuard::assertSafeToRecord('sk_test_abc123'))->toBe(KeyMode::Test);
});

it('refuses to record against a live key', function () {
    // Recording creates subscriptions and advances a clock through months of
    // billing. Against a live key that charges real cards, and there is no undo.
    KeyModeGuard::assertSafeToRecord('sk_live_abc123');
})->throws(UnsafeKey::class, 'LIVE key');

it('refuses to record with no key', function () {
    KeyModeGuard::assertSafeToRecord(null);
})->throws(UnsafeKey::class, 'no Stripe key is configured');

it('refuses to record with a key it cannot identify', function () {
    KeyModeGuard::assertSafeToRecord('pk_test_abc123');
})->throws(UnsafeKey::class, 'not in a recognised format');

it('redacts a key down to its prefix', function () {
    // CI logs outlive the runs that made them. Even the last four characters of
    // a live key are more than a diagnostic needs.
    expect(KeyModeGuard::redact('sk_live_51H8xYzAbCdEfGh'))->toBe('sk_live_…')
        ->and(KeyModeGuard::redact('sk_test_51H8xYzAbCdEfGh'))->toBe('sk_test_…')
        ->and(KeyModeGuard::redact(null))->toBe('(not set)')
        ->and(KeyModeGuard::redact('garbage'))->toBe('(unrecognised format)');
});

it('never leaks the key material in a redacted value', function () {
    $key = 'sk_live_51H8xYzAbCdEfGhIjKlMnOp';

    expect(KeyModeGuard::redact($key))->not->toContain('51H8xYz');
});

it('never names the key in the refusal message', function () {
    // The exception is printed to a terminal and pasted into bug reports.
    expect(fn () => KeyModeGuard::assertSafeToRecord('sk_live_51H8xYzAbCdEfGh'))
        ->toThrow(fn (UnsafeKey $e) => expect($e->getMessage())->not->toContain('51H8xYz'));
});
