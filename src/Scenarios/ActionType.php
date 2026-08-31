<?php

namespace Impruthvi\CashierDunning\Scenarios;

/**
 * The things a scenario can ask the recorder to do to a Stripe test account.
 *
 * A closed set, not an escape hatch for arbitrary API calls. Every action here
 * has to be something the recorder can perform against a test clock, retry
 * safely, and describe in a sentence when it fails. An open-ended "call this
 * endpoint" action would let a scenario record something nobody can later read
 * or re-record.
 */
enum ActionType: string
{
    /** Advance the clock and observe. Most steps in a dunning timeline do this. */
    case Wait = 'wait';

    /** Create the customer, attach a payment method, and start a subscription. */
    case Subscribe = 'subscribe';

    /** Swap the payment method — the recovery half of a dunning story. */
    case ReplacePaymentMethod = 'replace_payment_method';

    /** Start a second subscription after the first one ended. */
    case Resubscribe = 'resubscribe';

    /** Move the subscription to a different price — the downgrade half. */
    case SwapPrice = 'swap_price';

    /** Cancel, either at period end or immediately. */
    case Cancel = 'cancel';

    public function touchesStripe(): bool
    {
        return $this !== self::Wait;
    }
}
