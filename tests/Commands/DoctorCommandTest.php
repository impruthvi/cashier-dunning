<?php

use Impruthvi\CashierDunning\CashierDunning;

afterEach(fn () => CashierDunning::flush());

it('passes with no Stripe key at all', function () {
    // Replay is the default mode of use. An installation that has never seen a
    // Stripe key is a healthy installation.
    config()->set('cashier.secret', null);

    $this->artisan('billing:doctor')
        ->expectsOutputToContain('Stripe key: not set')
        ->expectsOutputToContain('works with no Stripe account')
        ->assertSuccessful();
});

it('reports a test key as ready to record', function () {
    config()->set('cashier.secret', 'sk_test_abc123');

    $this->artisan('billing:doctor')
        ->expectsOutputToContain('sk_test_…')
        ->expectsOutputToContain('Record  ready.')
        ->assertSuccessful();
});

it('warns loudly about a live key without failing replay', function () {
    // A live key is not a broken installation, it is a blocked recording. The
    // distinction matters: failing here would break CI for apps that simply
    // have production credentials in the environment.
    config()->set('cashier.secret', 'sk_live_abc123');

    // Substrings must not overlap: expectsOutputToContain consumes one written
    // line per expectation, so a substring that also appears in a later line
    // leaves the later expectation with nothing to match.
    $this->artisan('billing:doctor')
        ->expectsOutputToContain('Recording is refused; replay is unaffected')
        ->expectsOutputToContain('Record  blocked: LIVE mode')
        ->assertSuccessful();
});

it('never prints the key', function () {
    config()->set('cashier.secret', 'sk_live_51H8xYzAbCdEfGh');

    $this->artisan('billing:doctor')->assertSuccessful();

    expect(Artisan::output())->not->toContain('51H8xYz');
});

it('reports the shipped corpus as valid', function () {
    $this->artisan('billing:doctor')
        ->expectsOutputToContain('fixture(s) valid')
        ->assertSuccessful();
});

it('fails when a fixture on disk is not loadable', function () {
    // Doctor exists so a broken corpus is found in a second, offline, rather
    // than halfway through a rate-limited recording.
    $path = sys_get_temp_dir().'/cashier-dunning-doctor-'.uniqid().'/stripe';
    mkdir($path, 0755, true);
    file_put_contents($path.'/broken.json', '{"format_version": 1}');
    config()->set('cashier-dunning.fixtures.path', dirname($path));

    $this->artisan('billing:doctor')
        ->expectsOutputToContain('Fixture [broken.json]')
        ->assertFailed();

    unlink($path.'/broken.json');
    rmdir($path);
    rmdir(dirname($path));
});

it('reports a registered entitlement resolver', function () {
    CashierDunning::resolveEntitlementsUsing(fn () => ['teams' => true]);

    $this->artisan('billing:doctor')
        ->expectsOutputToContain('closure registered in code')
        ->assertSuccessful();
});

it('says what a missing webhook secret costs rather than just flagging it', function () {
    config()->set('cashier.webhook.secret', null);

    $this->artisan('billing:doctor')
        ->expectsOutputToContain('cannot prove your signature handling works')
        ->assertSuccessful();
});
