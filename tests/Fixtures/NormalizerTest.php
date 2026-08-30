<?php

use Impruthvi\CashierDunning\Fixtures\Normalizer;

$start = 1767225600; // arbitrary fixed epoch; scenarios are relative to their own start

it('replaces provider ids with stable placeholders', function () use ($start) {
    $normalizer = new Normalizer($start);

    $result = $normalizer->normalize([
        'id' => 'evt_1Abc',
        'data' => ['object' => ['id' => 'sub_9Xyz', 'customer' => 'cus_7Def']],
    ]);

    expect($result['id'])->toBe('{{evt_1}}')
        ->and($result['data']['object']['id'])->toBe('{{sub_1}}')
        ->and($result['data']['object']['customer'])->toBe('{{cus_1}}');
});

it('reuses the same placeholder for the same id', function () use ($start) {
    $normalizer = new Normalizer($start);

    $result = $normalizer->normalize([
        'a' => 'sub_SAME',
        'b' => 'sub_SAME',
        'c' => 'sub_OTHER',
    ]);

    expect($result['a'])->toBe('{{sub_1}}')
        ->and($result['b'])->toBe('{{sub_1}}')
        ->and($result['c'])->toBe('{{sub_2}}');
});

it('produces identical output for two recordings of the same shape', function () use ($start) {
    // This is the property drift detection depends on. Without it every
    // re-record diffs on ids that carry no meaning and the alarm gets muted.
    $payload = [
        'id' => 'evt_first',
        'data' => ['object' => ['id' => 'sub_first', 'customer' => 'cus_first']],
    ];

    $other = [
        'id' => 'evt_second',
        'data' => ['object' => ['id' => 'sub_second', 'customer' => 'cus_second']],
    ];

    expect((new Normalizer($start))->normalize($payload))
        ->toBe((new Normalizer($start))->normalize($other));
});

it('converts timestamp fields to offsets from the scenario start', function () use ($start) {
    $normalizer = new Normalizer($start);

    $result = $normalizer->normalize([
        'created' => $start,
        'trial_end' => $start + (14 * 86400),
        'next_payment_attempt' => $start + (17 * 86400) + 3600,
    ]);

    expect($result['created'])->toBe('{{t+0d}}')
        ->and($result['trial_end'])->toBe('{{t+14d}}')
        ->and($result['next_payment_attempt'])->toBe('{{t+17d1h}}');
});

it('leaves non-timestamp integers alone', function () use ($start) {
    // amount_due of 3000 is money, not an epoch. Detecting timestamps by key
    // rather than by numeric range is what keeps this true.
    $result = (new Normalizer($start))->normalize([
        'amount_due' => 3000,
        'attempt_count' => 2,
        'quantity' => 1,
    ]);

    expect($result)->toBe(['amount_due' => 3000, 'attempt_count' => 2, 'quantity' => 1]);
});

it('leaves strings that are not provider ids alone', function () use ($start) {
    $result = (new Normalizer($start))->normalize([
        'status' => 'past_due',
        'currency' => 'usd',
        'billing_reason' => 'subscription_cycle',
    ]);

    expect($result['status'])->toBe('past_due')
        ->and($result['currency'])->toBe('usd')
        ->and($result['billing_reason'])->toBe('subscription_cycle');
});

it('exposes its assignments so a recorder can report what it rewrote', function () use ($start) {
    $normalizer = new Normalizer($start);
    $normalizer->normalize(['id' => 'sub_ABC']);

    expect($normalizer->assignments())->toBe(['sub_ABC' => '{{sub_1}}']);
});

it('drops empty containers from recorded payloads', function () use ($start) {
    // PHP cannot tell `{}` from `[]`, so an empty container kept in a fixture
    // would re-encode differently from how it was recorded. It carries no
    // replayable information, so it is dropped at record time instead.
    $result = (new Normalizer($start))->normalize([
        'id' => 'sub_1',
        'metadata' => [],
        'discounts' => [],
        'items' => ['data' => [['id' => 'si_1']]],
    ]);

    expect($result)->not->toHaveKey('metadata')
        ->and($result)->not->toHaveKey('discounts')
        ->and($result['items']['data'][0]['id'])->toBe('{{si_1}}');
});
