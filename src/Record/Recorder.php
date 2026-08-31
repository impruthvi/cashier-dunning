<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Allowlist;
use Impruthvi\CashierDunning\Fixtures\ApiExchange;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\Normalizer;
use Impruthvi\CashierDunning\Fixtures\Provider;
use Impruthvi\CashierDunning\Fixtures\Step;
use Impruthvi\CashierDunning\Record\Exceptions\IncompleteRecording;
use Impruthvi\CashierDunning\Scenarios\ActionType;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Scenarios\ScenarioStep;
use Laravel\Cashier\Cashier;
use Stripe\Exception\RateLimitException;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

/**
 * Turns a scenario into a fixture by making it actually happen.
 *
 * Create a test clock, put a customer on it, subscribe with a card that will
 * fail, then walk the scenario: advance the clock to each offset, wait for
 * Stripe to finish reacting, and collect what it emitted. At the end, check the
 * manifest — and only then write a file.
 *
 * The order matters. A recording is checked for completeness *before* it becomes
 * a fixture, because a short recording is wrong in a way nothing downstream can
 * detect: it replays green, and the drift job compares it against another short
 * recording and agrees.
 *
 * Everything captured is normalised and passed through the allowlist on the way
 * out. Fixtures are published, so redaction happens at the point of writing, not
 * as a later review step somebody can forget.
 */
final class Recorder
{
    /** @var list<array<string, mixed>> */
    private array $captured = [];

    /** @var list<string> */
    private array $requestIds = [];

