<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Impruthvi\CashierDunning\Replay\WebhookSigner;
use Impruthvi\CashierDunning\Runner\WebhookDelivery;

/**
 * @param  array<string, mixed>  $event
 * @return array<string, mixed>
 */
function deliveredPayload(array $event): array
{
    $seen = [];

    Route::post('replay-envelope', function (Request $request) use (&$seen) {
        $seen = $request->json()->all();

        return response('', 200);
    });

    (new WebhookDelivery(
        app(Kernel::class),
        new WebhookSigner('whsec_test_inert'),
        'replay-envelope',
    ))->deliver($event);

    return $seen;
}

it('delivers the livemode flag a real Stripe event always carries', function () {
    // An application may refuse a live event on a test-mode install. Omitting
    // the flag makes every replayed event look live, and the refusal reads as
    // the application's bug rather than the harness's.
    $payload = deliveredPayload([
        'id' => 'evt_1',
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_replay1']],
    ]);

    expect($payload['livemode'])->toBeFalse();
});

it('leaves a fixture that declares its own mode alone', function () {
    $payload = deliveredPayload([
        'id' => 'evt_1',
        'type' => 'customer.subscription.created',
        'livemode' => true,
        'data' => ['object' => ['customer' => 'cus_replay1']],
    ]);

    expect($payload['livemode'])->toBeTrue();
});
