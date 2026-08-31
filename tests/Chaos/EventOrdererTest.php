<?php

use Impruthvi\CashierDunning\Chaos\EventOrderer;

function events(int $count): array
{
    return array_map(
        static fn (int $i): array => ['id' => "evt_{$i}", 'type' => 'invoice.payment_failed'],
        range(1, $count)
    );
}

it('leaves events alone when asked for no chaos', function () {
    expect(EventOrderer::inOrder()->apply(events(3), 0, 0))->toBe(events(3))
        ->and(EventOrderer::inOrder()->isChaotic())->toBeFalse();
});

it('produces the same order for the same seed', function () {
    // A chaos failure nobody can reproduce is a chaos failure nobody fixes.
    $first = (new EventOrderer(shuffle: true, seed: 42))->apply(events(6), 2, 1);
    $second = (new EventOrderer(shuffle: true, seed: 42))->apply(events(6), 2, 1);

    expect($first)->toBe($second);
});

it('produces different orders for different seeds', function () {
    $a = (new EventOrderer(shuffle: true, seed: 1))->apply(events(8), 0, 1);
    $b = (new EventOrderer(shuffle: true, seed: 2))->apply(events(8), 0, 1);

    expect($a)->not->toBe($b);
});

it('produces different orders on different passes of the same seed', function () {
    $a = (new EventOrderer(shuffle: true, seed: 7))->apply(events(8), 0, 1);
    $b = (new EventOrderer(shuffle: true, seed: 7))->apply(events(8), 0, 2);

    expect($a)->not->toBe($b);
});

it('keeps every event when reordering', function () {
    $shuffled = (new EventOrderer(shuffle: true, seed: 3))->apply(events(6), 0, 1);

    expect($shuffled)->toHaveCount(6)
        ->and(array_column($shuffled, 'id'))->toEqualCanonicalizing(array_column(events(6), 'id'));
});

it('cannot reorder a step holding one event', function () {
    expect((new EventOrderer(shuffle: true, seed: 9))->apply(events(1), 0, 1))->toBe(events(1));
});

it('delivers every event twice when duplicating', function () {
    // Stripe promises at-least-once delivery, so a duplicate is not a fault
    // condition. It is Tuesday.
    $duplicated = (new EventOrderer(duplicate: true))->apply(events(2), 0, 1);

    expect($duplicated)->toHaveCount(4)
        ->and(array_column($duplicated, 'id'))->toBe(['evt_1', 'evt_2', 'evt_1', 'evt_2']);
});

it('describes what it did so a report can name the pass', function () {
    expect(EventOrderer::inOrder()->describe())->toBe('in order')
        ->and((new EventOrderer(shuffle: true))->describe())->toBe('shuffled')
        ->and((new EventOrderer(duplicate: true))->describe())->toBe('duplicated')
        ->and((new EventOrderer(shuffle: true, duplicate: true))->describe())->toBe('shuffled and duplicated');
});
