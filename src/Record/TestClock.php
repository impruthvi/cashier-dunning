<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Duration;
use Stripe\StripeClient;

/**
 * Drives Stripe's test clock, which is the only way to make a month of billing
 * happen in a minute.
 *
 * Three of Stripe's rules shape this class.
 *
 * Advancing is asynchronous. The advance call returns immediately with the
 * clock in `advancing`, and the invoices, charges and webhooks that the jump
 * produces appear over the seconds that follow. Reading state before the clock
 * reaches `ready` records a half-finished world.
 *
 * A clock cannot jump more than two billing periods at once. A scenario's steps
 * are the natural staging points — they exist because something interesting
 * happens there — so advancing step by step satisfies the rule without inventing
 * artificial hops, and a scenario whose steps are further apart than that is a
 * scenario that needs more steps.
 *
 * Clocks auto-delete after 30 days, and deleting a clock removes its customers
 * and cancels their subscriptions. That is most of the cleanup for free, and the
 * reason recording never leaves a mess in an account it does not own.
 */
final class TestClock
{
    private const POLL_INTERVAL_MICROSECONDS = 500_000;

    public function __construct(
        private readonly StripeClient $stripe,
        public readonly string $id,
        public readonly CarbonImmutable $startedAt,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public static function create(StripeClient $stripe, CarbonImmutable $startsAt, int $timeoutSeconds = 120): self
    {
        $clock = $stripe->testHelpers->testClocks->create([
            'frozen_time' => $startsAt->getTimestamp(),
            'name' => 'cashier-dunning',
        ]);

        return new self($stripe, $clock->id, $startsAt, $timeoutSeconds);
    }

    /**
     * Move to an offset from the start of the scenario and wait until Stripe
     * has finished reacting.
     *
     * @throws ClockAdvanceFailed when the clock is still not ready in time
     */
    public function advanceTo(Duration $offset): CarbonImmutable
    {
        $moment = $this->startedAt->addMinutes($offset->minutes);

        $this->stripe->testHelpers->testClocks->advance($this->id, [
            'frozen_time' => $moment->getTimestamp(),
        ]);

        $this->waitUntilReady();

        return $moment;
    }

    public function status(): string
    {
        return (string) $this->stripe->testHelpers->testClocks->retrieve($this->id)->status;
    }

    /**
     * Deleting the clock takes its customers and their subscriptions with it,
     * which is the whole cleanup. Failures are swallowed: a recording that
     * succeeded should not be reported as failed because tidying up did not.
     */
    public function delete(): void
    {
        try {
            $this->stripe->testHelpers->testClocks->delete($this->id);
        } catch (\Throwable) {
            // Stripe deletes it after thirty days regardless.
        }
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        do {
            $status = $this->status();

            if ($status === 'ready') {
                return;
            }

            if ($status === 'internal_failure') {
                throw new ClockAdvanceFailed("Stripe reported an internal failure on test clock [{$this->id}].");
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new ClockAdvanceFailed(
            "Test clock [{$this->id}] was still [{$status}] after {$this->timeoutSeconds} seconds."
        );
    }
}
