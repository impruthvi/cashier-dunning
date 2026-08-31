<?php

namespace Impruthvi\CashierDunning\Scenarios;

/**
 * One thing to do at a point in a scenario, as data rather than as a closure.
 *
 * Data because a scenario has to be listable, comparable and printable before
 * anything runs. `billing:doctor` should be able to say what a scenario will do
 * to your Stripe account without doing it, and a diff of two scenarios should
 * be readable. A closure can do none of that.
 */
final readonly class Action
{
    /** @param array<string, scalar|null> $parameters */
    public function __construct(
        public ActionType $type,
        public array $parameters = [],
    ) {}

    public static function wait(): self
    {
        return new self(ActionType::Wait);
    }

    /**
     * @param  string  $card  a Stripe test payment method token, such as
     *                        pm_card_chargeCustomerFail, which authorises and
     *                        then fails on the first real charge — the only
     *                        reliable way to provoke dunning on demand
     */
    public static function subscribe(string $price, string $card, int $trialDays = 0): self
    {
        return new self(ActionType::Subscribe, [
            'price' => $price,
            'card' => $card,
            'trial_days' => $trialDays,
        ]);
    }

    public static function replacePaymentMethod(string $card): self
    {
        return new self(ActionType::ReplacePaymentMethod, ['card' => $card]);
    }

    public static function resubscribe(string $price, string $card): self
    {
        return new self(ActionType::Resubscribe, ['price' => $price, 'card' => $card]);
    }

    public static function cancel(bool $immediately = false): self
    {
        return new self(ActionType::Cancel, ['immediately' => $immediately]);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, scalar|null> $parameters */
        $parameters = (array) ($data['parameters'] ?? []);

        return new self(
            type: ActionType::from((string) ($data['type'] ?? 'wait')),
            parameters: $parameters,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'parameters' => $this->parameters];
    }

    public function describe(): string
    {
        return match ($this->type) {
            ActionType::Wait => 'wait',
            ActionType::Subscribe => sprintf(
                'subscribe to %s with %s%s',
                $this->parameters['price'] ?? '?',
                $this->parameters['card'] ?? '?',
                ($this->parameters['trial_days'] ?? 0) > 0
                    ? ' after a '.$this->parameters['trial_days'].' day trial'
                    : '',
            ),
            ActionType::ReplacePaymentMethod => 'replace the payment method with '.($this->parameters['card'] ?? '?'),
            ActionType::Resubscribe => 'subscribe again to '.($this->parameters['price'] ?? '?'),
            ActionType::Cancel => ($this->parameters['immediately'] ?? false) ? 'cancel immediately' : 'cancel at period end',
        };
    }
}
