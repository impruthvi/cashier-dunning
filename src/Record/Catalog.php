<?php

namespace Impruthvi\CashierDunning\Record;

use Stripe\Price;
use Stripe\StripeClient;

/**
 * Makes sure the products a scenario needs exist, and creates them if not.
 *
 * A scenario names a price like `price_monthly`, not a Stripe id, because a
 * recording has to be reproducible against any test account. Looking the price
 * up by `lookup_key` and creating it when missing is what makes "clone the repo,
 * point at your own test account, re-record" work — otherwise every contributor
 * would have to hand-build a catalog first and every recording would differ.
 *
 * Everything created here is invented. The reference corpus is recorded from a
 * dedicated synthetic account precisely so that a published fixture has nothing
 * sensitive in it by construction, and a product called "Cashier Dunning
 * Fixture Plan" is not somebody's real pricing.
 */
final readonly class Catalog
{
    public const PRODUCT_NAME = 'Cashier Dunning Fixture Plan';

    public function __construct(private StripeClient $stripe) {}

    /**
     * Returns the Stripe price id for a scenario's price name, creating the
     * product and price if this account has never recorded before.
     */
    public function priceFor(string $name, int $amount = 3000, string $currency = 'usd', string $interval = 'month'): string
    {
        $existing = $this->stripe->prices->all([
            'lookup_keys' => [$name],
            'limit' => 1,
        ]);

        if (($existing->data[0] ?? null) instanceof Price) {
            return $existing->data[0]->id;
        }

        $product = $this->stripe->products->create([
            'name' => self::PRODUCT_NAME,
            'metadata' => ['created_by' => 'cashier-dunning', 'synthetic' => 'true'],
        ]);

        return $this->stripe->prices->create([
            'product' => $product->id,
            'unit_amount' => $amount,
            'currency' => $currency,
            'recurring' => ['interval' => $interval],
            'lookup_key' => $name,
            'metadata' => ['created_by' => 'cashier-dunning', 'synthetic' => 'true'],
        ])->id;
    }
}
