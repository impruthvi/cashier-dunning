<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Duration;
use Impruthvi\CashierDunning\Replay\WebhookSigner;
use Impruthvi\CashierDunning\Simulation\SimulationContext;
use Impruthvi\CashierDunning\Simulation\SimulationEnvironment;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

afterEach(fn () => CashierDunning::flush());

it('produces a header Stripe own verifier accepts', function () {
    // Not a reimplementation checked against itself: this is Stripe's verifier,
    // the same code Cashier's middleware calls.
    $signer = new WebhookSigner('whsec_test');
    $payload = '{"id":"evt_1","type":"invoice.payment_failed"}';

    expect(WebhookSignature::verifyHeader($payload, $signer->sign($payload), 'whsec_test', 300))
        ->toBeTrue();
});

it('is rejected when the payload is altered', function () {
    $signer = new WebhookSigner('whsec_test');
    $header = $signer->sign('{"id":"evt_1"}');

    WebhookSignature::verifyHeader('{"id":"evt_TAMPERED"}', $header, 'whsec_test', 300);
})->throws(SignatureVerificationException::class);

it('is rejected under a different secret', function () {
    $signer = new WebhookSigner('whsec_one');
    $payload = '{"id":"evt_1"}';

    WebhookSignature::verifyHeader($payload, $signer->sign($payload), 'whsec_two', 300);
})->throws(SignatureVerificationException::class);

it('signs with real time even when the simulation clock has moved', function () {
    // The detail that decides this design. Stripe's verifier compares the header
    // timestamp against PHP's time(), which Carbon's test clock does not touch,
    // and rejects anything outside a five minute window. Signing with simulated
    // time would fail verification the moment a scenario advanced past it.
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);

    $header = (new SimulationEnvironment(config()))->run(function (SimulationContext $context) {
        $context->advanceTo(Duration::parse('30d'));

        return (new WebhookSigner($context->credentials->webhookSecret))->sign('{"id":"evt_1"}');
    }, CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    preg_match('/t=(\d+)/', $header, $matches);

    expect(abs(time() - (int) $matches[1]))->toBeLessThan(5);
});

it('carries simulated time in the event while signing with real time', function () {
    // Two different clocks on purpose. `created` is what the application reads;
    // the header timestamp only proves the payload was not tampered with in
    // transit, and there was no transit.
    $signer = new WebhookSigner('whsec_test');
    $simulated = CarbonImmutable::parse('2026-01-15T01:00:00Z')->getTimestamp();

    $envelope = $signer->envelope(['id' => 'evt_1', 'created' => $simulated]);

    preg_match('/t=(\d+)/', $envelope['headers']['Stripe-Signature'], $matches);

    expect(json_decode($envelope['payload'], true)['created'])->toBe($simulated)
        ->and((int) $matches[1])->not->toBe($simulated)
        ->and(abs(time() - (int) $matches[1]))->toBeLessThan(5);
});

it('signs the exact bytes it hands over', function () {
    // The payload is signed as encoded, not re-encoded on the way out. Any
    // difference in escaping between the two would fail verification.
    $signer = new WebhookSigner('whsec_test');

    $envelope = $signer->envelope(['id' => 'evt_1', 'url' => 'https://example.com/a/b']);

    expect(WebhookSignature::verifyHeader(
        $envelope['payload'],
        $envelope['headers']['Stripe-Signature'],
        'whsec_test',
        300
    ))->toBeTrue();
});

it('passes Cashier webhook signature middleware end to end', function () {
    // The claim that matters: Cashier's verification stays switched on during a
    // replay and does the same work it does in production.
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);

    $response = (new SimulationEnvironment(config()))->run(function (SimulationContext $context) {
        $signer = new WebhookSigner($context->credentials->webhookSecret);
        $envelope = $signer->envelope(['id' => 'evt_1', 'type' => 'ping', 'data' => ['object' => []]]);

        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $envelope['headers']['Stripe-Signature'], 'CONTENT_TYPE' => 'application/json'],
            $envelope['payload']
        );
    });

    $response->assertOk();
});

it('is refused by Cashier when the signature is wrong', function () {
    // Proves the middleware is actually running. Without this, the test above
    // would pass just as well with verification switched off.
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);

    $response = (new SimulationEnvironment(config()))->run(function () {
        $envelope = (new WebhookSigner('whsec_not_the_one'))
            ->envelope(['id' => 'evt_1', 'type' => 'ping', 'data' => ['object' => []]]);

        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $envelope['headers']['Stripe-Signature'], 'CONTENT_TYPE' => 'application/json'],
            $envelope['payload']
        );
    });

    $response->assertForbidden();
});

it('still passes Cashier verification thirty days into a scenario', function () {
    // The regression this design exists to prevent. A signer that used simulated
    // time would pass the first step of every scenario and fail every step after
    // the five minute tolerance window — which is every interesting step.
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);

    $response = (new SimulationEnvironment(config()))->run(function (SimulationContext $context) {
        $context->advanceTo(Duration::parse('30d'));

        $envelope = (new WebhookSigner($context->credentials->webhookSecret))->envelope([
            'id' => 'evt_1',
            'type' => 'ping',
            'created' => $context->now()->getTimestamp(),
            'data' => ['object' => []],
        ]);

        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $envelope['headers']['Stripe-Signature'], 'CONTENT_TYPE' => 'application/json'],
            $envelope['payload']
        );
    }, CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    $response->assertOk();
});
