<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Reporting\JsonReport;
use Impruthvi\CashierDunning\Tests\Support\User;

afterEach(fn () => CashierDunning::flush());

it('describes the run in a shape a machine can compare', function () {
    $report = JsonReport::toArray(passingReport());

    expect($report['scenario'])->toBe('demo')
        ->and($report['passed'])->toBeTrue()
        ->and($report['assertions'])->toBe(2)
        ->and($report['events_delivered'])->toBe(1)
        ->and($report['steps'][0]['label'])->toBe('trial starts')
        ->and($report['steps'][0]['advance_to'])->toBe('0d')
        ->and($report['steps'][0]['events'][0]['type'])->toBe('customer.subscription.created')
        ->and($report['steps'][0]['events'][0]['status'])->toBe(200);
});

it('keeps both sides of a divergence rather than only the verdict', function () {
    // The artifact is what turns "billing broke sometime last month" into a
    // date. A report that recorded only pass or fail could not do that.
    $report = JsonReport::toArray(divergingReport());

    expect($report['steps'][0]['entitlements']['recording'])->toBe(['teams' => true, 'projects' => 10])
        ->and($report['steps'][0]['entitlements']['application'])->toBe(['teams' => false, 'projects' => 10])
        ->and($report['steps'][0]['mismatches'])->toHaveCount(1)
        ->and($report['steps'][0]['passed'])->toBeFalse();
});

it('records which event caused each entitlement change', function () {
    $change = JsonReport::toArray(passingReport())['steps'][0]['events'][0]['changes'][0];

    expect($change)->toBe([
        'feature' => 'teams',
        'from' => false,
        'to' => true,
        'caused_by' => 'customer.subscription.created',
    ]);
});

it('encodes to stable json with a trailing newline', function () {
    $encoded = JsonReport::encode(passingReport());

    expect($encoded)->toEndWith("}\n")
        ->and(json_decode($encoded, true)['scenario'])->toBe('demo')
        ->and(JsonReport::encode(passingReport()))->toBe($encoded);
});

it('writes a report where CI can pick it up, creating the directory', function () {
    $path = sys_get_temp_dir().'/cashier-dunning-report-'.uniqid().'/report.json';

    JsonReport::write(passingReport(), $path);

    expect(file_exists($path))->toBeTrue()
        ->and(json_decode((string) file_get_contents($path), true)['assertions'])->toBe(2);

    unlink($path);
    rmdir(dirname($path));
});

it('is written by the command when asked for', function () {
    $user = User::create(['name' => 'J', 'email' => 'j@e.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => $user);

    $path = sys_get_temp_dir().'/cashier-dunning-cmd-'.uniqid().'.json';

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate --json='.$path)->assertFailed();

    $written = json_decode((string) file_get_contents($path), true);

    expect($written['scenario'])->toBe('trial-dunning-cancel-reactivate')
        ->and($written['events_delivered'])->toBeGreaterThan(0);

    unlink($path);
});
