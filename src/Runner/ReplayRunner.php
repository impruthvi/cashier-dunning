<?php

namespace Impruthvi\CashierDunning\Runner;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\Chaos\EventOrderer;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\EntitlementComparison;
use Impruthvi\CashierDunning\Entitlements\EntitlementTimeline;
use Impruthvi\CashierDunning\Entitlements\NullResolver;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\Step;
use Impruthvi\CashierDunning\Replay\WebhookSigner;
use Impruthvi\CashierDunning\Simulation\SimulationContext;
use Impruthvi\CashierDunning\Simulation\SimulationEnvironment;
use Throwable;

/**
 * Drives an application through a recorded billing lifecycle.
 *
 * Each step advances the clock, points the replay transport at that step's
 * recordings, delivers the step's webhooks in order, and asks the application
 * what the billable is entitled to afterwards.
 *
 * The resolver is asked once after every event rather than once per step. A
 * step that delivers `invoice.payment_failed` and `customer.subscription.updated`
 * together changes entitlements at one of the two, and only asking between them
 * can say which. That attribution is the difference between "your users lost API
 * access" and "your users lost API access at invoice.payment_failed, three days
 * before Stripe cancelled anything".
 *
 * A failing step stops the run. Once the application's state has diverged from
 * the recording, every later step is comparing against a world the fixture never
 * described, and the extra failures are noise that buries the first one.
 */
final readonly class ReplayRunner
{
    public function __construct(
        private Repository $config,
        private Kernel $kernel,
        private EntitlementResolver $resolver,
        private SimulationEnvironment $environment,
    ) {}

    public function run(
        Fixture $fixture,
        ?CarbonImmutable $startingAt = null,
        ?EventOrderer $orderer = null,
        int $pass = 0,
    ): ReplayReport {
        return $this->environment->run(
            $fixture,
            fn (SimulationContext $context): ReplayReport => $this->replay(
                $fixture,
                $context,
                $orderer ?? EventOrderer::inOrder(),
                $pass,
            ),
            $startingAt
        );
    }

    private function replay(
        Fixture $fixture,
        SimulationContext $context,
        EventOrderer $orderer,
        int $pass,
    ): ReplayReport {
        $delivery = new WebhookDelivery(
            $this->kernel,
            new WebhookSigner($context->credentials->webhookSecret),
            $this->webhookPath(),
        );

        $timeline = new EntitlementTimeline($this->resolver, $context->billable);

        $results = [];
        $assertions = 0;
        $completed = true;

        foreach ($fixture->steps as $index => $step) {
            $context->enterStep($index);

            [$result, $stepAssertions] = $this->replayStep(
                $step,
                $index,
                $delivery,
                $timeline,
                $context,
                $orderer,
                $pass,
            );

            $results[] = $result;
            $assertions += $stepAssertions;

            if ($result->failed()) {
                $completed = false;

                break;
            }
        }

        return new ReplayReport(
            scenario: $fixture->scenario,
            steps: $results,
            assertions: $assertions,
            completed: $completed,
            sideEffects: $context->sideEffects(),
            blockedDeliveries: $context->blockedDeliveries(),
        );
    }

    /** @return array{0: StepResult, 1: int} */
    private function replayStep(
        Step $step,
        int $index,
        WebhookDelivery $delivery,
        EntitlementTimeline $timeline,
        SimulationContext $context,
        EventOrderer $orderer,
        int $pass,
    ): array {
        $events = [];
        $assertions = 0;

        foreach ($orderer->apply($step->events, $index, $pass) as $event) {
            $payload = $context->resolvePlaceholders($event);

            try {
                $response = $delivery->deliver($payload);
                $status = $response->getStatusCode();

                // Every delivered webhook is an assertion: the application has
                // to accept an event Stripe really sent.
                $assertions++;

                $events[] = new EventResult(
                    id: (string) ($payload['id'] ?? 'unknown'),
                    type: (string) ($payload['type'] ?? 'unknown'),
                    status: $status,
                    changes: $timeline->observe($payload),
                    failure: $status >= 300
                        ? "The application answered {$status}. Stripe would retry this event, and keep retrying."
                        : null,
                );
            } catch (Throwable $e) {
                $events[] = new EventResult(
                    id: (string) ($payload['id'] ?? 'unknown'),
                    type: (string) ($payload['type'] ?? 'unknown'),
                    status: 0,
                    changes: [],
                    failure: $e->getMessage(),
                );

                break;
            }
        }

        $expectedEntitlements = $this->resolver instanceof NullResolver
            ? []
            : $step->entitlements;
        $actualEntitlements = $timeline->snapshot()->toArray();
        $comparison = EntitlementComparison::between($expectedEntitlements, $actualEntitlements);
        $assertions += $comparison->assertions;

        return [
            new StepResult(
                index: $index,
                label: $step->label,
                advanceTo: $step->advanceTo->toString(),
                events: $events,
                expectedEntitlements: $expectedEntitlements,
                actualEntitlements: $actualEntitlements,
                mismatches: $comparison->mismatches,
            ),
            $assertions,
        ];
    }

    private function webhookPath(): string
    {
        $prefix = $this->config->get('cashier.path');

        return '/'.trim(is_string($prefix) ? $prefix : 'stripe', '/').'/webhook';
    }
}
