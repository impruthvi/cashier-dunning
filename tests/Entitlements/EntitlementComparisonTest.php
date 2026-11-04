<?php

use Impruthvi\CashierDunning\Entitlements\EntitlementComparison;

it('reports typed comparisons and counts only declared features in fixture order', function () {
    $comparison = EntitlementComparison::between(
        ['projects' => 10, 'teams' => true],
        ['exports' => true, 'teams' => true, 'projects' => '10'],
    );

    expect($comparison->rows)->toBe([
        'projects' => ['expected' => 10, 'actual' => '10', 'differs' => true],
        'teams' => ['expected' => true, 'actual' => true, 'differs' => false],
    ])
        ->and($comparison->assertions)->toBe(2)
        ->and($comparison->mismatches)->toBe(['projects: recording says 10, application says 10']);
});

it('compares missing features against null and preserves mismatch wording', function () {
    $comparison = EntitlementComparison::between(
        ['missingNullable' => null, 'nullable' => null, 'teams' => true, 'projects' => 10, 'plan' => 'pro'],
        ['nullable' => null],
    );

    expect($comparison->rows)->toBe([
        'missingNullable' => ['expected' => null, 'actual' => null, 'differs' => false],
        'nullable' => ['expected' => null, 'actual' => null, 'differs' => false],
        'teams' => ['expected' => true, 'actual' => null, 'differs' => true],
        'projects' => ['expected' => 10, 'actual' => null, 'differs' => true],
        'plan' => ['expected' => 'pro', 'actual' => null, 'differs' => true],
    ])
        ->and($comparison->assertions)->toBe(5)
        ->and($comparison->mismatches)->toBe([
            'teams: recording says true, application says null',
            'projects: recording says 10, application says null',
            'plan: recording says pro, application says null',
        ]);
});

it('makes no assertions when the fixture declares no features', function () {
    $comparison = EntitlementComparison::between([], ['teams' => true]);

    expect($comparison->rows)->toBe([])
        ->and($comparison->assertions)->toBe(0)
        ->and($comparison->mismatches)->toBe([]);
});

it('keeps scalar types significant in comparisons', function ($expected, $actual, string $mismatch) {
    $comparison = EntitlementComparison::between(['feature' => $expected], ['feature' => $actual]);

    expect($comparison->rows['feature']['differs'])->toBeTrue()
        ->and($comparison->assertions)->toBe(1)
        ->and($comparison->mismatches)->toBe([$mismatch]);
})->with([
    'boolean versus text' => [false, 'false', 'feature: recording says false, application says false'],
    'integer versus float' => [10, 10.0, 'feature: recording says 10, application says 10'],
    'null versus text' => [null, 'null', 'feature: recording says null, application says null'],
    'fractional limits' => [1.5, 2.5, 'feature: recording says 1.5, application says 2.5'],
]);
