<?php

use Impruthvi\CashierDunning\Fixtures\Duration;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

it('parses whole days', function () {
    expect(Duration::parse('21d')->minutes)->toBe(21 * 24 * 60);
});

it('parses sub-day precision', function () {
    // Stripe leaves subscription invoices in draft for roughly an hour, so a
    // scenario that only moves in whole days never observes them being paid.
    expect(Duration::parse('14d1h')->minutes)->toBe((14 * 24 * 60) + 60)
        ->and(Duration::parse('2h30m')->minutes)->toBe(150)
        ->and(Duration::parse('45m')->minutes)->toBe(45);
});

it('treats zero as the scenario start', function () {
    expect(Duration::parse('0d')->minutes)->toBe(0)
        ->and(Duration::parse('0d')->toString())->toBe('0d');
});

it('round-trips every form it accepts', function (string $value) {
    expect(Duration::parse($value)->toString())->toBe($value);
})->with(['0d', '21d', '14d1h', '2h30m', '45m', '1d2h30m', '30d']);

it('rejects a duration it cannot parse', function (string $value) {
    Duration::parse($value);
})->with(['', '21', 'soon', '1w', '21D', '1h1d', '-3d'])
    ->throws(InvalidFixture::class);

it('normalises hours that overflow into days', function () {
    // 25h and 1d1h are the same instant; canonical output keeps re-recordings
    // from diffing on how the recorder happened to phrase the offset.
    expect(Duration::parse('25h')->toString())->toBe('1d1h');
});

it('compares by value', function () {
    expect(Duration::parse('1d')->equals(Duration::parse('24h')))->toBeTrue()
        ->and(Duration::parse('1d')->equals(Duration::parse('1d1m')))->toBeFalse();
});

it('builds from minutes and clamps negatives to the scenario start', function () {
    expect(Duration::fromMinutes(90)->toString())->toBe('1h30m')
        ->and(Duration::fromMinutes(-5)->minutes)->toBe(0);
});
