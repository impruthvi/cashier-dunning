<?php

use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\EntitlementTimeline;
use Impruthvi\CashierDunning\Entitlements\NullResolver;

/**
 * Stands in for an application whose entitlements follow subscription state.
 * The billable is mutated between observe() calls the way replaying a webhook
 * would mutate it.
 */
function scriptedResolver(object $billable): EntitlementResolver
{
    return new class($billable) implements EntitlementResolver
    {
        public int $calls = 0;

        public function __construct(private object $billable) {}

        public function resolve(object $billable): array
        {
            $this->calls++;

            return [
                'api' => $this->billable->status === 'active',
                'projects' => $this->billable->status === 'active' ? 10 : 0,
            ];
        }
    };
}

it('asks the resolver once before any event so the first change has a baseline', function () {
    $billable = (object) ['status' => 'active'];
    $resolver = scriptedResolver($billable);

    $timeline = new EntitlementTimeline($resolver, $billable);

    expect($resolver->calls)->toBe(1)
        ->and($timeline->snapshot()->get('api'))->toBeTrue()
        ->and($timeline->changes())->toBe([]);
});

it('attributes a change to the event that caused it', function () {
    // This is the whole point of resolving per event rather than per step: a
    // step delivering two events changes entitlements at exactly one of them.
    $billable = (object) ['status' => 'active'];
    $timeline = new EntitlementTimeline(scriptedResolver($billable), $billable);

    $timeline->observe(['id' => 'evt_1', 'type' => 'invoice.payment_failed']);

    $billable->status = 'past_due';
    $changes = $timeline->observe(['id' => 'evt_2', 'type' => 'customer.subscription.updated']);

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->eventType)->toBe('customer.subscription.updated')
        ->and($changes[0]->eventId)->toBe('evt_2');
});

it('returns only what the observed event changed, not the running total', function () {
    $billable = (object) ['status' => 'active'];
    $timeline = new EntitlementTimeline(scriptedResolver($billable), $billable);

    $billable->status = 'past_due';
    $timeline->observe(['type' => 'invoice.payment_failed']);

    expect($timeline->observe(['type' => 'customer.subscription.deleted']))->toBe([])
        ->and($timeline->changes())->toHaveCount(2);
});

it('collects losses across the whole timeline', function () {
    $billable = (object) ['status' => 'active'];
    $timeline = new EntitlementTimeline(scriptedResolver($billable), $billable);

    $billable->status = 'past_due';
    $timeline->observe(['type' => 'invoice.payment_failed']);

    $billable->status = 'active';
    $timeline->observe(['type' => 'invoice.paid']);

    expect($timeline->losses())->toHaveCount(2)
        ->and($timeline->losses()[0]->eventType)->toBe('invoice.payment_failed')
        ->and($timeline->changes())->toHaveCount(4);
});

it('runs against a resolver that answers nothing', function () {
    $timeline = new EntitlementTimeline(new NullResolver, new stdClass);

    expect($timeline->observe(['type' => 'invoice.payment_failed']))->toBe([])
        ->and($timeline->snapshot()->isEmpty())->toBeTrue();
});
