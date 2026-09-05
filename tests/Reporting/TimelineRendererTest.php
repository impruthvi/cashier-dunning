<?php

use Impruthvi\CashierDunning\Entitlements\EntitlementChange;
use Impruthvi\CashierDunning\Reporting\TimelineRenderer;
use Impruthvi\CashierDunning\Runner\EventResult;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\StepResult;
use Symfony\Component\Console\Output\BufferedOutput;

function passingReport(): ReplayReport
{
    return new ReplayReport('demo', [
        new StepResult(
            index: 0,
            label: 'trial starts',
            advanceTo: '0d',
            events: [new EventResult('evt_1', 'customer.subscription.created', 200, [
                new EntitlementChange('teams', false, true, 'customer.subscription.created'),
            ])],
            expectedEntitlements: ['teams' => true],
            actualEntitlements: ['teams' => true],
            mismatches: [],
        ),
    ], assertions: 2, completed: true);
}

function divergingReport(): ReplayReport
{
    return new ReplayReport('demo', [
        new StepResult(
            index: 0,
            label: 'payment fails',
            advanceTo: '14d1h',
            events: [new EventResult('evt_1', 'invoice.payment_failed', 200, [])],
            expectedEntitlements: ['teams' => true, 'projects' => 10],
            actualEntitlements: ['teams' => false, 'projects' => 10],
            mismatches: ['teams: recording says true, application says false'],
        ),
    ], assertions: 3, completed: false);
}

function render(ReplayReport $report, bool $decorated, bool $explain = false): string
{
    $output = new BufferedOutput(decorated: $decorated);

    (new TimelineRenderer($output, $explain))->render($report);

    return $output->fetch();
}

function renderFailures(ReplayReport $report): string
{
    $output = new BufferedOutput;

    (new TimelineRenderer($output))->renderFailures($report);

    return $output->fetch();
}

it('marks steps with symbols in a terminal', function () {
    expect(render(passingReport(), decorated: true))->toContain('✓');
});

it('degrades symbols to words when the output is not a terminal', function () {
    // A CI log that renders ✓ as a replacement character has lost the one
    // column a reader scans first. The Windows console cannot reliably draw it.
    $plain = render(passingReport(), decorated: false);

    expect($plain)->not->toContain('✓')
        ->and($plain)->toContain('ok   +0d  trial starts');
});

it('emits no escape codes when the output is not a terminal', function () {
    expect(render(divergingReport(), decorated: false))->not->toContain("\033");
});

it('shows the recording and the application side by side when they diverge', function () {
    $output = render(divergingReport(), decorated: false);

    expect($output)->toContain('feature   recording     application')
        ->and($output)->toContain('teams     true          false');
});

it('shows features that agree alongside the one that does not', function () {
    // A single red line reads as an isolated glitch. The same line among green
    // ones reads as "everything else held, this one moved", which is usually
    // the whole diagnosis.
    expect(render(divergingReport(), decorated: false))->toContain('projects  10            10');
});

it('renders a missing feature as an em dash rather than nothing', function () {
    $report = new ReplayReport('demo', [
        new StepResult(0, 'step', '0d', [], ['teams' => true], [], ['teams: missing']),
    ], assertions: 1, completed: false);

    expect(render($report, decorated: false))->toContain('teams    true          —');
});

it('stays quiet about entitlement changes unless asked to explain', function () {
    expect(render(passingReport(), decorated: false))
        ->not->toContain('teams: false -> true');
});

it('attributes each change to its event when explaining', function () {
    expect(render(passingReport(), decorated: false, explain: true))
        ->toContain('teams: false -> true (at customer.subscription.created)');
});

it('leads with the verdict a reader is looking for', function () {
    expect(render(passingReport(), decorated: false))->toContain('PASS  All 2 assertions passed.')
        ->and(render(divergingReport(), decorated: false))
        ->toContain('FAIL  1 step(s) did not match the recording, starting at step 0 (payment fails).');
});

it('shows why the application answered badly', function () {
    $report = new ReplayReport('demo', [
        new StepResult(0, 'step', '0d', [
            new EventResult('evt_1', 'invoice.payment_failed', 500, [], 'The application answered 500.'),
        ], [], [], []),
    ], assertions: 1, completed: false);

    expect(render($report, decorated: false))->toContain('The application answered 500.');
});

it('renders failed timeline rows without repeating the report wrapper', function () {
    $output = renderFailures(divergingReport());

    expect($output)->toContain('FAIL +14d1h  payment fails')
        ->and($output)->toContain('invoice.payment_failed')
        ->and($output)->toContain('teams     true          false')
        ->and($output)->not->toContain('demo  replayed with no Stripe account')
        ->and($output)->not->toContain('1 step(s) did not match');
});

it('renders the report verdict when failure has no step to show', function () {
    $report = new ReplayReport('empty', [], assertions: 0, completed: false);

    expect(renderFailures($report))->toContain('No assertions ran.');
});
