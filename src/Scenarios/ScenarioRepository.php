<?php

namespace Impruthvi\CashierDunning\Scenarios;

use Impruthvi\CashierDunning\Fixtures\Manifest;

/**
 * The scenarios that ship with the package.
 *
 * Defined in PHP rather than loaded from files: a scenario is executable
 * intent, and a typo in a JSON file would surface halfway through a
 * rate-limited recording instead of at boot.
 *
 * Prices and payment methods are Stripe's documented test values, so a
 * recording can be reproduced against any test account without setting
 * anything up by hand first.
 */
final readonly class ScenarioRepository
{
    /**
     * Authorises successfully and then fails on the first real charge, which is
     * the only reliable way to provoke dunning on demand. A card that declines
     * outright never creates the subscription to dun.
     */
    public const FAILING_CARD = 'pm_card_chargeCustomerFail';

    public const WORKING_CARD = 'pm_card_visa';

    /** @return array<string, Scenario> */
    public function all(): array
    {
        $scenarios = [$this->trialDunningCancelReactivate()];

        return array_column(
            array_map(static fn (Scenario $s): array => [$s->name, $s], $scenarios),
            1,
            0
        );
    }

    public function find(string $name): ?Scenario
    {
        return $this->all()[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * The story the package is named after: a trial that ends, a card that
     * fails, a retry window, a cancellation, and a customer who comes back.
     *
     * The offsets are not arbitrary. Payment steps land an hour past the day
     * boundary because Stripe leaves subscription invoices in `draft` for
     * roughly an hour before finalising them, so a scenario that moved in whole
     * days would advance straight past the charge it came to watch.
     */
    private function trialDunningCancelReactivate(): Scenario
    {
        return new Scenario(
            name: 'trial-dunning-cancel-reactivate',
            description: 'A trial ends, the card fails, the retry window expires, '.
                'the subscription is cancelled, and the customer resubscribes.',
            steps: [
                ScenarioStep::at('0d', 'trial starts', Action::subscribe(
                    price: 'price_monthly',
                    card: self::FAILING_CARD,
                    trialDays: 14,
                )),
                ScenarioStep::at('11d', 'three days before the trial ends'),
                ScenarioStep::at('14d1h', 'trial ends, first payment attempt fails'),
                ScenarioStep::at('17d1h', 'grace period expires on the second failed attempt'),
                ScenarioStep::at('28d', 'subscription cancelled after retries are exhausted'),
                ScenarioStep::at('30d', 'customer fixes their card and resubscribes', Action::resubscribe(
                    price: 'price_monthly',
                    card: self::WORKING_CARD,
                )),
            ],
            manifest: new Manifest(
                required: [
                    'customer.subscription.created',
                    'customer.subscription.trial_will_end',
                    'invoice.payment_failed',
                    'customer.subscription.updated',
                    'customer.subscription.deleted',
                ],
                // Stripe emits these for some account configurations and not
                // others. Requiring them would make the recorder work only for
                // accounts set up like the author's.
                optional: [
                    'invoice.created',
                    'invoice.finalized',
                    'invoice.paid',
                    'invoice.payment_succeeded',
                ],
            ),
        );
    }
}
