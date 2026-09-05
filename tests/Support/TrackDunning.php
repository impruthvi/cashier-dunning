<?php

namespace Impruthvi\CashierDunning\Tests\Support;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * The application-side dunning policy these tests exercise.
 *
 * Cashier's subscription row cannot express this on its own: a subscription is
 * `past_due` after the first failed payment and still `past_due` after the last
 * one, but most applications keep access alive through the retry window and cut
 * it off when Stripe gives up. The signal for "Stripe has given up" is an
 * `invoice.payment_failed` with no `next_payment_attempt`.
 *
 * Modelled here because it is what a real application does, and because it is
 * the reason the resolver contract exists: what a plan grants is a product
 * decision that no billing library can answer.
 */
class TrackDunning
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $object = $payload['data']['object'] ?? [];

        $user = match ($payload['type'] ?? null) {
            'invoice.payment_failed', 'customer.subscription.created' => Cashier::findBillable(
                $object['customer'] ?? null
            ),
            default => null,
        };

        if ($user === null) {
            return;
        }

        $user->forceFill([
            'dunning_exhausted' => $payload['type'] === 'invoice.payment_failed'
                && ($object['next_payment_attempt'] ?? null) === null,
        ])->save();
    }
}
