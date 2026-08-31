<?php

namespace Impruthvi\CashierDunning\Runner;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\EntitlementTimeline;
use Impruthvi\CashierDunning\Entitlements\Snapshot;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\Step;
use Impruthvi\CashierDunning\Replay\FixtureHttpClient;
use Impruthvi\CashierDunning\Replay\Placeholders;
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
    ) {}

    public function run(Fixture $fixture, ?CarbonImmutable $startingAt = null): ReplayReport
    {
        // Fixed before anything else, because the placeholder resolver and the
        // simulation clock have to agree on when the scenario begins. A fixture
        // timestamp of {{t+14d}} means nothing if they disagree.
        $startedAt = $startingAt ?? CarbonImmutable::now();

        $placeholders = new Placeholders($startedAt);
        $client = new FixtureHttpClient($fixture, $placeholders);

        return (new SimulationEnvironment($this->config, $client))->run(
            fn (SimulationContext $context): ReplayReport => $this->replay($fixture, $context, $client, $placeholders),
            $startedAt
        );
    }

    private function replay(
        Fixture $fixture,
        SimulationContext $context,
        FixtureHttpClient $client,
        Placeholders $placeholders,
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
            $context->advanceTo($step->advanceTo);
            $client->atStep($index);

            [$result, $stepAssertions] = $this->replayStep($step, $index, $delivery, $timeline, $placeholders);

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
        );
    }

    /** @return array{0: StepResult, 1: int} */
    private function replayStep(
        Step $step,
        int $index,
        WebhookDelivery $delivery,
        EntitlementTimeline $timeline,
        Placeholders $placeholders,
    ): array {
        $events = [];
        $assertions = 0;

        foreach ($step->events as $event) {
            /** @var array<string, mixed> $payload */
            $payload = $placeholders->resolve($event);

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

        return [
            new StepResult(
                index: $index,
                label: $step->label,
                advanceTo: $step->advanceTo->toString(),
                events: $events,
                expectedEntitlements: $step->entitlements,
                actualEntitlements: $timeline->snapshot()->toArray(),
                mismatches: $this->compareEntitlements($step->entitlements, $timeline->snapshot(), $assertions),
            ),
            $assertions,
        ];
    }

    /**
     * Only the features the fixture declared are compared.
     *
     * A fixture records what one application claimed at one point in time. An
     * application that has since added a feature the recording never mentioned
     * has not broken anything, and failing on it would make every fixture rot
     * the moment a plan gained a perk. A feature the fixture *did* declare going
     * missing is the opposite: that is a revocation, and it is exactly what this
     * package is for.
     *
     * @param  array<string, scalar|null>  $expected
     * @return list<string>
     */
    private function compareEntitlements(array $expected, Snapshot $actual, int &$assertions): array
    {
        $mismatches = [];

        foreach ($expected as $feature => $value) {
            $assertions++;

            if ($actual->get($feature) !== $value) {
                $mismatches[] = sprintf(
                    '%s: recording says %s, application says %s',
                    $feature,
                    $this->render($value),
                    $this->render($actual->get($feature)),
                );
            }
        }

        return $mismatches;
    }

    private function render(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private function webhookPath(): string
    {
        $prefix = $this->config->get('cashier.path');

        return '/'.trim(is_string($prefix) ? $prefix : 'stripe', '/').'/webhook';
    }
}