    /** @var array<string, true> event types the scenario never asked about */
    private array $undeclared = [];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly ?string $quarantineDirectory = null,
        private readonly int $eventTimeoutSeconds = 30,
        private readonly int $clockTimeoutSeconds = 120,
    ) {}

    /**
     * @throws IncompleteRecording when the scenario's manifest is not satisfied
     */
    public function record(Scenario $scenario, ?CarbonImmutable $startsAt = null): Fixture
    {
        // A round number in the recent past. Stripe rejects a clock frozen in
        // the future, and a scenario that starts mid-hour makes every recorded
        // offset harder to read than it needs to be.
        $startsAt ??= CarbonImmutable::now('UTC')->subDay()->startOfHour();

        $clock = TestClock::create($this->stripe, $startsAt, $this->clockTimeoutSeconds);
        $poller = new EventPoller($this->stripe, $startsAt->getTimestamp());

        $state = new RecordingState;
        $steps = [];

        try {
            foreach ($scenario->steps as $index => $step) {
                $steps[] = $this->recordStep($scenario, $step, $index, $clock, $poller, $state);
            }
        } catch (RateLimitException $e) {
            throw $this->quarantine($scenario, $startsAt, 'rate limited: '.$e->getMessage(), $e);
        } catch (ClockAdvanceFailed $e) {
            throw $this->quarantine($scenario, $startsAt, $e->getMessage(), $e);
        } finally {
            $clock->delete();
        }

        $types = array_map(
            static fn (array $event): string => (string) ($event['type'] ?? ''),
            $this->captured
        );

        if (! $scenario->manifest->isSatisfiedBy($types)) {
            throw $this->quarantine(
                $scenario,
                $startsAt,
                'the recording ended before every required event arrived',
                null,
                $scenario->manifest->missingFrom($types),
            );
        }

        return new Fixture(
            provider: Provider::Stripe,
            scenario: $scenario->name,
            provenance: $this->provenance($startsAt),
            manifest: $scenario->manifest,
            steps: $steps,
        );
    }

    /** @return list<array<string, mixed>> */
    public function capturedEvents(): array
    {
        return $this->captured;
    }

    private function recordStep(
        Scenario $scenario,
        ScenarioStep $step,
        int $index,
        TestClock $clock,
        EventPoller $poller,
        RecordingState $state,
    ): Step {
        $moment = $step->advanceTo->minutes > 0 || $index > 0
            ? $clock->advanceTo($step->advanceTo)
            : $clock->startedAt;

        $this->perform($step, $state, $poller, $clock);

        // Expect nothing in particular per step: which events land at which
        // offset varies with account settings, and the manifest judges the
        // recording as a whole. Waiting for a specific event here would make a
        // scenario that is merely arranged differently look like a failure.
        $events = $poller->collect([], $this->eventTimeoutSeconds);
        $this->captured = [...$this->captured, ...$events];

        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');

            if ($type !== '' && ! $scenario->manifest->declares($type)) {
                $this->undeclared[$type] = true;
            }
        }

        $normalizer = new Normalizer($clock->startedAt->getTimestamp());
        $allowlist = new Allowlist;

        $recorded = [];

        foreach ($events as $event) {
            if (! $scenario->manifest->declares((string) ($event['type'] ?? ''))) {
                continue;
            }

            // Stripe stamps an event with real wall-clock time even when the
            // object it describes lives on a test clock, so a recording keeps
            // the event's own `created` only to find that a step marked +0d
            // contains an event dated a day later. Restamped to where the clock
            // actually was, because that is the point in the timeline a replay
            // has to reproduce.
            $event['created'] = $moment->getTimestamp();

            $recorded[] = $allowlist->apply($normalizer->normalize($event));
        }

        return new Step(
            advanceTo: $step->advanceTo,
            label: $step->label,
            events: $recorded,
            apiExchanges: $this->recordSubscriptionState($state, $normalizer, $allowlist),
            entitlements: [],
        );
    }

    /**
     * Records the current state of the subscription as a replayable exchange.
     *
     * An application under test asks Stripe about its subscription far more
     * often than it does anything else, so this is the one call worth capturing
     * at every step without being asked. Capturing it per step is what lets
     * replay refuse to answer with state from the wrong point in the timeline.
     *
     * @return list<ApiExchange>
     */
    private function recordSubscriptionState(RecordingState $state, Normalizer $normalizer, Allowlist $allowlist): array
    {
        if ($state->subscriptionId === null) {
            return [];
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($state->subscriptionId);
        } catch (Throwable) {
            // A cancelled and deleted subscription is a normal end state, not a
            // recording failure.
            return [];
        }

        /** @var array<string, mixed> $body */
        $body = $normalizer->normalize($subscription->toArray());

        // The id is normalised through the same normalizer as the payload, so
        // the path and the body agree on which placeholder the subscription got.
        $id = $normalizer->normalize(['id' => $state->subscriptionId])['id'];

        return [new ApiExchange(
            method: 'GET',
            path: '/v1/subscriptions/'.(is_string($id) ? $id : $state->subscriptionId),
            matchBody: [],
            responseStatus: 200,
            responseBody: $allowlist->applyToObject($body, 'customer.subscription.updated'),
        )];
    }

    private function perform(ScenarioStep $step, RecordingState $state, EventPoller $poller, TestClock $clock): void
    {
        $parameters = $step->action->parameters;
        $catalog = new Catalog($this->stripe);

        switch ($step->action->type) {
            case ActionType::Wait:
                return;

            case ActionType::Subscribe:
                $customer = $this->stripe->customers->create([
                    'email' => 'fixture+'.bin2hex(random_bytes(4)).'@example.test',
                    'name' => 'Cashier Dunning Fixture',
                    'test_clock' => $clock->id,
                ]);

                $state->customerId = $customer->id;
                $poller->watch($customer->id);

                $state->subscriptionId = $this->subscribe($state, $catalog, $parameters);
                $poller->watch((string) $state->subscriptionId);

                return;

            case ActionType::ReplacePaymentMethod:
                $this->attachCard($state, (string) ($parameters['card'] ?? ''));

                return;

            case ActionType::Resubscribe:
                $state->subscriptionId = $this->subscribe($state, $catalog, $parameters);
                $poller->watch((string) $state->subscriptionId);

                return;

            case ActionType::Cancel:
                if ($state->subscriptionId !== null) {
                    ($parameters['immediately'] ?? false)
                        ? $this->stripe->subscriptions->cancel($state->subscriptionId)
                        : $this->stripe->subscriptions->update($state->subscriptionId, ['cancel_at_period_end' => true]);
                }
        }
    }

    /** @param array<string, scalar|null> $parameters */
    private function subscribe(RecordingState $state, Catalog $catalog, array $parameters): string
    {
        $paymentMethod = $this->attachCard($state, (string) ($parameters['card'] ?? ''));
        $trialDays = (int) ($parameters['trial_days'] ?? 0);

        $payload = [
            'customer' => (string) $state->customerId,
            'items' => [['price' => $catalog->priceFor((string) ($parameters['price'] ?? 'price_monthly'))]],
            'default_payment_method' => $paymentMethod,
            // Without this Stripe leaves an unpayable subscription `incomplete`
            // instead of taking it through the dunning cycle, and the scenario
            // records nothing worth replaying.
            'payment_behavior' => 'allow_incomplete',
        ];

        if ($trialDays > 0) {
            $payload['trial_period_days'] = $trialDays;
        }

        return $this->stripe->subscriptions->create($payload)->id;
    }

    private function attachCard(RecordingState $state, string $card): string
    {
        $attached = $this->stripe->paymentMethods->attach($card, ['customer' => (string) $state->customerId]);

        $this->stripe->customers->update((string) $state->customerId, [
            'invoice_settings' => ['default_payment_method' => $attached->id],
        ]);

        return $attached->id;
    }

    /** @return array<string, mixed> */
    private function provenance(CarbonImmutable $startsAt): array
    {
        return [
            'recorded_at' => $startsAt->toIso8601String(),
            'synthetic' => true,
            'source' => 'recorded from a dedicated Stripe test account',
            'stripe_api_version' => Stripe::VERSION,
            'cashier_version' => Cashier::VERSION,
            'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            // Kept so a maintainer can see what this account emitted that the
            // scenario did not ask for. That is how a manifest learns as Stripe
            // changes, rather than by someone noticing years later.
            'undeclared_events' => array_keys($this->undeclared),
        ];
    }

    /** @param list<string> $missing */
    private function quarantine(
        Scenario $scenario,
        CarbonImmutable $startsAt,
        string $reason,
        ?Throwable $previous = null,
        array $missing = [],
    ): IncompleteRecording {
        $path = null;

        if ($this->quarantineDirectory !== null) {
            $path = (new QuarantineArtifact(
                scenario: $scenario,
                captured: $this->captured,
                requestIds: $this->requestIds,
                reason: $reason,
                startedAt: $startsAt,
                failedAt: CarbonImmutable::now('UTC'),
            ))->write($this->quarantineDirectory);
        }

        $types = array_map(
            static fn (array $event): string => (string) ($event['type'] ?? ''),
            $this->captured
        );

        return new IncompleteRecording(
            scenario: $scenario->name,
            missing: $missing,
            captured: $types,
            reason: $reason,
            artifactPath: $path,
        );
    }
}
