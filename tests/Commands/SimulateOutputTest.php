<?php

use Illuminate\Support\Facades\Artisan;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;

beforeEach(fn () => DunningMailer::reset());
afterEach(function () {
    CashierDunning::flush();
    DunningMailer::reset();
});

/**
 * What the command actually writes to the terminal.
 *
 * `TimelineRendererTest` drives the renderer against a plain `BufferedOutput`,
 * which is not what the command used: it passed Laravel's `OutputStyle`, and
 * that collapses every run of spaces to one. So the suite saw an aligned
 * timeline the CLI could not produce, and the published README examples were
 * captured from the suite rather than from a run a reader could reproduce.
 *
 * These assert the spacing on the real command path. They fail if the rendering
 * output is ever routed back through `OutputStyle`.
 */
function simulateOutput(string $command): string
{
    billableUser();
    registerDunningPolicy();

    Artisan::call($command);

    return Artisan::output();
}

it('indents the timeline instead of collapsing it to single spaces', function () {
    $output = simulateOutput('billing:simulate trial-dunning-cancel-reactivate');

    expect($output)
        ->toContain('  trial-dunning-cancel-reactivate  replayed with no Stripe account')
        ->toContain('      invoice.payment_failed  200')
        ->and($output)->not->toContain(' trial-dunning-cancel-reactivate replayed');
});

it('keeps the step mark, the clock and the label in their own columns', function () {
    $output = simulateOutput('billing:simulate trial-dunning-cancel-reactivate');

    // "ok" padded to four, then the advance, then the label. Undecorated here,
    // because a test process has no terminal; a terminal gets the mark instead.
    expect($output)->toContain('  ok   +14d1h  trial ends, first payment attempt fails');
});

it('keeps the side-effect block aligned under its heading', function () {
    $output = simulateOutput('billing:simulate trial-dunning-cancel-reactivate');

    expect($output)
        ->toContain('  Side effects  what the application did')
        ->toContain('      mail:jenny@example.com  ×9');
});

it('keeps chaos pass lines aligned', function () {
    $output = simulateOutput('billing:simulate trial-dunning-cancel-reactivate --duplicate --seed=7 --iterations=1');

    expect($output)
        ->toContain('  Chaos  same events, orders Stripe is entitled to use')
        ->toContain('  ok    pass 0: in order')
        ->toContain('  FAIL  pass 1: duplicated');
});

it('lines up the recording and the application in the divergence table', function () {
    // The columns are the diagnosis: one red row among green ones reads as
    // "everything else held, this moved". Collapsed, it reads as noise.
    CashierDunning::resolveEntitlementsUsing(fn (): array => ['teams' => false, 'projects' => 0]);

    billableUser();
    Artisan::call('billing:simulate trial-dunning-cancel-reactivate');
    $output = Artisan::output();

    expect($output)->toContain('        feature   recording     application');
});
